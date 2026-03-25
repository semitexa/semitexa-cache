# semitexa/cache

Tenant-aware cache store with Redis backing, namespace isolation, and tag-based invalidation.

## Purpose

Provides a cache abstraction with pluggable backends. The default Redis store supports key namespacing for tenant isolation, tag groups for batch invalidation, and TTL-based expiration.

## Role in Semitexa

Depends on `semitexa/core`. Uses Predis as the default backend. Becomes tenant-aware when paired with the Tenancy package via `CacheNamespaceResolverInterface`.

## Key Features

- `RedisCacheStore` with Predis backend
- `ArrayCacheStore` for testing and development
- Tenant-aware key namespacing via `CacheNamespaceResolverInterface`
- Tag-based invalidation with `TagSet`
- `CacheStoreInterface` contract for custom backends
