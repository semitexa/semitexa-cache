<?php

declare(strict_types=1);

namespace Semitexa\Cache\Application\Service;

/**
 * Single-flight `remember()` shared by {@see CacheManager} and
 * {@see ScopedCacheManager}.
 *
 * The naive get-miss → resolve → put has a thundering herd: a Swoole worker
 * runs many coroutines, and a resolver call yields on its I/O, so when a
 * popular key misses every concurrent coroutine runs the expensive resolver
 * and puts — duplicated work, N times over. This coalesces them: the first
 * coroutine to miss a key becomes the leader and resolves; the rest wait on it
 * and then read its cached result.
 *
 * The classes using this trait each provide get()/put() (from
 * CacheManagerInterface); the trait only adds the coalescing wrapper.
 *
 * Scope of the guarantee: single-flight is PER WORKER (the in-flight map is
 * instance state). Two different workers can still each resolve once — bounded
 * by worker count, not coroutine count. And an EXTERNAL writer (a forget()/
 * put() from a mutation) can still race the leader's put — closing that window
 * fully needs a store-level compare-and-set the get/put/forget contract does
 * not expose. The herd, the common and costly case, is eliminated.
 */
trait SingleFlightRemember
{
    /**
     * In-flight resolutions keyed by cache key: a capacity-1 coroutine Channel
     * per key, held by the leader and closed when it finishes so every waiter
     * wakes at once. Per-worker instance state; empty outside coroutines.
     *
     * @var array<string, \Swoole\Coroutine\Channel>
     */
    private array $inFlightResolutions = [];

    public function remember(string $key, callable $resolver, ?int $ttlSeconds = null, array $tags = []): mixed
    {
        // A miss is told apart from a stored null by a marker of our own: with
        // null as the default, a resolver that returned null ran every time.
        $miss = self::rememberMiss();
        $existing = $this->get($key, $miss);
        if ($existing !== $miss) {
            return $existing;
        }

        // Outside a coroutine (CLI/tests) there is no concurrency to coalesce,
        // and Channel methods are illegal there — resolve directly.
        if (!self::inCoroutineForSingleFlight()) {
            $value = $resolver();
            $this->put($key, $value, $ttlSeconds, $tags);

            return $value;
        }

        if (isset($this->inFlightResolutions[$key])) {
            // Another coroutine is already resolving this key — wait for it,
            // then read the value it cached instead of recomputing.
            $this->inFlightResolutions[$key]->pop();
            $cached = $this->get($key, $miss);
            if ($cached !== $miss) {
                return $cached;
            }
            // The leader cached nothing (its resolver failed);
            // resolve directly this once rather than block again.
            $value = $resolver();
            $this->put($key, $value, $ttlSeconds, $tags);

            return $value;
        }

        // Leader: register the in-flight channel BEFORE resolving (no yield
        // between the isset check above and this assignment, so exactly one
        // coroutine becomes leader per key), resolve, then wake the waiters.
        $channel = new \Swoole\Coroutine\Channel(1);
        $this->inFlightResolutions[$key] = $channel;
        try {
            $value = $resolver();
            $this->put($key, $value, $ttlSeconds, $tags);

            return $value;
        } finally {
            unset($this->inFlightResolutions[$key]);
            $channel->close();
        }
    }

    private static function rememberMiss(): object
    {
        static $miss = null;

        return $miss ??= new \stdClass();
    }

        private static function inCoroutineForSingleFlight(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false) && \Swoole\Coroutine::getCid() > 0;
    }
}
