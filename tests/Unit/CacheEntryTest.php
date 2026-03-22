<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Internal\CacheEntry;
use Semitexa\Cache\Internal\TagSet;

final class CacheEntryTest extends TestCase
{
    private function makeEntry(
        mixed $value = 'test',
        int $createdAtEpoch = 1000,
        ?int $ttlSeconds = 300,
        string $format = 'json',
        TagSet $tags = new TagSet([]),
    ): CacheEntry {
        return new CacheEntry(
            value: $value,
            createdAtEpoch: $createdAtEpoch,
            ttlSeconds: $ttlSeconds,
            format: $format,
            tags: $tags,
        );
    }

    public function testExpiresAtEpochWithTtl(): void
    {
        $entry = $this->makeEntry(createdAtEpoch: 1000, ttlSeconds: 300);
        self::assertSame(1300, $entry->expiresAtEpoch());
    }

    public function testExpiresAtEpochWithNullTtl(): void
    {
        $entry = $this->makeEntry(ttlSeconds: null);
        self::assertNull($entry->expiresAtEpoch());
    }

    public function testExpiresAtEpochWithZeroTtl(): void
    {
        $entry = $this->makeEntry(ttlSeconds: 0);
        self::assertNull($entry->expiresAtEpoch());
    }

    public function testIsExpiredAtReturnsTrueWhenExpired(): void
    {
        $entry = $this->makeEntry(createdAtEpoch: 1000, ttlSeconds: 300);
        self::assertTrue($entry->isExpiredAt(1300));
        self::assertTrue($entry->isExpiredAt(1500));
    }

    public function testIsExpiredAtReturnsFalseWhenNotExpired(): void
    {
        $entry = $this->makeEntry(createdAtEpoch: 1000, ttlSeconds: 300);
        self::assertFalse($entry->isExpiredAt(999));
        self::assertFalse($entry->isExpiredAt(1000));
        self::assertFalse($entry->isExpiredAt(1299));
    }

    public function testIsExpiredAtReturnsFalseForNullTtl(): void
    {
        $entry = $this->makeEntry(ttlSeconds: null);
        self::assertFalse($entry->isExpiredAt(PHP_INT_MAX));
    }

    public function testIsExpiredAtReturnsFalseForZeroTtl(): void
    {
        $entry = $this->makeEntry(ttlSeconds: 0);
        self::assertFalse($entry->isExpiredAt(PHP_INT_MAX));
    }

    public function testEntryStoresValueTypes(): void
    {
        $arrayEntry = $this->makeEntry(value: ['a' => 1, 'b' => 2]);
        self::assertSame(['a' => 1, 'b' => 2], $arrayEntry->value);

        $nullEntry = $this->makeEntry(value: null);
        self::assertNull($nullEntry->value);

        $intEntry = $this->makeEntry(value: 42);
        self::assertSame(42, $intEntry->value);
    }

    public function testTagsAreStoredOnEntry(): void
    {
        $tags = new TagSet(['user', 'product']);
        $entry = $this->makeEntry(tags: $tags);
        self::assertSame(['product', 'user'], $entry->tags->values());
    }
}
