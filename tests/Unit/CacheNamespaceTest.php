<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\Cache\Domain\Model\CacheNamespace;

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
        self::assertSame('semitexa:tag:v2:myapp:prod:tenant:default:', $ns->tagKeyPrefix());
    }

    /**
     * The tag key carries the namespace, so two namespaces of one tenant have
     * separate tag sets. It did not, and the flush filtered members by
     * asPrefix() instead — which cannot separate the root namespace from a
     * named one, because the root's prefix is a string prefix of every named
     * one and a cache key may contain a colon of its own.
     */
    public function testTagKeyPrefixSeparatesNamespaces(): void
    {
        self::assertSame(
            'semitexa:tag:v2:myapp:prod:tenant:default:users:',
            $this->makeNamespace(namespace: 'users')->tagKeyPrefix(),
        );
        self::assertNotSame(
            $this->makeNamespace()->tagKeyPrefix(),
            $this->makeNamespace(namespace: 'users')->tagKeyPrefix(),
        );
    }

    /**
     * And the tag space is not reachable from the entry space. With the marker
     * after the prefix, `withNamespace('views')->put('tag:v2:foo', ...)`
     * addressed exactly the tag set for `foo`: an untagged put overwrote the
     * set, and a tagged one left a string where the next SADD failed with
     * WRONGTYPE. A key is always appended after the whole prefix, so a marker
     * ahead of it cannot be produced by one.
     */
    public function testATagKeyCannotBeProducedByACallerKey(): void
    {
        $ns = $this->makeNamespace(namespace: 'views');

        self::assertStringStartsNotWith($ns->asPrefix(), $ns->tagKeyPrefix());
    }

    /** Tenants stay separated too; the namespace is added, nothing is replaced. */
    public function testTagKeyPrefixStillSeparatesTenants(): void
    {
        self::assertNotSame(
            $this->makeNamespace(tenantKey: 'tenant:a')->tagKeyPrefix(),
            $this->makeNamespace(tenantKey: 'tenant:b')->tagKeyPrefix(),
        );
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

    /**
     * A namespace may only contain `[A-Za-z0-9_-]`, but a TAG is whatever the
     * caller passed. So a root tag of `views:foo` built the same key as tag
     * `foo` in namespace `views`: two different sets in one place. Flushing the
     * namespaced tag pruned the root entry's membership as not carrying `foo`,
     * and the root flush of `views:foo` could then never reach it. Raised in
     * review of cache#19.
     */
    #[Test]
    public function a_tag_cannot_pose_as_a_namespace_boundary(): void
    {
        $root = new CacheNamespace('semitexa', 'app', 'test', CacheScope::Tenant, 'tenant', '');
        $views = new CacheNamespace('semitexa', 'app', 'test', CacheScope::Tenant, 'tenant', 'views');

        self::assertNotSame(
            $root->tagKey('views:foo'),
            $views->tagKey('foo'),
            'two different tag sets must not share one key',
        );
    }

    #[Test]
    public function an_ordinary_tag_is_still_readable_in_the_key(): void
    {
        $ns = new CacheNamespace('semitexa', 'app', 'test', CacheScope::Tenant, 'tenant', 'views');

        self::assertSame($ns->tagKeyPrefix() . 'article-7', $ns->tagKey('article-7'));
    }

    #[Test]
    public function a_tag_round_trips_through_the_encoding(): void
    {
        $ns = new CacheNamespace('semitexa', 'app', 'test', CacheScope::Tenant, 'tenant', '');

        foreach (['views:foo', 'a b', 'a/b', 'ключ'] as $tag) {
            self::assertSame(
                $tag,
                rawurldecode(substr($ns->tagKey($tag), strlen($ns->tagKeyPrefix()))),
                'the key has to name exactly one tag, and say which',
            );
        }
    }
}
