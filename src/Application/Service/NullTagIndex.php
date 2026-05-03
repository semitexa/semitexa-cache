<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Service;

use Semitexa\Cache\Domain\Contract\TagIndexInterface;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Domain\Model\TagSet;

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
