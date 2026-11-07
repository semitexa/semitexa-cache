<?php

declare(strict_types=1);

namespace Semitexa\Cache\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\RedisCacheStore;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\Cache\Domain\Model\CacheEntry;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Domain\Model\TagSet;
use Semitexa\Cache\Tests\Support\RequiresRedis;

/**
 * RedisCacheStore::take() on a real Redis: the value once, removed by one
 * script, so a key written after it survives and a second take finds nothing.
 * Two connections stand for two workers.
 */
final class RedisTakeTest extends TestCase
{
    use RequiresRedis;

    private \Predis\Client $redis;
    private string $key;

    protected function setUp(): void
    {
        $this->redis = new \Predis\Client(['host' => self::redisHost(), 'port' => 6379, 'read_write_timeout' => 5]);
        $this->key = 'semitexa-take-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->del([$this->resolved()->asString()]);
        }
    }

    #[Test]
    public function two_workers_take_one_value_once(): void
    {
        $worker = fn (): RedisCacheStore => new RedisCacheStore(new CacheValueSerializer(), new \Predis\Client(['host' => self::redisHost(), 'port' => 6379]));
        $first = $worker();
        $second = $worker();
        $key = $this->resolved();
        $first->put($key, self::entry('hash'));

        self::assertSame('hash', $second->take($key)?->value);
        self::assertNull($first->take($key));
        self::assertSame(0, $this->redis->exists($key->asString()), 'removed by the take');

        $first->put($key, self::entry('new'));
        self::assertSame('new', $second->get($key)?->value, 'a value written after the take survives it');
    }

    private function resolved(): ResolvedCacheKey
    {
        return new ResolvedCacheKey(new CacheNamespace('semitexa', 'test-app', 'test', CacheScope::Global, 'global', $this->key), 'token');
    }

    private static function entry(string $value): CacheEntry
    {
        return new CacheEntry($value, time(), 60, 'json', new TagSet([]));
    }
}
