<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tag;

use Semitexa\Cache\Contract\TagIndexInterface;
use Semitexa\Cache\Internal\CacheNamespace;
use Semitexa\Cache\Internal\ResolvedCacheKey;
use Semitexa\Cache\Internal\TagSet;

final class NullTagIndex implements TagIndexInterface
{
    public function attach(ResolvedCacheKey $key, TagSet $tags): void {}

    public function detach(ResolvedCacheKey $key, TagSet $tags): void {}

    public function flush(CacheNamespace $namespace, TagSet $tags): int
    {
        return 0;
    }

    public function supportsNamespaceFlush(): bool
    {
        return false;
    }
}
