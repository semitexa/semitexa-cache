<?php
declare(strict_types=1);
namespace Semitexa\Cache\Contract;

use Semitexa\Cache\Internal\CacheNamespace;
use Semitexa\Cache\Internal\ResolvedCacheKey;
use Semitexa\Cache\Internal\TagSet;

interface TagIndexInterface
{
    public function attach(ResolvedCacheKey $key, TagSet $tags): void;
    public function detach(ResolvedCacheKey $key, TagSet $tags): void;
    public function flush(CacheNamespace $namespace, TagSet $tags): int;
    public function supportsNamespaceFlush(): bool;
}
