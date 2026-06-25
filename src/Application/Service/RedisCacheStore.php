<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Service;

use Predis\ClientInterface;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Contract\CacheStoreInterface;
use Semitexa\Cache\Domain\Model\CacheEntry;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Core\Redis\RedisConnectionPool;

/**
 * Redis-backed cache store.
 *
 * Coroutine safety: under Swoole the worker enables SWOOLE_HOOK_ALL, which makes
 * each Predis TCP socket coroutine-EXCLUSIVE — two concurrent coroutines sharing
 * one connection crash with "Socket #N has already been bound to another
 * coroutine". So this store does NOT hold a single shared Predis client; it
 * borrows one from a {@see RedisConnectionPool} (Swoole\Coroutine\Channel of
 * exclusive connections) for the duration of each logical operation and returns
 * it after, exactly as the SSR async server does. Each concurrent coroutine gets
 * its own socket; excess coroutines block on the pool until one frees. Outside
 * Swoole (CLI/tests) the pool falls back to a single shared client.
 *
 * A pre-resolved client may still be injected (tests / single-connection use);
 * when present it is used directly and the pool is skipped.
 */
final class RedisCacheStore implements CacheStoreInterface
{
    private readonly ?ClientInterface $client;
    private readonly ?RedisConnectionPool $pool;

    public function __construct(
        private readonly CacheValueSerializer $serializer,
        ?ClientInterface $redis = null,
        ?CacheConfig $config = null,
    ) {
        if ($redis !== null) {
            $this->client = $redis;
            $this->pool = null;
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

    public function get(ResolvedCacheKey $key): ?CacheEntry
    {
        return $this->withConnection(function (ClientInterface $redis) use ($key): ?CacheEntry {
            $raw = $redis->get($key->asString());
            if ($raw === null || $raw === '') {
                return null;
            }

            try {
                $entry = $this->serializer->decode($raw);
            } catch (\Throwable) {
                $redis->del([$key->asString()]);
                return null;
            }

            if ($entry->isExpiredAt(time())) {
                $redis->del([$key->asString()]);
                return null;
            }

            return $entry;
        });
    }

    public function put(ResolvedCacheKey $key, CacheEntry $entry): void
    {
        $raw = $this->serializer->encode($entry);
        $this->withConnection(function (ClientInterface $redis) use ($key, $entry, $raw): void {
            if ($entry->ttlSeconds !== null && $entry->ttlSeconds > 0) {
                $redis->setex($key->asString(), $entry->ttlSeconds, $raw);
            } else {
                $redis->set($key->asString(), $raw);
            }
        });
    }

    public function forget(ResolvedCacheKey $key): bool
    {
        return $this->withConnection(static function (ClientInterface $redis) use ($key): bool {
            $result = $redis->del([$key->asString()]);
            return (int) $result > 0;
        });
    }

    public function clearNamespace(CacheNamespace $namespace): int
    {
        return $this->withConnection(static function (ClientInterface $redis) use ($namespace): int {
            $pattern = $namespace->asPrefix() . '*';
            $count = 0;
            $cursor = '0';

            do {
                [$cursor, $keys] = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
                if (!empty($keys)) {
                    $redis->del($keys);
                    $count += count($keys);
                }
            } while ($cursor !== '0');

            return $count;
        });
    }

    public function supportsTags(): bool
    {
        return true;
    }

    /**
     * Run $fn with an exclusive Redis connection: a pooled one borrowed for the
     * call (coroutine-safe under Swoole), or the injected single client.
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
