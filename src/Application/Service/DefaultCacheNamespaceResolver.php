<?php
declare(strict_types=1);
namespace Semitexa\Cache\Application\Service;

use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Contract\CacheNamespaceResolverInterface;
use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\Cache\Domain\Model\CacheNamespace;

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
