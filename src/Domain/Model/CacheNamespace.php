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
     * Tag sets are per NAMESPACE, not merely per tenant.
     *
     * They were per tenant, and the flush filtered members by
     * {@see self::asPrefix()}. That cannot work: the root namespace's prefix is
     * a string prefix of every named one, and a cache key may itself contain a
     * colon, so "root key" and "named-namespace key" are not distinguishable
     * from the string. MEASURED: a root flush of a shared tag removed a named
     * namespace's entry too. Putting the namespace in the key makes the
     * separation structural instead of a guess.
     *
     * The `v2` segment says which layout a set belongs to. Sets written under
     * the old one are simply never read again and expire on their own.
     */
    public function tagKeyPrefix(): string
    {
        $app = $this->slugify($this->app);
        $env = $this->slugify($this->environment);
        $tenant = $this->tenantKey;
        $ns = $this->namespace !== '' ? ':' . $this->namespace : '';
        return "{$this->prefix}:{$app}:{$env}:{$tenant}{$ns}:tag:v2:";
    }

    private function slugify(string $value): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9\-_]/', '-', $value));
    }
}
