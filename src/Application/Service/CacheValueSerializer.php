<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Service;

use Semitexa\Cache\Exception\CacheSerializationException;
use Semitexa\Cache\Domain\Model\CacheEntry;
use Semitexa\Cache\Domain\Model\TagSet;

/**
 * Serializes and deserializes cache entries.
 *
 * Security: PHP serialization (unserialize) is disabled by default
 * to prevent object injection attacks (VULN-002). Non-scalar values
 * require signed PHP serialization and are rejected otherwise.
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
        if ($signingKey !== null) {
            $envKey = $signingKey;
        } else {
            $envKey = $_ENV['CACHE_SIGNING_KEY'] ?? null;
            if ($envKey === null) {
                $envKey = getenv('CACHE_SIGNING_KEY') ?: null;
            }
        }
        $this->signingKey = is_string($envKey) && $envKey !== '' ? $envKey : null;
    }

    public function encode(CacheEntry $entry): string
    {
        if (self::isJsonSafe($entry->value)) {
            $format = 'json';
            $encoded = json_encode($entry->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new CacheSerializationException('Failed to JSON-encode cache value: ' . json_last_error_msg());
            }
        } else {
            // Non-scalar values: use signed PHP serialization if key is available
            if ($this->signingKey !== null && $this->signingKey !== '') {
                $format = 'php';
                $raw = base64_encode(serialize($entry->value));
                $signature = hash_hmac('sha256', $raw, $this->signingKey);
                $encoded = json_encode(['sig' => $signature, 'data' => $raw]);
                if ($encoded === false) {
                    throw new CacheSerializationException('Failed to encode signed cache value.');
                }
            } else {
                throw new CacheSerializationException(
                    'Cannot cache non-scalar value without CACHE_SIGNING_KEY. '
                    . 'Objects require a signing key for secure serialization.'
                );
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

    /**
     * Whether JSON round-trips the value unchanged: scalars, null, and arrays of
     * those at any depth. An object anywhere inside an array used to take the
     * JSON path too, where json_encode() flattened it to its public properties
     * and the read returned a plain array — the class, a DateTimeImmutable or
     * an enum silently gone. Such values now take the same signed-serialization
     * (or reject) path as a bare object.
     *
     * Nesting deeper than json_encode()'s own default depth (512) cannot round-trip
     * through JSON either, so it is not JSON-safe. That bound also stops the walk
     * on an array that holds a reference to itself, which would otherwise recurse
     * until the stack gives out.
     */
    private static function isJsonSafe(mixed $value, int $depth = 0): bool
    {
        if (is_array($value)) {
            if ($depth >= 512) {
                return false;
            }

            foreach ($value as $item) {
                if (!self::isJsonSafe($item, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return is_scalar($value) || $value === null;
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

        if (!is_string($format) || !is_string($value)) {
            throw new CacheSerializationException('Cache envelope has invalid field types.');
        }

        $decoded = match ($format) {
            'json' => json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR),
            'php' => $this->decodeSignedPhp($value),
            default => throw new CacheSerializationException("Unknown cache format '{$format}'."),
        };

        /** @var list<string> $tags */
        $tags = is_array($data['tags'] ?? null) ? array_values(array_filter($data['tags'], 'is_string')) : [];

        $createdAt = $data['created_at'];
        $ttlRaw = $data['ttl'] ?? null;

        if (!is_numeric($createdAt)) {
            throw new CacheSerializationException('Cache envelope has invalid created_at value.');
        }

        if ($ttlRaw !== null && !is_numeric($ttlRaw)) {
            throw new CacheSerializationException('Cache envelope has invalid ttl value.');
        }

        return new CacheEntry(
            value: $decoded,
            createdAtEpoch: (int) $createdAt,
            ttlSeconds: $ttlRaw !== null ? (int) $ttlRaw : null,
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

        $sig = $wrapper['sig'];
        $data = $wrapper['data'];

        if (!is_string($sig) || !is_string($data)) {
            throw new CacheSerializationException('Signed cache envelope has invalid field types.');
        }

        if ($this->signingKey === null || $this->signingKey === '') {
            throw new CacheSerializationException(
                'Cannot decode signed PHP-serialized cache value: CACHE_SIGNING_KEY is not configured.'
            );
        }

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
