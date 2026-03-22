<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Exception\CacheSerializationException;
use Semitexa\Cache\Internal\CacheEntry;
use Semitexa\Cache\Internal\TagSet;
use Semitexa\Cache\Serialization\CacheValueSerializer;

final class CacheValueSerializerTest extends TestCase
{
    private CacheValueSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new CacheValueSerializer();
    }

    private function makeEntry(mixed $value, ?int $ttl = 300, array $tags = []): CacheEntry
    {
        return new CacheEntry(
            value: $value,
            createdAtEpoch: 1710000000,
            ttlSeconds: $ttl,
            format: 'json',
            tags: new TagSet($tags),
        );
    }

    public function testEncodeDecodeStringValue(): void
    {
        $entry = $this->makeEntry('hello world');
        $raw = $this->serializer->encode($entry);
        $decoded = $this->serializer->decode($raw);

        self::assertSame('hello world', $decoded->value);
        self::assertSame('json', $decoded->format);
        self::assertSame(1710000000, $decoded->createdAtEpoch);
        self::assertSame(300, $decoded->ttlSeconds);
    }

    public function testEncodeDecodeIntValue(): void
    {
        $entry = $this->makeEntry(42);
        $decoded = $this->serializer->decode($this->serializer->encode($entry));
        self::assertSame(42, $decoded->value);
    }

    public function testEncodeDecodeArrayValue(): void
    {
        $entry = $this->makeEntry(['key' => 'value', 'nested' => ['a', 'b']]);
        $decoded = $this->serializer->decode($this->serializer->encode($entry));
        self::assertSame(['key' => 'value', 'nested' => ['a', 'b']], $decoded->value);
    }

    public function testEncodeDecodeNullValue(): void
    {
        $entry = $this->makeEntry(null);
        $decoded = $this->serializer->decode($this->serializer->encode($entry));
        self::assertNull($decoded->value);
    }

    public function testEncodeDecodeObjectUsingPhpFormat(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $obj->value = 42;

        $entry = $this->makeEntry($obj);
        $raw = $this->serializer->encode($entry);
        $decoded = $this->serializer->decode($raw);

        self::assertInstanceOf(\stdClass::class, $decoded->value);
        self::assertSame('test', $decoded->value->name);
        self::assertSame(42, $decoded->value->value);
        self::assertSame('php', $decoded->format);
    }

    public function testEncodeDecodeTags(): void
    {
        $entry = $this->makeEntry('val', tags: ['user', 'product']);
        $decoded = $this->serializer->decode($this->serializer->encode($entry));
        self::assertSame(['product', 'user'], $decoded->tags->values());
    }

    public function testEncodeDecodeNullTtl(): void
    {
        $entry = $this->makeEntry('val', ttl: null);
        $decoded = $this->serializer->decode($this->serializer->encode($entry));
        self::assertNull($decoded->ttlSeconds);
    }

    public function testDecodeInvalidJsonThrows(): void
    {
        $this->expectException(CacheSerializationException::class);
        $this->expectExceptionMessage('not valid JSON');
        $this->serializer->decode('not-json-at-all');
    }

    public function testDecodeMissingFormatThrows(): void
    {
        $this->expectException(CacheSerializationException::class);
        $this->expectExceptionMessage("missing required field 'format'");
        $this->serializer->decode('{"value":"x","created_at":1000}');
    }

    public function testDecodeMissingValueThrows(): void
    {
        $this->expectException(CacheSerializationException::class);
        $this->expectExceptionMessage("missing required field 'value'");
        $this->serializer->decode('{"format":"json","created_at":1000}');
    }

    public function testDecodeMissingCreatedAtThrows(): void
    {
        $this->expectException(CacheSerializationException::class);
        $this->expectExceptionMessage("missing required field 'created_at'");
        $this->serializer->decode('{"format":"json","value":"null"}');
    }

    public function testDecodeUnknownFormatThrows(): void
    {
        $this->expectException(CacheSerializationException::class);
        $this->expectExceptionMessage("Unknown cache format");
        $this->serializer->decode('{"format":"xml","value":"data","created_at":1000}');
    }

    public function testEncodedValueIsValidJson(): void
    {
        $entry = $this->makeEntry(['hello' => 'world']);
        $raw = $this->serializer->encode($entry);
        $parsed = json_decode($raw, true);
        self::assertIsArray($parsed);
        self::assertSame('json', $parsed['format']);
        self::assertArrayHasKey('created_at', $parsed);
    }
}
