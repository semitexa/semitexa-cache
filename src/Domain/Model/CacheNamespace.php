<?php
declare(strict_types=1);
namespace Semitexa\Cache\Domain\Model;

use Semitexa\Cache\Domain\Enum\CacheScope;

final readonly class CacheNamespace
{
    public function __construct(
        public string $prefix,
        public string $app,
        public string $environment,
        public CacheScope $scope,
        public string $tenantKey,
        public string $namespace,
    ) {
        if ($namespace !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $namespace)) {
            throw new \InvalidArgumentException(
                "Cache namespace '{$namespace}' contains invalid characters. Use only alphanumeric, dash, or underscore."
            );
        }
    }

    public function asPrefix(): string
    {
        $app = $this->slugify($this->app);
        $env = $this->slugify($this->environment);
        $tenant = $this->tenantKey;
        $ns = $this->namespace !== '' ? ':' . $this->namespace : '';
        return "{$this->prefix}:{$app}:{$env}:{$tenant}{$ns}:";
    }

    /**
     * Tag sets are per NAMESPACE, and live OUTSIDE the entry key space.
     *
     * Two things had to change here. They were per tenant, and the flush
     * filtered members by {@see self::asPrefix()} — which cannot separate the
     * root namespace from a named one, because the root's prefix is a string
     * prefix of every named one and a cache key may contain a colon of its own.
     *
     * And the marker used to sit AFTER the entry prefix, so a caller could
     * address a tag set as an ordinary key: in namespace `views`,
     * `put('tag:v2:foo', ...)` resolved to exactly the tag key for `foo` — an
     * untagged put would overwrite the set, and a tagged one would write a
     * string where the next SADD then failed with WRONGTYPE. The marker now
     * comes BEFORE the app segment, which no caller key can reach: a key is
     * always appended after the whole prefix, never spliced into it.
     */
    public function tagKeyPrefix(): string
    {
        $app = $this->slugify($this->app);
        $env = $this->slugify($this->environment);
        $tenant = $this->tenantKey;
        $ns = $this->namespace !== '' ? ':' . $this->namespace : '';
        return "{$this->prefix}:tag:v2:{$app}:{$env}:{$tenant}{$ns}:";
    }

    /**
     * The key of ONE tag set in this namespace.
     *
     * The tag is encoded, and that is not cosmetic: a namespace may only
     * contain `[A-Za-z0-9_-]`, but a TAG is whatever the caller passed, so a
     * root tag of `views:foo` built the same key as tag `foo` in namespace
     * `views`. Two different sets, one key — flushing the namespaced tag
     * pruned the root entry's membership as not carrying `foo`, and the root
     * flush of `views:foo` could then never reach it. Percent-encoding the tag
     * removes the colon it needed to pose as a namespace boundary. Raised in
     * review of cache#19.
     */
    public function tagKey(string $tag): string
    {
        return $this->tagKeyPrefix() . rawurlencode($tag);
    }

    /**
     * Where tag memberships lived before this layout: one set per TENANT, with
     * no namespace and no marker of its own.
     *
     * Kept readable so entries already in a deployed cache stay invalidatable.
     * Nothing writes here any more; {@see \Semitexa\Cache\Application\Service\RedisTagIndex::flush()}
     * reads it alongside the current set and prunes what it visits, so the old
     * layout drains instead of being stranded. The version that wrote these
     * sets never gave them a TTL, so they do not expire on their own — they
     * have to be read or they are lost.
     */
    public function legacyTagKeyPrefix(): string
    {
        $app = $this->slugify($this->app);
        $env = $this->slugify($this->environment);
        $tenant = $this->tenantKey;
        return "{$this->prefix}:{$app}:{$env}:{$tenant}:tag:";
    }

    private function slugify(string $value): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '-', $value));
    }
}
