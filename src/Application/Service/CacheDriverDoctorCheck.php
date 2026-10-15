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
        return $this->evaluate(
            (string) (Environment::getEnvValue('CACHE_DRIVER', 'array') ?? 'array'),
            (string) (Environment::getEnvValue('APP_ENV', 'prod') ?? 'prod'),
        );
    }

    /**
     * Deliberately NO normalization: CacheConfig::validate() is exact-match on
     * 'array'/'redis', so values like 'Redis' or ' redis ' break the app at
     * boot — the doctor verdict must mirror the runtime, not be more
     * forgiving than it.
     */
    public function evaluate(string $driver, string $appEnv = 'dev'): DoctorResult
    {
        $production = in_array(strtolower(trim($appEnv)), ['prod', 'production'], true);

        return match ($driver) {
            // Per-worker is fine for one developer. In production, everything
            // that must agree across workers refuses to run on it — platform-ui
            // answers its UI events 503 'ui_replay_store_not_shared' — and that
            // was live on platform.semitexa.com's shared form (2026-10-16).
            'array' => $production
                ? DoctorResult::warn(
                    'CACHE_DRIVER=array in production: each worker has its own cache, so features that must agree '
                    . 'across workers (UI event replay protection, collaborative presence) refuse to run.',
                    'Set CACHE_DRIVER=redis (with REDIS_HOST) and recreate the containers.',
                )
                : DoctorResult::pass('CACHE_DRIVER=array (per-worker, coroutine-safe default).'),
            // The exit-255 crash was ONE Predis socket shared by coroutines.
            // The store (2026-06-25) and the tag index (2026-09-12) now borrow
            // an exclusive connection from a coroutine pool per operation;
            // RedisTagIndexCoroutineTest is the standing guard. Warning about
            // a fixed crash taught people to avoid the shared cache.
            'redis' => DoctorResult::pass(
                'CACHE_DRIVER=redis (shared across workers; pooled, coroutine-safe connections).',
            ),
            default => DoctorResult::fail(
                "Invalid CACHE_DRIVER '{$driver}'. Supported (exact, case-sensitive): array, redis.",
                'Fix CACHE_DRIVER in .env (container recreate needed for env_file changes).',
            ),
        };
    }
}
