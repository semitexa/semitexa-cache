<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Enum\CacheScope;
use Semitexa\Cache\Internal\CacheNamespace;
use Semitexa\Cache\Internal\ResolvedCacheKey;

final class ResolvedCacheKeyTest extends TestCase
{
    private function makeNamespace(string $namespace = ''): CacheNamespace
    {
        return new CacheNamespace(
            prefix: 'semitexa',
            app: 'app',
            environment: 'test',
            scope: CacheScope::Tenant,
            tenantKey: 'tenant:default',
            namespace: $namespace,
        );
    }

    public function testAsStringCombinesPrefixAndKey(): void
    {
        $resolved = new ResolvedCacheKey(
            namespace: $this->makeNamespace(),
            key: 'my-key',
        );

        self::assertSame('semitexa:app:test:tenant:default:my-key', $resolved->asString());
    }

    public function testAsStringWithNamespace(): void
    {
        $resolved = new ResolvedCacheKey(
            namespace: $this->makeNamespace('users'),
            key: 'user-42',
        );

        self::assertSame('semitexa:app:test:tenant:default:users:user-42', $resolved->asString());
    }

    public function testEmptyKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache key must not be empty.');

        new ResolvedCacheKey(
            namespace: $this->makeNamespace(),
            key: '',
        );
    }

    public function testKeyWithSpecialCharacters(): void
    {
        $resolved = new ResolvedCacheKey(
            namespace: $this->makeNamespace(),
            key: 'user:42:profile',
        );

        self::assertSame('semitexa:app:test:tenant:default:user:42:profile', $resolved->asString());
    }
}
