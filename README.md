# Semitexa Cache

Tenant-aware cache store with pluggable drivers (in-process array by default, optional Redis), namespace isolation, and tag-based invalidation.

## Install

Included in every project created by the installer (https://semitexa.com/install.sh).

## Purpose

Provides a cache abstraction with pluggable backends, selected by `CACHE_DRIVER` (`array` or `redis`, default `array`). Stores support key namespacing for tenant isolation, tag groups for batch invalidation, and TTL-based expiration.

## Role in Semitexa

Depends on `semitexa/core`. The default `array` driver is per-worker and coroutine-safe. `CACHE_DRIVER=redis` uses Predis, a blocking client that is not coroutine-safe under Swoole: `system:doctor` warns about it. Becomes tenant-aware when paired with the Tenancy package via `CacheNamespaceResolverInterface`.

## Key Features

- `ArrayCacheStore` (default, `CACHE_DRIVER=array`)
- `RedisCacheStore` with Predis backend (`CACHE_DRIVER=redis`)
- Tenant-aware key namespacing via `CacheNamespaceResolverInterface`
- Tag-based invalidation with `TagSet`
- `CacheStoreInterface` contract for custom backends
- Console: `cache:flush`, `cache:forget`, `cache:tags:flush`
