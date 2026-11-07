<?php

declare(strict_types=1);

namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\ArrayCacheStore;
use Semitexa\Cache\Application\Service\CacheManager;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Contract\CacheNamespaceResolverInterface;
use Semitexa\Cache\Domain\Contract\CacheStoreInterface;
use Semitexa\Cache\Domain\Contract\TagIndexInterface;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\Cache\Domain\Model\CacheNamespace;

/**
 * take(): one caller gets the value, removed in the same step.
 *
 * REGRESSION (review ultimate#130). A one-time form token was read with get()
 * and removed with forget(): two workers holding the same valid token both read
 * it before either forgot it, and both ran the action. A read followed by a
 * delete (review cache#30) still let a value written between them be removed
 * by a take that returned the older one: the store takes it in one step now,
 * a script on Redis (RedisTakeTest).
 */
final class CacheTakeTest extends TestCase
{
    #[Test]
    public function a_value_is_taken_once_and_is_gone(): void
    {
        $cache = $this->manager(new ArrayCacheStore(new CacheValueSerializer()));
        $cache->put('token', 'hash', 60);

        self::assertSame('hash', $cache->take('token'));
        self::assertNull($cache->take('token'));
        self::assertNull($cache->get('token'));
        self::assertNull($cache->take('never-put'));
    }

    #[Test]
    public function of_two_workers_on_one_store_only_the_first_to_take_gets_it(): void
    {
        $store = new ArrayCacheStore(new CacheValueSerializer());
        $first = $this->manager($store);
        $second = $this->manager($store);
        $first->put('token', 'hash', 60);

        self::assertSame('hash', $second->take('token'));
        self::assertNull($first->take('token'));
    }

    #[Test]
    public function a_value_written_after_a_take_survives_it(): void
    {
        $cache = $this->manager(new ArrayCacheStore(new CacheValueSerializer()));
        $cache->put('key', 'old', 60);
        self::assertSame('old', $cache->take('key'));
        $cache->put('key', 'new', 60);

        self::assertSame('new', $cache->get('key'));
    }

    #[Test]
    public function a_scoped_take_takes_its_own_namespace_only(): void
    {
        $cache = $this->manager(new ArrayCacheStore(new CacheValueSerializer()));
        $cache->withNamespace('a')->put('key', 'in a', 60);
        $cache->withNamespace('b')->put('key', 'in b', 60);

        self::assertSame('in a', $cache->withNamespace('a')->take('key'));
        self::assertNull($cache->withNamespace('a')->get('key'));
        self::assertSame('in b', $cache->withNamespace('b')->get('key'), 'the sibling stays');
    }

    #[Test]
    public function a_tagged_value_leaves_its_tags_and_a_failing_cleanup_keeps_the_value(): void
    {
        $tags = $this->createMock(TagIndexInterface::class);
        $tags->expects(self::once())->method('detach');
        $cache = $this->manager(new ArrayCacheStore(new CacheValueSerializer()), $tags);
        $cache->put('token', 'hash', 60, ['forms']);
        self::assertSame('hash', $cache->take('token'));

        // REGRESSION (review cache#30): the entry was gone and the cleanup threw, so a
        // value no retry could take again was lost to its caller.
        $failing = $this->createStub(TagIndexInterface::class);
        $failing->method('detach')->willThrowException(new \RuntimeException('redis is away'));
        $cache = $this->manager(new ArrayCacheStore(new CacheValueSerializer()), $failing);
        $cache->put('token', 'hash', 60, ['forms']);
        self::assertSame('hash', $cache->take('token'));
    }

    private function manager(CacheStoreInterface $store, ?TagIndexInterface $tags = null): CacheManager
    {
        $manager = new CacheManager();
        $resolver = new class () implements CacheNamespaceResolverInterface {
            public function resolve(string $namespace, CacheScope $scope = CacheScope::Tenant): CacheNamespace
            {
                return new CacheNamespace('semitexa', 'test-app', 'test', $scope, 'tenant:t', $namespace);
            }
        };
        (new \ReflectionProperty(CacheManager::class, 'boundNamespaceResolver'))->setValue($manager, $resolver);
        (new \ReflectionProperty(CacheManager::class, 'config'))->setValue($manager, new CacheConfig(
            driver: 'array', prefix: 'semitexa', app: 'test-app', env: 'test', defaultTtl: 300, allowForever: false,
            tagsEnabled: true, redisHost: '127.0.0.1', redisPort: 6379, redisScheme: 'tcp', redisPassword: null,
        ));
        (new \ReflectionProperty(CacheManager::class, 'store'))->setValue($manager, $store);
        (new \ReflectionProperty(CacheManager::class, 'tagIndex'))->setValue($manager, $tags ?? $this->createStub(TagIndexInterface::class));

        return $manager;
    }
}
