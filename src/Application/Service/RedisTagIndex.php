<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Service;

use Predis\ClientInterface;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Contract\ExternalTagIndexInterface;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Domain\Model\TagSet;
use Semitexa\Core\Redis\RedisConnectionPool;

/**
 * Redis-backed tag index.
 *
 * Coroutine safety: identical to {@see RedisCacheStore}, and for the same
 * reason. Under Swoole with SWOOLE_HOOK_ALL a Predis socket is
 * coroutine-EXCLUSIVE, so a single shared client kills the worker the moment
 * two coroutines touch tags at once — "Socket#N has already been bound to
 * another coroutine#M", exit 255. Connections are therefore borrowed from a
 * {@see RedisConnectionPool} for the duration of one logical operation, and the
 * pool is normally SHARED with the store rather than duplicated.
 *
 * One borrow spans a whole attach/detach/flush, not a single command: flush
 * issues SMEMBERS, one MGET over the candidates, DEL and SREM, and those must
 * not be interleaved with another coroutine's traffic on the same socket.
 *
 * A tag set carries an expiry covering its longest-lived member, so a tag that
 * nobody ever flushes cannot grow forever; flush() prunes members it has
 * visited, which bounds the sets that ARE flushed.
 *
 * The index is a list of CANDIDATES, not a statement of fact: membership is
 * written on put and never rewritten, so a member may name an entry that has
 * since been overwritten with different tags or has expired. Deletion re-reads
 * the entry and removes it only if it still carries the tag being flushed.
 *
 * Tag sets are per NAMESPACE — see {@see CacheNamespace::tagKeyPrefix()}. They
 * were per tenant, and flush() filtered members by the namespace prefix
 * instead; that could not separate the root namespace from a named one,
 * because the root's prefix is a string prefix of every named one. Every
 * member of a set now belongs to that namespace by construction, so there is
 * nothing left to filter.
 */
final class RedisTagIndex implements ExternalTagIndexInterface
{
    /**
     * Add a member and give the set a lifetime that covers it — atomically.
     *
     * The TTL is read BEFORE the SADD, and that ordering is the whole point:
     * afterwards a brand-new set and a deliberately immortal one both answer
     * -1, and Redis has no primitive that tells them apart. Reading first
     * distinguishes them (-2 is "no such key"), and doing it inside one script
     * closes the window that made the read useless — two coroutines attaching
     * to the same tag could otherwise each see a state the other had just
     * created, and settle on the SHORTER lifetime, leaving the longer-lived
     * member unflushable once the set expired under it.
     *
     * KEYS[1] tag set · ARGV[1] member · ARGV[2] ttl seconds · ARGV[3] 1 when the entry never expires
     */
    private const ATTACH_SCRIPT = <<<'LUA'
        local prior = redis.call('TTL', KEYS[1])
        redis.call('SADD', KEYS[1], ARGV[1])
        if ARGV[3] == '1' then
          redis.call('PERSIST', KEYS[1])
          return -1
        end
        if prior == -1 then
          return -1
        end
        local ttl = tonumber(ARGV[2])
        if prior == -2 or prior < ttl then
          redis.call('EXPIRE', KEYS[1], ttl)
        end
        return ttl
        LUA;

    private readonly ?ClientInterface $client;
    private readonly ?RedisConnectionPool $pool;

    public function __construct(
        private readonly CacheValueSerializer $serializer,
        ?ClientInterface $redis = null,
        ?CacheConfig $config = null,
        ?RedisConnectionPool $pool = null,
    ) {
        if ($redis !== null) {
            $this->client = $redis;
            $this->pool = null;
            return;
        }

        if ($pool !== null) {
            $this->client = null;
            $this->pool = $pool;
            return;
        }

        $config ??= CacheConfig::fromEnvironment();
        $this->client = null;
        $this->pool = new RedisConnectionPool($config->redisPoolSize, [
            'scheme' => $config->redisScheme,
            'host' => $config->redisHost,
            'port' => $config->redisPort,
            'password' => $config->redisPassword ?? '',
        ]);
    }

    /** The published contract, which carries no lifetime: the set outlives nothing it can measure. */
    public function attach(ResolvedCacheKey $key, TagSet $tags): void
    {
        $this->attachWithLifetime($key, $tags, null);
    }

