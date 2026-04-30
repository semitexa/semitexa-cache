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
    public function clearNamespace(CacheNamespace $namespace): int;
    public function supportsTags(): bool;
}
