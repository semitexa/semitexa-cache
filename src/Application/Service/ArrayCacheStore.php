<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Service;

use Semitexa\Cache\Domain\Contract\CacheStoreInterface;
use Semitexa\Cache\Domain\Model\CacheEntry;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Application\Service\CacheValueSerializer;

final class ArrayCacheStore implements CacheStoreInterface
{
    /** @var array<string, string> key => serialized envelope */
    private array $store = [];

    public function __construct(
        private readonly CacheValueSerializer $serializer,
    ) {}

    public function get(ResolvedCacheKey $key): ?CacheEntry
    {
        return $this->getByString($key->asString());
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

    /**
     * Read an entry by its already-resolved key string. The tag index holds raw
     * key strings rather than {@see ResolvedCacheKey} objects, and it must be
     * able to ask what an entry carries RIGHT NOW before deleting it — the same
     * seam as {@see deleteByString}, in the reading direction.
     */
    public function getByString(string $keyString): ?CacheEntry
    {
        $raw = $this->store[$keyString] ?? null;
        if ($raw === null) {
            return null;
        }

        try {
            $entry = $this->serializer->decode($raw);
        } catch (\Throwable) {
            unset($this->store[$keyString]);
            return null;
        }

        if ($entry->isExpiredAt(time())) {
            unset($this->store[$keyString]);
            return null;
        }

        return $entry;
    }
}
