<?php

declare(strict_types=1);

namespace Semitexa\Cache\Application\Service;

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
        // Read as the server's #[Config] reads them: a TTL that is not an
        // integer falls back to 300, a boolean takes true/yes/on/1.
        $ttl = filter_var(Environment::getEnvValue('CACHE_DEFAULT_TTL', '300'), FILTER_VALIDATE_INT);

        return self::verdict(
            $ttl === false ? '300' : (string) $ttl,
            (bool) filter_var(Environment::getEnvValue('CACHE_ALLOW_FOREVER', '0'), FILTER_VALIDATE_BOOLEAN),
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
