<?php
declare(strict_types=1);
namespace Semitexa\Cache\Store;

use Predis\ClientInterface;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Contract\CacheStoreInterface;
use Semitexa\Cache\Internal\CacheEntry;
use Semitexa\Cache\Internal\CacheNamespace;
use Semitexa\Cache\Internal\ResolvedCacheKey;
use Semitexa\Cache\Serialization\CacheValueSerializer;

final class RedisCacheStore implements CacheStoreInterface
{
    private readonly ClientInterface $redis;

    public function __construct(
        private readonly CacheValueSerializer $serializer,
        ?ClientInterface $redis = null,
        ?CacheConfig $config = null,
    ) {
        $this->redis = $redis ?? self::createClient($config ?? CacheConfig::fromEnvironment());
    }

    public function get(ResolvedCacheKey $key): ?CacheEntry
    {
        $raw = $this->redis->get($key->asString());
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $entry = $this->serializer->decode($raw);
        } catch (\Throwable) {
            $this->redis->del([$key->asString()]);
            return null;
        }

        if ($entry->isExpiredAt(time())) {
            $this->redis->del([$key->asString()]);
            return null;
        }

        return $entry;
    }

    public function put(ResolvedCacheKey $key, CacheEntry $entry): void
    {
        $raw = $this->serializer->encode($entry);
        if ($entry->ttlSeconds !== null && $entry->ttlSeconds > 0) {
            $this->redis->setex($key->asString(), $entry->ttlSeconds, $raw);
        } else {
            $this->redis->set($key->asString(), $raw);
        }
    }

    public function forget(ResolvedCacheKey $key): bool
    {
        $result = $this->redis->del([$key->asString()]);
        return (int) $result > 0;
    }

    public function clearNamespace(CacheNamespace $namespace): int
    {
        $pattern = $namespace->asPrefix() . '*';
        $count = 0;
        $cursor = '0';

        do {
            [$cursor, $keys] = $this->redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
            if (!empty($keys)) {
                $this->redis->del($keys);
                $count += count($keys);
            }
        } while ($cursor !== '0');

        return $count;
    }

    public function supportsTags(): bool
    {
        return true;
    }

    private static function createClient(CacheConfig $config): ClientInterface
    {
        $params = [
            'scheme' => $config->redisScheme,
            'host' => $config->redisHost,
            'port' => $config->redisPort,
        ];
        if ($config->redisPassword !== null) {
            $params['password'] = $config->redisPassword;
        }
        return new \Predis\Client($params);
    }
}
