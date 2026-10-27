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
        public int $redisPoolSize = 8,
    ) {}

    public static function fromEnvironment(): self
    {
        $driver = Environment::getEnvValue('CACHE_DRIVER', 'array');
        $ttl = self::defaultTtlFrom(Environment::getEnvValue('CACHE_DEFAULT_TTL'));
        $password = Environment::getEnvValue('REDIS_PASSWORD');

        return self::validate(new self(
            driver: $driver,
            prefix: Environment::getEnvValue('CACHE_PREFIX', 'semitexa'),
            app: Environment::getEnvValue('CACHE_APP', Environment::getEnvValue('APP_NAME', 'app')),
            env: Environment::getEnvValue('CACHE_ENV', Environment::getEnvValue('APP_ENV', 'prod')),
            defaultTtl: $ttl,
            allowForever: self::flagFrom(Environment::getEnvValue('CACHE_ALLOW_FOREVER'), false),
            tagsEnabled: Environment::getEnvValue('CACHE_TAGS_ENABLED', '1') === '1',
            redisHost: Environment::getEnvValue('REDIS_HOST', '127.0.0.1'),
            redisPort: (int) Environment::getEnvValue('REDIS_PORT', '6379'),
            redisScheme: Environment::getEnvValue('REDIS_SCHEME', 'tcp'),
            redisPassword: ($password !== null && $password !== '') ? $password : null,
            redisPoolSize: (int) Environment::getEnvValue('CACHE_REDIS_POOL_SIZE', '8'),
        ));
    }

    /**
     * CACHE_DEFAULT_TTL read as #[Config] reads it for the server: a value
     * that is not a whole number is the default 300. Cast with (int), "oops"
     * was 0 here, so the same .env passed `doctor` and the server and was
     * refused by this factory (TTL 0 without CACHE_ALLOW_FOREVER).
     */
    public static function defaultTtlFrom(?string $raw): int
    {
        if ($raw === null) {
            return 300;
        }
        $value = trim($raw);
        // filter_var refuses a leading zero; a zero-padded number is still that number.
        $value = preg_replace('/^([+-]?)0+(?=\d)/', '$1', $value) ?? $value;
        $ttl = filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        return is_int($ttl) ? $ttl : 300;
    }

    /** A flag read as #[Config] reads a bool: true, yes, on and 1 are on; anything else set is off. */
    public static function flagFrom(?string $raw, bool $default): bool
    {
        return $raw === null ? $default : (bool) filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    public static function validate(self $config): self
    {
        if (!in_array($config->driver, ['array', 'redis'], true)) {
            throw new \InvalidArgumentException("Invalid CACHE_DRIVER value '{$config->driver}'. Supported: array, redis.");
        }

        if ($config->defaultTtl < 0) {
            throw new \InvalidArgumentException('CACHE_DEFAULT_TTL must be >= 0.');
        }

        // 0 is forever. A put() with TTL 0 is refused without
        // CACHE_ALLOW_FOREVER, and this default made every put() without a
        // TTL permanent past that rule.
        if ($config->defaultTtl === 0 && !$config->allowForever) {
            throw new \InvalidArgumentException('CACHE_DEFAULT_TTL=0 makes every entry permanent: set CACHE_ALLOW_FOREVER=1 to allow that, or a positive TTL.');
        }

        if ($config->redisPoolSize < 1) {
            throw new \InvalidArgumentException('CACHE_REDIS_POOL_SIZE must be >= 1.');
        }

        return $config;
    }
}
