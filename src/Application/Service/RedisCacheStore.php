<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Service;

use Predis\ClientInterface;
use Predis\Connection\ConnectionException;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Contract\CacheStoreInterface;
use Semitexa\Cache\Domain\Model\CacheEntry;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Core\Redis\RedisUnavailableException;

/**
 * Redis-backed cache store.
 *
 * Coroutine safety: under Swoole the worker enables SWOOLE_HOOK_ALL, which makes
 * each Predis TCP socket coroutine-EXCLUSIVE — two concurrent coroutines sharing
 * one connection crash with "Socket #N has already been bound to another
 * coroutine". So this store does NOT hold a single shared Predis client; it
 * borrows one from a {@see RedisConnectionPool} (Swoole\Coroutine\Channel of
 * exclusive connections) for the duration of each logical operation and returns
 * it after, exactly as the SSR async server does. Each concurrent coroutine gets
 * its own socket; excess coroutines block on the pool until one frees. Outside
 * Swoole (CLI/tests) the pool falls back to a single shared client.
 *
 * A pre-resolved client may still be injected (tests / single-connection use);
 * when present it is used directly and the pool is skipped.
 */
final class RedisCacheStore implements CacheStoreInterface
{
    private readonly ?ClientInterface $client;
    private readonly ?RedisConnectionPool $pool;

    public function __construct(
        private readonly CacheValueSerializer $serializer,
        ?ClientInterface $redis = null,
        ?CacheConfig $config = null,
        ?RedisConnectionPool $pool = null,
    ) {
        if ($redis !== null) {
            $this->client = $redis;
            $this->pool = null;
            return;
        }

        if ($pool !== null) {
            // Shared with the tag index: one pool of connections per manager,
            // not one per collaborator.
            $this->client = null;
            $this->pool = $pool;
            return;
        }

        $config ??= CacheConfig::fromEnvironment();
        $this->client = null;
        $this->pool = new RedisConnectionPool($config->redisPoolSize, [
            'scheme' => $config->redisScheme,
            'host' => $config->redisHost,
            'port' => $config->redisPort,
            'password' => $config->redisPassword ?? '',
        ]);
    }

    /**
     * A cache that cannot be reached is a miss, not a failed page: the caller
     * computes the value as it would on any miss. Same for put(), which then
     * keeps nothing. forget() and clearNamespace() still throw — an
     * invalidation that silently did nothing would serve stale data once
     * Redis is back.
     */
    public function get(ResolvedCacheKey $key): ?CacheEntry
    {
        try {
            return $this->read($key);
        } catch (ConnectionException | RedisUnavailableException $e) {
            self::reportUnreachable($e, 'get');

            return null;
        }
    }

    private function read(ResolvedCacheKey $key): ?CacheEntry
    {
        return $this->withConnection(function (ClientInterface $redis) use ($key): ?CacheEntry {
            $raw = $redis->get($key->asString());
            if ($raw === null || $raw === '') {
                return null;
            }

            // Clean up only the bytes read: between the GET and a plain DEL the
            // key can lapse and another worker add() a lock there, which the DEL
            // then removed.
            try {
                $entry = $this->serializer->decode($raw);
            } catch (\Throwable) {
                $redis->eval(self::FORGET_IF_UNCHANGED, 1, $key->asString(), $raw);
                return null;
            }

            if ($entry->isExpiredAt(time())) {
                $redis->eval(self::FORGET_IF_UNCHANGED, 1, $key->asString(), $raw);
                return null;
            }

            return $entry;
        });
    }

    public function put(ResolvedCacheKey $key, CacheEntry $entry): void
    {
        $raw = $this->serializer->encode($entry);
        try {
            $this->withConnection(function (ClientInterface $redis) use ($key, $entry, $raw): void {
                if ($entry->ttlSeconds !== null && $entry->ttlSeconds > 0) {
                    $redis->setex($key->asString(), $entry->ttlSeconds, $raw);
                } else {
                    $redis->set($key->asString(), $raw);
                }
            });
        } catch (ConnectionException | RedisUnavailableException $e) {
            self::reportUnreachable($e, 'put');
        }
    }

    /**
     * The pool's circuit breaker logs the outage once when it opens; a call it
     * refused is not news. A first-hand connection error is worth a warning.
     */
    private static function reportUnreachable(\Throwable $e, string $operation): void
    {
        if ($e instanceof RedisUnavailableException) {
            return;
        }
        StaticLoggerBridge::warning('cache', 'Redis cache unreachable; treated as a miss', [
            'operation' => $operation,
            'error' => $e->getMessage(),
        ]);
    }

    /** GET and DEL in one script: Redis runs it whole, so nothing lands between them. GETDEL needs 6.2. */
    private const TAKE = "local v = redis.call('GET', KEYS[1]) if v then redis.call('DEL', KEYS[1]) end return v";

