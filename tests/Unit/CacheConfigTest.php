<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Configuration\CacheConfig;

final class CacheConfigTest extends TestCase
{
    public function testConstructorAssignsAllProperties(): void
    {
        $config = new CacheConfig(
            driver: 'redis',
            prefix: 'semitexa',
            app: 'myapp',
            env: 'prod',
            defaultTtl: 600,
            allowForever: false,
            tagsEnabled: true,
            redisHost: '127.0.0.1',
            redisPort: 6379,
            redisScheme: 'tcp',
            redisPassword: null,
        );

        self::assertSame('redis', $config->driver);
        self::assertSame('semitexa', $config->prefix);
        self::assertSame('myapp', $config->app);
        self::assertSame('prod', $config->env);
        self::assertSame(600, $config->defaultTtl);
        self::assertFalse($config->allowForever);
        self::assertTrue($config->tagsEnabled);
        self::assertSame('127.0.0.1', $config->redisHost);
        self::assertSame(6379, $config->redisPort);
        self::assertSame('tcp', $config->redisScheme);
        self::assertNull($config->redisPassword);
    }

    public function testConstructorAcceptsRedisPassword(): void
    {
        $config = new CacheConfig(
            driver: 'array',
            prefix: 'semitexa',
            app: 'app',
            env: 'test',
            defaultTtl: 300,
            allowForever: false,
            tagsEnabled: true,
            redisHost: 'localhost',
            redisPort: 6379,
            redisScheme: 'tcp',
            redisPassword: 'secret',
        );

        self::assertSame('secret', $config->redisPassword);
    }

    public function testArrayDriverConfig(): void
    {
        $config = new CacheConfig(
            driver: 'array',
            prefix: 'cache',
            app: 'test-app',
            env: 'test',
            defaultTtl: 60,
            allowForever: true,
            tagsEnabled: false,
            redisHost: '127.0.0.1',
            redisPort: 6379,
            redisScheme: 'tcp',
            redisPassword: null,
        );

        self::assertSame('array', $config->driver);
        self::assertTrue($config->allowForever);
        self::assertFalse($config->tagsEnabled);
    }
}
