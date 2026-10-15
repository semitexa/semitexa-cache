<?php

declare(strict_types=1);

namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Connection\ConnectionException;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\RedisCacheStore;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\Cache\Domain\Model\CacheEntry;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Domain\Model\TagSet;

/**
 * An unreachable Redis is a cache miss, not a failed page; an invalidation
 * that cannot reach it still fails loudly. The client points at a closed port,
 * so the connection error is Predis's own, not a double.
 */
final class RedisCacheStoreOutageTest extends TestCase
{
    #[Test]
    public function get_is_a_miss_and_put_keeps_nothing_when_redis_is_unreachable(): void
    {
        $store = $this->unreachableStore();

        self::assertNull($store->get($this->key()));
        $store->put($this->key(), new CacheEntry('v', time(), 60, 'json', new TagSet([])));
        $this->addToAssertionCount(1); // put() returned instead of throwing
    }

    #[Test]
    public function forget_still_reports_the_outage(): void
    {
        $this->expectException(ConnectionException::class);

        $this->unreachableStore()->forget($this->key());
    }

    private function unreachableStore(): RedisCacheStore
    {
        $client = new Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2]);

        return new RedisCacheStore(new CacheValueSerializer(), $client);
    }

    private function key(): ResolvedCacheKey
    {
        return new ResolvedCacheKey(
            namespace: new CacheNamespace(
                prefix: 'semitexa',
                app: 'app',
                environment: 'test',
                scope: CacheScope::Tenant,
                tenantKey: 'tenant:default',
                namespace: '',
            ),
            key: 'k',
        );
    }
}
