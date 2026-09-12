<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\CacheManager;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Application\Service\RedisCacheStore;
use Semitexa\Cache\Application\Service\RedisTagIndex;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Tests\Unit\Fake\FakeRedisClient;

/**
 * The Redis tag index on a fake client: no server, because what is under test
 * is which keys the index decides to delete, prune or leave alone.
 */
final class RedisTagMembershipTest extends TestCase
{
    private FakeRedisClient $redis;

    private function makeManager(): CacheManager
    {
        $config = new CacheConfig(
            driver: 'redis',
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
        $this->redis = new FakeRedisClient();

        return CacheManager::withDependencies(
            config: $config,
            store: new RedisCacheStore($serializer, $this->redis),
            tagIndex: new RedisTagIndex($serializer, $this->redis),
            namespaceResolver: new DefaultCacheNamespaceResolver($config),
        );
    }

    public function testFlushingAnOldTagDoesNotDeleteTheRewrittenValue(): void
    {
        $manager = $this->makeManager();
        $manager->put('item', 'old', tags: ['old-tag']);
        $manager->put('item', 'new', tags: ['new-tag']);

        self::assertSame(0, $manager->flushTags('old-tag'));
        self::assertSame('new', $manager->get('item'));
        self::assertSame(1, $manager->flushTags('new-tag'));
        self::assertNull($manager->get('item'));
    }

    public function testFlushingOneNamespaceLeavesTheOtherIntactAndFlushable(): void
    {
        $manager = $this->makeManager();
        $a = $manager->withNamespace('nsa');
        $b = $manager->withNamespace('nsb');

        $a->put('item', 'A', tags: ['shared']);
        $b->put('item', 'B', tags: ['shared']);

        self::assertSame(1, $a->flushTags('shared'), 'only namespace a had one entry under the tag');
        self::assertNull($a->get('item'));
        self::assertSame('B', $b->get('item'), 'a flush in namespace a must not delete namespace b');

        self::assertSame(1, $b->flushTags('shared'), 'namespace b must still be flushable by the same tag');
        self::assertNull($b->get('item'));
    }

    public function testStaleMembersArePrunedFromTheTagSet(): void
    {
        $manager = $this->makeManager();
        $manager->put('item', 'v', tags: ['tag']);
        $manager->put('item', 'v2', tags: ['other']);

        $manager->flushTags('tag');

        $tagKeys = array_keys($this->redis->sets);
        self::assertSame([], array_filter($tagKeys, static fn(string $k) => str_ends_with($k, ':tag:tag')),
            'a tag whose every member went stale leaves no set behind');
    }

    public function testTheSharedTagKeySurvivesWhileAnotherNamespaceStillUsesIt(): void
    {
        $manager = $this->makeManager();
        $a = $manager->withNamespace('nsa');
        $b = $manager->withNamespace('nsb');
        $a->put('item', 'A', tags: ['shared']);
        $b->put('item', 'B', tags: ['shared']);

        $a->flushTags('shared');

        $sharedKeys = array_filter(
            array_keys($this->redis->sets),
            static fn(string $k) => str_ends_with($k, ':tag:shared')
        );
        self::assertCount(1, $sharedKeys, 'the tag key must stay: namespace b still has a member in it');
    }

    public function testAnEntryGoneByTtlIsNotCountedAsRemoved(): void
    {
        $manager = $this->makeManager();
        $manager->put('gone', 'v', tags: ['tag']);
        $manager->put('here', 'v', tags: ['tag']);

        // Redis would have dropped the key itself on expiry; the tag set keeps
        // naming it until something prunes the member.
        foreach (array_keys($this->redis->strings) as $key) {
            if (str_ends_with($key, 'gone')) {
                unset($this->redis->strings[$key]);
            }
        }

        self::assertSame(1, $manager->flushTags('tag'), 'only the entry that was still there counts');
    }
}
