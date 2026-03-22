<?php
declare(strict_types=1);
namespace Semitexa\Cache\Serialization;

use Semitexa\Cache\Exception\CacheSerializationException;
use Semitexa\Cache\Internal\CacheEntry;
use Semitexa\Cache\Internal\TagSet;

final class CacheValueSerializer
{
    public function encode(CacheEntry $entry): string
    {
        if (is_scalar($entry->value) || is_array($entry->value) || $entry->value === null) {
            $format = 'json';
            $encoded = json_encode($entry->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new CacheSerializationException('Failed to JSON-encode cache value: ' . json_last_error_msg());
            }
        } else {
            $format = 'php';
            $encoded = base64_encode(serialize($entry->value));
        }

        $envelope = json_encode([
            'format' => $format,
            'value' => $encoded,
            'created_at' => $entry->createdAtEpoch,
            'ttl' => $entry->ttlSeconds,
            'tags' => $entry->tags->values(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($envelope === false) {
            throw new CacheSerializationException('Failed to encode cache envelope.');
        }

        return $envelope;
    }

    public function decode(string $raw): CacheEntry
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new CacheSerializationException('Cache envelope is not valid JSON.');
        }

        foreach (['format', 'value', 'created_at'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new CacheSerializationException("Cache envelope missing required field '{$field}'.");
            }
        }

        $format = $data['format'];
        $value = match ($format) {
            'json' => json_decode($data['value'], associative: true, flags: JSON_THROW_ON_ERROR),
            'php' => unserialize(base64_decode($data['value'])),
            default => throw new CacheSerializationException("Unknown cache format '{$format}'."),
        };

        return new CacheEntry(
            value: $value,
            createdAtEpoch: (int) $data['created_at'],
            ttlSeconds: isset($data['ttl']) ? (int) $data['ttl'] : null,
            format: $format,
            tags: new TagSet(is_array($data['tags'] ?? null) ? $data['tags'] : []),
        );
    }
}
