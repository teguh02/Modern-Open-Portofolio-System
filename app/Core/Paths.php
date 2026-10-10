<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * Path helpers. Keeps absolute-path handling in one place so nothing depends
 * on the current working directory.
 */
final class Paths
{
    public static function root(): string
    {
        return defined('MOPS_ROOT') ? MOPS_ROOT : dirname(__DIR__, 2);
    }

    /**
     * Resolve a path relative to the project root (absolute paths pass through).
     */
    public static function make(string $path): string
    {
        if ($path === '') {
            return self::root();
        }

        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            return rtrim($path, '/');
        }

        return self::root() . '/' . trim($path, '/');
    }

    public static function ensure(string $directory): string
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }

    /**
     * Turn an absolute path back into a project-relative one (for the DB).
     */
    public static function relative(string $absolute): string
    {
        $root = self::root() . '/';

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }

    /**
     * Reject traversal: only paths that stay inside the project root are allowed.
     */
    public static function isInside(string $path, ?string $base = null): bool
    {
        $base = rtrim($base ?? self::root(), '/');
        $realBase = realpath($base);
        $realPath = realpath($path);

        if ($realBase === false || $realPath === false) {
            return false;
        }

        return $realPath === $realBase || str_starts_with($realPath, $realBase . '/');
    }
}
