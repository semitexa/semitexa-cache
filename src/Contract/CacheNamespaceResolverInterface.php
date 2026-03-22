<?php
declare(strict_types=1);
namespace Semitexa\Cache\Contract;

use Semitexa\Cache\Enum\CacheScope;
use Semitexa\Cache\Internal\CacheNamespace;

interface CacheNamespaceResolverInterface
{
    public function resolve(string $namespace, CacheScope $scope = CacheScope::Tenant): CacheNamespace;
}
