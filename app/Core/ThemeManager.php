<?php

declare(strict_types=1);

namespace Mops\Core;

use RuntimeException;

/**
 * Theme discovery + activation. A theme is a folder in themes/ with a theme.json
 * manifest and a templates/ directory. Themes only render; this class never lets
 * a theme touch the data layer.
 */
final class ThemeManager
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $themes = null;

    private ?string $active = null;

    /** @var array<int, true> */
    private array $functionsLoaded = [];

    /** @var array<int, string> */
    private array $errors = [];

    /**
     * Templates every theme must provide to be activatable.
     *
     * @var array<int, string>
     */
    private const REQUIRED_TEMPLATES = [
        'layout.twig',
        'home.twig',
    ];

    public function __construct(
        private string $themesPath,
        private Settings $settings,
        private string $fallbackSlug = 'vantablo',
    ) {
    }

    /**
     * @return array<string, array<string, mixed>> keyed by slug
     */
    public function all(): array
    {
        if ($this->themes !== null) {
            return $this->themes;
        }

        $themes = [];
        $directories = is_dir($this->themesPath) ? glob(rtrim($this->themesPath, '/') . '/*', GLOB_ONLYDIR) ?: [] : [];
        sort($directories, SORT_STRING);

        foreach ($directories as $directory) {
            $slug = basename($directory);
            $manifest = $this->readManifest($directory, $slug);

            $manifest['slug'] = $slug;
            $manifest['path'] = $directory;
            $manifest['template_path'] = $directory . '/templates';
            $manifest['asset_path'] = $directory . '/assets';
            $manifest['screenshot'] = is_file($directory . '/screenshot.svg')
                ? "themes/{$slug}/screenshot.svg"
                : null;
            $manifest['legacy'] = $this->hasLegacyPublicDir($directory);
            $manifest['missing_templates'] = $this->missingTemplates($directory . '/templates');
            $manifest['valid'] = $manifest['missing_templates'] === [];

            $themes[$slug] = $manifest;
        }

        return $this->themes = $themes;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    public function exists(string $slug): bool
    {
        return $this->find($slug) !== null;
    }

    public function activeSlug(): string
    {
        if ($this->active !== null) {
            return $this->active;
        }

        $configured = (string) $this->settings->get('active_theme', $this->fallbackSlug);

        // Never let a missing theme folder take the site down.
        if (!$this->exists($configured)) {
            return $this->active = $this->defaultSlug();
        }

        return $this->active = $configured;
    }

    /**
     * @return array<string, mixed>
     */
    public function active(): array
    {
        $slug = $this->activeSlug();

        return $this->find($slug) ?? [
            'slug' => $slug,
            'name' => ucfirst($slug),
            'version' => '0.0.0',
            'author' => 'Unknown',
            'description' => '',
            'screenshot' => null,
            'template_path' => $this->themesPath . '/' . $slug . '/templates',
            'valid' => false,
            'missing_templates' => self::REQUIRED_TEMPLATES,
        ];
    }

    public function templatePath(?string $slug = null): string
    {
        $slug ??= $this->activeSlug();
        $theme = $this->find($slug);

        if ($theme !== null && is_dir((string) $theme['template_path'])) {
            return (string) $theme['template_path'];
        }

        // Fall back to the core views directory so pages still render.
        return Paths::make('app/Views/fallback');
    }

    public function activate(string $slug): void
    {
        $theme = $this->find($slug);

        if ($theme === null) {
            throw new RuntimeException("Tema '{$slug}' tidak ditemukan.");
        }

        if (($theme['valid'] ?? false) !== true) {
            $missing = implode(', ', (array) $theme['missing_templates']);

            throw new RuntimeException("Tema '{$slug}' tidak lengkap. Template hilang: {$missing}.");
        }

        $old = $this->activeSlug();

        $this->settings->set('active_theme', $slug);
        $this->active = $slug;

        if ($old !== $slug) {
            Hook::doAction('theme_deactivated', $old);
            Hook::doAction('after_switch_theme', $slug, $old, $theme);
        }

        Hook::doAction('theme_activated', $slug, $theme);
    }

    public function defaultSlug(): string
    {
        foreach ($this->all() as $slug => $theme) {
            if (($theme['valid'] ?? false) === true) {
                return $slug;
            }
        }

        return $this->fallbackSlug;
    }

    /**
     * Versioned URL for a file in the active theme's assets/ folder.
     */
    public function assetUrl(string $path, ?string $slug = null): string
    {
        $slug ??= $this->activeSlug();
        $path = ltrim($path, '/');
        $absolute = rtrim($this->themesPath, '/') . "/{$slug}/assets/{$path}";
        $version = is_file($absolute) ? (string) filemtime($absolute) : '0';

        return "/theme/{$slug}/assets/{$path}?v={$version}";
    }

    /**
     * Absolute path of a theme asset, or null when it escapes the theme folder.
     */
    public function assetPath(string $slug, string $path): ?string
    {
        $theme = $this->find($slug);

        if ($theme === null) {
            return null;
        }

        $base = (string) $theme['asset_path'];
        $candidate = $base . '/' . ltrim(str_replace('\\', '/', $path), '/');

        if (!is_file($candidate)) {
            return null;
        }

        return Paths::isInside($candidate, $base) ? $candidate : null;
    }

    /**
     * Require functions.php of the active theme and its parent (WordPress
     * order: child first). Runs once per request. Throwables, including
     * ParseError, from functions.php are caught and recorded in errors();
     * language-level fatals (e.g. redeclaring a defined function) still
     * abort, matching WordPress.
     */
    public function loadFunctions(): void
    {
        foreach ($this->chain() as $theme) {
            $file = (string) $theme['path'] . '/functions.php';
            $slug = (string) $theme['slug'];

            if (isset($this->functionsLoaded[$slug]) || !is_file($file)) {
                continue;
            }

            $this->functionsLoaded[$slug] = true;

            try {
                (static function () use ($file): void {
                    require $file;
                })();
            } catch (\Throwable $e) {
                $this->errors[] = "Tema '{$slug}': functions.php gagal dimuat: " . $e->getMessage();
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function flush(): void
    {
        $this->themes = null;
        $this->active = null;
        $this->functionsLoaded = [];
        $this->errors = [];
    }

    /**
     * Active theme first, then its parent (max two levels).
     *
     * @return array<int, array<string, mixed>>
     */
    private function chain(): array
    {
        $active = $this->find($this->activeSlug());

        if ($active === null) {
            return [];
        }

        $chain = [$active];
        $parent = isset($active['parent']) ? $this->find((string) $active['parent']) : null;

        if ($parent !== null) {
            $chain[] = $parent;
        }

        return $chain;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $directory, string $slug): array
    {
        $manifestPath = $directory . '/theme.json';
        $defaults = [
            'name' => ucfirst($slug),
            'version' => '0.0.0',
            'author' => 'Unknown',
            'description' => '',
            'persona' => 'general',
        ];

        if (!is_file($manifestPath)) {
            return $defaults;
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);

        if (!is_array($decoded)) {
            return $defaults;
        }

        return array_merge($defaults, $decoded);
    }

    /**
     * @return array<int, string>
     */
    private function missingTemplates(string $templatePath): array
    {
        if (!is_dir($templatePath)) {
            return self::REQUIRED_TEMPLATES;
        }

        return array_values(array_filter(
            self::REQUIRED_TEMPLATES,
            static fn (string $template): bool => !is_file($templatePath . '/' . $template),
        ));
    }

    private function hasLegacyPublicDir(string $directory): bool
    {
        return is_file($directory . '/homepage.html');
    }
}