    public function attachWithLifetime(ResolvedCacheKey $key, TagSet $tags, ?int $ttlSeconds): void
    {
        $this->withConnection(function (ClientInterface $redis) use ($key, $tags, $ttlSeconds): void {
            $forever = $ttlSeconds === null || $ttlSeconds <= 0 ? 1 : 0;

            foreach ($tags->values() as $tag) {
                $redis->eval(
                    self::ATTACH_SCRIPT,
                    1,
                    $this->tagKey($key->namespace, $tag),
                    $key->asString(),
                    (string) ($ttlSeconds ?? 0),
                    (string) $forever,
                );
            }
        });
    }

    public function detach(ResolvedCacheKey $key, TagSet $tags): void
    {
        $this->withConnection(function (ClientInterface $redis) use ($key, $tags): void {
            foreach ($tags->values() as $tag) {
                $redis->srem($this->tagKey($key->namespace, $tag), $key->asString());
            }
        });
    }

    public function flush(CacheNamespace $namespace, TagSet $tags): int
    {
        return $this->withConnection(function (ClientInterface $redis) use ($namespace, $tags): int {
            $count = 0;

            foreach ($tags->values() as $tag) {
                $count += $this->flushSet($redis, $this->tagKey($namespace, $tag), $tag, null);

                // The layout before this one kept ONE set per tenant, with no
                // namespace. Entries already in a deployed cache are listed
                // only there, and that version never gave those sets a TTL, so
                // they do not drain by themselves — unread, those entries would
                // simply stop being invalidatable by tag. Read alongside, and
                // pruned as we go, so the old layout empties out.
                //
                // Members of that set can belong to any namespace of the
                // tenant, and telling them apart is the very thing a prefix
                // cannot do. They get the separation the old layout had —
                // a prefix test — which is no worse than before and applies
                // only to entries written before this deploy.
                $count += $this->flushSet(
                    $redis,
                    $namespace->legacyTagKeyPrefix() . $tag,
                    $tag,
                    $namespace->asPrefix(),
                );
            }

            return $count;
        });
    }

    /**
     * Flush one tag set, deleting only entries that still carry the tag AND
     * have not changed since they were read.
     *
     * The classification is a read; the deletion is a write; between the two a
     * concurrent put can replace the value and reattach its tags. Comparing the
     * raw value inside the script closes that: a rewritten entry no longer
     * matches what was classified, so it is neither deleted nor unlisted, and
     * the flush leaves the new value and its fresh membership alone.
     *
     * @param string|null $confineToPrefix keys outside it are left untouched
     */
    private function flushSet(ClientInterface $redis, string $tagKey, string $tag, ?string $confineToPrefix): int
    {
        $members = $redis->smembers($tagKey);
        if (empty($members)) {
            return 0;
        }

        $members = array_values($members);
        if ($confineToPrefix !== null) {
            $members = array_values(array_filter(
                $members,
                static fn(string $k): bool => str_starts_with($k, $confineToPrefix),
            ));
            if ($members === []) {
                return 0;
            }
        }

        // One round trip for every candidate. Reading them one at a time turned
        // a tag with N members into N sequential round trips, on a connection
        // the rest of the worker shares.
        $raws = $redis->mget($members);

        $argv = [];
        $acted = 0;
        foreach ($members as $i => $keyStr) {
            $raw = $raws[$i] ?? null;
            $verdict = $this->verdictFor($raw, $tag);

            if ($verdict === self::UNREADABLE) {
                // Left in the set untouched. Pruning it would make the entry
                // permanently unflushable, and an entry this process cannot
                // decode — a rotated signing key, a worker with a different
                // environment — is not evidence that the membership is wrong.
                continue;
            }

            $argv[] = $keyStr;
            $argv[] = $verdict === self::CARRIES_TAG ? '1' : '0';
            $argv[] = is_string($raw) ? $raw : self::ABSENT;
            $acted++;
        }

        if ($acted === 0) {
            return 0;
        }

        return (int) $redis->eval(self::FLUSH_SCRIPT, 1, $tagKey, ...$argv);
    }

    public function supportsNamespaceFlush(): bool
    {
        return true;
    }

