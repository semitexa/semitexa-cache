<?php
declare(strict_types=1);
namespace Semitexa\Cache\Configuration;

use Semitexa\Core\Environment;

final readonly class CacheConfig
{
    public function __construct(
        public string $driver,
        public string $prefix,
        public string $app,
        public string $env,
        public int $defaultTtl,
        public bool $allowForever,
        public bool $tagsEnabled,
        public string $redisHost,
        public int $redisPort,
        public string $redisScheme,
        public ?string $redisPassword,
    ) {}

    public static function fromEnvironment(): self
    {
        $driver = Environment::getEnvValue('CACHE_DRIVER', 'array');
        if (!in_array($driver, ['array', 'redis'], true)) {
            throw new \InvalidArgumentException("Invalid CACHE_DRIVER value '{$driver}'. Supported: array, redis.");
        }

        $ttl = (int) Environment::getEnvValue('CACHE_DEFAULT_TTL', '300');
        if ($ttl < 0) {
            throw new \InvalidArgumentException('CACHE_DEFAULT_TTL must be >= 0.');
        }

        $password = Environment::getEnvValue('REDIS_PASSWORD');

        return new self(
            driver: $driver,
            prefix: Environment::getEnvValue('CACHE_PREFIX', 'semitexa'),
            app: Environment::getEnvValue('CACHE_APP', Environment::getEnvValue('APP_NAME', 'app')),
            env: Environment::getEnvValue('CACHE_ENV', Environment::getEnvValue('APP_ENV', 'prod')),
            defaultTtl: $ttl,
            allowForever: Environment::getEnvValue('CACHE_ALLOW_FOREVER', '0') === '1',
            tagsEnabled: Environment::getEnvValue('CACHE_TAGS_ENABLED', '1') === '1',
            redisHost: Environment::getEnvValue('REDIS_HOST', '127.0.0.1'),
            redisPort: (int) Environment::getEnvValue('REDIS_PORT', '6379'),
            redisScheme: Environment::getEnvValue('REDIS_SCHEME', 'tcp'),
            redisPassword: ($password !== null && $password !== '') ? $password : null,
        );
    }
}
