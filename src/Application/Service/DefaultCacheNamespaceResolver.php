<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Service;

use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Contract\CacheNamespaceResolverInterface;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Core\Tenant\TenantContextAccess;
use Semitexa\Core\Tenant\TenantContextStoreInterface;

final class DefaultCacheNamespaceResolver implements CacheNamespaceResolverInterface
{
    public function __construct(
        private readonly CacheConfig $config,
        private readonly ?TenantContextStoreInterface $tenantContexts = null,
    ) {}

    public function resolve(string $namespace, CacheScope $scope = CacheScope::Tenant): CacheNamespace
    {
        $tenantKey = match ($scope) {
            // Not 'tenant:global': a tenant whose id is "global" read and
            // wrote the global scope's keys.
            CacheScope::Global => 'scope:global',
            CacheScope::Tenant => $this->resolveTenantKey(),
        };

        return new CacheNamespace(
            prefix: $this->config->prefix,
            app: $this->config->app,
            environment: $this->config->env,
            scope: $scope,
            tenantKey: $tenantKey,
            namespace: $namespace,
        );
    }

    private function resolveTenantKey(): string
    {
        $tenantId = TenantContextAccess::tenantId($this->tenantContexts?->tryGet());

        return 'tenant:' . ($tenantId ?? 'default');
    }
}
