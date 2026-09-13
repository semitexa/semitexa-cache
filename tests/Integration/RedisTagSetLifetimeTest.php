<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\CacheManager;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Application\Service\RedisCacheStore;
use Semitexa\Cache\Application\Service\RedisTagIndex;
use Semitexa\Cache\Configuration\CacheConfig;

/**
 * A tag set must not outlive everything it names, and must not die before any
 * of it. Members are pruned when a tag is flushed, but a tag nobody ever
 * flushes is never visited, so the set itself carries an expiry covering its
 * longest-lived member.
 *
 * AGAINST A REAL REDIS, not a stand-in. attach() decides the lifetime inside a
 * Lua script — the TTL is read before the SADD, because afterwards a brand-new
 * set and a deliberately immortal one both answer -1 — and a hand-written
 * simulation of that script would test the simulation rather than the
 * behaviour. Skips where no Redis is reachable, rather than pretending to run.
 */
final class RedisTagSetLifetimeTest extends TestCase
{
    private \Predis\Client $redis;
    private string $prefix;

    protected function setUp(): void
    {
        $host = self::reachableHost();
        if ($host === null) {
            self::markTestSkipped('needs a reachable Redis on port 6379');
        }

        $this->prefix = 'semitexa-lifetime-' . bin2hex(random_bytes(4));
        $this->redis = new \Predis\Client(['host' => $host, 'port' => 6379]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->redis)) {
            return;
        }
        foreach ($this->redis->keys($this->prefix . '*') as $key) {
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

    private function makeManager(bool $allowForever = false): CacheManager
    {
        $config = new CacheConfig(
            driver: 'redis',
            prefix: $this->prefix,
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

        return CacheManager::withDependencies(
            config: $config,
            store: new RedisCacheStore($serializer, $this->redis),
            tagIndex: new RedisTagIndex($serializer, $this->redis),
            namespaceResolver: new DefaultCacheNamespaceResolver($config),
        );
    }

    private function ttlOfTagSet(string $tag): int
    {
        foreach ($this->redis->keys($this->prefix . '*') as $key) {
            if (str_ends_with((string) $key, ':tag:v2:' . $tag)) {
                return (int) $this->redis->ttl($key);
            }
        }

        self::fail("no tag set was written for '{$tag}'");
    }

    #[Test]
    public function the_tag_set_gets_an_expiry_covering_its_member(): void
    {
        $this->makeManager()->put('item', 'v', ttlSeconds: 120, tags: ['tag']);

        self::assertEqualsWithDelta(120, $this->ttlOfTagSet('tag'), 2);
    }

    #[Test]
    public function a_longer_lived_member_extends_the_set(): void
    {
        $manager = $this->makeManager();
        $manager->put('short', 'v', ttlSeconds: 60, tags: ['tag']);
        $manager->put('long', 'v', ttlSeconds: 600, tags: ['tag']);

        self::assertEqualsWithDelta(600, $this->ttlOfTagSet('tag'), 2);
    }

    #[Test]
    public function a_shorter_lived_member_does_not_shorten_the_set(): void
    {
        $manager = $this->makeManager();
        $manager->put('long', 'v', ttlSeconds: 600, tags: ['tag']);
        $manager->put('short', 'v', ttlSeconds: 60, tags: ['tag']);

        self::assertEqualsWithDelta(600, $this->ttlOfTagSet('tag'), 2);
    }

    #[Test]
    public function the_default_ttl_is_used_when_the_caller_gives_none(): void
    {
        $this->makeManager()->put('item', 'v', tags: ['tag']);

        self::assertEqualsWithDelta(300, $this->ttlOfTagSet('tag'), 2);
    }

    #[Test]
    public function a_forever_member_leaves_the_set_without_an_expiry(): void
    {
        $manager = $this->makeManager(allowForever: true);
        $manager->put('mortal', 'v', ttlSeconds: 60, tags: ['tag']);
        $manager->put('forever', 'v', ttlSeconds: 0, tags: ['tag']);

        self::assertSame(-1, $this->ttlOfTagSet('tag'), 'a set naming an entry that never expires must not expire');
    }

    #[Test]
    public function a_mortal_member_does_not_revive_an_expiry_on_a_persisted_set(): void
    {
        $manager = $this->makeManager(allowForever: true);
        $manager->put('forever', 'v', ttlSeconds: 0, tags: ['tag']);
        $manager->put('mortal', 'v', ttlSeconds: 60, tags: ['tag']);

        self::assertSame(-1, $this->ttlOfTagSet('tag'), 'the immortal member is still in the set');
    }

    /**
     * The ordering inside the script is what makes this work: a second attach
     * reads the expiry the first one set, not the -1 of a set that merely
     * exists. Done with three separate commands, two concurrent puts could each
     * see -1 and settle on the shorter lifetime.
     */
    #[Test]
    public function a_new_set_and_an_immortal_one_are_told_apart(): void
    {
        $manager = $this->makeManager(allowForever: true);

        $manager->put('a', 'v', ttlSeconds: 90, tags: ['fresh']);
        self::assertEqualsWithDelta(90, $this->ttlOfTagSet('fresh'), 2, 'a brand-new set gets its member expiry');

        $manager->put('b', 'v', ttlSeconds: 0, tags: ['immortal']);
        $manager->put('c', 'v', ttlSeconds: 90, tags: ['immortal']);
        self::assertSame(-1, $this->ttlOfTagSet('immortal'), 'an immortal set is left immortal');
    }
}
