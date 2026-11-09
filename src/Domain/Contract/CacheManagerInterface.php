<?php
declare(strict_types=1);
namespace Semitexa\Cache\Domain\Contract;

use Semitexa\Cache\Domain\Enum\CacheScope;

interface CacheManagerInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void;
    public function remember(string $key, callable $resolver, ?int $ttlSeconds = null, array $tags = []): mixed;
    public function forget(string $key): void;

    /**
     * The value under $key, removed: null when there was none, or when another caller
     * removed it first. Of two callers taking one key at once, exactly one gets the
     * value, on every store (Redis DEL counts what it deleted): a one-time token is
     * spent once, by whichever worker took it.
     *
     * The store makes the read and the removal one step (a script on Redis), so a value
     * written after the take survives it. An unreachable Redis is a miss: null.
     */
    public function take(string $key): mixed;

    /**
     * Store $value under $key only when nothing live is there, atomically; true
     * when this call stored it. Of workers adding one key at once, one wins:
     * a claim or a lock, which get() then put() cannot give. Stores no tags, so
     * a manager scoped with withTags() refuses it (a tag link written before the
     * add could delete another holder's entry on a flush, one written after could
     * be lost).
     */
    public function add(string $key, mixed $value, ?int $ttlSeconds = null): bool;

    /**
     * Remove $key only while its value is $expected (===), atomically; true when
     * this call removed it. How a lock holder releases: a check then forget()
     * removed a lock taken by someone else in between. Compare scalars or
     * arrays: an object comes back from the store as a new instance and is
     * never === what was put.
     *
     * Both are atomic across workers on a shared store (redis); the array
     * driver is one worker's memory and is atomic only within it.
     */
    public function forgetIf(string $key, mixed $expected): bool;
    public function flushTags(string ...$tags): int;
    public function flushNamespace(?string $namespace = null): int;
    public function withNamespace(string $namespace): CacheManagerInterface;
    public function withTags(string ...$tags): CacheManagerInterface;
    public function scope(CacheScope $scope): CacheManagerInterface;
}
