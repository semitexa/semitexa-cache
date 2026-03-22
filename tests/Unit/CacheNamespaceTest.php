<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Enum\CacheScope;
use Semitexa\Cache\Internal\CacheNamespace;

final class CacheNamespaceTest extends TestCase
{
    private function makeNamespace(
        string $namespace = '',
        CacheScope $scope = CacheScope::Tenant,
        string $tenantKey = 'tenant:default',
    ): CacheNamespace {
        return new CacheNamespace(
            prefix: 'semitexa',
            app: 'myapp',
            environment: 'prod',
            scope: $scope,
            tenantKey: $tenantKey,
            namespace: $namespace,
        );
    }

    public function testAsPrefixWithoutNamespace(): void
    {
        $ns = $this->makeNamespace();
        self::assertSame('semitexa:myapp:prod:tenant:default:', $ns->asPrefix());
    }

    public function testAsPrefixWithNamespace(): void
    {
        $ns = $this->makeNamespace(namespace: 'users');
        self::assertSame('semitexa:myapp:prod:tenant:default:users:', $ns->asPrefix());
    }

    public function testTagKeyPrefix(): void
    {
        $ns = $this->makeNamespace();
        self::assertSame('semitexa:myapp:prod:tenant:default:tag:', $ns->tagKeyPrefix());
    }

    public function testAsPrefixWithGlobalScope(): void
    {
        $ns = $this->makeNamespace(scope: CacheScope::Global, tenantKey: 'tenant:global');
        self::assertSame('semitexa:myapp:prod:tenant:global:', $ns->asPrefix());
    }

    public function testAsPrefixSlugifiesAppAndEnv(): void
    {
        $ns = new CacheNamespace(
            prefix: 'semitexa',
            app: 'My App Name',
            environment: 'staging_01',
            scope: CacheScope::Tenant,
            tenantKey: 'tenant:abc123',
            namespace: '',
        );
        self::assertSame('semitexa:my-app-name:staging_01:tenant:abc123:', $ns->asPrefix());
    }

    public function testInvalidNamespaceCharactersThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("contains invalid characters");

        new CacheNamespace(
            prefix: 'semitexa',
            app: 'app',
            environment: 'prod',
            scope: CacheScope::Tenant,
            tenantKey: 'tenant:default',
            namespace: 'bad namespace!',
        );
    }

    public function testValidNamespaceWithDashAndUnderscore(): void
    {
        $ns = new CacheNamespace(
            prefix: 'semitexa',
            app: 'app',
            environment: 'prod',
            scope: CacheScope::Tenant,
            tenantKey: 'tenant:default',
            namespace: 'my-module_v2',
        );
        self::assertStringContainsString('my-module_v2', $ns->asPrefix());
    }
}
