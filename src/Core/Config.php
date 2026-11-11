<?php

declare(strict_types=1);

namespace ZbxDeploy\Core;

/**
 * Configuration comes from the environment only (docker compose env_file).
 * There is no config file to drift out of sync with the container.
 */
final class Config
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? null;
        }
        if ($value === null || $value === '') {
            return $default;
        }

        self::$cache[$key] = (string) $value;

        return self::$cache[$key];
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new \RuntimeException(sprintf('Missing required environment variable: %s', $key));
        }

        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);

        return $value === null ? $default : (int) $value;
    }

    public static function isDebug(): bool
    {
        return self::bool('APP_DEBUG', false);
    }

    public static function dataDir(): string
    {
        return rtrim(self::get('DATA_DIR', '/data') ?? '/data', '/');
    }

    public static function appRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
