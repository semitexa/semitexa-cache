<?php
declare(strict_types=1);
namespace Semitexa\Cache\Domain\Model;

final readonly class ResolvedCacheKey
{
    public function __construct(
        public CacheNamespace $namespace,
        public string $key,
    ) {
        if ($key === '') {
            throw new \InvalidArgumentException('Cache key must not be empty.');
        }

        // The one byte a key may not contain, because it is what separates a
        // prefix from a key: allow it here and a caller could spell another
        // namespace's entry, which is the collision this layout removes. See
        // CacheNamespace::KEY_BOUNDARY.
        if (str_contains($key, CacheNamespace::KEY_BOUNDARY)) {
            throw new \InvalidArgumentException('Cache key must not contain a NUL byte; it separates the namespace from the key.');
        }
    }

    public function asString(): string
    {
        return $this->namespace->asPrefix() . $this->key;
    }
}
