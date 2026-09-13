<?php
declare(strict_types=1);
namespace Semitexa\Cache\Domain\Contract;

use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Domain\Model\TagSet;

interface TagIndexInterface
{
    /**
     * Record that $key carries $tags.
     *
     * An index that keeps its own keys also needs the entry's lifetime, and
     * needs to hear about namespace flushes. Both are asked for through
     * {@see ExternalTagIndexInterface} rather than through this method, whose
     * signature is published and cannot widen without breaking every
     * implementation of it on upgrade.
     */
    public function attach(ResolvedCacheKey $key, TagSet $tags): void;
    public function detach(ResolvedCacheKey $key, TagSet $tags): void;
    public function flush(CacheNamespace $namespace, TagSet $tags): int;
    public function supportsNamespaceFlush(): bool;
}
