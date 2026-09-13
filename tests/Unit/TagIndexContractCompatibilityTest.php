<?php
declare(strict_types=1);
namespace Semitexa\Cache\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Cache\Application\Service\ArrayCacheStore;
use Semitexa\Cache\Application\Service\CacheManager;
use Semitexa\Cache\Application\Service\CacheValueSerializer;
use Semitexa\Cache\Application\Service\DefaultCacheNamespaceResolver;
use Semitexa\Cache\Configuration\CacheConfig;
use Semitexa\Cache\Domain\Contract\TagIndexInterface;
use Semitexa\Cache\Domain\Model\CacheNamespace;
use Semitexa\Cache\Domain\Model\ResolvedCacheKey;
use Semitexa\Cache\Domain\Model\TagSet;

/**
 * An index that keeps its own keys needs two things a plain index does not: the
 * entry's lifetime, and notice when a namespace is cleared. Both were briefly
 * asked for by widening TagIndexInterface::attach() — which is published, so
 * every downstream implementation of it would have become
 * signature-incompatible and fatal at class load, on upgrade, before any of it
 * ran. Raised in review of cache#19.
 *
 * They are a separate capability now. This is the half that proves the old
 * contract still works: an index written against the two-argument attach()
 * loads and is called correctly.
 */
final class TagIndexContractCompatibilityTest extends TestCase
{
    private function manager(TagIndexInterface $index): CacheManager
    {
        $config = new CacheConfig(
            driver: 'array',
            prefix: 'semitexa',
            app: 'test-app',
            env: 'test',
            defaultTtl: 300,
            allowForever: true,
            tagsEnabled: true,
            redisHost: '127.0.0.1',
            redisPort: 6379,
            redisScheme: 'tcp',
            redisPassword: null,
        );

        return CacheManager::withDependencies(
            config: $config,
            store: new ArrayCacheStore(new CacheValueSerializer()),
            tagIndex: $index,
            namespaceResolver: new DefaultCacheNamespaceResolver($config),
        );
    }

    #[Test]
    public function an_index_written_against_the_published_contract_still_works(): void
    {
        $index = new LegacyContractTagIndex();

        $this->manager($index)->put('item', 'v', ttlSeconds: 120, tags: ['tag']);

        self::assertSame(1, $index->attachCalls, 'the old two-argument attach is the one that gets called');
        self::assertSame(['tag'], $index->lastTags);
    }

    #[Test]
    public function clearing_a_namespace_does_not_require_the_capability(): void
    {
        $manager = $this->manager(new LegacyContractTagIndex());
        $manager->put('item', 'v', ttlSeconds: 120, tags: ['tag']);

        self::assertSame(1, $manager->flushNamespace(), 'an index that cannot be told is simply not told');
    }
}

/** Exactly the shape a downstream index had before the capability existed. */
final class LegacyContractTagIndex implements TagIndexInterface
{
    public int $attachCalls = 0;
    /** @var list<string> */
    public array $lastTags = [];

    public function attach(ResolvedCacheKey $key, TagSet $tags): void
    {
        $this->attachCalls++;
        $this->lastTags = $tags->values();
    }

    public function detach(ResolvedCacheKey $key, TagSet $tags): void {}

    public function flush(CacheNamespace $namespace, TagSet $tags): int
    {
        return 0;
    }

    public function supportsNamespaceFlush(): bool
    {
        return true;
    }
}
