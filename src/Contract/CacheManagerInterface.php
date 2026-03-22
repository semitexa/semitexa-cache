<?php
declare(strict_types=1);
namespace Semitexa\Cache\Contract;

use Semitexa\Cache\Enum\CacheScope;

interface CacheManagerInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void;
    public function remember(string $key, callable $resolver, ?int $ttlSeconds = null, array $tags = []): mixed;
    public function forget(string $key): void;
    public function flushTags(string ...$tags): int;
    public function flushNamespace(?string $namespace = null): int;
    public function withNamespace(string $namespace): static;
    public function withTags(string ...$tags): static;
    public function scope(CacheScope $scope): static;
}
