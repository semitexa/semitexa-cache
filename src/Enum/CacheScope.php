<?php
declare(strict_types=1);
namespace Semitexa\Cache\Enum;

enum CacheScope: string
{
    case Tenant = 'tenant';
    case Global = 'global';
}
