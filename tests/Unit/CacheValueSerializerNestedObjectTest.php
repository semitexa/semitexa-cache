<?php

declare(strict_types=1);

namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Exception\CacheSerializationException;
use Semitexa\Cache\Domain\Model\CacheEntry;
use Semitexa\Cache\Domain\Model\TagSet;

/**
 * An object inside an array must not take the JSON path: json_encode()
 * flattens it and the read hands back a plain array, the class silently gone.
 */
final class CacheValueSerializerNestedObjectTest extends TestCase
{
    #[Test]
    public function an_object_inside_an_array_needs_the_signing_key_like_a_bare_object(): void
    {
        $this->expectException(CacheSerializationException::class);

        // '' selects the no-key path explicitly; null would read CACHE_SIGNING_KEY.
        (new CacheValueSerializer(signingKey: ''))->encode($this->entry(['at' => new \DateTimeImmutable('2026-01-01')]));
    }

    #[Test]
    public function with_a_signing_key_it_comes_back_as_the_same_object(): void
    {
        $serializer = new CacheValueSerializer(signingKey: 'test-signing-key');

        $value = $serializer->decode($serializer->encode($this->entry(['nested' => ['at' => new \DateTimeImmutable('2026-01-01')]])))->value;

        self::assertInstanceOf(\DateTimeImmutable::class, $value['nested']['at']);
    }

    #[Test]
    public function an_array_that_contains_itself_is_rejected_not_walked_forever(): void
    {
        $value = ['a' => 1];
        $value['self'] = &$value;

        $this->expectException(CacheSerializationException::class);

        (new CacheValueSerializer(signingKey: ''))->encode($this->entry($value));
    }

    #[Test]
    public function plain_nested_arrays_still_use_json(): void
    {
        $serializer = new CacheValueSerializer(signingKey: null);

        $raw = $serializer->encode($this->entry(['a' => ['b' => [1, 'x', null, true, 1.5]]]));

        self::assertSame('json', json_decode($raw, true)['format']);
        self::assertSame(['a' => ['b' => [1, 'x', null, true, 1.5]]], $serializer->decode($raw)->value);
    }

    private function entry(mixed $value): CacheEntry
    {
        return new CacheEntry(value: $value, createdAtEpoch: time(), ttlSeconds: 60, format: 'json', tags: new TagSet([]));
    }
}
