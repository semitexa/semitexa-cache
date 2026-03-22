<?php
declare(strict_types=1);
namespace Semitexa\Cache\Service;

use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Contract\CacheManagerInterface;
use Semitexa\Cache\Contract\CacheNamespaceResolverInterface;
use Semitexa\Cache\Contract\CacheStoreInterface;
use Semitexa\Cache\Contract\TagIndexInterface;
use Semitexa\Cache\Enum\CacheScope;
use Semitexa\Cache\Internal\CacheEntry;
use Semitexa\Cache\Internal\CacheNamespace;
use Semitexa\Cache\Internal\ResolvedCacheKey;
use Semitexa\Cache\Internal\TagSet;
use Semitexa\Cache\Namespace\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Serialization\CacheValueSerializer;
use Semitexa\Cache\Store\ArrayCacheStore;
use Semitexa\Cache\Store\RedisCacheStore;
use Semitexa\Cache\Tag\ArrayTagIndex;
use Semitexa\Cache\Tag\NullTagIndex;
use Semitexa\Cache\Tag\RedisTagIndex;
use Semitexa\Core\Attributes\SatisfiesServiceContract;

#[SatisfiesServiceContract(of: CacheManagerInterface::class)]
final class CacheManager implements CacheManagerInterface
{
    private readonly CacheConfig $config;
    private readonly CacheStoreInterface $store;
    private readonly TagIndexInterface $tagIndex;
    private readonly CacheNamespaceResolverInterface $namespaceResolver;

    public function __construct(
        ?CacheConfig $config = null,
        ?CacheStoreInterface $store = null,
        ?TagIndexInterface $tagIndex = null,
        ?CacheNamespaceResolverInterface $namespaceResolver = null,
    ) {
        $this->config = $config ?? CacheConfig::fromEnvironment();
        $serializer = new CacheValueSerializer();
        $this->store = $store ?? $this->createStore($serializer);
        $this->tagIndex = $tagIndex ?? $this->createTagIndex();
        $this->namespaceResolver = $namespaceResolver ?? new DefaultCacheNamespaceResolver($this->config);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $resolved = $this->resolveKey($key, '');
        $entry = $this->store->get($resolved);
        return $entry !== null ? $entry->value : $default;
    }

    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void
    {
        $this->doPut(namespace: '', scope: CacheScope::Tenant, extraTags: [], key: $key, value: $value, ttlSeconds: $ttlSeconds, tags: $tags);
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
        $this->doForget(namespace: '', scope: CacheScope::Tenant, key: $key);
    }

    public function flushTags(string ...$tags): int
    {
        return $this->doFlushTags(namespace: '', scope: CacheScope::Tenant, tags: $tags);
    }

    public function flushNamespace(?string $namespace = null): int
    {
        $ns = $this->namespaceResolver->resolve($namespace ?? '', CacheScope::Tenant);
        return $this->store->clearNamespace($ns);
    }

    public function withNamespace(string $namespace): ScopedCacheManager
    {
        return new ScopedCacheManager(
            root: $this,
            namespace: $namespace,
            scope: CacheScope::Tenant,
            tags: [],
        );
    }

    public function withTags(string ...$tags): ScopedCacheManager
    {
        return new ScopedCacheManager(
            root: $this,
            namespace: '',
            scope: CacheScope::Tenant,
            tags: array_values($tags),
        );
    }

    public function scope(CacheScope $scope): ScopedCacheManager
    {
        return new ScopedCacheManager(
            root: $this,
            namespace: '',
            scope: $scope,
            tags: [],
        );
    }

