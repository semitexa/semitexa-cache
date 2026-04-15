<?php
declare(strict_types=1);
namespace Semitexa\Cache\Serialization;

use Semitexa\Cache\Exception\CacheSerializationException;
use Semitexa\Cache\Internal\CacheEntry;
use Semitexa\Cache\Internal\TagSet;

/**
 * Serializes and deserializes cache entries.
 *
 * Security: PHP serialization (unserialize) is disabled by default
 * to prevent object injection attacks (VULN-002). Non-scalar values
 * are converted to JSON on encode and rejected on decode.
 *
 * To enable signed PHP serialization, configure a CACHE_SIGNING_KEY
 * environment variable. When set, values are HMAC-signed before
 * serialization and verified before deserialization.
 */
final class CacheValueSerializer
{
    private ?string $signingKey;

    public function __construct(?string $signingKey = null)
    {
        $envKey = $_ENV['CACHE_SIGNING_KEY'] ?? null;
        $this->signingKey = $signingKey ?? (is_string($envKey) ? $envKey : null);
    }

    public function encode(CacheEntry $entry): string
    {
        if (is_scalar($entry->value) || is_array($entry->value) || $entry->value === null) {
            $format = 'json';
            $encoded = json_encode($entry->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new CacheSerializationException('Failed to JSON-encode cache value: ' . json_last_error_msg());
            }
        } else {
            // Non-scalar values: use signed PHP serialization if key is available
            if ($this->signingKey !== null) {
                $format = 'php';
                $raw = base64_encode(serialize($entry->value));
                $signature = hash_hmac('sha256', $raw, $this->signingKey);
                $encoded = json_encode(['sig' => $signature, 'data' => $raw]);
                if ($encoded === false) {
                    throw new CacheSerializationException('Failed to encode signed cache value.');
                }
            } else {
                // Fallback: convert to JSON (may lose type fidelity for objects)
                $format = 'json';
                $encoded = json_encode($entry->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded === false) {
                    throw new CacheSerializationException(
                        'Cannot cache non-scalar value without CACHE_SIGNING_KEY. '
                        . 'Objects require a signing key for secure serialization.'
                    );
                }
            }
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

        /** @var string $format */
        $format = $data['format'];
        /** @var string $value */
        $value = $data['value'];

        $decoded = match ($format) {
            'json' => json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR),
            'php' => $this->decodeSignedPhp($value),
            default => throw new CacheSerializationException("Unknown cache format '{$format}'."),
        };

        /** @var list<string> $tags */
        $tags = is_array($data['tags'] ?? null) ? array_values(array_filter($data['tags'], 'is_string')) : [];

        $createdAt = $data['created_at'];
        $ttlRaw = $data['ttl'] ?? null;

        return new CacheEntry(
            value: $decoded,
            createdAtEpoch: is_numeric($createdAt) ? (int) $createdAt : 0,
            ttlSeconds: is_numeric($ttlRaw) ? (int) $ttlRaw : null,
            format: $format,
            tags: new TagSet($tags),
        );
    }

    /**
     * Decode a signed PHP-serialized value.
     * Verifies HMAC signature before calling unserialize().
     */
    private function decodeSignedPhp(string $encoded): mixed
    {
        $wrapper = json_decode($encoded, true);
        if (!is_array($wrapper) || !isset($wrapper['sig'], $wrapper['data'])) {
            // Legacy unsigned format — reject for security
            throw new CacheSerializationException(
                'Unsigned PHP-serialized cache value rejected. '
                . 'Set CACHE_SIGNING_KEY to enable signed serialization.'
            );
        }

        if ($this->signingKey === null) {
            throw new CacheSerializationException(
                'Cannot decode signed PHP-serialized cache value: CACHE_SIGNING_KEY is not configured.'
            );
        }

        /** @var string $sig */
        $sig = $wrapper['sig'];
        /** @var string $data */
        $data = $wrapper['data'];

        $expectedSig = hash_hmac('sha256', $data, $this->signingKey);
        if (!hash_equals($expectedSig, $sig)) {
            throw new CacheSerializationException('Cache value signature mismatch — possible tampering detected.');
        }

        $raw = base64_decode($data, true);
        if ($raw === false) {
            throw new CacheSerializationException('Failed to decode signed cache value.');
        }

        return unserialize($raw);
    }
}
