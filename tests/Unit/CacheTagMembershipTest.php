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

/**
 * Tag membership must describe the entry as it is NOW, not as it was at some
 * earlier put. The index is a list of CANDIDATES: flushing a tag may only
 * delete an entry that still carries that tag, and may only touch entries of
 * the namespace it was asked to flush.
 */
final class CacheTagMembershipTest extends TestCase
{
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
        $store = new ArrayCacheStore(new CacheValueSerializer());
        $tagIndex = new ArrayTagIndex(
            deleteByString: static fn(string $k) => $store->deleteByString($k),
            tagsOfKey: static fn(string $k) => $store->getByString($k)?->tags,
        );

        return CacheManager::withDependencies(
            config: $config,
            store: $store,
            tagIndex: $tagIndex,
            namespaceResolver: new DefaultCacheNamespaceResolver($config),
        );
    }

    public function testFlushingAnOldTagDoesNotDeleteTheRewrittenValue(): void
    {
        $manager = $this->makeManager();
        $manager->put('item', 'old', tags: ['old-tag']);
        $manager->put('item', 'new', tags: ['new-tag']);

        $removed = $manager->flushTags('old-tag');

        self::assertSame('new', $manager->get('item'), 'the rewritten value must survive a flush of the tag it no longer carries');
        self::assertSame(0, $removed, 'nothing carried old-tag any more, so nothing was removed');
    }

    public function testFlushingTheCurrentTagStillDeletesTheValue(): void
    {
        $manager = $this->makeManager();
        $manager->put('item', 'old', tags: ['old-tag']);
        $manager->put('item', 'new', tags: ['new-tag']);

        $removed = $manager->flushTags('new-tag');

        self::assertNull($manager->get('item'));
        self::assertSame(1, $removed);
    }

    public function testRewritingAnEntryWithoutTagsDetachesItFromTheOldTag(): void
    {
        $manager = $this->makeManager();
        $manager->put('item', 'old', tags: ['old-tag']);
        $manager->put('item', 'untagged');

        self::assertSame(0, $manager->flushTags('old-tag'));
        self::assertSame('untagged', $manager->get('item'));
    }

    public function testRepeatedPutDoesNotMultiplyMembership(): void
    {
        $manager = $this->makeManager();
        $manager->put('item', 'v1', tags: ['tag']);
        $manager->put('item', 'v2', tags: ['tag']);
        $manager->put('item', 'v3', tags: ['tag']);

        self::assertSame(1, $manager->flushTags('tag'), 'one key was removed, so the count is one');
        self::assertNull($manager->get('item'));
    }

    public function testFlushCountsOnlyWhatItActuallyRemoved(): void
    {
        $manager = $this->makeManager();
        $manager->put('a', 'val-a', tags: ['shared']);
        $manager->put('b', 'val-b', tags: ['shared']);
        $manager->forget('b');

        self::assertSame(1, $manager->flushTags('shared'), 'b was already gone; only a was removed');
    }

    public function testFlushingOneNamespaceLeavesTheOtherFlushable(): void
    {
        $manager = $this->makeManager();
        $a = $manager->withNamespace('nsa');
        $b = $manager->withNamespace('nsb');

        $a->put('item', 'A', tags: ['shared']);
        $b->put('item', 'B', tags: ['shared']);

        self::assertSame(1, $a->flushTags('shared'));
        self::assertNull($a->get('item'));
        self::assertSame('B', $b->get('item'), 'flushing namespace a must not touch namespace b');

        self::assertSame(1, $b->flushTags('shared'), 'namespace b must still be flushable by the same tag');
        self::assertNull($b->get('item'));
    }

    public function testAnEntryKeepsEveryTagItWasWrittenWith(): void
    {
        $manager = $this->makeManager();
        $manager->put('item', 'v', tags: ['one', 'two']);

        self::assertSame(1, $manager->flushTags('two'));
        self::assertNull($manager->get('item'));
    }

    /**
     * The root namespace is a string PREFIX of every named one, and a cache key
     * may itself contain a colon — so no amount of prefix matching can tell
     * "root key" from "named-namespace key". This is why the tag key carries
     * the namespace rather than the flush filtering by it.
     *
     * MEASURED before the fix: a root flush of a shared tag removed 2 entries
     * and left the named namespace's value NULL.
     */
    #[Test]
    public function a_root_flush_does_not_reach_into_a_named_namespace(): void
    {
        $manager = $this->makeManager();
        $manager->put('item', 'ROOT', tags: ['shared']);
        $manager->withNamespace('views')->put('item', 'VIEWS', tags: ['shared']);

        self::assertSame(1, $manager->flushTags('shared'), 'only the root entry carried the tag in this namespace');
        self::assertSame('VIEWS', $manager->withNamespace('views')->get('item'), 'a named namespace is not the root namespace');
    }

    #[Test]
    public function a_named_namespace_flush_does_not_reach_into_the_root(): void
    {
        $manager = $this->makeManager();
        $manager->put('item', 'ROOT', tags: ['shared']);
        $manager->withNamespace('views')->put('item', 'VIEWS', tags: ['shared']);

        self::assertSame(1, $manager->withNamespace('views')->flushTags('shared'));
        self::assertSame('ROOT', $manager->get('item'));
    }

    /**
     * The two tag SETS are separate even when the keys look alike.
     *
     * This started life asserting that the two VALUES survive each other, and
     * failed for a reason that has nothing to do with tags: root key
     * 'views:item' and key 'item' inside namespace 'views' resolve to the same
     * store key, so there was only ever one entry. That is a separate defect —
     * see tk-cache-namespace-key-collision — and the part this change is
     * responsible for is asserted here instead.
     */
    #[Test]
    public function the_two_namespaces_keep_separate_tag_sets(): void
    {
        $manager = $this->makeManager();
        $manager->put('root-item', 'ROOT', tags: ['shared']);
        $manager->withNamespace('views')->put('view-item', 'VIEWS', tags: ['shared']);

        self::assertSame(1, $manager->withNamespace('views')->flushTags('shared'));
        self::assertSame('ROOT', $manager->get('root-item'), 'the root set was never touched');
        self::assertSame(1, $manager->flushTags('shared'), 'and it is still flushable on its own');
    }

}