    /**
     * Internal: perform a put with explicit context (used by ScopedCacheManager).
     * @param list<string> $extraTags
     * @param list<string> $tags
     */
    public function doPut(
        string $namespace,
        CacheScope $scope,
        array $extraTags,
        string $key,
        mixed $value,
        ?int $ttlSeconds,
        array $tags,
    ): void {
        if ($ttlSeconds !== null && $ttlSeconds < 0) {
            throw new \InvalidArgumentException('TTL must not be negative.');
        }
        if ($ttlSeconds === 0 && !$this->config->allowForever) {
            throw new \InvalidArgumentException('TTL=0 (forever) is not allowed. Set CACHE_ALLOW_FOREVER=1 to enable.');
        }

        $resolved = $this->resolveKey($key, $namespace, $scope);
        $allTags = array_values(array_unique(array_merge($extraTags, $tags)));
        $tagSet = new TagSet($allTags);

        $entry = new CacheEntry(
            value: $value,
            createdAtEpoch: time(),
            ttlSeconds: $ttlSeconds ?? $this->config->defaultTtl,
            format: 'json',
            tags: $tagSet,
        );

        $this->store->put($resolved, $entry);

        if (!$tagSet->isEmpty() && $this->config->tagsEnabled) {
            $this->tagIndex->attach($resolved, $tagSet);
        }
    }

    /**
     * Internal: perform a get with explicit context (used by ScopedCacheManager).
     */
    public function doGet(string $namespace, CacheScope $scope, string $key, mixed $default): mixed
    {
        $resolved = $this->resolveKey($key, $namespace, $scope);
        $entry = $this->store->get($resolved);
        return $entry !== null ? $entry->value : $default;
    }

    /**
     * Internal: perform a forget with explicit context (used by ScopedCacheManager).
     */
    public function doForget(string $namespace, CacheScope $scope, string $key): void
    {
        $resolved = $this->resolveKey($key, $namespace, $scope);
        $entry = $this->store->get($resolved);
        if ($entry !== null && !$entry->tags->isEmpty() && $this->config->tagsEnabled) {
            $this->tagIndex->detach($resolved, $entry->tags);
        }
        $this->store->forget($resolved);
    }

    /**
     * Internal: flush tags with explicit context (used by ScopedCacheManager).
     * @param list<string> $tags
     */
    public function doFlushTags(string $namespace, CacheScope $scope, array $tags): int
    {
        if (!$this->config->tagsEnabled) {
            return 0;
        }
        $ns = $this->namespaceResolver->resolve($namespace, $scope);
        return $this->tagIndex->flush($ns, new TagSet($tags));
    }

    /**
     * Internal: flush namespace with explicit context (used by ScopedCacheManager).
     */
    public function doFlushNamespace(string $namespace, CacheScope $scope): int
    {
        $ns = $this->namespaceResolver->resolve($namespace, $scope);
        return $this->store->clearNamespace($ns);
    }

    private function resolveKey(string $key, string $namespace, CacheScope $scope = CacheScope::Tenant): ResolvedCacheKey
    {
        return new ResolvedCacheKey(
            namespace: $this->namespaceResolver->resolve($namespace, $scope),
            key: $key,
        );
    }

    private function createStore(CacheValueSerializer $serializer): CacheStoreInterface
    {
        return match ($this->config->driver) {
            'redis' => new RedisCacheStore($serializer, config: $this->config),
            default => new ArrayCacheStore($serializer),
        };
    }

    private function createTagIndex(): TagIndexInterface
    {
        if (!$this->config->tagsEnabled) {
            return new NullTagIndex();
        }

        return match ($this->config->driver) {
            'redis' => new RedisTagIndex($this->createRedisClient()),
            default => $this->createArrayTagIndex(),
        };
    }

    private function createArrayTagIndex(): ArrayTagIndex
    {
        /** @var ArrayCacheStore $arrayStore */
        $arrayStore = $this->store;
        return new ArrayTagIndex(
            deleteByString: static fn(string $k) => $arrayStore->deleteByString($k),
        );
    }

    private function createRedisClient(): \Predis\ClientInterface
    {
        $params = [
            'scheme' => $this->config->redisScheme,
            'host' => $this->config->redisHost,
            'port' => $this->config->redisPort,
        ];
        if ($this->config->redisPassword !== null) {
            $params['password'] = $this->config->redisPassword;
        }
        return new \Predis\Client($params);
    }
}
