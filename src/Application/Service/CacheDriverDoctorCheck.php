<?php

declare(strict_types=1);

namespace Semitexa\Cache\Application\Service;

use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Support\DoctorResult;
use Semitexa\Core\Environment;

#[AsDoctorCheck(name: 'cache.driver', package: 'semitexa/cache')]
final class CacheDriverDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        $driver = strtolower(trim((string) (Environment::getEnvValue('CACHE_DRIVER', 'array') ?? 'array')));

        return match ($driver) {
            'array' => DoctorResult::pass("CACHE_DRIVER=array (per-worker, coroutine-safe default)."),
            'redis' => DoctorResult::warn(
                'CACHE_DRIVER=redis uses a blocking client that is not coroutine-safe under Swoole '
                . '(known to kill workers with exit 255).',
                'Prefer CACHE_DRIVER=array until a coroutine-safe Redis client ships.',
            ),
            default => DoctorResult::fail(
                "Invalid CACHE_DRIVER '{$driver}'. Supported: array, redis.",
                'Fix CACHE_DRIVER in .env (container recreate needed for env_file changes).',
            ),
        };
    }
}
