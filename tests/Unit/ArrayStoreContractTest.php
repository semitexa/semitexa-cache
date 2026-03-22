<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Enum\CacheScope;
use Semitexa\Cache\Internal\CacheEntry;
use Semitexa\Cache\Internal\CacheNamespace;
use Semitexa\Cache\Internal\ResolvedCacheKey;
use Semitexa\Cache\Internal\TagSet;
use Semitexa\Cache\Serialization\CacheValueSerializer;
use Semitexa\Cache\Store\ArrayCacheStore;

final class ArrayStoreContractTest extends TestCase
{
    private ArrayCacheStore $store;

    protected function setUp(): void
    {
        $this->store = new ArrayCacheStore(new CacheValueSerializer());
    }

    private function makeKey(string $key, string $namespace = ''): ResolvedCacheKey
    {
        return new ResolvedCacheKey(
            namespace: new CacheNamespace(
                prefix: 'semitexa',
                app: 'app',
                environment: 'test',
                scope: CacheScope::Tenant,
                tenantKey: 'tenant:default',
                namespace: $namespace,
            ),
            key: $key,
        );
    }

    private function makeEntry(mixed $value, int $ttl = 300): CacheEntry
    {
        return new CacheEntry(
            value: $value,
            createdAtEpoch: time(),
            ttlSeconds: $ttl,
            format: 'json',
            tags: new TagSet([]),
        );
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        self::assertNull($this->store->get($this->makeKey('missing')));
    }

    public function testPutAndGetRoundTrip(): void
    {
        $key = $this->makeKey('my-key');
        $this->store->put($key, $this->makeEntry('hello'));
        $entry = $this->store->get($key);
        self::assertNotNull($entry);
        self::assertSame('hello', $entry->value);
    }

    public function testPutOverwritesExistingKey(): void
    {
        $key = $this->makeKey('my-key');
        $this->store->put($key, $this->makeEntry('first'));
        $this->store->put($key, $this->makeEntry('second'));
        self::assertSame('second', $this->store->get($key)?->value);
    }

    public function testForgetRemovesKey(): void
    {
        $key = $this->makeKey('my-key');
        $this->store->put($key, $this->makeEntry('val'));
        $result = $this->store->forget($key);
        self::assertTrue($result);
        self::assertNull($this->store->get($key));
    }

    public function testForgetReturnsFalseForMissingKey(): void
    {
        $result = $this->store->forget($this->makeKey('nonexistent'));
        self::assertFalse($result);
    }

    public function testExpiredEntriesAreEvictedOnGet(): void
    {
        $key = $this->makeKey('expiring');
        $entry = new CacheEntry(
            value: 'gone',
            createdAtEpoch: time() - 100,
            ttlSeconds: 50,
            format: 'json',
            tags: new TagSet([]),
        );
        $this->store->put($key, $entry);
        self::assertNull($this->store->get($key));
    }

    public function testClearNamespaceDeletesAllMatchingKeys(): void
    {
        $ns = new CacheNamespace(
            prefix: 'semitexa',
            app: 'app',
            environment: 'test',
            scope: CacheScope::Tenant,
            tenantKey: 'tenant:default',
            namespace: 'users',
        );
        $key1 = new ResolvedCacheKey(namespace: $ns, key: 'user-1');
        $key2 = new ResolvedCacheKey(namespace: $ns, key: 'user-2');
        $otherKey = $this->makeKey('other');

        $this->store->put($key1, $this->makeEntry('a'));
        $this->store->put($key2, $this->makeEntry('b'));
        $this->store->put($otherKey, $this->makeEntry('c'));

        $count = $this->store->clearNamespace($ns);

        self::assertSame(2, $count);
        self::assertNull($this->store->get($key1));
        self::assertNull($this->store->get($key2));
        self::assertNotNull($this->store->get($otherKey));
    }

    public function testSupportsTagsReturnsTrue(): void
    {
        self::assertTrue($this->store->supportsTags());
    }

    public function testDeleteByStringRemovesEntry(): void
    {
        $key = $this->makeKey('target');
        $this->store->put($key, $this->makeEntry('val'));
        $this->store->deleteByString($key->asString());
        self::assertNull($this->store->get($key));
    }

    public function testGetReturnsArrayValue(): void
    {
        $key = $this->makeKey('arr');
        $this->store->put($key, $this->makeEntry(['a' => 1]));
        self::assertSame(['a' => 1], $this->store->get($key)?->value);
    }
}
