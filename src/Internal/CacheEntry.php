<?php
declare(strict_types=1);
namespace Semitexa\Cache\Internal;

final readonly class CacheEntry
{
    public function __construct(
        public mixed $value,
        public int $createdAtEpoch,
        public ?int $ttlSeconds,
        public string $format,
        public TagSet $tags,
    ) {}

    public function expiresAtEpoch(): ?int
    {
        if ($this->ttlSeconds === null || $this->ttlSeconds === 0) {
            return null;
        }
        return $this->createdAtEpoch + $this->ttlSeconds;
    }

    public function isExpiredAt(int $nowEpoch): bool
    {
        $expiresAt = $this->expiresAtEpoch();
        if ($expiresAt === null) {
            return false;
        }
        return $nowEpoch >= $expiresAt;
    }
}
