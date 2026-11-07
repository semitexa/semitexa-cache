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
    public function flushTags(string ...$tags): int;
    public function flushNamespace(?string $namespace = null): int;
    public function withNamespace(string $namespace): CacheManagerInterface;
    public function withTags(string ...$tags): CacheManagerInterface;
    public function scope(CacheScope $scope): CacheManagerInterface;
}
