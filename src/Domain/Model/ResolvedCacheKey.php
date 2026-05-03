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
    }

    public function asString(): string
    {
        return $this->namespace->asPrefix() . $this->key;
    }
}
