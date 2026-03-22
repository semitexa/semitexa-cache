<?php
declare(strict_types=1);
namespace Semitexa\Cache\Contract;

use Semitexa\Cache\Internal\CacheEntry;
use Semitexa\Cache\Internal\CacheNamespace;
use Semitexa\Cache\Internal\ResolvedCacheKey;

interface CacheStoreInterface
{
    public function get(ResolvedCacheKey $key): ?CacheEntry;
    public function put(ResolvedCacheKey $key, CacheEntry $entry): void;
    public function forget(ResolvedCacheKey $key): bool;
    public function clearNamespace(CacheNamespace $namespace): int;
    public function supportsTags(): bool;
}
