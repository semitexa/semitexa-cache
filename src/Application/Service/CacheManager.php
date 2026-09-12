<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Service;

use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Contract\CacheManagerInterface;
use Semitexa\Cache\Domain\Contract\CacheNamespaceResolverInterface;
use Semitexa\Cache\Domain\Contract\CacheStoreInterface;
use Semitexa\Cache\Domain\Contract\TagIndexInterface;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\Cache\Domain\Model\CacheEntry;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Domain\Model\TagSet;
use Semitexa\Cache\Application\Service\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\ArrayCacheStore;
use Semitexa\Cache\Application\Service\RedisCacheStore;
use Semitexa\Cache\Application\Service\ArrayTagIndex;
use Semitexa\Cache\Application\Service\NullTagIndex;
use Semitexa\Cache\Application\Service\RedisTagIndex;
use Semitexa\Core\Attribute\Config;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Environment;
use Semitexa\Core\Redis\RedisConnectionPool;
use Semitexa\Core\Redis\RedisSharedPool;

#[SatisfiesServiceContract(of: CacheManagerInterface::class)]
final class CacheManager implements CacheManagerInterface
{
    use SingleFlightRemember;

    #[Config(env: 'CACHE_DRIVER', default: 'array')]
    protected string $driver;

    #[Config(env: 'CACHE_PREFIX', default: 'semitexa')]
    protected string $prefix;

    #[Config(env: 'CACHE_APP', default: 'app')]
    protected string $app;

    #[Config(env: 'CACHE_ENV', default: 'prod')]
    protected string $env;

    #[Config(env: 'CACHE_DEFAULT_TTL', default: 300)]
    protected int $defaultTtl;

    #[Config(env: 'CACHE_ALLOW_FOREVER', default: false)]
    protected bool $allowForever;

    #[Config(env: 'CACHE_TAGS_ENABLED', default: true)]
    protected bool $tagsEnabled;

    #[Config(env: 'REDIS_HOST', default: '127.0.0.1')]
    protected string $redisHost;

    #[Config(env: 'REDIS_PORT', default: 6379)]
    protected int $redisPort;

    #[Config(env: 'REDIS_SCHEME', default: 'tcp')]
    protected string $redisScheme;

    #[Config(env: 'REDIS_PASSWORD', default: '')]
    protected string $redisPassword;

    /**
     * The worker's one pool owner. Non-nullable because it is registered
     * unconditionally — it answers whether Redis is configured, rather than
     * being absent when it is not. That is what lets the cache stop opening a
     * pool of its own without tripping the nullable-injection rule.
     */
    #[InjectAsReadonly]
    protected RedisSharedPool $sharedRedis;

    private ?CacheConfig $config = null;
    private ?CacheStoreInterface $store = null;
    private ?TagIndexInterface $tagIndex = null;
    private ?CacheNamespaceResolverInterface $namespaceResolver = null;
    private ?RedisConnectionPool $redisPool = null;

    public static function withDependencies(
        CacheConfig $config,
        CacheStoreInterface $store,
        TagIndexInterface $tagIndex,
        CacheNamespaceResolverInterface $namespaceResolver,
    ): self {
        $manager = new self();
        $manager->config = $config;
        $manager->store = $store;
        $manager->tagIndex = $tagIndex;
        $manager->namespaceResolver = $namespaceResolver;
        return $manager;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->boot();
        $resolved = $this->resolveKey($key, '');
        $entry = $this->store->get($resolved);
        return $entry !== null ? $entry->value : $default;
    }

    public function put(string $key, mixed $value, ?int $ttlSeconds = null, array $tags = []): void
    {
        $this->boot();
        $this->doPut(namespace: '', scope: CacheScope::Tenant, extraTags: [], key: $key, value: $value, ttlSeconds: $ttlSeconds, tags: $tags);
    }


    public function forget(string $key): void
    {
        $this->boot();
        $this->doForget(namespace: '', scope: CacheScope::Tenant, key: $key);
    }

    public function flushTags(string ...$tags): int
    {
        $this->boot();
        return $this->doFlushTags(namespace: '', scope: CacheScope::Tenant, tags: $tags);
    }

