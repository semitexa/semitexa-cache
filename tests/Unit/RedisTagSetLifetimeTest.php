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
 * A tag set must not outlive everything it names. Members are pruned when a tag
 * is flushed, but a tag nobody ever flushes is never visited, so the set itself
 * has to carry an expiry — one that always covers its longest-lived member.
 */
final class RedisTagSetLifetimeTest extends TestCase
{
    private FakeRedisClient $redis;

    private function makeManager(bool $allowForever = false): CacheManager
    {
        $config = new CacheConfig(
            driver: 'redis',
            prefix: 'semitexa',
            app: 'test-app',
            env: 'test',
            defaultTtl: 300,
            allowForever: $allowForever,
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

    private function tagKey(string $tag): string
    {
        foreach (array_keys($this->redis->sets) as $key) {
            if (str_ends_with($key, ':tag:' . $tag)) {
                return $key;
            }
        }
        self::fail("no tag set was written for '{$tag}'");
    }

    public function testTheTagSetGetsAnExpiryCoveringItsMember(): void
    {
        $manager = $this->makeManager();
        $manager->put('item', 'v', ttlSeconds: 120, tags: ['tag']);

        self::assertSame(120, $this->redis->expiries[$this->tagKey('tag')] ?? null,
            'the set must outlive the entry it names, and no longer than it has to');
    }

    public function testTheExpiryIsExtendedByALongerLivedMember(): void
    {
        $manager = $this->makeManager();
        $manager->put('short', 'v', ttlSeconds: 60, tags: ['tag']);
        $manager->put('long', 'v', ttlSeconds: 600, tags: ['tag']);

        self::assertSame(600, $this->redis->expiries[$this->tagKey('tag')] ?? null,
            'the set must cover its longest-lived member');
    }

    public function testAShorterLivedMemberDoesNotShortenTheSet(): void
    {
        $manager = $this->makeManager();
        $manager->put('long', 'v', ttlSeconds: 600, tags: ['tag']);
        $manager->put('short', 'v', ttlSeconds: 60, tags: ['tag']);

        self::assertSame(600, $this->redis->expiries[$this->tagKey('tag')] ?? null,
            'a later short-lived write must not orphan the long-lived member');
    }

    public function testTheDefaultTtlIsUsedWhenTheCallerGivesNone(): void
    {
        $manager = $this->makeManager();
        $manager->put('item', 'v', tags: ['tag']);

        self::assertSame(300, $this->redis->expiries[$this->tagKey('tag')] ?? null,
            'the entry got the configured default TTL, so the set must match it');
    }

    public function testAForeverMemberLeavesTheSetWithoutAnExpiry(): void
    {
        $manager = $this->makeManager(allowForever: true);
        $manager->put('mortal', 'v', ttlSeconds: 60, tags: ['tag']);
        $manager->put('forever', 'v', ttlSeconds: 0, tags: ['tag']);

        self::assertArrayNotHasKey($this->tagKey('tag'), $this->redis->expiries,
            'a set naming an entry that never expires must not expire either');
    }

    public function testAMortalMemberDoesNotReviveAnExpiryOnAPersistedSet(): void
    {
        $manager = $this->makeManager(allowForever: true);
        $manager->put('forever', 'v', ttlSeconds: 0, tags: ['tag']);
        $manager->put('mortal', 'v', ttlSeconds: 60, tags: ['tag']);

        self::assertArrayNotHasKey($this->tagKey('tag'), $this->redis->expiries,
            'the immortal member is still in the set, so the set stays immortal');
    }

    /**
     * The expiry is not free, so the price is written down. Measured
     * 2026-09-12 with FakeRedisClient::$calls; before the tag set had a
     * lifetime a tagged put cost SETEX + one SADD per tag.
     *
     * Steady state is now SETEX + (TTL + SADD) per tag, with one EXPIRE extra
     * the first time a set is written or when a longer-lived member extends it.
     * If this number grows, something started reading per put — find out what.
     */
    public function testATaggedPutCostsAKnownNumberOfCommands(): void
    {
        $manager = $this->makeManager();

        $manager->put('warm', 'v', tags: ['t1', 't2']);

        $this->redis->calls = [];
        $manager->put('warm', 'v2', tags: ['t1', 't2']);
        self::assertSame(
            ['setex', 'ttl', 'sadd', 'ttl', 'sadd'],
            $this->redis->calls,
            'two tags onto warm sets: one write plus a read and a write per tag'
        );

        $this->redis->calls = [];
        $manager->put('cold', 'v', tags: ['fresh']);
        self::assertSame(
            ['setex', 'ttl', 'sadd', 'expire'],
            $this->redis->calls,
            'a brand-new set pays one EXPIRE on top'
        );

        $this->redis->calls = [];
        $manager->put('untagged', 'v');
        self::assertSame(['setex'], $this->redis->calls, 'an untagged put must not touch the index at all');
    }
}
