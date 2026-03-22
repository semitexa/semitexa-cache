<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tag;

use Semitexa\Cache\Contract\TagIndexInterface;
use Semitexa\Cache\Internal\CacheNamespace;
use Semitexa\Cache\Internal\ResolvedCacheKey;
use Semitexa\Cache\Internal\TagSet;

/**
 * In-memory tag index for array driver. Development and test use only.
 * Does not persist across requests or workers.
 */
final class ArrayTagIndex implements TagIndexInterface
{
    /** @var array<string, list<string>> tag => list of resolved key strings */
    private array $index = [];

    /**
     * @param \Closure(string): void $deleteByString Callback to delete a store entry by raw key string
     */
    public function __construct(
        private readonly \Closure $deleteByString,
    ) {}

    public function attach(ResolvedCacheKey $key, TagSet $tags): void
    {
        foreach ($tags->values() as $tag) {
            $this->index[$tag][] = $key->asString();
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
            foreach ($this->index[$tag] ?? [] as $keyStr) {
                if (str_starts_with($keyStr, $prefix)) {
                    ($this->deleteByString)($keyStr);
                    $count++;
                }
            }
            unset($this->index[$tag]);
        }

        return $count;
    }

    public function supportsNamespaceFlush(): bool
    {
        return true;
    }
}
