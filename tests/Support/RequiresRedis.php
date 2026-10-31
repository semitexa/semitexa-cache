<?php

declare(strict_types=1);

namespace Semitexa\Cache\Tests\Support;

/**
 * The real Redis these integration tests are about.
 *
 * Not configured (REDIS_HOST unset: the suite run outside the stack) is a
 * skip. Configured and not answering is a failure: each test used to try
 * REDIS_HOST, then "redis", then 127.0.0.1, and skip when none answered, so a
 * configured Redis that was down read the same as none at all.
 */
trait RequiresRedis
{
    private static function redisHost(): string
    {
        $host = getenv('REDIS_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('REDIS_HOST not configured — the test requires real Redis');
        }
        $socket = @fsockopen($host, 6379, $errno, $errstr, 0.5);
        if ($socket === false) {
            self::fail("REDIS_HOST={$host} is configured, but nothing answers on port 6379: {$errstr}");
        }
        fclose($socket);

        return $host;
    }
}
