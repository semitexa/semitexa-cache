<?php

declare(strict_types=1);

namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\ArrayCacheStore;
use Semitexa\Cache\Application\Service\ArrayTagIndex;
use Semitexa\Cache\Application\Service\CacheManager;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Configuration\CacheConfig;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

/**
 * remember() must be single-flight: a Swoole worker runs many coroutines, and
 * a resolver yields on its I/O, so when a popular key misses the naive
 * get→resolve→put let EVERY concurrent coroutine run the expensive resolver.
 * The leader resolves; the rest wait and read its cached result — so a
 * concurrent burst of misses triggers exactly ONE resolver call.
 */
final class CacheRememberSingleFlightTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(Coroutine::class)) {
            self::markTestSkipped('Swoole extension is required.');
        }
    }

    #[Test]
    public function concurrent_misses_of_the_same_key_run_the_resolver_once(): void
    {
        $manager = $this->makeManager();
        $calls = 0;
        $results = [];

        Coroutine\run(function () use ($manager, &$calls, &$results): void {
            $wg = new WaitGroup();
            for ($i = 0; $i < 8; $i++) {
                $wg->add();
                Coroutine::create(function () use ($manager, &$calls, &$results, $wg): void {
                    $results[] = $manager->remember('hot-key', static function () use (&$calls): string {
                        $calls++;
                        Coroutine::sleep(0.02); // the resolver's I/O — a yield point
                        return 'computed';
                    });
                    $wg->done();
                });
            }
            $wg->wait();
        });

        self::assertSame(1, $calls, 'the resolver must run once for a concurrent burst of misses');
        self::assertCount(8, $results);
        self::assertSame(['computed'], array_values(array_unique($results)), 'every caller gets the one computed value');
    }

    #[Test]
    public function distinct_keys_each_resolve_independently(): void
    {
        $manager = $this->makeManager();
        $calls = 0;

        Coroutine\run(function () use ($manager, &$calls): void {
            $wg = new WaitGroup();
            foreach (['a', 'b', 'c'] as $key) {
                $wg->add();
                Coroutine::create(function () use ($manager, &$calls, $key, $wg): void {
                    $manager->remember($key, static function () use (&$calls, $key): string {
                        $calls++;
                        Coroutine::sleep(0.01);
                        return 'v-' . $key;
                    });
                    $wg->done();
                });
            }
            $wg->wait();
        });

        self::assertSame(3, $calls, 'single-flight coalesces per key, not across keys');
    }

    private function makeManager(): CacheManager
    {
        $config = new CacheConfig(
            driver: 'array',
            prefix: 'semitexa',
            app: 'test-app',
            env: 'test',
            defaultTtl: 300,
            allowForever: false,
            tagsEnabled: true,
            redisHost: '127.0.0.1',
            redisPort: 6379,
            redisScheme: 'tcp',
            redisPassword: null,
        );
        $serializer = new CacheValueSerializer();
        $store = new ArrayCacheStore($serializer);
        $tagIndex = new ArrayTagIndex(
            deleteByString: static fn (string $k) => $store->deleteByString($k),
        );

        return CacheManager::withDependencies(
            config: $config,
            store: $store,
            tagIndex: $tagIndex,
            namespaceResolver: new DefaultCacheNamespaceResolver($config),
        );
    }
}
