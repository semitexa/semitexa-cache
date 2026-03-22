<?php
declare(strict_types=1);
namespace Semitexa\Cache\Namespace;

use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Contract\CacheNamespaceResolverInterface;
use Semitexa\Cache\Enum\CacheScope;
use Semitexa\Cache\Internal\CacheNamespace;

final class DefaultCacheNamespaceResolver implements CacheNamespaceResolverInterface
{
    public function __construct(
        private readonly CacheConfig $config,
    ) {}

    public function resolve(string $namespace, CacheScope $scope = CacheScope::Tenant): CacheNamespace
    {
        $tenantKey = match ($scope) {
            CacheScope::Global => 'tenant:global',
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
        $context = null;
        if (class_exists(\Semitexa\Tenancy\Context\TenantContext::class)) {
            $context = \Semitexa\Tenancy\Context\TenantContext::get();
        }

        if ($context !== null && method_exists($context, 'getTenantId')) {
            $tenantId = $context->getTenantId();
            if ($tenantId !== '' && $tenantId !== 'default') {
                return 'tenant:' . $tenantId;
            }
        }

        return 'tenant:default';
    }
}