    public function flushNamespace(?string $namespace = null): int
    {
        $this->boot();
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
        $this->boot();
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
            $this->tagIndex->attach($resolved, $tagSet, $entry->ttlSeconds);
        }
    }

    /**
     * Internal: perform a get with explicit context (used by ScopedCacheManager).
     */
    public function doGet(string $namespace, CacheScope $scope, string $key, mixed $default): mixed
    {
        $this->boot();
        $resolved = $this->resolveKey($key, $namespace, $scope);
        $entry = $this->store->get($resolved);
        return $entry !== null ? $entry->value : $default;
    }

    /**
     * Internal: perform a forget with explicit context (used by ScopedCacheManager).
     */
    public function doForget(string $namespace, CacheScope $scope, string $key): void
    {
        $this->boot();
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
        $this->boot();
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
        $this->boot();
        $ns = $this->namespaceResolver->resolve($namespace, $scope);
        return $this->store->clearNamespace($ns);
    }

    private function boot(): void
    {
        if ($this->config === null) {
            $this->config = $this->buildConfig();
        }

        if ($this->namespaceResolver === null) {
            $this->namespaceResolver = new DefaultCacheNamespaceResolver($this->config);
        }

        if ($this->store === null) {
            $serializer = new CacheValueSerializer();
            $this->store = $this->createStore($serializer);
        }

        if ($this->tagIndex === null) {
            $this->tagIndex = $this->createTagIndex();
        }
    }

    private function buildConfig(): CacheConfig
    {
        if (!isset(
            $this->driver,
            $this->prefix,
            $this->app,
            $this->env,
            $this->defaultTtl,
            $this->allowForever,
            $this->tagsEnabled,
            $this->redisHost,
            $this->redisPort,
            $this->redisScheme,
            $this->redisPassword,
        )) {
            return CacheConfig::fromEnvironment();
        }

        $resolvedApp = Environment::getEnvValue('CACHE_APP', Environment::getEnvValue('APP_NAME', $this->app));
        $resolvedEnv = Environment::getEnvValue('CACHE_ENV', Environment::getEnvValue('APP_ENV', $this->env));

        return CacheConfig::validate(new CacheConfig(
            driver: $this->driver,
            prefix: $this->prefix,
            app: $resolvedApp,
            env: $resolvedEnv,
            defaultTtl: $this->defaultTtl,
            allowForever: $this->allowForever,
            tagsEnabled: $this->tagsEnabled,
            redisHost: $this->redisHost,
            redisPort: $this->redisPort,
            redisScheme: $this->redisScheme,
            redisPassword: $this->redisPassword !== '' ? $this->redisPassword : null,
        ));
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
            'redis' => new RedisCacheStore($serializer, config: $this->config, pool: $this->redisPool()),
            default => new ArrayCacheStore($serializer),
        };
    }

    /**
     * One pool for the store and the tag index — never one each, and never a
     * bare client, which is coroutine-fatal under Swoole.
     *
     * The worker's shared pool is used whenever Redis is configured, so the
     * cache adds no connections of its own. MEASURED before that on the dev
     * stack 2026-09-12: 64 connections across 4 workers, 64 of 66 idle over an
     * hour with no load — a second pool here would have been 8 more per worker
     * for traffic the first one was plainly not carrying.
     *
     * Sharing cannot starve anyone: RedisConnectionPool::get() waits at most 2s
     * and then hands out an over-cap client, so a long flush holding a borrowed
     * connection costs an extra socket, not a stalled coroutine.
     *
     * The fallback covers two cases. One: this manager was built outside the
     * container — `new CacheManager()` in the console commands — so the typed
     * property was never injected and `isset` is false. Two: the container
     * calls Redis "configured" only when REDIS_HOST is set, while this class
     * defaults it to 127.0.0.1, so a project running CACHE_DRIVER=redis with no
     * REDIS_HOST still gets a working cache, at the cost of its own pool.
     */
    private function redisPool(): RedisConnectionPool
    {
        if (isset($this->sharedRedis) && $this->sharedRedis->isConfigured()) {
            return $this->sharedRedis->pool();
        }

        return $this->redisPool ??= new RedisConnectionPool($this->config->redisPoolSize, [
            'scheme' => $this->config->redisScheme,
            'host' => $this->config->redisHost,
            'port' => $this->config->redisPort,
            'password' => $this->config->redisPassword ?? '',
        ]);
    }

    private function createTagIndex(): TagIndexInterface
    {
        if (!$this->config->tagsEnabled) {
            return new NullTagIndex();
        }

        return match ($this->config->driver) {
            'redis' => new RedisTagIndex(new CacheValueSerializer(), config: $this->config, pool: $this->redisPool()),
            default => $this->createArrayTagIndex(),
        };
    }

    private function createArrayTagIndex(): ArrayTagIndex
    {
        /** @var ArrayCacheStore $arrayStore */
        $arrayStore = $this->store;
        return new ArrayTagIndex(
            deleteByString: static fn(string $k) => $arrayStore->deleteByString($k),
            tagsOfKey: static fn(string $k) => $arrayStore->getByString($k)?->tags,
        );
    }

}
