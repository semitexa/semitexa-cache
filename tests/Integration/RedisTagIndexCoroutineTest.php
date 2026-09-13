<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\CacheManager;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Application\Service\RedisCacheStore;
use Semitexa\Cache\Application\Service\RedisTagIndex;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Core\Redis\RedisConnectionPool;

/**
 * A Predis socket is coroutine-exclusive once Swoole hooks the runtime: two
 * coroutines reading the same one kill the worker with "Socket#N has already
 * been bound to another coroutine#M" and exit 255. The store has always
 * borrowed from a pool for that reason; the tag index used to hold one shared
 * client, so any concurrent tagged put or flush hit the trap.
 *
 * This test is the standing guard. It needs a real Redis and a real Swoole,
 * and skips where either is missing rather than pretending to have run.
 *
 * Manual harness with both wirings side by side: var/docs/cache-tag-socket-repro.php
 */
final class RedisTagIndexCoroutineTest extends TestCase
{
    private const CONCURRENCY = 16;

    private static function redisHost(): ?string
    {
        foreach ([getenv('REDIS_HOST') ?: null, 'redis', '127.0.0.1'] as $host) {
            if ($host === null) {
                continue;
            }
            $socket = @fsockopen($host, 6379, $errno, $errstr, 0.5);
            if ($socket !== false) {
                fclose($socket);
                return $host;
            }
        }
        return null;
    }

    public function testConcurrentTaggedWritesDoNotShareASocket(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('needs the swoole extension: the failure only exists in a hooked runtime');
        }
        $host = self::redisHost();
        if ($host === null) {
            self::markTestSkipped('needs a reachable Redis on port 6379');
        }

        $config = new CacheConfig(
            driver: 'redis',
            prefix: 'semitexa-test-' . bin2hex(random_bytes(4)),
            app: 'coroutine-test',
            env: 'test',
            defaultTtl: 30,
            allowForever: false,
            tagsEnabled: true,
            redisHost: $host,
            redisPort: 6379,
            redisScheme: 'tcp',
            redisPassword: null,
        );

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $errors = [];
        $done = 0;

        \Co\run(function () use ($config, &$errors, &$done): void {
            $serializer = new CacheValueSerializer();
            $pool = new RedisConnectionPool($config->redisPoolSize, [
                'scheme' => $config->redisScheme,
                'host' => $config->redisHost,
                'port' => $config->redisPort,
            ]);

            $manager = CacheManager::withDependencies(
                config: $config,
                store: new RedisCacheStore($serializer, config: $config, pool: $pool),
                tagIndex: new RedisTagIndex(serializer: $serializer, config: $config, pool: $pool),
                namespaceResolver: new DefaultCacheNamespaceResolver($config),
            );

            for ($i = 0; $i < self::CONCURRENCY; $i++) {
                go(function () use ($manager, $i, &$errors, &$done): void {
                    try {
                        for ($n = 0; $n < 5; $n++) {
                            $manager->put("key-{$i}-{$n}", "value-{$i}-{$n}", tags: ['shared', "own-{$i}"]);
                            $manager->get("key-{$i}-{$n}");
                        }
                        $manager->flushTags("own-{$i}");
                        $done++;
                    } catch (\Throwable $e) {
                        $errors[] = get_class($e) . ': ' . $e->getMessage();
                    }
                });
            }
        });

        self::assertSame([], $errors, 'concurrent tagged cache traffic must not collide on a socket');
        self::assertSame(self::CONCURRENCY, $done);
    }
}
