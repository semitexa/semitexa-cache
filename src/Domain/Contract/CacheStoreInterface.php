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

    /**
     * Store $entry only when $key holds no live entry, in one step the store
     * makes atomic. True when this call stored it: of callers adding one key at
     * once, one gets true. The primitive a claim or a lock needs; get() then
     * put() let two workers both read nothing and both write.
     */
    public function add(ResolvedCacheKey $key, CacheEntry $entry): bool;

    /**
     * Remove $key only while its live entry's value is $expected (===), in one
     * step the store makes atomic; true when this call removed it. A lock's
     * holder releases with it: forget() after a check removed a lock someone
     * else had taken in between.
     */
    public function forgetIfValue(ResolvedCacheKey $key, mixed $expected): bool;
    public function clearNamespace(CacheNamespace $namespace): int;
    public function supportsTags(): bool;
}
