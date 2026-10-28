<?php

declare(strict_types=1);

namespace Semitexa\Cache\Application\Service;

use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Core\Attribute\AsDoctorCheck;
use Semitexa\Core\Contract\DoctorCheckInterface;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\DoctorResult;

/** Whether CACHE_DEFAULT_TTL is one CacheConfig accepts, said before the first cache call refuses it. */
#[AsDoctorCheck(name: 'cache.default-ttl', package: 'semitexa/cache')]
final class CacheTtlDoctorCheck implements DoctorCheckInterface
{
    public function run(): DoctorResult
    {
        // Read by the same code CacheConfig::fromEnvironment() reads them
        // with, which reads them as the server's #[Config] does: a TTL that
        // is not a whole number is 300, a flag takes true/yes/on/1. Read
        // apart, "oops" passed here and was refused there.
        return self::verdict(
            (string) CacheConfig::defaultTtlFrom(Environment::getEnvValue('CACHE_DEFAULT_TTL')),
            CacheConfig::flagFrom(Environment::getEnvValue('CACHE_ALLOW_FOREVER'), false),
        );
    }

    public static function verdict(string $ttl, bool $allowForever): DoctorResult
    {
        $seconds = (int) $ttl;

        return match (true) {
            $seconds < 0 => DoctorResult::fail("CACHE_DEFAULT_TTL={$ttl} is negative: every cache call fails.", 'Set a positive number of seconds.'),
            $seconds === 0 && !$allowForever => DoctorResult::fail(
                'CACHE_DEFAULT_TTL=0 (forever) without CACHE_ALLOW_FOREVER=1: every cache call fails.',
                'Set a positive TTL, or CACHE_ALLOW_FOREVER=1 if entries should never expire.',
            ),
            $seconds === 0 => DoctorResult::pass('Cache entries without a TTL never expire (CACHE_ALLOW_FOREVER=1).'),
            default => DoctorResult::pass("Cache entries without a TTL live {$seconds} s."),
        };
    }
}
