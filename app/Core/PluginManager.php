<?php

declare(strict_types=1);

namespace Mops\Core;

use Throwable;

/**
 * Plugin discovery + activation.
 *
 * A plugin is a folder in plugins/<slug>/ containing plugin.json and an entry
 * file (default plugin.php). The entry file may only talk to MOPS through the
 * hook API and container services — plugins never edit core.
 */
final class PluginManager
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifests = null;

    /** @var array<int, string> */
    private array $loaded = [];

    /** @var array<int, string> */
    private array $errors = [];

    public function __construct(private string $pluginsPath, private Settings $settings)
    {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $manifests = [];
        $directories = is_dir($this->pluginsPath) ? glob(rtrim($this->pluginsPath, '/') . '/*', GLOB_ONLYDIR) ?: [] : [];
        sort($directories, SORT_STRING);

        foreach ($directories as $directory) {
            $slug = basename($directory);
            $manifest = $this->readManifest($directory, $slug);

            $manifest['slug'] = $slug;
            $manifest['path'] = $directory;
            $manifest['active'] = in_array($slug, $this->activeSlugs(), true);
            $manifest['entry_exists'] = is_file($directory . '/' . $manifest['main']);
            $manifest['valid'] = $manifest['entry_exists'] && $manifest['manifest_exists'];

            $manifests[$slug] = $manifest;
        }

        return $this->manifests = $manifests;
    }

    /**
     * @return array<int, string>
     */
    public function activeSlugs(): array
    {
        $slugs = $this->settings->json('active_plugins', []);

        return array_values(array_filter(array_map('strval', $slugs)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function active(): array
    {
        $active = $this->activeSlugs();

        return array_values(array_filter(
            $this->all(),
            static fn (array $plugin): bool => in_array($plugin['slug'], $active, true),
        ));
    }

    public function isActive(string $slug): bool
    {
        return in_array($slug, $this->activeSlugs(), true);
    }

    public function activate(string $slug): void
    {
        $plugin = $this->all()[$slug] ?? null;

        if ($plugin === null) {
            throw new \RuntimeException("Plugin '{$slug}' tidak ditemukan.");
        }

        if (!$plugin['valid']) {
            throw new \RuntimeException("Plugin '{$slug}' tidak valid: manifest atau file utama tidak ditemukan.");
        }

        $this->checkRequirements($plugin);

        if ($this->isActive($slug)) {
            return;
        }

        $this->settings->set('active_plugins', [...$this->activeSlugs(), $slug]);
        $this->manifests = null;

        // Run its bootstrap right away so newly activated hooks work this request.
        $this->load($slug);
        Hook::doAction('plugin_activated', $slug, $plugin);
    }

    public function deactivate(string $slug): void
    {
        $remaining = array_values(array_filter(
            $this->activeSlugs(),
            static fn (string $active): bool => $active !== $slug,
        ));

        $this->settings->set('active_plugins', $remaining);
        $this->manifests = null;

        Hook::doAction('plugin_deactivated', $slug);
    }

    /**
     * Require the entry file of every active plugin.
     */
    public function loadActive(): void
    {
        foreach ($this->activeSlugs() as $slug) {
            $this->load($slug);
        }
    }

    /**
     * @return array<int, string>
     */
    public function loaded(): array
    {
        return $this->loaded;
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Absolute path to a file inside a plugin's own assets/ folder, or null when
     * the plugin or file does not exist (or the path escapes the folder).
     *
     * Plugins live outside the web root, so `/plugin/{slug}/assets/{path}`
     * streams these files — the plugin equivalent of a theme's assets.
     */
    public function assetPath(string $slug, string $path): ?string
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
            return null;
        }

        $base = rtrim($this->pluginsPath, '/') . '/' . $slug . '/assets';

        if (!is_dir($base)) {
            return null;
        }

        $candidate = $base . '/' . ltrim(str_replace('\\', '/', $path), '/');

        if (!is_file($candidate)) {
            return null;
        }

        return Paths::isInside($candidate, $base) ? $candidate : null;
    }

    public function flush(): void
    {
        $this->manifests = null;
    }

    private function load(string $slug): void
    {
        $plugin = $this->all()[$slug] ?? null;

        if ($plugin === null || in_array($slug, $this->loaded, true)) {
            return;
        }

        $entry = $plugin['path'] . '/' . $plugin['main'];

        if (!is_file($entry)) {
            $this->errors[] = "Plugin '{$slug}': file utama tidak ditemukan.";

            return;
        }

        try {
            (static function () use ($entry): void {
                require $entry;
            })();

            $this->loaded[] = $slug;
        } catch (Throwable $e) {
            $this->errors[] = "Plugin '{$slug}' gagal dimuat: " . $e->getMessage();
        }
    }

    /**
     * @param array<string, mixed> $plugin
     */
    private function checkRequirements(array $plugin): void
    {
        $requires = (string) ($plugin['requires'] ?? '');

        if ($requires === '') {
            return;
        }

        if (version_compare(App::VERSION, $requires, '<')) {
            throw new \RuntimeException(
                "Plugin '{$plugin['slug']}' membutuhkan MOPS {$requires}, versi terpasang " . App::VERSION . '.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(string $directory, string $slug): array
    {
        $path = $directory . '/plugin.json';
        $defaults = [
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'version' => '0.0.0',
            'author' => 'Unknown',
            'description' => '',
            'requires' => '',
            'main' => 'plugin.php',
            'manifest_exists' => is_file($path),
        ];

        if (!is_file($path)) {
            return $defaults;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            return $defaults;
        }

        $manifest = array_merge($defaults, $decoded);
        $manifest['manifest_exists'] = true;

        return $manifest;
    }
}
