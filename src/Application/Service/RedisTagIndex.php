<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Service;

use Predis\ClientInterface;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Contract\TagIndexInterface;
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
final class RedisTagIndex implements TagIndexInterface
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

    public function attach(ResolvedCacheKey $key, TagSet $tags, ?int $ttlSeconds = null): void
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
                $tagKey = $this->tagKey($namespace, $tag);
                $members = $redis->smembers($tagKey);

                if (empty($members)) {
                    continue;
                }

                // One round trip for every candidate. Reading them one at a
                // time turned a tag with N members into N sequential round
                // trips, on a connection borrowed from the pool the rest of
                // the worker shares.
                $raws = $redis->mget($members);

                $doomed = [];
                $stale = [];

                foreach (array_values($members) as $i => $keyStr) {
                    $verdict = $this->verdictFor($raws[$i] ?? null, $tag);

                    if ($verdict === self::CARRIES_TAG) {
                        $doomed[] = $keyStr;
                    } elseif ($verdict === self::NOT_OURS) {
                        $stale[] = $keyStr;
                    }
                    // UNREADABLE: left in the set untouched. Pruning it would
                    // make the entry permanently unflushable, and an entry this
                    // process cannot decode — a rotated signing key, a worker
                    // with a different environment — is not evidence that the
                    // membership is wrong.
                }

                if ($doomed !== []) {
                    $redis->del($doomed);
                    $count += count($doomed);
                }

                $handled = array_merge($doomed, $stale);
                if ($handled !== []) {
                    // Only what was visited. The tag key itself is never
                    // deleted: a member added between the SMEMBERS above and
                    // this SREM would go with it, leaving that entry tagged and
                    // unreachable. Removing every member empties the set, and
                    // Redis drops an empty set on its own.
                    $redis->srem($tagKey, $handled);
                }
            }

            return $count;
        });
    }

    public function supportsNamespaceFlush(): bool
    {
        return true;
    }

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
