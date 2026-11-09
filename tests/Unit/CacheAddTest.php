<?php

declare(strict_types=1);

namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\ArrayCacheStore;
use Semitexa\Cache\Application\Service\CacheManager;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Application\Service\NullTagIndex;
use Semitexa\Cache\Configuration\CacheConfig;

/**
 * add() grants a key once and forgetIf() releases only the holder's own value,
 * through the manager and its scoped views (tk-cache-set-if-absent).
 */
final class CacheAddTest extends TestCase
{
    #[Test]
    public function a_key_is_added_once_until_it_is_gone(): void
    {
        $cache = $this->manager();

        self::assertTrue($cache->add('claim:evt-1', 'worker-1', 60));
        self::assertFalse($cache->add('claim:evt-1', 'worker-2', 60));
        self::assertSame('worker-1', $cache->get('claim:evt-1'));

        $cache->forget('claim:evt-1');
        self::assertTrue($cache->add('claim:evt-1', 'worker-2', 60), 'free again once removed');
    }

    #[Test]
    public function a_holder_releases_only_its_own_value(): void
    {
        $cache = $this->manager()->withNamespace('form-locks');
        $cache->add('form-7', 'holder-a', 60);

        self::assertFalse($cache->forgetIf('form-7', 'holder-b'));
        self::assertSame('holder-a', $cache->get('form-7'));
        self::assertTrue($cache->forgetIf('form-7', 'holder-a'));
        self::assertNull($cache->get('form-7'));
        self::assertFalse($cache->forgetIf('form-7', 'holder-a'));
    }

    #[Test]
    public function namespaces_hold_their_own_keys(): void
    {
        $root = $this->manager();

        self::assertTrue($root->withNamespace('a')->add('k', 1, 60));
        self::assertTrue($root->withNamespace('b')->add('k', 2, 60));
    }

    #[Test]
    public function a_tag_scoped_manager_refuses_add(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('add() stores no tags');
        $this->manager()->withTags('reports')->add('k', 1, 60);
    }

    #[Test]
    public function the_ttl_rules_are_puts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->manager()->add('k', 1, -1);
    }

    private function manager(): CacheManager
    {
        $config = new CacheConfig(
            driver: 'array', prefix: 'semitexa', app: 'test-app', env: 'test', defaultTtl: 300, allowForever: false,
            tagsEnabled: true, redisHost: '127.0.0.1', redisPort: 6379, redisScheme: 'tcp', redisPassword: null,
        );

        return CacheManager::withDependencies($config, new ArrayCacheStore(new CacheValueSerializer()), new NullTagIndex(), new DefaultCacheNamespaceResolver($config));
    }
}
