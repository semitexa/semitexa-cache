<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Enum\CacheScope;
use Semitexa\Cache\Internal\CacheNamespace;
use Semitexa\Cache\Namespace\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Serialization\CacheValueSerializer;
use Semitexa\Cache\Service\CacheManager;
use Semitexa\Cache\Service\ScopedCacheManager;
use Semitexa\Cache\Store\ArrayCacheStore;
use Semitexa\Cache\Tag\ArrayTagIndex;

final class CacheManagerTest extends TestCase
{
    private function makeManager(bool $tagsEnabled = true, bool $allowForever = false): CacheManager
    {
        $config = new CacheConfig(
            driver: 'array',
            prefix: 'semitexa',
            app: 'test-app',
            env: 'test',
            defaultTtl: 300,
            allowForever: $allowForever,
            tagsEnabled: $tagsEnabled,
            redisHost: '127.0.0.1',
            redisPort: 6379,
            redisScheme: 'tcp',
            redisPassword: null,
        );
        $serializer = new CacheValueSerializer();
        $store = new ArrayCacheStore($serializer);
        $tagIndex = new ArrayTagIndex(
            deleteByString: static fn(string $k) => $store->deleteByString($k),
        );
        $resolver = new DefaultCacheNamespaceResolver($config);

        return new CacheManager(
            config: $config,
            store: $store,
            tagIndex: $tagIndex,
            namespaceResolver: $resolver,
        );
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $manager = $this->makeManager();
        self::assertNull($manager->get('missing'));
        self::assertSame('fallback', $manager->get('missing', 'fallback'));
    }

    public function testPutAndGetRoundTrip(): void
    {
        $manager = $this->makeManager();
        $manager->put('greeting', 'hello');
        self::assertSame('hello', $manager->get('greeting'));
    }

    public function testPutAndGetArrayValue(): void
    {
        $manager = $this->makeManager();
        $manager->put('data', ['a' => 1, 'b' => 2]);
        self::assertSame(['a' => 1, 'b' => 2], $manager->get('data'));
    }

    public function testForgetRemovesEntry(): void
    {
        $manager = $this->makeManager();
        $manager->put('temp', 'value');
        $manager->forget('temp');
        self::assertNull($manager->get('temp'));
    }

    public function testRememberCachesAndReturnsSameValue(): void
    {
        $manager = $this->makeManager();
        $callCount = 0;
        $result = $manager->remember('computed', function () use (&$callCount) {
            $callCount++;
            return 'expensive-result';
        });

        self::assertSame('expensive-result', $result);
        self::assertSame(1, $callCount);

        // Second call should not invoke the resolver
        $result2 = $manager->remember('computed', function () use (&$callCount) {
            $callCount++;
            return 'expensive-result';
        });

        self::assertSame('expensive-result', $result2);
        self::assertSame(1, $callCount);
    }

    public function testWithNamespaceReturnsScopedManager(): void
    {
        $manager = $this->makeManager();
        $scoped = $manager->withNamespace('users');
        self::assertInstanceOf(ScopedCacheManager::class, $scoped);
    }

    public function testWithNamespaceIsolatesKeys(): void
    {
        $manager = $this->makeManager();
        $usersCache = $manager->withNamespace('users');
        $ordersCache = $manager->withNamespace('orders');

        $usersCache->put('item-1', 'user-data');
        $ordersCache->put('item-1', 'order-data');

        self::assertSame('user-data', $usersCache->get('item-1'));
        self::assertSame('order-data', $ordersCache->get('item-1'));
        // Root namespace doesn't see it
        self::assertNull($manager->get('item-1'));
    }

    public function testWithTagsReturnsScopedManager(): void
    {
        $manager = $this->makeManager();
        $tagged = $manager->withTags('user', 'product');
        self::assertInstanceOf(ScopedCacheManager::class, $tagged);
    }

    public function testScopeReturnsScopedManager(): void
    {
        $manager = $this->makeManager();
        $scoped = $manager->scope(CacheScope::Global);
        self::assertInstanceOf(ScopedCacheManager::class, $scoped);
    }

    public function testFlushNamespaceRemovesAllKeysInNamespace(): void
    {
        $manager = $this->makeManager();
        $scoped = $manager->withNamespace('products');
        $scoped->put('p1', 'data1');
        $scoped->put('p2', 'data2');

        $manager->put('root-key', 'root-data');

        $count = $scoped->flushNamespace();

        self::assertSame(2, $count);
        self::assertNull($scoped->get('p1'));
        self::assertNull($scoped->get('p2'));
        self::assertSame('root-data', $manager->get('root-key'));
    }

    public function testFlushTagsInvalidatesTaggedEntries(): void
    {
        $manager = $this->makeManager();
        $manager->put('a', 'val-a', tags: ['user']);
        $manager->put('b', 'val-b', tags: ['user', 'product']);
        $manager->put('c', 'val-c', tags: ['product']);
        $manager->put('d', 'val-d');

        $manager->flushTags('user');

        self::assertNull($manager->get('a'));
        self::assertNull($manager->get('b'));
        self::assertNotNull($manager->get('c'));
        self::assertNotNull($manager->get('d'));
    }

    public function testNegativeTtlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL must not be negative');

        $this->makeManager()->put('key', 'val', ttlSeconds: -1);
    }

    public function testZeroTtlThrowsWhenForeverNotAllowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('TTL=0 (forever) is not allowed');

        $this->makeManager(allowForever: false)->put('key', 'val', ttlSeconds: 0);
    }

    public function testZeroTtlAllowedWhenForeverEnabled(): void
    {
        $manager = $this->makeManager(allowForever: true);
        $manager->put('forever-key', 'permanent', ttlSeconds: 0);
        self::assertSame('permanent', $manager->get('forever-key'));
    }

    public function testScopedManagerChaining(): void
    {
        $manager = $this->makeManager();
        $scoped = $manager->withNamespace('ns')->withTags('tag1')->scope(CacheScope::Tenant);
        self::assertInstanceOf(ScopedCacheManager::class, $scoped);

        $scoped->put('key', 'value');
        self::assertSame('value', $scoped->get('key'));
    }

    public function testGlobalScopeIsolatedFromTenantScope(): void
    {
        $manager = $this->makeManager();
        $tenantManager = $manager->scope(CacheScope::Tenant);
        $globalManager = $manager->scope(CacheScope::Global);

        $tenantManager->put('shared-key', 'tenant-data');
        $globalManager->put('shared-key', 'global-data');

        self::assertSame('tenant-data', $tenantManager->get('shared-key'));
        self::assertSame('global-data', $globalManager->get('shared-key'));
    }

    public function testTagsDisabledDoesNotFailOnFlushTags(): void
    {
        $manager = $this->makeManager(tagsEnabled: false);
        $manager->put('key', 'val', tags: ['tag1']);
        $count = $manager->flushTags('tag1');
        self::assertSame(0, $count);
        // Entry should still be accessible because tags are disabled
        self::assertSame('val', $manager->get('key'));
    }

    public function testScopedManagerWithNamespaceReturnsNewScopedManager(): void
    {
        $manager = $this->makeManager();
        $scoped1 = $manager->withNamespace('ns1');
        $scoped2 = $scoped1->withNamespace('ns2');

        self::assertNotSame($scoped1, $scoped2);
        self::assertInstanceOf(ScopedCacheManager::class, $scoped2);

        $scoped2->put('key', 'in-ns2');
        self::assertNull($scoped1->get('key'));
        self::assertSame('in-ns2', $scoped2->get('key'));
    }
}