    /**
     * Unreachable is a miss, as for get(): a one-time token that cannot be taken is
     * not consumed, and the form is refused rather than run twice.
     */
    public function take(ResolvedCacheKey $key): ?CacheEntry
    {
        try {
            $raw = $this->withConnection(static fn (ClientInterface $redis): mixed => $redis->eval(self::TAKE, 1, $key->asString()));
        } catch (ConnectionException | RedisUnavailableException $e) {
            self::reportUnreachable($e, 'take');

            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $entry = $this->serializer->decode($raw);
        } catch (\Throwable) {
            return null;
        }

        return $entry->isExpiredAt(time()) ? null : $entry;
    }

    /**
     * SET NX in one command. Unreachable is a refusal, not a grant: a claim or a
     * lock that cannot be recorded must not be handed out.
     */
    public function add(ResolvedCacheKey $key, CacheEntry $entry): bool
    {
        $raw = $this->serializer->encode($entry);
        try {
            $result = $this->withConnection(static function (ClientInterface $redis) use ($key, $entry, $raw): mixed {
                if ($entry->ttlSeconds === null || $entry->ttlSeconds <= 0) {
                    return $redis->set($key->asString(), $raw, 'NX');
                }
                // Expire when the entry does: it is read as expired at
                // createdAt + ttl (whole seconds), and an EX from now kept the
                // key up to a second past that, refusing an add() of a slot a
                // get() already called empty.
                $ms = (int) ceil((($entry->createdAtEpoch + $entry->ttlSeconds) - microtime(true)) * 1000);

                return $redis->set($key->asString(), $raw, 'PX', max(1, $ms), 'NX');
            });
        } catch (ConnectionException | RedisUnavailableException $e) {
            self::reportUnreachable($e, 'add');

            return false;
        }

        // OK on a write, null when the key was there.
        return $result !== null && (string) $result === 'OK';
    }

    /** DEL only while the key still holds exactly the bytes read: Redis runs it whole. */
    private const FORGET_IF_UNCHANGED = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0";

    /**
     * The value is compared decoded, in PHP; the removal is one script that
     * deletes only while the key still holds the bytes that value came from. A
     * write in between (another holder's lock) changes them, and nothing goes.
     *
     * Unreachable is false, as for add(), and a caller cannot tell it from "not
     * the holder": a release during an outage leaves the lock until its TTL
     * (for good with TTL 0 and CACHE_ALLOW_FOREVER on), which is why a lock
     * should always carry one. The outage itself is logged: first-hand here,
     * or once by the pool's breaker when it opened.
     */
    public function forgetIfValue(ResolvedCacheKey $key, mixed $expected): bool
    {
        try {
            return (bool) $this->withConnection(function (ClientInterface $redis) use ($key, $expected): bool {
                $raw = $redis->get($key->asString());
                if (!is_string($raw) || $raw === '') {
                    return false;
                }
                try {
                    $entry = $this->serializer->decode($raw);
                } catch (\Throwable) {
                    return false;
                }
                if ($entry->isExpiredAt(time()) || $entry->value !== $expected) {
                    return false;
                }

                return (int) $redis->eval(self::FORGET_IF_UNCHANGED, 1, $key->asString(), $raw) > 0;
            });
        } catch (ConnectionException | RedisUnavailableException $e) {
            self::reportUnreachable($e, 'forgetIf');

            return false;
        }
    }

    public function forget(ResolvedCacheKey $key): bool
    {
        return $this->withConnection(static function (ClientInterface $redis) use ($key): bool {
            $result = $redis->del([$key->asString()]);
            return (int) $result > 0;
        });
    }

    public function clearNamespace(CacheNamespace $namespace): int
    {
        return $this->withConnection(static function (ClientInterface $redis) use ($namespace): int {
            $count = 0;

            // Every spelling this namespace covers — see sweepPrefixes(). The
            // pre-move one matters because an entry written to live forever
            // would otherwise be unreachable AND immortal.
            foreach ($namespace->sweepPrefixes() as $prefix) {
                if ($prefix === '') {
                    continue;
                }

                $pattern = $prefix . '*';
                $cursor = '0';
                do {
                    [$cursor, $keys] = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
                    if (!empty($keys)) {
                        $redis->del($keys);
                        $count += count($keys);
                    }
                } while ($cursor !== '0');
            }

            return $count;
        });
    }

    public function supportsTags(): bool
    {
        return true;
    }

    /**
     * Run $fn with an exclusive Redis connection: a pooled one borrowed for the
     * call (coroutine-safe under Swoole), or the injected single client.
     *
     * @template T
     * @param callable(ClientInterface): T $fn
     * @return T
     */
    private function withConnection(callable $fn): mixed
    {
        if ($this->pool !== null) {
            return $this->pool->withConnection($fn);
        }

        /** @var ClientInterface $client */
        $client = $this->client;
        return $fn($client);
    }
}
