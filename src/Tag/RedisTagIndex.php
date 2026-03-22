<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tag;

use Predis\ClientInterface;
use Semitexa\Cache\Contract\TagIndexInterface;
use Semitexa\Cache\Internal\CacheNamespace;
use Semitexa\Cache\Internal\ResolvedCacheKey;
use Semitexa\Cache\Internal\TagSet;

final class RedisTagIndex implements TagIndexInterface
{
    public function __construct(
        private readonly ClientInterface $redis,
    ) {}

    public function attach(ResolvedCacheKey $key, TagSet $tags): void
    {
        foreach ($tags->values() as $tag) {
            $tagKey = $this->tagKey($key->namespace, $tag);
            $this->redis->sadd($tagKey, [$key->asString()]);
        }
    }

    public function detach(ResolvedCacheKey $key, TagSet $tags): void
    {
        foreach ($tags->values() as $tag) {
            $tagKey = $this->tagKey($key->namespace, $tag);
            $this->redis->srem($tagKey, $key->asString());
        }
    }

    public function flush(CacheNamespace $namespace, TagSet $tags): int
    {
        $count = 0;

        foreach ($tags->values() as $tag) {
            $tagKey = $this->tagKey($namespace, $tag);
            $members = $this->redis->smembers($tagKey);

            if (!empty($members)) {
                $this->redis->del($members);
                $count += count($members);
            }
            $this->redis->del([$tagKey]);
        }

        return $count;
    }

    public function supportsNamespaceFlush(): bool
    {
        return true;
    }

    private function tagKey(CacheNamespace $namespace, string $tag): string
    {
        return $namespace->tagKeyPrefix() . $tag;
    }
}
