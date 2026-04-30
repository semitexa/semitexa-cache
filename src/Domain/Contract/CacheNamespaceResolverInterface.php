<?php
declare(strict_types=1);
namespace Semitexa\Cache\Domain\Contract;

use Semitexa\Cache\Domain\Enum\CacheScope;
use Semitexa\Cache\Domain\Model\CacheNamespace;

interface CacheNamespaceResolverInterface
{
    public function resolve(string $namespace, CacheScope $scope = CacheScope::Tenant): CacheNamespace;
}
