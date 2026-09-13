<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Integration;

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

/**
 * What flush() decides to delete, prune, or leave alone.
 *
 * Against a real Redis, because flush applies its decisions through a Lua
 * script — it deletes only entries that have not changed since it classified
 * them — and a hand-written stand-in for that script would test the stand-in.
 *
 * The state is still SEEDED directly rather than produced by attach(): that
 * keeps each scenario saying plainly what the set contained, which is the
 * thing under test.
 */
final class RedisTagFlushTest extends TestCase
{
    private \Predis\Client $redis;
    private CacheValueSerializer $serializer;
    private CacheConfig $config;
    private string $prefix;

    protected function setUp(): void
    {
        $host = self::reachableHost();
        if ($host === null) {
            self::markTestSkipped('needs a reachable Redis on port 6379');
        }

        $this->prefix = 'semitexa-flush-' . bin2hex(random_bytes(4));
        $this->redis = new \Predis\Client(['host' => $host, 'port' => 6379]);
        $this->serializer = new CacheValueSerializer();
        $this->config = new CacheConfig(
            driver: 'redis',
            prefix: $this->prefix,
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

    protected function tearDown(): void
    {
        if (!isset($this->redis)) {
            return;
        }
        foreach ($this->redis->keys('*' . $this->prefix . '*') as $key) {
            $this->redis->del([$key]);
        }
    }

    private static function reachableHost(): ?string
    {
        foreach ([getenv('REDIS_HOST') ?: null, 'redis', '127.0.0.1'] as $host) {
            if ($host === null) {
                continue;
            }
            $socket = @fsockopen($host, 6379, $errno, $errstr, 0.5);
            if ($socket !== false) {
                fclose($socket);
                return $host;
            }
        }

        return null;
    }

    private function namespaceFor(string $name = ''): CacheNamespace
    {
        return (new DefaultCacheNamespaceResolver($this->config))->resolve($name, CacheScope::Tenant);
    }

    private function index(): RedisTagIndex
    {
        return new RedisTagIndex($this->redis, $this->serializer);
    }

    /**
     * Store an entry and list it under $tags — the state attach() produces.
     *
     * @param list<string> $tags
     */
    private function seed(CacheNamespace $ns, string $key, mixed $value, array $tags): string
    {
        $resolved = new ResolvedCacheKey($ns, $key);
        $this->redis->set($resolved->asString(), $this->serializer->encode(new CacheEntry(
            value: $value,
            createdAtEpoch: time(),
            ttlSeconds: 300,
            format: 'json',
            tags: new TagSet($tags),
        )));
        foreach ($tags as $tag) {
            $this->redis->sadd($ns->tagKeyPrefix() . $tag, [$resolved->asString()]);
        }

        return $resolved->asString();
    }

    private function valueAt(string $key): mixed
    {
        $raw = $this->redis->get($key);

        return $raw === null ? null : $this->serializer->decode($raw)->value;
    }

    /** @return list<string> */
    private function membersOf(CacheNamespace $ns, string $tag): array
    {
        return array_values($this->redis->smembers($ns->tagKeyPrefix() . $tag));
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
        $this->redis->del([$gone]); // Redis dropped it on expiry

        self::assertSame(1, $this->index()->flush($ns, new TagSet(['tag'])));
    }

    public function testAStaleMemberIsPrunedFromTheTagSet(): void
    {
        $ns = $this->namespaceFor();
        $gone = $this->seed($ns, 'gone', 'v', ['tag']);
        $this->redis->del([$gone]);

        $this->index()->flush($ns, new TagSet(['tag']));

        self::assertSame([], $this->membersOf($ns, 'tag'), 'the stale member is gone from the set');
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
        $this->redis->set($key, 'not something this serializer wrote');

        self::assertSame(0, $this->index()->flush($ns, new TagSet(['tag'])));
        self::assertSame('not something this serializer wrote', $this->redis->get($key), 'not deleted');
        self::assertContains($key, $this->membersOf($ns, 'tag'), 'and not forgotten');
    }

    /**
     * An entry rewritten between the classification and the delete keeps both
     * its value and its membership. The flush reads, decides, then acts; a put
     * landing in that window replaces the value and reattaches its tags, and
     * deleting on the strength of the older reading would take the new value
     * with it.
     */
    public function testAnEntryRewrittenDuringTheFlushIsLeftAlone(): void
    {
        $ns = $this->namespaceFor();
        $key = $this->seed($ns, 'item', 'old', ['tag']);

        // A real Redis underneath; the decorator only chooses WHEN the
        // concurrent write lands — between the read that classifies and the
        // script that acts, which is the window under test.
        $client = new class ($this->redis, $key) implements \Predis\ClientInterface {
            private bool $done = false;

            public function __construct(
                private readonly \Predis\Client $inner,
                private readonly string $key,
            ) {}

            public function getCommandFactory() { return $this->inner->getCommandFactory(); }
            public function getOptions() { return $this->inner->getOptions(); }
            public function connect() { return $this->inner->connect(); }
            public function disconnect() { return $this->inner->disconnect(); }
            public function getConnection() { return $this->inner->getConnection(); }
            public function createCommand($method, $arguments = []) { return $this->inner->createCommand($method, $arguments); }
            public function executeCommand(\Predis\Command\CommandInterface $command) { return $this->inner->executeCommand($command); }

            public function __call($method, $arguments)
            {
                $result = $this->inner->{$method}(...$arguments);

                if (!$this->done && strtolower($method) === 'mget') {
                    $this->done = true;
                    $this->inner->set($this->key, 'REWRITTEN BY SOMEBODY ELSE');
                }

                return $result;
            }
        };

        $index = new RedisTagIndex($client, $this->serializer);

        self::assertSame(0, $index->flush($ns, new TagSet(['tag'])), 'nothing matched what was classified');
        self::assertSame('REWRITTEN BY SOMEBODY ELSE', $this->redis->get($key), 'the newer value survives');
        self::assertContains($key, $this->membersOf($ns, 'tag'), 'and so does its membership');
    }

    /**
     * Memberships written under the layout before this one are still honoured.
     * Nothing writes there any more, and that version gave those sets no TTL,
     * so unread they would strand every entry already in a deployed cache.
     */
    public function testMembershipsFromTheOldLayoutAreStillFlushable(): void
    {
        $ns = $this->namespaceFor();
        $key = $this->seed($ns, 'item', 'v', ['tag']);
        // Move the membership to where the previous version kept it.
        $this->redis->del([$ns->tagKeyPrefix() . 'tag']);
        $this->redis->sadd($ns->legacyTagKeyPrefix() . 'tag', [$key]);

        self::assertSame(1, $this->index()->flush($ns, new TagSet(['tag'])));
        self::assertNull($this->valueAt($key));
        self::assertSame([], array_values($this->redis->smembers($ns->legacyTagKeyPrefix() . 'tag')), 'and the old set drains');
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

}
