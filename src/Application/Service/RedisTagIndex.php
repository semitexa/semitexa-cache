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
 * issues SMEMBERS, a GET per candidate, DEL and SREM, and those must not be
 * interleaved with another coroutine's traffic on the same socket.
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
 * Two namespaces of the same tenant currently share one tag key, because
 * {@see CacheNamespace::tagKeyPrefix()} does not include the namespace. Until
 * that changes, flush() must filter members by the namespace prefix or a flush
 * in one namespace deletes another's entries.
 */
final class RedisTagIndex implements TagIndexInterface
{
    /** Redis TTL replies: the key is gone, and the key exists but never expires. */
    private const TTL_NO_KEY = -2;
    private const TTL_NO_EXPIRY = -1;

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
            $forever = $ttlSeconds === null || $ttlSeconds <= 0;

            foreach ($tags->values() as $tag) {
                $tagKey = $this->tagKey($key->namespace, $tag);

                // Read the expiry BEFORE writing: afterwards a brand-new set and
                // a deliberately immortal one both answer -1, and Redis has no
                // primitive that tells them apart. One extra command per tag per
                // put buys that distinction; the alternative is a set that
                // either outlives everything it names or dies before it.
                $priorTtl = (int) $redis->ttl($tagKey);

                $redis->sadd($tagKey, [$key->asString()]);

                if ($forever) {
                    // The set must now outlive an entry that never expires.
                    $redis->persist($tagKey);
                    continue;
                }

                if ($priorTtl === self::TTL_NO_EXPIRY) {
                    // Already immortal because some member is. Leave it.
                    continue;
                }

                if ($priorTtl === self::TTL_NO_KEY || $priorTtl < $ttlSeconds) {
                    $redis->expire($tagKey, $ttlSeconds);
                }
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
            $prefix = $namespace->asPrefix();
            $count = 0;

            foreach ($tags->values() as $tag) {
                $tagKey = $this->tagKey($namespace, $tag);
                $members = $redis->smembers($tagKey);

                if (empty($members)) {
                    continue;
                }

                $doomed = [];
                $stale = [];
                $foreign = 0;

                foreach ($members as $keyStr) {
                    if (!str_starts_with($keyStr, $prefix)) {
                        // Belongs to another namespace sharing this tag key.
                        $foreign++;
                        continue;
                    }

                    if ($this->stillCarries($redis, $keyStr, $tag)) {
                        $doomed[] = $keyStr;
                    } else {
                        $stale[] = $keyStr;
                    }
                }

                if ($doomed !== []) {
                    $redis->del($doomed);
                    $count += count($doomed);
                }

                $handled = array_merge($doomed, $stale);
                if ($handled !== []) {
                    $redis->srem($tagKey, $handled);
                }

                if ($foreign === 0) {
                    $redis->del([$tagKey]);
                }
            }

            return $count;
        });
    }

    public function supportsNamespaceFlush(): bool
    {
        return true;
    }

    /**
     * Does the entry behind this raw key still carry the tag being flushed?
     * A key that is gone, undecodable or expired answers no — the member is
     * stale and gets pruned rather than obeyed.
     */
    private function stillCarries(ClientInterface $redis, string $keyStr, string $tag): bool
    {
        $raw = $redis->get($keyStr);
        if ($raw === null || $raw === '') {
            return false;
        }

        try {
            $entry = $this->serializer->decode($raw);
        } catch (\Throwable) {
            return false;
        }

        if ($entry->isExpiredAt(time())) {
            return false;
        }

        return in_array($tag, $entry->tags->values(), true);
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
