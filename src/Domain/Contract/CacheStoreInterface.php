<?php
declare(strict_types=1);
namespace Semitexa\Cache\Domain\Contract;

use Semitexa\Cache\Domain\Model\CacheEntry;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;

interface CacheStoreInterface
{
    public function get(ResolvedCacheKey $key): ?CacheEntry;
    public function put(ResolvedCacheKey $key, CacheEntry $entry): void;
    public function forget(ResolvedCacheKey $key): bool;

    /**
     * The entry under $key, read and removed in one step the store makes atomic,
     * or null when there was none (or it had expired). Of callers taking one key
     * at once, one gets it; a value written after the take is not removed by it.
     */
    public function take(ResolvedCacheKey $key): ?CacheEntry;
    public function clearNamespace(CacheNamespace $namespace): int;
    public function supportsTags(): bool;
}
