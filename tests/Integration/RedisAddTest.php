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
 * A claim and a lock on Redis: add() is SET NX, so of two workers one wins;
 * forgetIfValue() removes only the holder's own entry, even when another
 * holder writes between the read and the removal. get() then put() let both
 * workers read nothing and both write (tk-cache-set-if-absent).
 */
final class RedisAddTest extends TestCase
{
    use RequiresRedis;

    private \Predis\Client $redis;
    private string $key;

    protected function setUp(): void
    {
        $this->redis = new \Predis\Client(['host' => self::redisHost(), 'port' => 6379, 'read_write_timeout' => 5]);
        $this->key = 'semitexa-add-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->del([$this->resolved()->asString()]);
        }
    }

    #[Test]
    public function of_two_workers_adding_one_key_one_wins_and_the_ttl_is_kept(): void
    {
        $first = $this->worker();
        $second = $this->worker();
        $key = $this->resolved();

        self::assertTrue($first->add($key, self::entry('worker-1')));
        self::assertFalse($second->add($key, self::entry('worker-2')));
        self::assertSame('worker-1', $second->get($key)?->value, 'the loser did not overwrite');
        $ttl = (int) $this->redis->ttl($key->asString());
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(60, $ttl);
    }

    #[Test]
    public function a_holder_removes_only_its_own_entry(): void
    {
        $store = $this->worker();
        $key = $this->resolved();
        $store->put($key, self::entry('holder-a'));

        self::assertFalse($store->forgetIfValue($key, 'holder-b'));
        self::assertSame('holder-a', $store->get($key)?->value);
        self::assertTrue($store->forgetIfValue($key, 'holder-a'));
        self::assertSame(0, $this->redis->exists($key->asString()));
        self::assertFalse($store->forgetIfValue($key, 'holder-a'), 'nothing left to remove');
    }

    #[Test]
    public function a_write_between_the_read_and_the_removal_is_kept(): void
    {
        $key = $this->resolved();
        $other = $this->worker();
        $other->put($key, self::entry('holder-a'));
        // Holder A reads its own lock; before its removal runs, the lock expires
        // and B takes it. A's value check passed on what it read, so only the
        // removal that compares the bytes still there keeps B's lock.
        $client = new InterleavingClient(['host' => self::redisHost(), 'port' => 6379]);
        $client->afterGet = static fn () => $other->put($key, self::entry('holder-b'));
        $holderA = new RedisCacheStore(new CacheValueSerializer(), $client);

        self::assertFalse($holderA->forgetIfValue($key, 'holder-a'), 'removal only while the bytes read are still there');
        self::assertSame('holder-b', $other->get($key)?->value, "B's lock survives A's release");
    }

    #[Test]
    public function an_added_key_expires_when_its_entry_does_not_a_ttl_from_now(): void
    {
        // Read as expired at createdAt + ttl: with EX 60 from now the key lived
        // up to a second longer, and add() was refused for a slot get() called empty.
        $key = $this->resolved();
        // Read as expired 10 s from now; EX 60 from now would leave ~60 s, and no
        // expiry at all gives -1, so both bounds matter.
        self::assertTrue($this->worker()->add($key, new CacheEntry('lock', time() - 50, 60, 'json', new TagSet([]))));
        $pttl = (int) $this->redis->pttl($key->asString());

        self::assertGreaterThan(8000, $pttl);
        self::assertLessThanOrEqual(10000, $pttl);
    }

    #[Test]
    public function reading_an_expired_entry_does_not_remove_a_lock_added_meanwhile(): void
    {
        $key = $this->resolved();
        $other = $this->worker();
        // Logically expired (created 100 s ago, ttl 60), still physically there.
        $other->put($key, new CacheEntry('holder-a', time() - 100, 60, 'json', new TagSet([])));
        // Between the reader's GET and its clean-up, the key lapses and B adds its lock.
        $client = new InterleavingClient(['host' => self::redisHost(), 'port' => 6379]);
        $client->afterGet = function () use ($key, $other): void {
            $this->redis->del([$key->asString()]);
            self::assertTrue($other->add($key, self::entry('holder-b')));
        };

        self::assertNull((new RedisCacheStore(new CacheValueSerializer(), $client))->get($key));
        self::assertSame('holder-b', $other->get($key)?->value, "the reader's clean-up kept B's lock");
    }

    private function worker(): RedisCacheStore
    {
        return new RedisCacheStore(new CacheValueSerializer(), new \Predis\Client(['host' => self::redisHost(), 'port' => 6379]));
    }

    private function resolved(): ResolvedCacheKey
    {
        return new ResolvedCacheKey(new CacheNamespace('semitexa', 'test-app', 'test', CacheScope::Global, 'global', $this->key), 'lock');
    }

    private static function entry(string $value): CacheEntry
    {
        return new CacheEntry($value, time(), 60, 'json', new TagSet([]));
    }
}

/** A Predis client that runs a callback right after its first GET: a write landing mid-operation. */
final class InterleavingClient extends \Predis\Client
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $afterGet = null;

    public function __call($commandID, $arguments)
    {
        $result = parent::__call($commandID, $arguments);
        if (strtolower((string) $commandID) === 'get' && $this->afterGet !== null) {
            $after = $this->afterGet;
            $this->afterGet = null;
            $after();
        }

        return $result;
    }
}
