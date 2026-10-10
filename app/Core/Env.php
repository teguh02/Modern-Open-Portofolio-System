<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * Minimal .env reader. Values already present in the real environment win,
 * which is what makes container/deploy configuration possible.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;

        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            // Strip matching quotes.
            if (strlen($value) > 1
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $value = substr($value, 1, -1);
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $fromServer = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if (is_string($fromServer) && $fromServer !== '') {
            return $fromServer;
        }

        $fromFile = self::$values[$key] ?? null;

        if ($fromFile !== null) {
            return $fromFile;
        }

        $fromGetenv = getenv($key);

        return $fromGetenv === false ? $default : (string) $fromGetenv;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }
}
