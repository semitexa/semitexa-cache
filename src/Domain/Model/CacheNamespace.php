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

        // The boundary byte is only a boundary while NOTHING that goes ahead of
        // it can contain one. `app` and `environment` are slugified and
        // `namespace` is checked above, but `prefix` and `tenantKey` are taken
        // verbatim -- and `tenantKey` comes from TenantContext, i.e. from a
        // custom resolver or, ultimately, a request. A tenant key of
        // `tenant:t:views:\0part` reproduces the layout of tenant `tenant:t`
        // in namespace `views` exactly, which is a cross-tenant read and
        // overwrite. Raised in review of cache#20.
        foreach (['prefix' => $prefix, 'tenant key' => $tenantKey] as $label => $component) {
            if (str_contains($component, self::KEY_BOUNDARY)) {
                throw new \InvalidArgumentException(
                    "Cache {$label} may not contain a NUL byte: it is the boundary between a prefix and a caller's key."
                );
            }
        }
    }

    /**
     * The byte that ends a prefix and begins a caller's key.
     *
     * Moving the namespace marker to the front did NOT create a boundary — it
     * only moved the ambiguity, as review showed with a case I had dismissed:
     * two applications sharing a Redis prefix, one named `ns` in environment
     * `v2`, and a root key carrying the rest. Both spellings produced
     * `semitexa:ns:v2:tenant:default:tenant:default:views:item`. Reproduced
     * before this fix.
     *
     * A position cannot be a boundary while the key may contain the separator.
     * A BYTE the key may not contain can. NUL is rejected in keys
     * ({@see ResolvedCacheKey}), never survives slugify() in app or
     * environment, and is not a legal namespace character — so every entry
     * string splits at its first NUL into exactly one prefix and one key, and
     * no combination of valid values can forge another.
     */
    public const KEY_BOUNDARY = "\0";

    /**
     * Where this namespace's ENTRIES live.
     *
     * The ROOT is byte-identical to what it has always been, which is most of
     * every deployed cache and was never ambiguous with itself. A NAMED
     * namespace ends with {@see self::KEY_BOUNDARY}, which is what makes the
     * root key `views:item` and the key `item` inside `views` different
     * strings: reaching the second would need a key containing NUL, and keys
     * containing NUL are refused.
     *
     * A named prefix still sits INSIDE the root prefix, so clearing the root
     * still clears the tenant — including for a third-party store that sweeps
     * by asPrefix() alone, which the first version of this change quietly
     * broke.
     */
    public function asPrefix(): string
    {
        $app = $this->slugify($this->app);
        $env = $this->slugify($this->environment);
        $tenant = $this->tenantKey;

        if ($this->namespace === '') {
            return "{$this->prefix}:{$app}:{$env}:{$tenant}:";
        }

        return "{$this->prefix}:{$app}:{$env}:{$tenant}:{$this->namespace}:" . self::KEY_BOUNDARY;
    }

    /**
     * Where a NAMED namespace's entries lived before the boundary byte, or ''
     * for the root, which never moved.
     *
     * Nothing reads entries here any more, and a flush of THIS namespace
     * deliberately does not sweep it: this string and a root key beginning
     * with the namespace's name are the same bytes, which is the entire defect
     * this layout removes — sweeping it would delete the root entries it
     * cannot tell apart. An entry stranded by the move expires on its own TTL,
     * because it is a cache and a cold entry is a cold start rather than lost
     * data. One that was written to live forever is cleared by flushing the
     * ROOT, which sweeps the whole tenant on purpose.
     *
     * It is still the right spelling for reading the OLD tag sets, whose
     * members are old entry keys — see RedisTagIndex::flush().
     */
    public function legacyAsPrefix(): string
    {
        if ($this->namespace === '') {
            return '';
        }

        $app = $this->slugify($this->app);
        $env = $this->slugify($this->environment);

        return "{$this->prefix}:{$app}:{$env}:{$this->tenantKey}:{$this->namespace}:";
    }

    /**
     * Every entry prefix a flush of this namespace has to sweep.
     *
     * One each. Clearing the ROOT clears the tenant because every named prefix
     * begins with the root's — that is how it always behaved, and the boundary
     * byte keeps it true instead of trading it away.
     *
     * The pre-boundary spelling is deliberately absent from a NAMED sweep: it
     * and a root key beginning with the namespace's name are the same bytes,
     * so sweeping it would delete root entries it cannot tell apart. The ROOT
     * sweep covers it, because it sits inside the root prefix.
     *
     * @return list<string>
     */
    public function sweepPrefixes(): array
    {
        return [$this->asPrefix()];
    }

    /**
     * Tag sets are per NAMESPACE, and live OUTSIDE the entry key space.
     *
     * The marker before the app segment stops a CALLER KEY from addressing a
     * tag set: in namespace `views`, `put('tag:v2:foo', ...)` used to resolve
     * to exactly the tag key for `foo`, so an untagged put overwrote the set
     * and a tagged one wrote a string where the next SADD failed with
     * WRONGTYPE. That part stands.
     *
     * What does NOT follow from it — and what the entry side was corrected for
     * in review — is that the layout is unambiguous between CONFIGURATIONS. The
     * tenant key is the one segment nothing sanitises, so a root set for tenant
     * `t:views` and a named set for tenant `t` in namespace `views` spelled the
     * same string. A named prefix therefore ends with
     * {@see self::KEY_BOUNDARY}, which no tag can carry: {@see self::tagKey()}
     * percent-encodes them.
     */
    public function tagKeyPrefix(): string
    {
        $app = $this->slugify($this->app);
        $env = $this->slugify($this->environment);
        $tenant = $this->tenantKey;

        if ($this->namespace === '') {
            return "{$this->prefix}:tag:v2:{$app}:{$env}:{$tenant}:";
        }

        return "{$this->prefix}:tag:v2:{$app}:{$env}:{$tenant}:{$this->namespace}:" . self::KEY_BOUNDARY;
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
