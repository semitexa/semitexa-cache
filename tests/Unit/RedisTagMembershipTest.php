<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Application\Service\RedisTagIndex;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\Cache\Domain\Model\CacheEntry;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Domain\Model\TagSet;
use Semitexa\Cache\Tests\Unit\Fake\FakeRedisClient;

/**
 * What flush() decides to delete, prune, or leave alone.
 *
 * The state is SEEDED directly rather than produced by attach(): attach runs a
 * Lua script, and a hand-written stand-in for one would only ever test the
 * stand-in — attach's behaviour is covered against a real Redis in
 * tests/Integration/RedisTagSetLifetimeTest. Seeding also makes each scenario
 * state plainly what the set contained, which is the thing under test.
 */
final class RedisTagMembershipTest extends TestCase
{
    private FakeRedisClient $redis;
    private CacheValueSerializer $serializer;
    private CacheConfig $config;

    protected function setUp(): void
    {
        $this->redis = new FakeRedisClient();
        $this->serializer = new CacheValueSerializer();
        $this->config = new CacheConfig(
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
    }

    private function namespaceFor(string $name = ''): CacheNamespace
    {
        return (new DefaultCacheNamespaceResolver($this->config))->resolve($name, CacheScope::Tenant);
    }

    private function index(): RedisTagIndex
    {
        return new RedisTagIndex($this->serializer, $this->redis);
    }

    /**
     * Store an entry and list it under $tags — the state attach() produces.
     *
     * @param list<string> $tags
     */
    private function seed(CacheNamespace $ns, string $key, mixed $value, array $tags): string
    {
        $resolved = new ResolvedCacheKey($ns, $key);
        $this->redis->strings[$resolved->asString()] = $this->serializer->encode(new CacheEntry(
            value: $value,
            createdAtEpoch: time(),
            ttlSeconds: 300,
            format: 'json',
            tags: new TagSet($tags),
        ));
        foreach ($tags as $tag) {
            $tagKey = $ns->tagKeyPrefix() . $tag;
            if (!in_array($resolved->asString(), $this->redis->sets[$tagKey] ?? [], true)) {
                $this->redis->sets[$tagKey][] = $resolved->asString();
            }
        }

        return $resolved->asString();
    }

    private function valueAt(string $key): mixed
    {
        $raw = $this->redis->strings[$key] ?? null;

        return $raw === null ? null : $this->serializer->decode($raw)->value;
    }

    public function testAnEntryThatStillCarriesTheTagIsDeleted(): void
    {
        $ns = $this->namespaceFor();
        $key = $this->seed($ns, 'item', 'v', ['tag']);

        self::assertSame(1, $this->index()->flush($ns, new TagSet(['tag'])));
        self::assertNull($this->valueAt($key));
    }

    public function testAnEntryRewrittenWithoutTheTagSurvives(): void
    {
        $ns = $this->namespaceFor();
        $key = $this->seed($ns, 'item', 'old', ['old-tag']);
        // Rewritten under a different tag; the old membership is still listed.
        $this->seed($ns, 'item', 'new', ['new-tag']);

        self::assertSame(0, $this->index()->flush($ns, new TagSet(['old-tag'])));
        self::assertSame('new', $this->valueAt($key));
    }

    public function testAnEntryGoneByTtlIsNotCountedAsRemoved(): void
    {
        $ns = $this->namespaceFor();
        $gone = $this->seed($ns, 'gone', 'v', ['tag']);
        $this->seed($ns, 'here', 'v', ['tag']);
        unset($this->redis->strings[$gone]); // Redis dropped it on expiry

        self::assertSame(1, $this->index()->flush($ns, new TagSet(['tag'])));
    }

    public function testAStaleMemberIsPrunedFromTheTagSet(): void
    {
        $ns = $this->namespaceFor();
        $gone = $this->seed($ns, 'gone', 'v', ['tag']);
        unset($this->redis->strings[$gone]);

        $this->index()->flush($ns, new TagSet(['tag']));

        self::assertArrayNotHasKey($ns->tagKeyPrefix() . 'tag', $this->redis->sets);
    }

    /**
     * An entry this process cannot decode — a rotated signing key, a worker
     * with a different environment — is not evidence that the membership is
     * wrong. Pruning it would make the entry permanently unflushable while
     * leaving it in the cache.
     */
    public function testAnUndecodableEntryIsNeitherDeletedNorForgotten(): void
    {
        $ns = $this->namespaceFor();
        $key = $this->seed($ns, 'item', 'v', ['tag']);
        $this->redis->strings[$key] = 'not something this serializer wrote';

        self::assertSame(0, $this->index()->flush($ns, new TagSet(['tag'])));
        self::assertSame('not something this serializer wrote', $this->redis->strings[$key], 'not deleted');
        self::assertContains($key, $this->redis->sets[$ns->tagKeyPrefix() . 'tag'], 'and not forgotten');
    }

    /**
     * A member added between the SMEMBERS and the SREM must survive. The tag
     * key used to be deleted outright at the end of a flush, which took that
     * member's membership with it and left its entry tagged but unreachable.
     */
    public function testAMemberAddedDuringTheFlushKeepsItsMembership(): void
    {
        $ns = $this->namespaceFor();
        $this->seed($ns, 'old', 'v', ['tag']);

        $tagKey = $ns->tagKeyPrefix() . 'tag';
        $latecomer = null;
        $this->redis->onCall('mget', function () use ($ns, &$latecomer): void {
            $latecomer ??= $this->seed($ns, 'new', 'v', ['tag']);
        });

        $this->index()->flush($ns, new TagSet(['tag']));

        self::assertNotNull($latecomer);
        self::assertArrayHasKey($tagKey, $this->redis->sets, 'the set must not be deleted wholesale');
        self::assertContains($latecomer, $this->redis->sets[$tagKey]);
    }

    public function testFlushingOneNamespaceLeavesAnotherAlone(): void
    {
        $a = $this->namespaceFor('nsa');
        $b = $this->namespaceFor('nsb');
        $this->seed($a, 'item', 'A', ['shared']);
        $keyB = $this->seed($b, 'item', 'B', ['shared']);

        self::assertSame(1, $this->index()->flush($a, new TagSet(['shared'])));
        self::assertSame('B', $this->valueAt($keyB));
        self::assertSame(1, $this->index()->flush($b, new TagSet(['shared'])), 'and b is still flushable');
    }

    /**
     * The root namespace's prefix is a string prefix of every named one, so
     * this is the pair a prefix filter could never separate — which is why the
     * namespace lives in the tag key instead.
     */
    public function testFlushingTheRootNamespaceLeavesANamedOneAlone(): void
    {
        $root = $this->namespaceFor();
        $named = $this->namespaceFor('views');
        $this->seed($root, 'item', 'ROOT', ['shared']);
        $keyNamed = $this->seed($named, 'item', 'VIEWS', ['shared']);

        self::assertSame(1, $this->index()->flush($root, new TagSet(['shared'])));
        self::assertSame('VIEWS', $this->valueAt($keyNamed));
    }

    public function testReadingTheCandidatesCostsOneRoundTrip(): void
    {
        $ns = $this->namespaceFor();
        foreach (range(1, 20) as $n) {
            $this->seed($ns, "item-{$n}", 'v', ['tag']);
        }

        $this->redis->calls = [];
        $this->index()->flush($ns, new TagSet(['tag']));

        $mgets = array_filter($this->redis->calls, static fn(string $c): bool => $c === 'mget');
        $gets = array_filter($this->redis->calls, static fn(string $c): bool => $c === 'get');

        self::assertCount(1, $mgets, 'twenty members must not be twenty round trips');
        self::assertSame([], $gets, 'and none of them a single-key read');
    }
}
