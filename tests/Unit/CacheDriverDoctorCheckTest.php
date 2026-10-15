<?php

declare(strict_types=1);

namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\CacheDriverDoctorCheck;
use Semitexa\Core\Support\DoctorStatus;

final class CacheDriverDoctorCheckTest extends TestCase
{
    #[Test]
    public function arrayDriverPasses(): void
    {
        $result = new CacheDriverDoctorCheck()->evaluate('array');

        self::assertSame(DoctorStatus::Pass, $result->status);
    }

    #[Test]
    public function redisDriverPassesNowThatConnectionsArePooled(): void
    {
        $result = new CacheDriverDoctorCheck()->evaluate('redis');

        // verify:accept-test-change the warning described a crash fixed by connection pooling (store 2026-06-25, tag index 2026-09-12; RedisTagIndexCoroutineTest guards it), so redis is a supported driver again
        self::assertSame(DoctorStatus::Pass, $result->status);
        self::assertStringContainsString('coroutine-safe', $result->message);
    }

    #[Test]
    public function unknownDriverFails(): void
    {
        $result = new CacheDriverDoctorCheck()->evaluate('memcached');

        self::assertSame(DoctorStatus::Fail, $result->status);
    }

    /**
     * CacheConfig::validate() is exact-match; the doctor must not be more
     * forgiving than the runtime it diagnoses.
     */
    #[Test]
    public function caseOrWhitespaceVariantsFailLikeTheRuntimeWould(): void
    {
        $check = new CacheDriverDoctorCheck();

        self::assertSame(DoctorStatus::Fail, $check->evaluate('Redis')->status);
        self::assertSame(DoctorStatus::Fail, $check->evaluate(' redis ')->status);
        self::assertSame(DoctorStatus::Fail, $check->evaluate('ARRAY')->status);
    }
}
