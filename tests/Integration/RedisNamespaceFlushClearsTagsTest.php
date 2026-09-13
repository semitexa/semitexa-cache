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
 * Tag sets were moved out of the entry keyspace so that the root namespace's
 * prefix would stop matching every named one. Clearing a namespace sweeps the
 * ENTRY keyspace, so after the move nothing removed the sets — and a set whose
 * longest-lived member never expires carries no expiry of its own, so it
 * outlived everything it named and grew again from there on the next write.
 * Raised in review of cache#19.
 *
 * Against a real Redis, because what is being asserted is which keys exist
 * afterwards. Skips where none is reachable.
 */
final class RedisNamespaceFlushClearsTagsTest extends TestCase
{
    private \Predis\Client $redis;
    private string $prefix;

    protected function setUp(): void
    {
        $host = self::reachableHost();
        if ($host === null) {
            self::markTestSkipped('needs a reachable Redis on port 6379');
        }

        $this->prefix = 'semitexa-nsflush-' . bin2hex(random_bytes(4));
        $this->redis = new \Predis\Client(['host' => $host, 'port' => 6379]);
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

    private function makeManager(): CacheManager
    {
        $config = new CacheConfig(
            driver: 'redis',
            prefix: $this->prefix,
            app: 'test-app',
            env: 'test',
            defaultTtl: 300,
            allowForever: true,
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
            tagIndex: new RedisTagIndex($this->redis, $serializer),
            namespaceResolver: new DefaultCacheNamespaceResolver($config),
        );
    }

    /** @return list<string> */
    private function tagKeys(): array
    {
        return array_values(array_map(strval(...), $this->redis->keys($this->prefix . ':tag:v2:*')));
    }

    #[Test]
    public function clearing_a_namespace_takes_its_tag_sets_with_it(): void
    {
        $manager = $this->makeManager();
        $manager->withNamespace('views')->put('item', 'v', ttlSeconds: 120, tags: ['tag']);

        self::assertNotSame([], $this->tagKeys(), 'the set has to exist before it can be left behind');

        $manager->flushNamespace('views');

        self::assertSame([], $this->tagKeys(), 'the set outlived every entry it named');
    }

    /**
     * The case that could grow without bound: a member that never expires
     * leaves the set without an expiry, so nothing else would ever remove it.
     */
    #[Test]
    public function a_set_with_an_immortal_member_does_not_survive_the_flush(): void
    {
        $manager = $this->makeManager();
        $manager->withNamespace('views')->put('forever', 'v', ttlSeconds: 0, tags: ['tag']);

        $key = $this->tagKeys()[0] ?? self::fail('no tag set was written');
        self::assertSame(-1, (int) $this->redis->ttl($key), 'this is the set nothing else would clean up');

        $manager->flushNamespace('views');

        self::assertSame([], $this->tagKeys());
    }

    #[Test]
    public function another_namespace_keeps_its_own_sets(): void
    {
        $manager = $this->makeManager();
        $manager->withNamespace('views')->put('item', 'v', ttlSeconds: 120, tags: ['tag']);
        $manager->withNamespace('pages')->put('item', 'v', ttlSeconds: 120, tags: ['tag']);

        $manager->flushNamespace('views');

        $left = $this->tagKeys();
        self::assertCount(1, $left);
        self::assertStringContainsString(':pages:', $left[0]);
    }

    /**
     * The scoped manager goes to doFlushNamespace() directly, so anything
     * flushNamespace() did on its own it did not do — which is how the cleanup
     * covered one of the two ways to ask for the same thing. Raised in review
     * of cache#19.
     */
    #[Test]
    public function a_scoped_flush_clears_the_sets_too(): void
    {
        $manager = $this->makeManager();
        $scoped = $manager->withNamespace('views');
        $scoped->put('forever', 'v', ttlSeconds: 0, tags: ['tag']);

        self::assertNotSame([], $this->tagKeys());

        $scoped->flushNamespace();

        self::assertSame([], $this->tagKeys());
    }

    /**
     * Clearing the ROOT namespace clears the tenant — its prefix is a prefix of
     * every named one, and that is already what clearing the root does to the
     * entries. The index follows the entries rather than inventing a second
     * meaning for the same call.
     */
    #[Test]
    public function clearing_the_root_namespace_clears_the_named_ones_too(): void
    {
        $manager = $this->makeManager();
        $manager->withNamespace('views')->put('item', 'v', ttlSeconds: 120, tags: ['tag']);
        $manager->put('loose', 'v', ttlSeconds: 120, tags: ['tag']);

        $manager->flushNamespace();

        self::assertSame([], $this->tagKeys());
    }
}
