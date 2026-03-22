<?php
declare(strict_types=1);
namespace Semitexa\Cache\Store;

use Semitexa\Cache\Contract\CacheStoreInterface;
use Semitexa\Cache\Internal\CacheEntry;
use Semitexa\Cache\Internal\CacheNamespace;
use Semitexa\Cache\Internal\ResolvedCacheKey;
use Semitexa\Cache\Serialization\CacheValueSerializer;

final class ArrayCacheStore implements CacheStoreInterface
{
    /** @var array<string, string> key => serialized envelope */
    private array $store = [];

    public function __construct(
        private readonly CacheValueSerializer $serializer,
    ) {}

    public function get(ResolvedCacheKey $key): ?CacheEntry
    {
        $raw = $this->store[$key->asString()] ?? null;
        if ($raw === null) {
            return null;
        }

        try {
            $entry = $this->serializer->decode($raw);
        } catch (\Throwable) {
            unset($this->store[$key->asString()]);
            return null;
        }

        if ($entry->isExpiredAt(time())) {
            unset($this->store[$key->asString()]);
            return null;
        }

        return $entry;
    }

    public function put(ResolvedCacheKey $key, CacheEntry $entry): void
    {
        $this->store[$key->asString()] = $this->serializer->encode($entry);
    }

    public function forget(ResolvedCacheKey $key): bool
    {
        if (!isset($this->store[$key->asString()])) {
            return false;
        }
        unset($this->store[$key->asString()]);
        return true;
    }

    public function clearNamespace(CacheNamespace $namespace): int
    {
        $prefix = $namespace->asPrefix();
        $count = 0;
        foreach (array_keys($this->store) as $k) {
            if (str_starts_with($k, $prefix)) {
                unset($this->store[$k]);
                $count++;
            }
        }
        return $count;
    }

    public function supportsTags(): bool
    {
        return true;
    }

    public function deleteByString(string $keyString): void
    {
        unset($this->store[$keyString]);
    }
}
