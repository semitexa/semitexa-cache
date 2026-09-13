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

    /**
     * Where this namespace's ENTRIES live.
     *
     * The namespace marker sits BEFORE the app segment, and that is the whole
     * point. It used to sit after the tenant — `…:tenant:views:` — with the
     * caller's key appended to it, so the root key `views:item` and the key
     * `item` inside `views` spelled the same string and each silently
     * overwrote the other. A key is always appended AFTER the whole prefix and
     * can never be spliced into it, so a marker in front of the app is
     * somewhere no key can reach. Same fix, same reason, as
     * {@see self::tagKeyPrefix()}.
     *
     * The ROOT namespace keeps its old spelling: it is the majority of every
     * deployed cache, it was never ambiguous with itself, and leaving it alone
     * means this change costs nothing there.
     */
    public function asPrefix(): string
    {
        $app = $this->slugify($this->app);
        $env = $this->slugify($this->environment);
        $tenant = $this->tenantKey;

        if ($this->namespace === '') {
            return "{$this->prefix}:{$app}:{$env}:{$tenant}:";
        }

        return "{$this->prefix}:ns:v2:{$app}:{$env}:{$tenant}:{$this->namespace}:";
    }

    /**
     * Where a NAMED namespace's entries lived before the marker moved, or ''
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
     * Clearing the ROOT clears the tenant — its named namespaces included.
     * That is what clearing the root already did, but only because the root's
     * prefix happened to be a string prefix of every named one: the same
     * overlap this class exists to remove. It is now stated rather than
     * inherited from an accident.
     *
     * @return list<string>
     */
    public function sweepPrefixes(): array
    {
        if ($this->namespace === '') {
            $app = $this->slugify($this->app);
            $env = $this->slugify($this->environment);

            // The second one covers every named namespace of this tenant; the
            // first still covers the pre-move spelling, which sits inside it —
            // which is why clearing the ROOT is what reaches a pre-move entry
            // that would otherwise never expire.
            return [
                $this->asPrefix(),
                "{$this->prefix}:ns:v2:{$app}:{$env}:{$this->tenantKey}:",
            ];
        }

        // Only the current spelling. See legacyAsPrefix() for why the old one
        // is not swept here.
        return [$this->asPrefix()];
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
