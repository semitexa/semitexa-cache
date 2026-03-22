<?php
declare(strict_types=1);
namespace Semitexa\Cache\Service;

use Semitexa\Cache\Contract\CacheManagerInterface;
use Semitexa\Cache\Enum\CacheScope;

/**
 * Immutable scoped view over a root CacheManager.
 * Created by CacheManager::withNamespace(), withTags(), or scope().
 * All operations are delegated to the root manager with the frozen context applied.
 */
final class ScopedCacheManager implements CacheManagerInterface
{
    /** @param list<string> $tags */
    public function __construct(
        private readonly CacheManager $root,
        private readonly string $namespace = '',
        private readonly CacheScope $scope = CacheScope::Tenant,
        private readonly array $tags = [],
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->root->doGet($this->namespace, $this->scope, $key, $default);
    }

    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void
    {
        $this->root->doPut(
            namespace: $this->namespace,
            scope: $this->scope,
            extraTags: $this->tags,
            key: $key,
            value: $value,
            ttlSeconds: $ttlSeconds,
            tags: $tags,
        );
    }

    public function remember(string $key, callable $resolver, ?int $ttlSeconds = null, array $tags = []): mixed
    {
        $existing = $this->get($key);
        if ($existing !== null) {
            return $existing;
        }
        $value = $resolver();
        $this->put($key, $value, $ttlSeconds, $tags);
        return $value;
    }

    public function forget(string $key): void
    {
        $this->root->doForget($this->namespace, $this->scope, $key);
    }

    public function flushTags(string ...$tags): int
    {
        $merged = array_values(array_unique(array_merge($this->tags, array_values($tags))));
        return $this->root->doFlushTags($this->namespace, $this->scope, $merged);
    }

    public function flushNamespace(?string $namespace = null): int
    {
        $ns = $namespace ?? $this->namespace;
        return $this->root->doFlushNamespace($ns, $this->scope);
    }

    public function withNamespace(string $namespace): static
    {
        return new self(
            root: $this->root,
            namespace: $namespace,
            scope: $this->scope,
            tags: $this->tags,
        );
    }

    public function withTags(string ...$tags): static
    {
        return new self(
            root: $this->root,
            namespace: $this->namespace,
            scope: $this->scope,
            tags: array_values(array_unique(array_merge($this->tags, array_values($tags)))),
        );
    }

    public function scope(CacheScope $scope): static
    {
        return new self(
            root: $this->root,
            namespace: $this->namespace,
            scope: $scope,
            tags: $this->tags,
        );
    }
}
