<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Internal\TagSet;

final class TagSetTest extends TestCase
{
    public function testEmptyTagSetIsEmpty(): void
    {
        $tags = new TagSet([]);
        self::assertTrue($tags->isEmpty());
        self::assertSame([], $tags->values());
    }

    public function testTagsAreNormalizedToLowercase(): void
    {
        $tags = new TagSet(['User', 'PRODUCT', 'Order']);
        self::assertSame(['order', 'product', 'user'], $tags->values());
    }

    public function testTagsAreTrimmed(): void
    {
        $tags = new TagSet(['  user  ', ' product ']);
        self::assertSame(['product', 'user'], $tags->values());
    }

    public function testDuplicatesAreRemoved(): void
    {
        $tags = new TagSet(['user', 'USER', 'User', 'product']);
        self::assertSame(['product', 'user'], $tags->values());
    }

    public function testTagsAreSortedAlphabetically(): void
    {
        $tags = new TagSet(['zebra', 'apple', 'mango']);
        self::assertSame(['apple', 'mango', 'zebra'], $tags->values());
    }

    public function testIsEmptyReturnsFalseForNonEmptySet(): void
    {
        $tags = new TagSet(['user']);
        self::assertFalse($tags->isEmpty());
    }

    public function testValuesReturnsListType(): void
    {
        $tags = new TagSet(['b', 'a']);
        $values = $tags->values();
        self::assertSame(['a', 'b'], $values);
        self::assertArrayHasKey(0, $values);
        self::assertArrayHasKey(1, $values);
    }
}
