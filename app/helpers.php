<?php

declare(strict_types=1);

/**
 * Global helper functions. These are the public API plugins and themes use.
 */

use Mops\Core\App;

if (!function_exists('mops')) {
    /**
     * Reach the application service container.
     */
    function mops(?string $service = null): mixed
    {
        $app = App::instance();

        return $service === null ? $app : $app->get($service);
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        Mops\Core\Hook::addAction($hook, $callback, $priority);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        Mops\Core\Hook::doAction($hook, ...$args);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10): void
    {
        Mops\Core\Hook::addFilter($hook, $callback, $priority);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return Mops\Core\Hook::applyFilters($hook, $value, ...$args);
    }
}

if (!function_exists('setting')) {
    /**
     * Read a setting (falls back to config defaults, then $default).
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return mops(Mops\Core\Settings::class)->get($key, $default);
    }
}

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('asset')) {
    /**
     * URL for a file inside public/assets, with a cache-busting version.
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $absolute = Mops\Core\Paths::make('public/assets/' . $path);
        $version = is_file($absolute) ? (string) filemtime($absolute) : '0';

        return '/assets/' . $path . '?v=' . $version;
    }
}

if (!function_exists('base_url')) {
    function base_url(string $path = ''): string
    {
        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('upload_url')) {
    /**
     * Uploaded files live outside the web root and are streamed by a route.
     */
    function upload_url(?string $storedPath): ?string
    {
        if ($storedPath === null || $storedPath === '') {
            return null;
        }

        return '/uploads/' . ltrim(str_replace('\\', '/', $storedPath), '/');
    }
}

if (!function_exists('plugin_asset')) {
    /**
     * URL for a file in a plugin's own assets/ folder, so plugins can ship CSS
     * or JS under the strict CSP (`script-src 'self'`) without touching core or
     * a theme. Files live in plugins/<slug>/assets/ and are streamed by core.
     */
    function plugin_asset(string $slug, string $path): string
    {
        return '/plugin/' . rawurlencode($slug) . '/assets/' . ltrim($path, '/');
    }
}

if (!function_exists('theme_asset')) {
    /**
     * URL for a file in the active theme's assets/ folder. Themes keep their
     * files in themes/<slug>/ (outside public/), so a core route streams them.
     */
    function theme_asset(string $path): string
    {
        return mops(Mops\Core\ThemeManager::class)->assetUrl($path);
    }
}
