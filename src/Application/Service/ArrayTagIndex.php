<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Service;

use Semitexa\Cache\Domain\Contract\TagIndexInterface;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Domain\Model\TagSet;

/**
 * In-memory tag index for array driver. Development and test use only.
 * Does not persist across requests or workers.
 *
 * The index is a list of CANDIDATES, not a statement of fact. Membership is
 * written on put and never rewritten, so it goes stale the moment an entry is
 * overwritten with a different tag set or dies of its own TTL. Deletion
 * therefore re-reads the entry and removes it only if it still carries the tag
 * being flushed; a member that no longer qualifies is pruned from the index
 * instead of being obeyed.
 */
final class ArrayTagIndex implements TagIndexInterface
{
    /** @var array<string, list<string>> tag => list of resolved key strings */
    private array $index = [];

    /**
     * @param \Closure(string): void $deleteByString Callback to delete a store entry by raw key string
     * @param \Closure(string): ?TagSet $tagsOfKey Callback returning the tags an entry carries now, or null if it is gone
     */
    public function __construct(
        private readonly \Closure $deleteByString,
        private readonly \Closure $tagsOfKey,
    ) {}

    /**
     * $ttlSeconds is ignored: this index lives in the process, not in a store
     * that outlives it, so it cannot leak past the worker. Its members are
     * pruned when a tag is flushed, which is the only growth bound it needs.
     */
    public function attach(ResolvedCacheKey $key, TagSet $tags, ?int $ttlSeconds = null): void
    {
        $keyStr = $key->asString();
        foreach ($tags->values() as $tag) {
            // Re-attaching the same key under the same tag is a no-op: a set,
            // not a log. Without this a key rewritten N times is counted N
            // times by flush() and walked N times on every invalidation.
            if (in_array($keyStr, $this->index[$tag] ?? [], true)) {
                continue;
            }
            $this->index[$tag][] = $keyStr;
        }
    }

    public function detach(ResolvedCacheKey $key, TagSet $tags): void
    {
        $keyStr = $key->asString();
        foreach ($tags->values() as $tag) {
            if (isset($this->index[$tag])) {
                $this->index[$tag] = array_values(
                    array_filter($this->index[$tag], static fn(string $k) => $k !== $keyStr)
                );
            }
        }
    }

    public function flush(CacheNamespace $namespace, TagSet $tags): int
    {
        $prefix = $namespace->asPrefix();
        $count = 0;

        foreach ($tags->values() as $tag) {
            $members = $this->index[$tag] ?? [];
            if ($members === []) {
                continue;
            }

            $kept = [];
            foreach ($members as $keyStr) {
                if (!str_starts_with($keyStr, $prefix)) {
                    // Another namespace's key under the same tag. Not ours to
                    // delete, and not ours to forget either: dropping it here
                    // is what left that namespace unable to flush its own tag.
                    $kept[] = $keyStr;
                    continue;
                }

                $current = ($this->tagsOfKey)($keyStr);
                if ($current === null || !in_array($tag, $current->values(), true)) {
                    // Gone, or rewritten without this tag. Prune the stale
                    // member; the value itself is none of this tag's business.
                    continue;
                }

                ($this->deleteByString)($keyStr);
                $count++;
            }

            if ($kept === []) {
                unset($this->index[$tag]);
            } else {
                $this->index[$tag] = $kept;
            }
        }

        return $count;
    }

    public function supportsNamespaceFlush(): bool
    {
        return true;
    }
}
