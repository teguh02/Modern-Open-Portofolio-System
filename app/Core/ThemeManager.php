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

            $themes[$slug] = $manifest;
        }

        // Second pass: a child inherits the parent's templates, so "complete"
        // is evaluated over the chain, never one folder alone.
        foreach ($themes as $slug => &$theme) {
            $dirs = [(string) $theme['template_path']];
            $parentSlug = (string) ($theme['parent'] ?? '');

            if ($parentSlug !== '' && isset($themes[$parentSlug])) {
                $dirs[] = (string) $themes[$parentSlug]['template_path'];
            }

            $theme['missing_templates'] = $this->missingTemplatesIn($dirs);
            $theme['valid'] = $theme['missing_templates'] === [];
        }
        unset($theme);

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

        // Never let a missing theme folder — or a child whose parent folder is
        // gone — take the site down.
        $theme = $this->find($configured);

        if ($theme === null || !$this->usable($theme)) {
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

    /**
     * Template folders for a theme: its own first, then its parent's — the
     * child-override order WordPress themes use.
     *
     * @return array<int, string>
     */
    public function templatePaths(?string $slug = null): array
    {
        $slug ??= $this->activeSlug();
        $theme = $this->find($slug);

        if ($theme === null) {
            return [Paths::make('app/Views/fallback')];
        }

        $paths = [];

        if (is_dir((string) $theme['template_path'])) {
            $paths[] = (string) $theme['template_path'];
        }

        $parentSlug = (string) ($theme['parent'] ?? '');
        $parent = $parentSlug !== '' ? $this->find($parentSlug) : null;

        if ($parent !== null && is_dir((string) $parent['template_path'])) {
            $paths[] = (string) $parent['template_path'];
        }

        return $paths !== [] ? $paths : [Paths::make('app/Views/fallback')];
    }

    public function templatePath(?string $slug = null): string
    {
        return $this->templatePaths($slug)[0];
    }

    /**
     * Everything that must hold before a theme (child or not) may be activated.
     */
    public function assertActivatable(string $slug): void
    {
        $theme = $this->find($slug);

        if ($theme === null) {
            throw new RuntimeException("Tema '{$slug}' tidak ditemukan.");
        }

        if (($theme['valid'] ?? false) !== true) {
            $missing = implode(', ', (array) $theme['missing_templates']);

            throw new RuntimeException("Tema '{$slug}' tidak lengkap. Template hilang: {$missing}.");
        }

        $parentSlug = (string) ($theme['parent'] ?? '');

        if ($parentSlug === '') {
            return;
        }

        $parent = $this->find($parentSlug);

        if ($parent === null || ($parent['valid'] ?? false) !== true) {
            throw new RuntimeException("Tema induk '{$parentSlug}' untuk '{$slug}' tidak ditemukan atau tidak lengkap.");
        }

        if ((string) ($parent['parent'] ?? '') !== '') {
            throw new RuntimeException(
                "Tema '{$slug}' menunjuk parent '{$parentSlug}' yang sendiri punya parent — MOPS mendukung child theme maksimal 2 level.",
            );
        }
    }

    public function activate(string $slug): void
    {
        $this->assertActivatable($slug);

        $theme = $this->find($slug) ?? throw new RuntimeException("Tema '{$slug}' tidak ditemukan.");
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
            if (($theme['valid'] ?? false) === true && $this->usable($theme)) {
                return $slug;
            }
        }

        return $this->fallbackSlug;
    }

    /**
     * A theme is usable when it exists and its declared parent (if any) still
     * exists — a child without its parent can't render its inherited templates.
     *
     * @param array<string, mixed> $theme
     */
    private function usable(array $theme): bool
    {
        $parentSlug = (string) ($theme['parent'] ?? '');

        return $parentSlug === '' || $this->exists($parentSlug);
    }

    public function assetUrl(string $path, ?string $slug = null): string
    {
        $slug ??= $this->activeSlug();
        $path = ltrim($path, '/');

        foreach ($this->assetChain($slug, $path) as $absolute) {
            if (is_file($absolute)) {
                return "/theme/{$slug}/assets/{$path}?v=" . filemtime($absolute);
            }
        }

        return "/theme/{$slug}/assets/{$path}?v=0";
    }

    /**
     * Absolute path of a theme asset, or null when it escapes the theme folder.
     */
    public function assetPath(string $slug, string $path): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $path), '/');

        foreach ($this->assetChain($slug, $relative) as $base => $absolute) {
            if (is_file($absolute) && Paths::isInside($absolute, $base)) {
                return $absolute;
            }
        }

        return null;
    }

    /**
     * Candidate absolute asset paths for a relative file, child first then parent,
     * keyed by the asset folder each candidate came from.
     *
     * @return array<string, string>
     */
    private function assetChain(string $slug, string $relative): array
    {
        $theme = $this->find($slug);

        if ($theme === null) {
            return [];
        }

        $bases = [(string) $theme['asset_path']];
        $parentSlug = (string) ($theme['parent'] ?? '');
        $parent = $parentSlug !== '' ? $this->find($parentSlug) : null;

        if ($parent !== null) {
            $bases[] = (string) $parent['asset_path'];
        }

        $chain = [];

        foreach ($bases as $base) {
            $chain[$base] = rtrim($base, '/') . '/' . $relative;
        }

        return $chain;
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
     * @param array<int, string> $directories
     * @return array<int, string>
     */
    private function missingTemplatesIn(array $directories): array
    {
        $missing = [];

        foreach (self::REQUIRED_TEMPLATES as $template) {
            $found = false;

            foreach ($directories as $directory) {
                if (is_file(rtrim($directory, '/') . '/' . $template)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $missing[] = $template;
            }
        }

        return $missing;
    }

    private function hasLegacyPublicDir(string $directory): bool
    {
        return is_file($directory . '/homepage.html');
    }
}
