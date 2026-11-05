<?php

declare(strict_types=1);

namespace Semitexa\Cache;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * Without this the package is invisible to anyone whose project has not
 * installed it - which is precisely the audience worth telling, since they are
 * the ones about to build it by hand. The convention is one `Capabilities` class
 * per package: a definite place to look, and a definite place for a guard to
 * check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'cache.store',
    summary: 'A tenant-aware cache with namespaces and tag invalidation over Redis or per-worker memory.',
    useWhen: 'A result is expensive to compute and stays valid long enough to be worth keeping.',
    avoidWhen: 'The value changes every time it is read, or is cheap enough that a lookup costs more than a recompute.',
    replaces: [
        'a static array that survives one request and leaks between coroutines',
        'cache keys assembled by string concatenation, with the tenant forgotten in one place',
    ],
    usage: <<<'USAGE'
        #[AsService]
        final class ProductCatalog
        {
            #[InjectAsReadonly]
            protected CacheManagerInterface $cache;

            public function featured(): array
            {
                // The closure runs only on a miss.
                return $this->cache->remember('featured-products', fn (): array => $this->load(),
                    ttlSeconds: 600, tags: ['products']);
            }
        }

        // When a product changes:
        $this->cache->flushTags('products');

        // Every key is already separate per tenant: never put the tenant in the key.
        // Store scalars and arrays of them. An object needs CACHE_SIGNING_KEY.
        // CACHE_DRIVER=array is per worker, so a flush reaches one worker; use redis across workers.
        USAGE,
)]
final class Capabilities
{
}
