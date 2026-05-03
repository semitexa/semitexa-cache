<?php
declare(strict_types=1);
namespace Semitexa\Cache\Domain\Contract;

use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Domain\Model\TagSet;

interface TagIndexInterface
{
    public function attach(ResolvedCacheKey $key, TagSet $tags): void;
    public function detach(ResolvedCacheKey $key, TagSet $tags): void;
    public function flush(CacheNamespace $namespace, TagSet $tags): int;
    public function supportsNamespaceFlush(): bool;
}
