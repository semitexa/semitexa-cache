<?php
declare(strict_types=1);
namespace Semitexa\Cache\Domain\Contract;

use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Domain\Model\TagSet;

/**
 * A tag index that keeps its own keys, outside the entries it names.
 *
 * Two things follow from living outside, and neither can be asked of a plain
 * {@see TagIndexInterface}:
 *
 * - it needs the entry's lifetime, because a set that dies before its members
 *   leaves them unflushable and one that never dies grows forever;
 * - it must be told when a namespace is cleared, because clearing the entries
 *   no longer touches the index that named them.
 *
 * Declared as a separate capability rather than added to TagIndexInterface:
 * that interface is published, and widening `attach()` on it makes every
 * downstream implementation signature-incompatible — a fatal error when PHP
 * loads the class, on upgrade, before any of it runs. Raised in review of
 * cache#19.
 *
 * An index that does not implement this is called through the old contract and
 * keeps working exactly as before.
 */
interface ExternalTagIndexInterface extends TagIndexInterface
{
    /**
     * Record that $key carries $tags, and that the entry itself lives for
     * $ttlSeconds — null or 0 meaning it never expires.
     */
    public function attachWithLifetime(ResolvedCacheKey $key, TagSet $tags, ?int $ttlSeconds): void;

    /**
     * Drop everything this index holds for $namespace. Returns how many of its
     * own keys it removed, which is not a count of cache entries.
     */
    public function clearNamespace(CacheNamespace $namespace): int;
}
