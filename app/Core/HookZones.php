<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * The registry of injection zones — MOPS' equivalent of WordPress' named
 * action points (`wp_head`, `wp_body_open`, `wp_footer`, ...).
 *
 * Core and the bundled themes print zones with
 * `{{ render_hook('<zone>', context) }}`; plugins fill them with
 * `add_action('<zone>', fn () => ...)`. A plugin therefore never edits a theme
 * to place its output — it picks one of the available zones.
 *
 * Themes must print the `theme_*` and `main_*` zones (part of the theme
 * contract, exactly like `wp_head()` / `wp_footer()`), and may print the
 * `home_*` zones on the homepage.
 */
final class HookZones
{
    /**
     * zone id => metadata.
     *
     * scope:  public  — rendered on the public site
     *         admin   — rendered inside the admin shell
     * where:  short human hint of the physical position on the page
     */
    private const ZONES = [
        'theme_head' => [
            'label' => 'Theme head (before </head>)',
            'where' => 'Setiap halaman publik, tepat sebelum </head>',
            'args' => 'context',
            'scope' => 'public',
        ],
        'theme_body_open' => [
            'label' => 'Theme body open (after <body>)',
            'where' => 'Setiap halaman publik, tepat setelah <body>',
            'args' => 'context',
            'scope' => 'public',
        ],
        'main_before_content' => [
            'label' => 'Before main content',
            'where' => 'Setiap halaman publik, di awal <main>',
            'args' => 'context',
            'scope' => 'public',
        ],
        'main_after_content' => [
            'label' => 'After main content',
            'where' => 'Setiap halaman publik, di akhir <main>',
            'args' => 'context',
            'scope' => 'public',
        ],
        'theme_footer' => [
            'label' => 'Theme footer (before </body>)',
            'where' => 'Setiap halaman publik, setelah elemen footer',
            'args' => 'context',
            'scope' => 'public',
        ],
        'home_before_sections' => [
            'label' => 'Homepage — before sections',
            'where' => 'Homepage saja, sebelum bagian-bagian profil',
            'args' => 'context, sections',
            'scope' => 'public',
        ],
        'home_after_sections' => [
            'label' => 'Homepage — after sections',
            'where' => 'Homepage saja, tepat di atas footer (area call-to-action)',
            'args' => 'context, sections',
            'scope' => 'public',
        ],
        'admin_dashboard_widgets' => [
            'label' => 'Admin dashboard widgets',
            'where' => 'Dashboard admin, di bawah statistik',
            'args' => 'stats',
            'scope' => 'admin',
        ],
    ];

    /**
     * Zones a theme is expected to print (the theme contract).
     *
     * @var array<int, string>
     */
    public const REQUIRED_THEME_ZONES = [
        'theme_head',
        'theme_body_open',
        'main_before_content',
        'main_after_content',
        'theme_footer',
    ];

    /**
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        return self::ZONES;
    }

    /**
     * @return array<int, string>
     */
    public static function ids(): array
    {
        return array_keys(self::ZONES);
    }

    public static function exists(string $zone): bool
    {
        return isset(self::ZONES[$zone]);
    }

    /**
     * @return array<string, string>|null
     */
    public static function find(string $zone): ?array
    {
        return self::ZONES[$zone] ?? null;
    }

    /**
     * Print a zone and capture the output, exactly like the Twig helper of the
     * same purpose (kept here so plugin PHP code can chain HTML too).
     */
    public static function render(string $zone, mixed ...$args): string
    {
        ob_start();

        try {
            Hook::doAction($zone, ...$args);
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Zones that currently have at least one listener.
     *
     * @return array<int, string>
     */
    public static function filled(): array
    {
        return array_values(array_filter(
            self::ids(),
            static fn (string $zone): bool => Hook::hasAction($zone),
        ));
    }
}
