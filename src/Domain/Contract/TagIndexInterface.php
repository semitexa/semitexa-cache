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
     * $ttlSeconds is the entry's own lifetime — null or 0 meaning it never
     * expires. An index that persists outside the entry needs it: a tag set
     * that dies before its members leaves them unflushable, and one that never
     * dies grows forever.
     */
    public function attach(ResolvedCacheKey $key, TagSet $tags, ?int $ttlSeconds = null): void;
    public function detach(ResolvedCacheKey $key, TagSet $tags): void;
    public function flush(CacheNamespace $namespace, TagSet $tags): int;
    public function supportsNamespaceFlush(): bool;
}
