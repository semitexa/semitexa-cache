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
use Semitexa\Cache\Domain\Model\CacheEntry;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;

/**
 * take(): one caller gets the value, removed in the same step.
 *
 * REGRESSION (review ultimate#130). A one-time form token was read with get()
 * and removed with forget(), which says nothing about who removed it: two
 * workers holding the same valid token both read it before either forgot it,
 * and both ran the action. Here the second worker takes the key while the
 * first is between its read and its removal, as two workers on one Redis can.
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
    public function of_two_workers_taking_one_key_at_once_only_one_gets_it(): void
    {
        $store = new class (new ArrayCacheStore(new CacheValueSerializer())) implements CacheStoreInterface {
            /** @var (\Closure(): void)|null runs once, right after the first read: the other worker */
            public ?\Closure $between = null;

            public function __construct(private readonly ArrayCacheStore $inner) {}

            public function get(ResolvedCacheKey $key): ?CacheEntry
            {
                $entry = $this->inner->get($key);
                if ($this->between !== null) {
                    $other = $this->between;
                    $this->between = null;
                    $other();
                }

                return $entry;
            }

            public function put(ResolvedCacheKey $key, CacheEntry $entry): void { $this->inner->put($key, $entry); }
            public function forget(ResolvedCacheKey $key): bool { return $this->inner->forget($key); }
            public function clearNamespace(CacheNamespace $namespace): int { return $this->inner->clearNamespace($namespace); }
            public function supportsTags(): bool { return $this->inner->supportsTags(); }
        };
        $first = $this->manager($store);
        $second = $this->manager($store);
        $first->put('token', 'hash', 60);

        $other = null;
        $store->between = static function () use ($second, &$other): void {
            $other = $second->take('token');
        };
        $mine = $first->take('token');

        self::assertSame('hash', $other, 'the worker that removed it first has it');
        self::assertNull($mine, 'the one that read it too, but removed nothing, does not');
    }

    private function manager(CacheStoreInterface $store): CacheManager
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
        (new \ReflectionProperty(CacheManager::class, 'tagIndex'))->setValue($manager, $this->createStub(TagIndexInterface::class));

        return $manager;
    }
}
