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

    /**
     * A named prefix ends with the boundary byte, which is what makes the root
     * key `users:x` and the key `x` in `users` different strings: reaching the
     * second needs a key containing NUL, and those are refused. Moving a marker
     * was not enough — review found two applications whose valid app, env and
     * key values reproduced the marker layout exactly.
     *
     * The ROOT is deliberately byte-identical to what it always was.
     */
    public function testAsPrefixWithNamespace(): void
    {
        $ns = $this->makeNamespace(namespace: 'users');
        self::assertSame(
            'semitexa:myapp:prod:tenant:default:users:' . CacheNamespace::KEY_BOUNDARY,
            $ns->asPrefix(),
        );
    }

    /**
     * The case review reproduced: two applications sharing a Redis prefix, one
     * of them named `ns` in environment `v2`, and a root key carrying the rest.
     * Both spellings produced one string.
     */
    public function testTwoApplicationsSharingAPrefixCannotCollide(): void
    {
        $named = new CacheNamespace('semitexa', 'tenant', 'default', CacheScope::Tenant, 'tenant:default', 'views');
        $root = new CacheNamespace('semitexa', 'ns', 'v2', CacheScope::Tenant, 'tenant:default', '');

        self::assertNotSame(
            $named->asPrefix() . 'item',
            $root->asPrefix() . 'tenant:default:views:item',
        );
    }

    /**
     * The tenant key is the one segment nothing sanitises, so it could align
     * with a namespace: tenant `t:views` against tenant `t` in namespace
     * `views`. Entries and TAG SETS both.
     */
    public function testAnUnsanitisedTenantCannotImpersonateANamespace(): void
    {
        $rootish = new CacheNamespace('semitexa', 'app', 'test', CacheScope::Tenant, 't:views', '');
        $named = new CacheNamespace('semitexa', 'app', 'test', CacheScope::Tenant, 't', 'views');

        self::assertNotSame($rootish->asPrefix() . 'item', $named->asPrefix() . 'item');
        self::assertNotSame($rootish->tagKey('x'), $named->tagKey('x'));
    }

    public function testLegacyPrefixStillNamesThePreMoveSpelling(): void
    {
        $ns = $this->makeNamespace(namespace: 'users');
        self::assertSame('semitexa:myapp:prod:tenant:default:users:', $ns->legacyAsPrefix());
        self::assertSame('', $this->makeNamespace()->legacyAsPrefix(), 'the root never moved');
    }

    /**
     * Clearing the root clears the tenant, named namespaces included — which is
     * what it already did, but only because the root prefix happened to be a
     * string prefix of every named one. Now it is stated.
     */
    public function testTheRootSweepStillCoversNamedNamespaces(): void
    {
        $root = $this->makeNamespace();
        $named = $this->makeNamespace(namespace: 'users');

        $covered = static function (array $prefixes, string $key): bool {
            foreach ($prefixes as $p) {
                if ($p !== '' && str_starts_with($key, $p)) {
                    return true;
                }
            }
            return false;
        };

        self::assertTrue($covered($root->sweepPrefixes(), $named->asPrefix() . 'item'));
        self::assertTrue($covered($root->sweepPrefixes(), $named->legacyAsPrefix() . 'item'));
        self::assertTrue($covered($root->sweepPrefixes(), $root->asPrefix() . 'item'));
    }

    /**
     * A third-party CacheStoreInterface that clears with asPrefix() alone —
     * the behaviour the two bundled stores had before any of this — must keep
     * clearing named namespaces when the ROOT is flushed. The first version of
     * this change moved named entries outside the root prefix and silently
     * broke that for every store nobody updated; review caught it.
     */
    public function testANamedPrefixStillSitsInsideTheRootPrefix(): void
    {
        $root = $this->makeNamespace();
        $named = $this->makeNamespace(namespace: 'users');

        self::assertStringStartsWith(
            $root->asPrefix(),
            $named->asPrefix(),
            'a store sweeping the root prefix alone would stop reaching this namespace',
        );
        self::assertStringStartsWith($root->asPrefix(), $named->asPrefix() . 'some-key');
    }

    /**
     * And the pre-move spelling is NOT in a named sweep: it and a root key
     * starting with the namespace's name are the same bytes, so sweeping it
     * would delete root entries it cannot tell apart — the defect itself.
     */
    public function testANamedSweepDoesNotIncludeTheAmbiguousLegacyPrefix(): void
    {
        $named = $this->makeNamespace(namespace: 'users');

        self::assertNotContains($named->legacyAsPrefix(), $named->sweepPrefixes());
        self::assertSame([$named->asPrefix()], $named->sweepPrefixes());
    }

    /** A named flush must not reach outside its own namespace. */
    public function testANamedSweepDoesNotReachTheRootOrASibling(): void
    {
        $named = $this->makeNamespace(namespace: 'users');
        $sibling = $this->makeNamespace(namespace: 'pages');
        $root = $this->makeNamespace();

        foreach ($named->sweepPrefixes() as $p) {
            if ($p === '') {
                continue;
            }
            self::assertStringStartsNotWith($p, $root->asPrefix() . 'item');
            self::assertStringStartsNotWith($p, $sibling->asPrefix() . 'item');
        }
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
            'semitexa:tag:v2:myapp:prod:tenant:default:users:' . CacheNamespace::KEY_BOUNDARY,
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
