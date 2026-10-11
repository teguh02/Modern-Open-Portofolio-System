<?php

declare(strict_types=1);

namespace Mops\Core;

use RuntimeException;

/**
 * Registry of admin pages owned by plugins — MOPS' `add_menu_page()`.
 *
 * A plugin registers a page from its entry file; core serves it at
 * /admin/{slug} (inside the admin group, so auth + CSRF already apply) and
 * merges the entry into the sidebar through the `admin_menu` filter.
 */
final class AdminMenu
{
    /** @var array<string, array{title: string, icon: string, renderer: callable}> */
    private static array $pages = [];

    /**
     * Core admin slugs a plugin may never claim.
     *
     * @var array<int, string>
     */
    private const RESERVED = [
        'login', 'logout', 'profile', 'experiences', 'education', 'skills',
        'certifications', 'awards', 'projects', 'posts', 'media', 'settings',
        'themes', 'plugins', 'backup',
    ];

    public static function add(string $slug, string $title, string $icon, callable $renderer): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            throw new RuntimeException("Menu slug '{$slug}' tidak valid (huruf kecil, angka, tanda minus).");
        }

        if (in_array($slug, self::RESERVED, true)) {
            throw new RuntimeException("Menu slug '{$slug}' is reserved for core and cannot be claimed by a plugin.");
        }

        if (isset(self::$pages[$slug])) {
            throw new RuntimeException("Menu slug '{$slug}' sudah terdaftar.");
        }

        self::$pages[$slug] = ['title' => $title, 'icon' => $icon, 'renderer' => $renderer];

        return $slug;
    }

    /**
     * @return array{title: string, icon: string, renderer: callable}|null
     */
    public static function find(string $slug): ?array
    {
        return self::$pages[$slug] ?? null;
    }

    /**
     * @return array<string, array{title: string, icon: string, renderer: callable}>
     */
    public static function all(): array
    {
        return self::$pages;
    }

    public static function reset(): void
    {
        self::$pages = [];
    }
}
