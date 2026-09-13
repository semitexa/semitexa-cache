<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\ArrayCacheStore;
use Semitexa\Cache\Application\Service\CacheManager;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Application\Service\NullTagIndex;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;

/**
 * A namespace and a key are both spelled into one string, and until now the
 * string could not say which was which.
 *
 * `asPrefix()` for namespace `views` ends `…:tenant:views:`, and the key is
 * appended to it — so the root key `views:item` and the key `item` inside
 * `views` produced the SAME string. Two different entries, one place: each
 * silently overwrote the other, whatever their tags.
 *
 * Found sideways: a test written to check TAG isolation between the root and a
 * named namespace failed for the wrong reason — the value was not being
 * invalidated, it had never been two values.
 */
final class CacheKeyCollisionTest extends TestCase
{
    private function ns(string $namespace = ''): CacheNamespace
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

    private function key(string $namespace, string $key): string
    {
        return (new ResolvedCacheKey($this->ns($namespace), $key))->asString();
    }

    #[Test]
    public function a_root_key_cannot_address_an_entry_inside_a_namespace(): void
    {
        self::assertNotSame(
            $this->key('', 'views:item'),
            $this->key('views', 'item'),
            'two different entries must not share one storage key',
        );
    }

    /** The same collision one level deeper: the key carries the rest of the path. */
    #[Test]
    public function nested_colons_in_a_root_key_cannot_reach_a_namespace_either(): void
    {
        self::assertNotSame(
            $this->key('', 'views:list:page-2'),
            $this->key('views', 'list:page-2'),
        );
    }

    /** And from the other side: a key inside a namespace must not reach the root. */
    #[Test]
    public function a_namespaced_key_cannot_address_a_root_entry(): void
    {
        self::assertNotSame($this->key('reports', 'q1'), $this->key('', 'reports:q1'));
    }

    #[Test]
    public function two_namespaces_stay_apart(): void
    {
        self::assertNotSame($this->key('views', 'item'), $this->key('pages', 'item'));
    }

    /**
     * Keys with colons are ordinary and must keep working — `settings:app` is
     * the shape half this project's caches use.
     */
    #[Test]
    public function an_ordinary_colon_key_still_round_trips_to_one_stable_string(): void
    {
        self::assertSame($this->key('', 'settings:app'), $this->key('', 'settings:app'));
        self::assertNotSame($this->key('', 'settings:app'), $this->key('', 'settings:web'));
    }

    /**
     * A namespace may only be `[A-Za-z0-9_-]+`, so the interesting half is the
     * KEY: it carries whatever the caller passed, Unicode and spaces included,
     * and none of it may become a route into another namespace.
     */
    #[Test]
    public function unicode_and_spaces_in_a_key_are_not_a_collision_route(): void
    {
        self::assertNotSame($this->key('', 'reports:звіт q1'), $this->key('reports', 'звіт q1'));
        self::assertNotSame($this->key('', 'a b:c'), $this->key('', 'a b:d'));
    }

    #[Test]
    public function the_empty_namespace_is_the_root_and_stays_stable(): void
    {
        self::assertSame($this->key('', 'item'), $this->key('', 'item'));
    }

    private function manager(): CacheManager
    {
        $config = new CacheConfig(
            driver: 'array',
            prefix: 'semitexa',
            app: 'app',
            env: 'test',
            defaultTtl: 300,
            allowForever: true,
            tagsEnabled: false,
            redisHost: '127.0.0.1',
            redisPort: 6379,
            redisScheme: 'tcp',
            redisPassword: null,
        );

        return CacheManager::withDependencies(
            config: $config,
            store: new ArrayCacheStore(new CacheValueSerializer()),
            tagIndex: new NullTagIndex(),
            namespaceResolver: new DefaultCacheNamespaceResolver($config),
        );
    }

    /**
     * The spelling is the mechanism; this is the behaviour it exists for. Two
     * callers who never heard of each other wrote to one entry.
     */
    #[Test]
    public function two_entries_that_used_to_overwrite_each_other_now_both_survive(): void
    {
        $cache = $this->manager();

        $cache->put('views:item', 'written by the root');
        $cache->withNamespace('views')->put('item', 'written inside views');

        self::assertSame('written by the root', $cache->get('views:item'));
        self::assertSame('written inside views', $cache->withNamespace('views')->get('item'));
    }

    #[Test]
    public function forgetting_one_of_them_leaves_the_other_alone(): void
    {
        $cache = $this->manager();
        $cache->put('views:item', 'root');
        $cache->withNamespace('views')->put('item', 'named');

        $cache->forget('views:item');

        self::assertNull($cache->get('views:item'));
        self::assertSame('named', $cache->withNamespace('views')->get('item'), 'a neighbour was deleted');
    }

    /** Clearing the root still clears the tenant — named namespaces included. */
    #[Test]
    public function clearing_the_root_still_reaches_a_named_namespace(): void
    {
        $cache = $this->manager();
        $cache->put('loose', 'root');
        $cache->withNamespace('views')->put('item', 'named');

        $cache->flushNamespace();

        self::assertNull($cache->get('loose'));
        self::assertNull($cache->withNamespace('views')->get('item'));
    }

    /** Clearing a named namespace must not reach the root or a sibling. */
    #[Test]
    public function clearing_a_named_namespace_leaves_the_root_and_siblings(): void
    {
        $cache = $this->manager();
        $cache->put('views:item', 'root');
        $cache->withNamespace('views')->put('item', 'named');
        $cache->withNamespace('pages')->put('item', 'sibling');

        $cache->withNamespace('views')->flushNamespace();

        self::assertNull($cache->withNamespace('views')->get('item'));
        self::assertSame('root', $cache->get('views:item'));
        self::assertSame('sibling', $cache->withNamespace('pages')->get('item'));
    }
}