    /**
     * Drop this namespace's tag sets.
     *
     * Clearing a namespace scans the ENTRY keyspace, and the sets no longer
     * live there — they were moved out so that the root namespace's prefix
     * could stop matching every named one. So nothing removed them, and a set
     * holding a member that never expires has no expiry of its own either: it
     * survived the flush that deleted everything it named, and grew again from
     * there on the next write. Raised in review of cache#19.
     *
     * The prefix match carries the same reach the store's own sweep has: the
     * root namespace's prefix is a prefix of every named one, so clearing the
     * root clears the tenant, entries and sets alike. That is what clearing
     * the root already did to the entries.
     *
     * The legacy tenant-wide sets are deliberately not swept here. They live
     * INSIDE the entry keyspace, so the store's scan already removes them when
     * the root is cleared — and they name members from every namespace, so
     * clearing one named namespace must not take them with it.
     */
    public function clearNamespace(CacheNamespace $namespace): int
    {
        return $this->withConnection(static function (ClientInterface $redis) use ($namespace): int {
            $removed = 0;
            $pattern = $namespace->tagKeyPrefix() . '*';
            $cursor = '0';

            do {
                $page = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
                $cursor = is_scalar($page[0] ?? null) ? (string) $page[0] : '0';

                $keys = [];
                foreach (is_array($page[1] ?? null) ? $page[1] : [] as $key) {
                    if (is_string($key)) {
                        $keys[] = $key;
                    }
                }

                if ($keys !== []) {
                    $redis->del($keys);
                    $removed += count($keys);
                }
            } while ($cursor !== '0');

            return $removed;
        });
    }

    /**
     * Delete and unlist only what has not moved since it was classified.
     *
     * KEYS[1] tag set · then triples of (member, '1' when it should be deleted, the raw value seen)
     *
     * A member whose current value differs from the one classified was written
     * by somebody else between the read and this call: it keeps both its value
     * and its membership, because the new value may well carry the tag and the
     * fresh SADD that accompanied it must not be undone. Returns how many
     * entries were actually deleted.
     */
    private const FLUSH_SCRIPT = <<<'LUA'
        local removed = 0
        for i = 1, #ARGV, 3 do
          local member = ARGV[i]
          local doomed = ARGV[i + 1] == '1'
          local seen = ARGV[i + 2]
          local now = redis.call('GET', member)
          local same = (now == false and seen == '\0absent') or (now ~= false and now == seen)
          if same then
            if doomed then
              redis.call('DEL', member)
              removed = removed + 1
            end
            redis.call('SREM', KEYS[1], member)
          end
        end
        return removed
        LUA;

    /** Stands in for "there was no value", which Lua sees as false. */
    private const ABSENT = "\0absent";

    private const CARRIES_TAG = 'carries';
    private const NOT_OURS = 'not-ours';
    private const UNREADABLE = 'unreadable';

    /**
     * What a stored value says about its membership of $tag.
     *
     * Three answers, not two. "Gone" and "no longer tagged" both mean the
     * membership is stale and may be pruned. "Unreadable" means this process
     * cannot tell — and must therefore neither delete the entry nor forget it.
     */
    private function verdictFor(mixed $raw, string $tag): string
    {
        if (!is_string($raw) || $raw === '') {
            return self::NOT_OURS; // gone: the entry gave up before the tag did
        }

        try {
            $entry = $this->serializer->decode($raw);
        } catch (\Throwable) {
            return self::UNREADABLE;
        }

        if ($entry->isExpiredAt(time())) {
            return self::NOT_OURS;
        }

        return in_array($tag, $entry->tags->values(), true) ? self::CARRIES_TAG : self::NOT_OURS;
    }

    private function tagKey(CacheNamespace $namespace, string $tag): string
    {
        return $namespace->tagKeyPrefix() . $tag;
    }

    /**
     * Run $fn with an exclusive Redis connection: a pooled one borrowed for the
     * whole operation (coroutine-safe under Swoole), or the injected client.
     *
     * @template T
     * @param callable(ClientInterface): T $fn
     * @return T
     */
    private function withConnection(callable $fn): mixed
    {
        if ($this->pool !== null) {
            return $this->pool->withConnection($fn);
        }

        /** @var ClientInterface $client */
        $client = $this->client;
        return $fn($client);
    }
}
