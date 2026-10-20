<?php

declare(strict_types=1);

namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\ArrayCacheStore;
use Semitexa\Cache\Application\Service\CacheManager;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Contract\CacheNamespaceResolverInterface;
use Semitexa\Cache\Domain\Contract\ExternalTagIndexInterface;
use Semitexa\Cache\Domain\Contract\TagIndexInterface;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Core\Redis\RedisUnavailableException;
use Semitexa\Core\Tenant\DefaultTenantContextStore;
use Semitexa\Core\Tenant\Layer\TenantLayerInterface;
use Semitexa\Core\Tenant\Layer\TenantLayerValueInterface;
use Semitexa\Core\Tenant\TenantContextInterface;

/** Found while writing the cache guide (2026-10-21): each was a promise the code did not keep. */
final class CacheReviewFixesTest extends TestCase
{
    #[Test]
    public function a_tagged_entry_whose_tag_index_cannot_be_written_is_not_kept_and_the_call_does_not_fail(): void
    {
        $store = new ArrayCacheStore(new CacheValueSerializer());
        $down = $this->createStub(TagIndexInterface::class);
        $down->method('attach')->willThrowException(new RedisUnavailableException('redis is away'));
        $manager = CacheManager::withDependencies(self::config(), $store, $down, new DefaultCacheNamespaceResolver(self::config()));

        $manager->put('report', 'v1', tags: ['reports']);

        self::assertNull($manager->get('report'), 'no tag knows it, so no flush could ever clear it');
    }

    #[Test]
    public function an_index_that_keeps_its_own_lifetimes_is_handled_the_same_way(): void
    {
        $store = new ArrayCacheStore(new CacheValueSerializer());
        $down = $this->createStub(ExternalTagIndexInterface::class);
        $down->method('attachWithLifetime')->willThrowException(new RedisUnavailableException('redis is away'));
        $manager = CacheManager::withDependencies(self::config(), $store, $down, new DefaultCacheNamespaceResolver(self::config()));

        $manager->put('report', 'v1', tags: ['reports']);

        self::assertNull($manager->get('report'));
    }

    #[Test]
    public function a_bug_in_a_tag_index_is_not_mistaken_for_an_outage(): void
    {
        $broken = $this->createStub(TagIndexInterface::class);
        $broken->method('attach')->willThrowException(new \LogicException('index bug'));
        $manager = CacheManager::withDependencies(self::config(), new ArrayCacheStore(new CacheValueSerializer()), $broken, new DefaultCacheNamespaceResolver(self::config()));

        $this->expectException(\LogicException::class);
        $manager->put('report', 'v1', tags: ['reports']);
    }

    #[Test]
    public function the_global_scope_is_not_the_tenant_called_global(): void
    {
        $global = (new DefaultCacheNamespaceResolver(self::config()))->resolve('ns', CacheScope::Global);

        self::assertSame('scope:global', $global->tenantKey);
        self::assertNotSame('tenant:global', $global->tenantKey, 'a tenant whose id is "global" shared the global keys');
    }

    #[Test]
    public function the_tenant_comes_from_the_core_tenant_context_store(): void
    {
        $store = new DefaultTenantContextStore();
        $resolver = new DefaultCacheNamespaceResolver(self::config(), $store);
        self::assertSame('tenant:default', $resolver->resolve('ns')->tenantKey);

        $store->set(self::tenant('global'));
        try {
            self::assertSame('tenant:global', $resolver->resolve('ns')->tenantKey);
            self::assertSame('scope:global', $resolver->resolve('ns', CacheScope::Global)->tenantKey);
        } finally {
            $store->clear();
        }
    }

    #[Test]
    public function a_bound_namespace_resolver_is_used(): void
    {
        $bound = new class () implements CacheNamespaceResolverInterface {
            public function resolve(string $namespace, CacheScope $scope = CacheScope::Tenant): CacheNamespace
            {
                return new CacheNamespace('semitexa', 'test-app', 'test', $scope, 'tenant:custom', $namespace);
            }
        };
        $manager = new CacheManager();
        (new \ReflectionProperty(CacheManager::class, 'boundNamespaceResolver'))->setValue($manager, $bound);
        (new \ReflectionProperty(CacheManager::class, 'config'))->setValue($manager, self::config());
        (new \ReflectionProperty(CacheManager::class, 'store'))->setValue($manager, new ArrayCacheStore(new CacheValueSerializer()));
        (new \ReflectionProperty(CacheManager::class, 'tagIndex'))->setValue($manager, $this->createStub(TagIndexInterface::class));

        (new \ReflectionMethod(CacheManager::class, 'boot'))->invoke($manager);

        self::assertSame($bound, (new \ReflectionProperty(CacheManager::class, 'namespaceResolver'))->getValue($manager));
    }

    private static function tenant(string $id): TenantContextInterface
    {
        return new class ($id) implements TenantContextInterface {
            public function __construct(private readonly string $id) {}

            public function getTenantId(): string
            {
                return $this->id;
            }

            public function getLayer(TenantLayerInterface $layer): ?TenantLayerValueInterface
            {
                return null;
            }

            public function hasLayer(TenantLayerInterface $layer): bool
            {
                return false;
            }
        };
    }

    private static function config(): CacheConfig
    {
        return new CacheConfig(
            driver: 'array', prefix: 'semitexa', app: 'test-app', env: 'test', defaultTtl: 300, allowForever: false,
            tagsEnabled: true, redisHost: '127.0.0.1', redisPort: 6379, redisScheme: 'tcp', redisPassword: null,
        );
    }
}
