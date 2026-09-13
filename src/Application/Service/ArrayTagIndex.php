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
 * Keyed by the namespace's TAG KEY, not by the bare tag: the root namespace's
 * prefix is a string prefix of every named one and a cache key may contain a
 * colon, so no filtering of members by prefix can separate them. The Redis
 * index is keyed the same way, for the same reason.
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
    /** @var array<string, list<string>> namespaced tag key => list of resolved key strings */
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
            $tagKey = $key->namespace->tagKeyPrefix() . $tag;
            // Re-attaching the same key under the same tag is a no-op: a set,
            // not a log. Without this a key rewritten N times is counted N
            // times by flush() and walked N times on every invalidation.
            if (in_array($keyStr, $this->index[$tagKey] ?? [], true)) {
                continue;
            }
            $this->index[$tagKey][] = $keyStr;
        }
    }

    public function detach(ResolvedCacheKey $key, TagSet $tags): void
    {
        $keyStr = $key->asString();
        foreach ($tags->values() as $tag) {
            $tagKey = $key->namespace->tagKeyPrefix() . $tag;
            if (isset($this->index[$tagKey])) {
                $this->index[$tagKey] = array_values(
                    array_filter($this->index[$tagKey], static fn(string $k) => $k !== $keyStr)
                );
            }
        }
    }

    public function flush(CacheNamespace $namespace, TagSet $tags): int
    {
        $count = 0;

        foreach ($tags->values() as $tag) {
            $tagKey = $namespace->tagKeyPrefix() . $tag;
            $members = $this->index[$tagKey] ?? [];
            if ($members === []) {
                continue;
            }

            // Every member of this set belongs to this namespace by
            // construction, so there is nothing to filter — only to verify.
            $kept = [];
            foreach ($members as $keyStr) {
                $current = ($this->tagsOfKey)($keyStr);
                if ($current === null) {
                    // Gone already. Prune the stale member.
                    continue;
                }
                if (!in_array($tag, $current->values(), true)) {
                    // Rewritten without this tag; the value is not this tag's
                    // business, and the membership is what is out of date.
                    continue;
                }

                ($this->deleteByString)($keyStr);
                $count++;
            }

            if ($kept === []) {
                unset($this->index[$tagKey]);
            } else {
                $this->index[$tagKey] = $kept;
            }
        }

        return $count;
    }

    public function supportsNamespaceFlush(): bool
    {
        return true;
    }
}
