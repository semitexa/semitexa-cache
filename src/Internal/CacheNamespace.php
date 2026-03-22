<?php
declare(strict_types=1);
namespace Semitexa\Cache\Internal;

use Semitexa\Cache\Enum\CacheScope;

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

    public function tagKeyPrefix(): string
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
