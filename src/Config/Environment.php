<?php

declare(strict_types=1);

namespace App\Config;

use Dotenv\Dotenv;
use RuntimeException;

class Environment
{
    private static bool $loaded = false;

    public static function load(string $baseDir): void
    {
        if (self::$loaded) {
            return;
        }

        if (file_exists($baseDir . '/.env')) {
            $dotenv = Dotenv::createImmutable($baseDir);
            $dotenv->load();
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $processValue = getenv($key);
        if ($processValue !== false && $processValue !== '') {
            return $processValue;
        }
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    public static function require(string $key): string
    {
        $val = self::get($key);
        if ($val === null || $val === '') {
            throw new RuntimeException("Missing required environment variable: {$key}");
        }
        return (string) $val;
    }

    public static function isProduction(): bool
    {
        return (self::get('APP_ENV', 'production') === 'production');
    }
}
