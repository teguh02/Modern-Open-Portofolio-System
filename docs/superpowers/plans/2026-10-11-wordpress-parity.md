# WordPress Parity (Lifecycle, Admin Menu, Installer, Child Theme) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** MOPS plugin/theme system becomes WordPress-faithful: activation/deactivation hooks, plugin-owned admin pages, zip install/delete from the UI, theme functions.php, and one-level child themes — all without touching core from an extension.

**Architecture:** Incremental extension of the existing managers. `Hook`/`HookZones` stay as-is; we add lifecycle hook names (`activate_{slug}`, `deactivate_{slug}`, `after_switch_theme`, `theme_deactivated`), a static `AdminMenu` registry fed by an `add_menu_page()` helper and rendered by one generic admin route pair, `functions.php` loading in the existing boot sequence, parent-aware template/asset resolution in `ThemeManager`, and a `ZipInstaller` that extracts to `storage/tmp`, validates, then renames into `plugins/`/`themes/`.

**Tech Stack:** PHP 8.2+ (no new dependencies; `ext-zip` already conditionally used by Backup), Slim 4, Twig 3, PHPUnit. Feature tests drive real HTTP requests via `ApplicationTestCase`; file uploads use the `$_FILES` superglobal (same pattern as `MediaController::upload`).

**Spec:** `docs/superpowers/specs/2026-10-11-wordpress-parity-design.md` — every requirement below is argued from that spec; read it before implementing.

## Global Constraints

- Stack is locked: PHP 8.2+, Slim 4, Twig 3, SQLite via PDO, Tailwind + Alpine. **No new Composer or npm dependencies.**
- `composer check` (php -l + PHPUnit) and `php dev/smoke.php` must pass after every task.
- No driver-specific SQL anywhere; `tests/Unit/PortabilityTest.php` enforces it.
- Themes never touch the data layer; plugins extend only through hooks/container/manifests (`tests/Unit/PortabilityTest.php` guards both bundled themes).
- Admin UI language: English (`docs/admin-ui.md`). Public labels: Indonesian.
- All state-changing admin routes are POST inside the `/admin` group (already covered by `AdminAuthMiddleware` + `CsrfMiddleware`).
- Do not weaken `HookZones::REQUIRED_THEME_ZONES` or the fallback-template safety net.
- When a task says "Run tests", the command is `composer test -- --filter <Name>` during iteration and `composer check` at task end.

## File Structure (map)

| File | Responsibility |
|---|---|
| `app/helpers.php` | add `register_activation_hook()`, `register_deactivation_hook()`, `add_menu_page()` |
| `app/Core/PluginManager.php` | fire `activate_{slug}`/`deactivate_{slug}`, add `delete()` |
| `app/Core/ThemeManager.php` | switch hooks, `loadFunctions()`, parent-aware `all()`/`templatePaths()`/asset chain, `delete()`, `assertActivatable()` |
| `app/Core/AdminMenu.php` (new) | static registry of plugin admin pages + reserved slugs |
| `app/Core/ZipInstaller.php` (new) | safe zip extract → validate → atomic move |
| `app/Core/Paths.php` | add `deleteTree()` |
| `app/Core/View.php` | use `templatePaths()` array |
| `app/Core/App.php` | load theme functions + `after_setup_theme`; bind `MenuPageController` |
| `app/Controllers/Controller.php` | nothing (unchanged) |
| `app/Controllers/Admin/AdminController.php` | `admin_menu` filter in `nav()`, shared `guardZipUpload()` |
| `app/Controllers/Admin/MenuPageController.php` (new) | GET/POST `/admin/{menu-slug}` renderer |
| `app/Controllers/Admin/PluginController.php` | `upload()`, `destroy()`, `supports_zip` |
| `app/Controllers/Admin/ThemeController.php` | `upload()`, `destroy()`, `supports_zip`, `errors` |
| `app/routes.php` | admin menu routes + upload/delete routes |
| `app/Views/admin/plugin-page.twig` (new) | shell wrapper for plugin HTML |
| `app/Views/admin/plugins.twig`, `themes.twig` | upload form + delete buttons |
| `tests/TestCase.php` | add `tempDir()` helper |
| `tests/Unit/PluginLifecycleTest.php` (new) | activation/deactivation hook semantics |
| `tests/Unit/ThemeLifecycleTest.php` (new) | switch hook semantics |
| `tests/Unit/AdminMenuTest.php` (new) | registry + reserved slugs |
| `tests/Unit/ThemeFunctionsTest.php` (new) | functions.php boot |
| `tests/Unit/ChildThemeTest.php` (new) | template/asset chain + guards |
| `tests/Unit/ZipInstallerTest.php` (new) | Zip Slip, slug detection, duplicate |
| `tests/Feature/ExtensionParityTest.php` (new) | menu page auth, zip upload flows, uninstall.php |
| `docs/plugin-api.md`, `knowledge.md`, `docs/README.md` | document the new API |

---

### Task 1: Plugin activation/deactivation hooks

**Files:**
- Modify: `app/helpers.php` (append after `apply_filters` block)
- Modify: `app/Core/PluginManager.php` (`activate()` lines 88-112, `deactivate()` lines 114-125)
- Modify: `tests/TestCase.php` (add `tempDir()` helper)
- Test: `tests/Unit/PluginLifecycleTest.php` (new)

**Interfaces:**
- Consumes: `Hook::addAction/doAction/hasAction/reset`, `Settings::get/set/json`, `PluginManager::all/activeSlugs/isActive/load/flush`.
- Produces: global helpers `register_activation_hook(string $file, callable $cb): void`, `register_deactivation_hook(string $file, callable $cb): void`; actions `activate_{slug}($slug, $manifest)` fired **after** the entry file is loaded, `deactivate_{slug}($slug)` fired **only if the plugin was active**, before it leaves `active_plugins`. Later tasks rely on these names unchanged.

- [ ] **Step 1: Add `tempDir()` to `tests/TestCase.php`**

Next to `tempDatabase()`:

```php
    /** @var array<int, string> */
    private array $temporaryDirectories = [];

    protected function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/mops-dir-' . bin2hex(random_bytes(4));

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }

        $this->temporaryDirectories[] = $path;

        return $path;
    }
```

And in `tearDown()`, after the file loop:

```php
        foreach ($this->temporaryDirectories as $directory) {
            \Mops\Core\Paths::deleteTree($directory);
        }

        $this->temporaryDirectories = [];
```

(`Paths::deleteTree()` lands in Task 6 — do Task 6 first if you prefer tests to run green immediately; otherwise the call is a no-op on missing method until then. Recommended order: implement `Paths::deleteTree()` right here as the first commit of Task 1, since it is 10 lines and nothing else needs it.)

Add `deleteTree()` to `app/Core/Paths.php` now:

```php
    /**
     * Recursively delete a directory. Refuses anything outside the project root.
     */
    public static function deleteTree(string $directory): void
    {
        if (!is_dir($directory) || !self::isInside($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_dir($path) && !is_link($path)) {
                self::deleteTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
```

- [ ] **Step 2: Write the failing test `tests/Unit/PluginLifecycleTest.php`**

```php
<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Config;
use Mops\Core\Database;
use Mops\Core\Hook;
use Mops\Core\Paths;
use Mops\Core\PluginManager;
use Mops\Core\Settings;
use Mops\Tests\TestCase;

final class PluginLifecycleTest extends TestCase
{
    private string $pluginsDir;

    protected function setUp(): void
    {
        Hook::reset();
        $GLOBALS['mops_lifecycle'] = [];
        $this->pluginsDir = $this->tempDir();
    }

    protected function tearDown(): void
    {
        Hook::reset();
        $GLOBALS['mops_lifecycle'] = [];

        parent::tearDown();
    }

    public function test_activation_hook_runs_once_when_the_plugin_is_activated(): void
    {
        $settings = $this->settings();
        $this->fixturePlugin('fixture-plugin');
        $manager = new PluginManager($this->pluginsDir, $settings);

        $manager->activate('fixture-plugin');

        $this->assertSame(['activated'], $GLOBALS['mops_lifecycle']);
        $this->assertTrue($manager->isActive('fixture-plugin'));

        // A fresh request only loads the entry file — the hook does not re-run.
        $GLOBALS['mops_lifecycle'] = [];
        $booted = new PluginManager($this->pluginsDir, $settings);
        $booted->loadActive();

        $this->assertSame([], $GLOBALS['mops_lifecycle']);
    }

    public function test_deactivation_hook_runs_before_the_plugin_is_deactivated(): void
    {
        $settings = $this->settings();
        $this->fixturePlugin('fixture-plugin');
        $manager = new PluginManager($this->pluginsDir, $settings);
        $manager->activate('fixture-plugin');

        $GLOBALS['mops_lifecycle'] = [];
        $manager->deactivate('fixture-plugin');

        $this->assertSame(['deactivated'], $GLOBALS['mops_lifecycle']);
        $this->assertFalse($manager->isActive('fixture-plugin'));
        $this->assertSame([], $settings->json('active_plugins', []));
    }

    public function test_activation_helper_is_silent_outside_a_plugin_folder(): void
    {
        register_activation_hook('/nowhere/else.php', static function (): void {
        });

        $this->assertFalse(Hook::hasAction('activate_else'));
    }

    private function settings(): Settings
    {
        $config = Config::fromFile(Paths::make('config/config.php'));
        $config->set('database.driver', 'sqlite');
        $config->set('database.database', $this->tempDatabase());

        return new Settings(new Database((array) $config->get('database')), $config);
    }

    private function fixturePlugin(string $slug): void
    {
        $dir = $this->pluginsDir . '/' . $slug;
        Paths::ensure($dir);

        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => 'Fixture',
            'version' => '1.0.0',
            'main' => 'plugin.php',
        ]));

        file_put_contents($dir . '/plugin.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            register_activation_hook(__FILE__, function (): void {
                $GLOBALS['mops_lifecycle'][] = 'activated';
            });

            register_deactivation_hook(__FILE__, function (): void {
                $GLOBALS['mops_lifecycle'][] = 'deactivated';
            });
            PHP);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `composer test -- --filter PluginLifecycle`
Expected: FAIL — `Call to undefined function register_activation_hook()` (helpers not added yet).

- [ ] **Step 4: Implement the helpers in `app/helpers.php`**

Append after the `apply_filters` block:

```php
if (!function_exists('register_activation_hook')) {
    /**
     * Run $callback once, when this plugin is activated. Pass __FILE__ — the
     * slug is derived from the plugin folder, WordPress-style.
     */
    function register_activation_hook(string $file, callable $callback): void
    {
        $slug = basename(dirname($file));

        if (!is_file(Paths::make('plugins/' . $slug . '/plugin.json'))) {
            return;
        }

        add_action('activate_' . $slug, $callback);
    }
}

if (!function_exists('register_deactivation_hook')) {
    /**
     * Run $callback once, when this plugin is deactivated. Pass __FILE__.
     */
    function register_deactivation_hook(string $file, callable $callback): void
    {
        $slug = basename(dirname($file));

        if (!is_file(Paths::make('plugins/' . $slug . '/plugin.json'))) {
            return;
        }

        add_action('deactivate_' . $slug, $callback);
    }
}
```

Add `use Mops\Core\Paths;` is unnecessary — helpers reference classes FQ or already imported; use `\Mops\Core\Paths::make` inside these functions (the file has `use Mops\Core\App;` at top; add `use Mops\Core\Paths;` next to it).

- [ ] **Step 5: Run the test again — helpers exist, PluginManager does not fire yet**

Run: `composer test -- --filter PluginLifecycle`
Expected: FAIL — `assertSame(['activated'], …)` gets `[]` because `PluginManager` never fires `activate_fixture-plugin`.

- [ ] **Step 6: Fire the hooks in `PluginManager`**

In `activate()`, replace the tail (from `$this->settings->set(...)` to the end) with:

```php
        $this->settings->set('active_plugins', [...$this->activeSlugs(), $slug]);
        $this->manifests = null;

        // Run its bootstrap right away so newly activated hooks work this request.
        $this->load($slug);
        Hook::doAction('activate_' . $slug, $slug, $plugin);
        Hook::doAction('plugin_activated', $slug, $plugin);
```

Replace `deactivate()` entirely with:

```php
    public function deactivate(string $slug): void
    {
        // The entry file must have been loaded for its deactivation hook to run.
        if ($this->isActive($slug)) {
            $this->load($slug);
            Hook::doAction('deactivate_' . $slug, $slug);
        }

        $remaining = array_values(array_filter(
            $this->activeSlugs(),
            static fn (string $active): bool => $active !== $slug,
        ));

        $this->settings->set('active_plugins', $remaining);
        $this->manifests = null;

        Hook::doAction('plugin_deactivated', $slug);
    }
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `composer test -- --filter PluginLifecycle`
Expected: PASS (3 tests).

- [ ] **Step 8: Run the whole suite**

Run: `composer check`
Expected: PASS (existing `ApplicationTest` plugin tests unaffected).

- [ ] **Step 9: Commit**

```bash
git add app/helpers.php app/Core/PluginManager.php app/Core/Paths.php tests/TestCase.php tests/Unit/PluginLifecycleTest.php
git commit -m "feat(plugins): register_activation/deactivation_hook lifecycle actions"
```

---

### Task 2: Theme switch hooks

**Files:**
- Modify: `app/Core/ThemeManager.php` (`activate()` lines 134-151)
- Test: `tests/Unit/ThemeLifecycleTest.php` (new)

**Interfaces:**
- Consumes: `Hook::doAction`, `Settings::get/set`, `ThemeManager::find/activeSlug/exists`.
- Produces: actions `theme_deactivated($oldSlug)` (only on a real switch), `after_switch_theme($newSlug, $oldSlug, $manifest)` (only on a real switch), `theme_activated($slug, $manifest)` (always). Task 8's feature test asserts `after_switch_theme` fires over HTTP.

- [ ] **Step 1: Write the failing test `tests/Unit/ThemeLifecycleTest.php`**

```php
<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Config;
use Mops\Core\Database;
use Mops\Core\Hook;
use Mops\Core\Paths;
use Mops\Core\Settings;
use Mops\Core\ThemeManager;
use Mops\Tests\TestCase;

final class ThemeLifecycleTest extends TestCase
{
    private string $themesDir;

    protected function setUp(): void
    {
        Hook::reset();
        $this->themesDir = $this->tempDir();
    }

    protected function tearDown(): void
    {
        Hook::reset();

        parent::tearDown();
    }

    public function test_switching_themes_fires_deactivated_then_after_switch_theme(): void
    {
        $settings = $this->settings();
        $this->fixtureTheme('alpha');
        $this->fixtureTheme('beta');
        $settings->set('active_theme', 'alpha');
        $themes = new ThemeManager($this->themesDir, $settings);

        $fired = [];
        Hook::addAction('theme_deactivated', static function (string $slug) use (&$fired): void {
            $fired[] = "off:{$slug}";
        });
        Hook::addAction('after_switch_theme', static function (string $new, string $old) use (&$fired): void {
            $fired[] = "switch:{$old}->{$new}";
        });
        Hook::addAction('theme_activated', static function (string $slug) use (&$fired): void {
            $fired[] = "on:{$slug}";
        });

        $themes->activate('beta');

        $this->assertSame(['off:alpha', 'switch:alpha->beta', 'on:beta'], $fired);
        $this->assertSame('beta', $settings->get('active_theme'));
    }

    public function test_reactivating_the_same_theme_skips_theme_deactivated(): void
    {
        $settings = $this->settings();
        $this->fixtureTheme('alpha');
        $settings->set('active_theme', 'alpha');
        $themes = new ThemeManager($this->themesDir, $settings);

        $fired = [];
        Hook::addAction('theme_deactivated', static function (string $slug) use (&$fired): void {
            $fired[] = "off:{$slug}";
        });
        Hook::addAction('theme_activated', static function (string $slug) use (&$fired): void {
            $fired[] = "on:{$slug}";
        });

        $themes->activate('alpha');

        $this->assertSame(['on:alpha'], $fired);
    }

    private function settings(): Settings
    {
        $config = Config::fromFile(Paths::make('config/config.php'));
        $config->set('database.driver', 'sqlite');
        $config->set('database.database', $this->tempDatabase());

        return new Settings(new Database((array) $config->get('database')), $config);
    }

    private function fixtureTheme(string $slug): void
    {
        $dir = $this->themesDir . '/' . $slug;
        Paths::ensure($dir . '/templates');

        file_put_contents($dir . '/theme.json', json_encode([
            'name' => ucfirst($slug),
            'version' => '1.0.0',
        ]));
        file_put_contents($dir . '/templates/layout.twig', '<html>{% block content %}{% endblock %}</html>');
        file_put_contents($dir . '/templates/home.twig', 'home');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test -- --filter ThemeLifecycle`
Expected: FAIL — `['on:beta']` received instead of `['off:alpha', 'switch:alpha->beta', 'on:beta']` (no `theme_deactivated`/`after_switch_theme` today).

- [ ] **Step 3: Implement in `ThemeManager::activate()`**

Replace the body after the validity check with:

```php
        $old = $this->activeSlug();

        $this->settings->set('active_theme', $slug);
        $this->active = $slug;

        if ($old !== $slug) {
            Hook::doAction('theme_deactivated', $old);
            Hook::doAction('after_switch_theme', $slug, $old, $theme);
        }

        Hook::doAction('theme_activated', $slug, $theme);
```

- [ ] **Step 4: Run to verify it passes**

Run: `composer test -- --filter ThemeLifecycle`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the whole suite and commit**

Run: `composer check`
Expected: PASS.

```bash
git add app/Core/ThemeManager.php tests/Unit/ThemeLifecycleTest.php
git commit -m "feat(themes): theme_deactivated + after_switch_theme on real switches"
```

---

### Task 3: Admin menu injection (`add_menu_page`)

**Files:**
- Create: `app/Core/AdminMenu.php`
- Create: `app/Controllers/Admin/MenuPageController.php`
- Create: `app/Views/admin/plugin-page.twig`
- Modify: `app/helpers.php` (append `add_menu_page`)
- Modify: `app/Controllers/Admin/AdminController.php` (`nav()` + `guardZipUpload()` used later)
- Modify: `app/Core/App.php` (`registerControllers()` — add `MenuPageController::class`)
- Modify: `app/routes.php` (menu routes at the end of the `/admin` group)
- Test: `tests/Unit/AdminMenuTest.php` (new), `tests/Feature/ExtensionParityTest.php` (new)

**Interfaces:**
- Consumes: `Hook::applyFilters`, `AdminController::admin()`, Slim group routes, `AdminAuthMiddleware`/`CsrfMiddleware` (already on the group).
- Produces: global `add_menu_page(string $slug, string $title, string $icon, callable $renderer): string`; static `AdminMenu::add/find/all/reset`; routes `GET|POST /admin/{menu:[a-z0-9][a-z0-9-]*}` → `MenuPageController::show` / `submit`; filter `admin_menu` over the sidebar groups array. Task 7/8 UI does not depend on this, but the feature test here is the template for plugin-owned pages.

- [ ] **Step 1: Write the failing unit test `tests/Unit/AdminMenuTest.php`**

```php
<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\AdminMenu;
use Mops\Tests\TestCase;
use RuntimeException;

final class AdminMenuTest extends TestCase
{
    protected function tearDown(): void
    {
        AdminMenu::reset();

        parent::tearDown();
    }

    public function test_add_menu_page_registers_a_page(): void
    {
        $slug = add_menu_page('cv-settings', 'CV Settings', 'document', static fn (): string => '<p>hi</p>');

        $this->assertSame('cv-settings', $slug);

        $page = AdminMenu::find('cv-settings');

        $this->assertNotNull($page);
        $this->assertSame('CV Settings', $page['title']);
        $this->assertSame('document', $page['icon']);
        $this->assertSame('<p>hi</p>', ($page['renderer'])());
    }

    public function test_reserved_core_slugs_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reserved');

        add_menu_page('settings', 'Nope', 'cog', static fn (): string => '');
    }

    public function test_invalid_slug_pattern_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        add_menu_page('Bad Slug', 'Nope', 'cog', static fn (): string => '');
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        add_menu_page('dup', 'One', 'puzzle', static fn (): string => '');

        $this->expectException(RuntimeException::class);

        add_menu_page('dup', 'Two', 'puzzle', static fn (): string => '');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test -- --filter AdminMenuTest`
Expected: FAIL — undefined function `add_menu_page`.

- [ ] **Step 3: Create `app/Core/AdminMenu.php`**

```php
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
            throw new RuntimeException("Menu slug '{$slug}' dipakai core dan tidak bisa dipakai plugin.");
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
```

- [ ] **Step 4: Add the helper to `app/helpers.php`**

```php
if (!function_exists('add_menu_page')) {
    /**
     * Register a plugin-owned admin page at /admin/{slug}. The renderer runs
     * for GET and POST (check the method inside) and returns the page body —
     * core wraps it in the admin shell.
     */
    function add_menu_page(string $slug, string $title, string $icon, callable $renderer): string
    {
        return \Mops\Core\AdminMenu::add($slug, $title, $icon, $renderer);
    }
}
```

- [ ] **Step 5: Run the unit test to verify it passes**

Run: `composer test -- --filter AdminMenuTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Write the failing feature test — append to `tests/Feature/ExtensionParityTest.php` (new file)**

```php
<?php

declare(strict_types=1);

namespace Mops\Tests\Feature;

use Mops\Tests\ApplicationTestCase;
use Psr\Http\Message\ServerRequestInterface;

final class ExtensionParityTest extends ApplicationTestCase
{
    protected function tearDown(): void
    {
        \Mops\Core\AdminMenu::reset();

        parent::tearDown();
    }

    public function test_plugin_admin_page_renders_behind_auth_and_merges_into_the_sidebar(): void
    {
        $this->install();

        add_menu_page('demo-page', 'Demo Page', 'puzzle', static function (): string {
            return '<p>demo body</p>';
        });

        // Guests never see plugin pages.
        $guest = $this->get('/admin/demo-page');
        $this->assertSame(302, $guest->getStatusCode());
        $this->assertStringStartsWith('/admin/login', $guest->getHeaderLine('Location'));

        $this->login();

        $page = $this->get('/admin/demo-page');
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('demo body', $this->body($page));

        // Sidebar picks it up through the admin_menu filter.
        $this->assertStringContainsString('Demo Page', $this->body($this->get('/admin')));

        // Unknown slug is a 404, not a white screen.
        $this->assertSame(404, $this->get('/admin/tidak-ada')->getStatusCode());
    }

    public function test_plugin_admin_page_can_handle_post_with_csrf(): void
    {
        $this->install();

        add_menu_page('demo-post', 'Demo Post', 'puzzle', static function (ServerRequestInterface $request): string {
            if ($request->getMethod() === 'POST') {
                return 'got:' . (string) ($_POST['name'] ?? '');
            }

            return '<form>form</form>';
        });

        $this->login();

        $this->assertStringContainsString('form', $this->body($this->get('/admin/demo-post')));
        $this->assertStringContainsString('got:Teguh', $this->body($this->post('/admin/demo-post', ['name' => 'Teguh'])));
    }
}
```

- [ ] **Step 7: Run to verify it fails**

Run: `composer test -- --filter ExtensionParity`
Expected: FAIL — `/admin/demo-page` currently hits the public 404 fallback (status 404 for the first GET while logged out is a redirect only after routes exist; the 200-with-body assertion fails because no route serves the page).

- [ ] **Step 8: Implement `MenuPageController`, routes, nav filter, view, container binding**

Create `app/Controllers/Admin/MenuPageController.php`:

```php
<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\AdminMenu;
use Mops\Core\Auth;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Serves plugin-owned admin pages registered with add_menu_page(). GET and
 * POST both land here — a settings form posts back to its own page and the
 * plugin reads the method inside the renderer.
 */
final class MenuPageController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderMenuPage($request, $response, (string) ($args['menu'] ?? ''));
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderMenuPage($request, $response, (string) ($args['menu'] ?? ''));
    }

    private function renderMenuPage(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $page = AdminMenu::find($slug);

        if ($page === null) {
            throw new HttpNotFoundException($request);
        }

        $renderer = $page['renderer'];
        $html = (string) $renderer($request);

        return $this->admin($response, 'plugin-page.twig', [
            'title' => $page['title'],
            'crumbs' => $this->crumbs($page['title'], 'Plugins', '/admin/plugins'),
            'body_html' => $html,
        ]);
    }
}
```

Create `app/Views/admin/plugin-page.twig`:

```twig
{% extends 'admin/layout.twig' %}

{% block content %}
  {{ html(body_html) }}
{% endblock %}
```

In `app/Controllers/Admin/AdminController.php::nav()`, change the final `return [...]` to:

```php
        $groups = [
            // ... existing array unchanged ...
        ];

        // Plugins append their own group or slide an item into System — the
        // WordPress add_menu_page() counterpart.
        return \Mops\Core\Hook::applyFilters('admin_menu', $groups);
```

In `app/routes.php`, inside the `/admin` group right after the backup routes:

```php
        // Plugin-owned pages (add_menu_page). Registered last so static core
        // routes always win in FastRoute; the 404 for unknown slugs is thrown
        // by the controller.
        $group->get('/{menu:[a-z0-9][a-z0-9-]*}', Admin\MenuPageController::class . ':show');
        $group->post('/{menu:[a-z0-9][a-z0-9-]*}', Admin\MenuPageController::class . ':submit');
```

In `app/Core/App.php::registerControllers()`, add next to `PluginController::class`:

```php
            Controllers\Admin\MenuPageController::class,
```

- [ ] **Step 9: Run to verify it passes**

Run: `composer test -- --filter ExtensionParity`
Expected: PASS (2 tests).

- [ ] **Step 10: Run the whole suite and commit**

Run: `composer check`
Expected: PASS — static admin routes still win over `{menu}`.

```bash
git add app/Core/AdminMenu.php app/helpers.php app/Controllers/Admin/MenuPageController.php \
  app/Controllers/Admin/AdminController.php app/Views/admin/plugin-page.twig app/routes.php \
  app/Core/App.php tests/Unit/AdminMenuTest.php tests/Feature/ExtensionParityTest.php
git commit -m "feat(admin): add_menu_page registry + generic /admin/{menu} routes"
```

---

### Task 4: Theme functions.php

**Files:**
- Modify: `app/Core/ThemeManager.php` (add `$functionsLoaded`, `errors()`, `loadFunctions()`)
- Modify: `app/Core/App.php` (`loadPlugins()` lines 101-117)
- Test: `tests/Unit/ThemeFunctionsTest.php` (new)

**Interfaces:**
- Consumes: `ThemeManager::templatePaths()` (Task 5 — for this task, iterate the active theme then its optional parent via `find()` + `parent`; if Task 5 is not merged yet, use `templatePath()` and note the follow-up), `do_action('after_setup_theme')`.
- Produces: `ThemeManager::loadFunctions(): void`, `ThemeManager::errors(): array`; boot order becomes `mops_before_plugins → plugin load → plugins_loaded → theme functions.php → after_setup_theme`. Later docs task records this.

- [ ] **Step 1: Write the failing test `tests/Unit/ThemeFunctionsTest.php`**

```php
<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Config;
use Mops\Core\Database;
use Mops\Core\Hook;
use Mops\Core\Paths;
use Mops\Core\Settings;
use Mops\Core\ThemeManager;
use Mops\Tests\TestCase;

final class ThemeFunctionsTest extends TestCase
{
    private string $themesDir;

    protected function setUp(): void
    {
        Hook::reset();
        $this->themesDir = $this->tempDir();
    }

    protected function tearDown(): void
    {
        Hook::reset();
        $GLOBALS['mops_theme_functions'] = [];

        parent::tearDown();
    }

    public function test_functions_php_of_the_active_theme_runs_after_plugins_loaded(): void
    {
        $GLOBALS['mops_theme_functions'] = [];
        $settings = $this->settings();
        $this->fixtureTheme('fn-theme');
        $settings->set('active_theme', 'fn-theme');
        $themes = new ThemeManager($this->themesDir, $settings);

        $themes->loadFunctions();

        $this->assertSame(['ran'], $GLOBALS['mops_theme_functions']);
        $this->assertFalse(Hook::didAction('after_setup_theme')); // core fires that, not the manager

        // Loaded once per manager instance.
        $themes->loadFunctions();
        $this->assertSame(['ran'], $GLOBALS['mops_theme_functions']);
    }

    public function test_broken_functions_php_is_recorded_and_never_fatal(): void
    {
        $settings = $this->settings();
        $dir = $this->themesDir . '/boom';
        Paths::ensure($dir . '/templates');
        file_put_contents($dir . '/theme.json', json_encode(['name' => 'Boom', 'version' => '1.0.0']));
        file_put_contents($dir . '/templates/layout.twig', 'x');
        file_put_contents($dir . '/templates/home.twig', 'x');
        file_put_contents($dir . '/functions.php', '<?php throw new RuntimeException("boom");');
        $settings->set('active_theme', 'boom');
        $themes = new ThemeManager($this->themesDir, $settings);

        $themes->loadFunctions();

        $errors = $themes->errors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('boom', $errors[0]);
    }

    private function settings(): Settings
    {
        $config = Config::fromFile(Paths::make('config/config.php'));
        $config->set('database.driver', 'sqlite');
        $config->set('database.database', $this->tempDatabase());

        return new Settings(new Database((array) $config->get('database')), $config);
    }

    private function fixtureTheme(string $slug): void
    {
        $dir = $this->themesDir . '/' . $slug;
        Paths::ensure($dir . '/templates');

        file_put_contents($dir . '/theme.json', json_encode([
            'name' => ucfirst($slug),
            'version' => '1.0.0',
        ]));
        file_put_contents($dir . '/templates/layout.twig', 'x');
        file_put_contents($dir . '/templates/home.twig', 'x');
        file_put_contents($dir . '/functions.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            $GLOBALS['mops_theme_functions'][] = 'ran';
            PHP);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test -- --filter ThemeFunctionsTest`
Expected: FAIL — no `loadFunctions()` method.

- [ ] **Step 3: Implement in `ThemeManager`**

Add properties near `$active`:

```php
    /** @var array<int, string> */
    private array $functionsLoaded = [];

    /** @var array<int, string> */
    private array $errors = [];
```

Add methods after `assetPath()`:

```php
    /**
     * Require functions.php of the active theme and its parent (WordPress
     * order: child first). Runs once per request; a broken theme is recorded,
     * never fatal.
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
```

Note: `flush()` must also reset `$this->functionsLoaded = []` and `$this->errors = []` — add that.

- [ ] **Step 4: Wire it into `App::loadPlugins()`**

```php
        // Plugins must have the hook API available before they are loaded.
        do_action('mops_before_plugins');

        if ($this->isInstalled()) {
            $this->container->get(PluginManager::class)->loadActive();
        }

        do_action('plugins_loaded', $this);

        // WordPress order: the active theme's functions.php runs after plugins.
        if ($this->isInstalled()) {
            $this->container->get(ThemeManager::class)->loadFunctions();
            do_action('after_setup_theme');
        }
```

- [ ] **Step 5: Run to verify it passes**

Run: `composer test -- --filter ThemeFunctionsTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Whole suite + commit**

Run: `composer check`
Expected: PASS.

```bash
git add app/Core/ThemeManager.php app/Core/App.php tests/Unit/ThemeFunctionsTest.php
git commit -m "feat(themes): load functions.php after plugins_loaded, fire after_setup_theme"
```

---

### Task 5: Child theme (parent-aware templates + assets)

**Files:**
- Modify: `app/Core/ThemeManager.php` (`all()` two-pass, `templatePaths()`, `templatePath()`, `assetUrl()`, `assetPath()`, `activate()` + `assertActivatable()`)
- Modify: `app/Core/View.php` (`environment()` lines 46-52)
- Test: `tests/Unit/ChildThemeTest.php` (new)

**Interfaces:**
- Consumes: Task 2's `activate()`; `Paths::isInside`.
- Produces: `ThemeManager::templatePaths(?string $slug = null): array<int,string>` (child templates dir first, then parent's; fallback dir when empty); `ThemeManager::templatePath()` keeps returning the first entry; `ThemeManager::assertActivatable(string $slug): void` (throws on invalid/missing parent/3-level/cycle/missing chain templates) — Task 8 calls it after a zip install. `all()` now computes `missing_templates`/`valid` across the child+parent chain. `assetUrl`/`assetPath` fall back to the parent's `assets/`.

- [ ] **Step 1: Write the failing test `tests/Unit/ChildThemeTest.php`**

```php
<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Config;
use Mops\Core\Database;
use Mops\Core\Hook;
use Mops\Core\Paths;
use Mops\Core\Settings;
use Mops\Core\ThemeManager;
use Mops\Tests\TestCase;
use RuntimeException;

final class ChildThemeTest extends TestCase
{
    private string $themesDir;

    protected function setUp(): void
    {
        Hook::reset();
        $this->themesDir = $this->tempDir();
    }

    protected function tearDown(): void
    {
        Hook::reset();

        parent::tearDown();
    }

    public function test_template_paths_resolve_child_first_then_parent(): void
    {
        $this->fixtureTheme('parent-theme');
        $this->fixtureTheme('child-theme', ['parent' => 'parent-theme']);
        $themes = $this->manager('child-theme');

        $this->assertSame(
            [$this->themesDir . '/child-theme/templates', $this->themesDir . '/parent-theme/templates'],
            $themes->templatePaths(),
        );
    }

    public function test_child_is_valid_when_only_the_parent_ships_a_template(): void
    {
        $this->fixtureTheme('parent-theme');
        $this->fixtureTheme('child-theme', ['parent' => 'parent-theme']);
        unlink($this->themesDir . '/child-theme/templates/home.twig');
        $themes = $this->manager('child-theme');

        $child = $themes->find('child-theme');

        $this->assertTrue($child['valid']);
        $this->assertSame([], $child['missing_templates']);
    }

    public function test_asset_urls_fall_back_to_the_parent(): void
    {
        $this->fixtureTheme('parent-theme');
        file_put_contents($this->themesDir . '/parent-theme/assets/parent.css', 'p{}');
        $this->fixtureTheme('child-theme', ['parent' => 'parent-theme']);
        $themes = $this->manager('child-theme');

        $url = $themes->assetUrl('parent.css');

        $this->assertStringStartsWith('/theme/child-theme/assets/parent.css?v=', $url);
        $this->assertNotNull($themes->assetPath('child-theme', 'parent.css'));
    }

    public function test_activation_is_rejected_when_the_parent_is_missing(): void
    {
        $this->fixtureTheme('child-theme', ['parent' => 'nope']);
        $themes = $this->manager('parent-theme-missing-active');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nope');

        $themes->activate('child-theme');
    }

    public function test_three_level_chains_are_rejected(): void
    {
        $this->fixtureTheme('grand');
        $this->fixtureTheme('mid', ['parent' => 'grand']);
        $this->fixtureTheme('leaf', ['parent' => 'mid']);
        $themes = $this->manager('grand');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('level');

        $themes->activate('leaf');
    }

    private function manager(string $active): ThemeManager
    {
        $settings = $this->settings();

        if ($active !== 'parent-theme-missing-active') {
            $settings->set('active_theme', $active);
        }

        return new ThemeManager($this->themesDir, $settings);
    }

    private function settings(): Settings
    {
        $config = Config::fromFile(Paths::make('config/config.php'));
        $config->set('database.driver', 'sqlite');
        $config->set('database.database', $this->tempDatabase());

        return new Settings(new Database((array) $config->get('database')), $config);
    }

    private function fixtureTheme(string $slug, array $extra = []): void
    {
        $dir = $this->themesDir . '/' . $slug;
        Paths::ensure($dir . '/templates');
        Paths::ensure($dir . '/assets');

        file_put_contents($dir . '/theme.json', json_encode(array_merge([
            'name' => ucfirst($slug),
            'version' => '1.0.0',
        ], $extra)));
        file_put_contents($dir . '/templates/layout.twig', 'x');
        file_put_contents($dir . '/templates/home.twig', 'x');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test -- --filter ChildThemeTest`
Expected: FAIL — `templatePaths()` undefined / child marked invalid.

- [ ] **Step 3: Rework `ThemeManager::all()` to a two-pass build**

Replace `all()` with:

```php
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
```

Rename the old `missingTemplates(string $templatePath)` to:

```php
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
```

- [ ] **Step 4: Replace `templatePath()` with a chain-aware version**

```php
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
```

- [ ] **Step 5: Parent-aware `assetUrl()` and `assetPath()`**

Replace both methods with:

```php
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

    public function assetPath(string $slug, string $path): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $path), '/');

        foreach ($this->assetChain($slug, $relative) as $absolute) {
            if (is_file($absolute) && Paths::isInside($absolute)) {
                return $absolute;
            }
        }

        return null;
    }

    /**
     * Candidate absolute asset paths for a relative file, child first then parent.
     *
     * @return array<int, string>
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

        return array_map(
            static fn (string $base): string => rtrim($base, '/') . '/' . $relative,
            $bases,
        );
    }
```

- [ ] **Step 6: Validation for activation — extract `assertActivatable()`**

Add to `ThemeManager`:

```php
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
```

And make `activate()` call it in place of the inline `find`/valid checks (keep the `RuntimeException` messages identical for the non-child path):

```php
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
```

- [ ] **Step 7: `View::environment()` uses the chain**

In `app/Core/View.php`:

```php
        $paths = Hook::applyFilters('view_paths', [
            ...$this->themes->templatePaths(),
            Paths::make('app/Views'),
            Paths::make('app/Views/fallback'),
        ]);
```

- [ ] **Step 8: Run to verify it passes**

Run: `composer test -- --filter ChildThemeTest`
Expected: PASS (5 tests).

- [ ] **Step 9: Whole suite + commit**

Run: `composer check`
Expected: PASS — `PortabilityTest` still green (bundled themes unchanged, still complete).

```bash
git add app/Core/ThemeManager.php app/Core/View.php tests/Unit/ChildThemeTest.php
git commit -m "feat(themes): one-level child themes with template/asset parent fallback"
```

---

### Task 6: ZipInstaller

**Files:**
- Create: `app/Core/ZipInstaller.php`
- Modify: `app/Core/App.php` (`registerServices()` — container entry)
- Test: `tests/Unit/ZipInstallerTest.php` (new)

**Interfaces:**
- Consumes: `Paths::ensure/isInside/deleteTree`, `ZipArchive` (guarded).
- Produces: `new ZipInstaller(string $tmpRoot)`; `install(string $zipPath, string $targetRoot, string $manifest): string` returns the slug; throws `RuntimeException` on unsafe entries, bad structure, missing `slug` (flat zips), duplicate target. Tasks 7/8 call exactly this signature.

- [ ] **Step 1: Write the failing test `tests/Unit/ZipInstallerTest.php`**

```php
<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Paths;
use Mops\Core\ZipInstaller;
use Mops\Tests\TestCase;
use RuntimeException;
use ZipArchive;

final class ZipInstallerTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip tidak tersedia.');
        }

        $this->work = $this->tempDir();
    }

    public function test_rejects_parent_traversal_entries(): void
    {
        $zip = $this->zip(['../evil.php' => '<?php']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak aman');

        $this->installer()->install($zip, $this->work . '/plugins', 'plugin.json');
    }

    public function test_rejects_absolute_path_entries(): void
    {
        $zip = $this->zip(['/etc/passwd' => 'x']);

        $this->expectException(RuntimeException::class);

        $this->installer()->install($zip, $this->work . '/plugins', 'plugin.json');
    }

    public function test_installs_a_zip_with_one_top_folder(): void
    {
        $zip = $this->zip([
            'demo-plugin/plugin.json' => json_encode(['main' => 'plugin.php']),
            'demo-plugin/plugin.php' => '<?php // entry',
        ]);
        $target = $this->work . '/plugins';

        $slug = $this->installer()->install($zip, $target, 'plugin.json');

        $this->assertSame('demo-plugin', $slug);
        $this->assertFileExists($target . '/demo-plugin/plugin.php');
        $this->assertFileExists($target . '/demo-plugin/plugin.json');
    }

    public function test_flat_zip_uses_the_manifest_slug(): void
    {
        $zip = $this->zip([
            'plugin.json' => json_encode(['slug' => 'flat-plugin', 'main' => 'plugin.php']),
            'plugin.php' => '<?php // entry',
        ]);
        $target = $this->work . '/plugins';

        $slug = $this->installer()->install($zip, $target, 'plugin.json');

        $this->assertSame('flat-plugin', $slug);
        $this->assertFileExists($target . '/flat-plugin/plugin.php');
    }

    public function test_flat_zip_without_a_slug_is_rejected(): void
    {
        $zip = $this->zip([
            'plugin.json' => json_encode(['main' => 'plugin.php']),
            'plugin.php' => '<?php // entry',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slug');

        $this->installer()->install($zip, $this->work . '/plugins', 'plugin.json');
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        Paths::ensure($this->work . '/plugins/exists');
        $zip = $this->zip(['exists/plugin.json' => '{}', 'exists/plugin.php' => '<?php']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah ada');

        $this->installer()->install($zip, $this->work . '/plugins', 'plugin.json');
    }

    private function installer(): ZipInstaller
    {
        return new ZipInstaller($this->work . '/tmp');
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $path = $this->work . '/upload-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test -- --filter ZipInstallerTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `app/Core/ZipInstaller.php`**

```php
<?php

declare(strict_types=1);

namespace Mops\Core;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Installs a plugin or theme from a zip uploaded by an admin.
 *
 * Zip Slip protection, validation in a staging folder under storage/tmp, and
 * an atomic rename into plugins/ or themes/ — never a partial install.
 */
final class ZipInstaller
{
    public function __construct(private string $tmpRoot)
    {
        Paths::ensure($this->tmpRoot);
    }

    /**
     * @param string $manifest 'plugin.json' or 'theme.json'
     * @return string the installed slug
     */
    public function install(string $zipPath, string $targetRoot, string $manifest): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi zip tidak tersedia di server ini.');
        }

        if (!is_file($zipPath)) {
            throw new RuntimeException('File zip tidak ditemukan.');
        }

        $extractDir = $this->tmpRoot . '/install-' . bin2hex(random_bytes(6));
        Paths::ensure($extractDir);

        try {
            $this->extract($zipPath, $extractDir);
            [$root, $slug] = $this->detect($extractDir, $manifest);

            $target = rtrim($targetRoot, '/') . '/' . $slug;

            if (is_dir($target)) {
                throw new RuntimeException("Slug '{$slug}' sudah ada — hapus dulu atau gunakan nama lain.");
            }

            Paths::ensure($targetRoot);

            if (!@rename($root, $target)) {
                throw new RuntimeException("Gagal memindahkan '{$slug}' ke tujuan.");
            }

            return $slug;
        } finally {
            Paths::deleteTree($extractDir);
        }
    }

    private function extract(string $zipPath, string $extractDir): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('File zip tidak bisa dibuka.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $name = str_replace('\\', '/', $name);

                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                if (str_starts_with($name, '/') || preg_match('#^[a-zA-Z]:#', $name) === 1 || str_contains($name, '..')) {
                    throw new RuntimeException("Entry zip tidak aman: {$name}");
                }

                if ($this->isSymlink($zip, $i)) {
                    throw new RuntimeException("Entry zip berupa symlink: {$name}");
                }

                if ($zip->extractTo($extractDir, $name) !== true) {
                    throw new RuntimeException("Gagal mengekstrak entry: {$name}");
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;

        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        // Unix mode bits live in the high 16 bits; 0120000 = symlink.
        return $opsys === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000);
    }

    /**
     * @return array{0: string, 1: string} [content root, slug]
     */
    private function detect(string $extractDir, string $manifest): array
    {
        $entries = array_values(array_filter(scandir($extractDir) ?: [], static fn (string $e): bool => $e !== '.' && $e !== '..'));
        $dirs = array_values(array_filter($entries, static fn (string $e): bool => is_dir($extractDir . '/' . $e)));
        $files = array_values(array_filter($entries, static fn (string $e): bool => is_file($extractDir . '/' . $e)));

        if (count($dirs) === 1 && count($files) === 0) {
            $slug = $dirs[0];

            if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
                throw new RuntimeException("Nama folder '{$slug}' bukan slug yang valid.");
            }

            if (!is_file($extractDir . '/' . $slug . '/' . $manifest)) {
                throw new RuntimeException("Folder '{$slug}' tidak berisi {$manifest}.");
            }

            return [$extractDir . '/' . $slug, $slug];
        }

        if (!is_file($extractDir . '/' . $manifest)) {
            throw new RuntimeException("Zip tidak berisi {$manifest} di root-nya.");
        }

        $decoded = json_decode((string) file_get_contents($extractDir . '/' . $manifest), true);
        $slug = is_array($decoded) ? ($decoded['slug'] ?? null) : null;

        if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
            throw new RuntimeException('Zip datar wajib punya field "slug" yang valid di ' . $manifest . '.');
        }

        return [$extractDir, $slug];
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `composer test -- --filter ZipInstallerTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Register in the container + commit**

In `app/Core/App.php::registerServices()`, next to `Backup`:

```php
        $container->set(ZipInstaller::class, fn (): ZipInstaller => new ZipInstaller(
            Paths::ensure(Paths::make('storage/tmp')),
        ));
```

Run: `composer check`
Expected: PASS.

```bash
git add app/Core/ZipInstaller.php app/Core/App.php tests/Unit/ZipInstallerTest.php
git commit -m "feat(core): ZipInstaller with Zip Slip protection and staged validation"
```

---

### Task 7: Plugin install + delete (upload UI, uninstall.php)

**Files:**
- Modify: `app/Core/PluginManager.php` (add `delete()`)
- Modify: `app/Controllers/Admin/PluginController.php` (constructor, `index()`, `upload()`, `destroy()`)
- Modify: `app/Controllers/Admin/AdminController.php` (add `guardZipUpload()`)
- Modify: `app/routes.php` (upload/delete routes)
- Modify: `app/Views/admin/plugins.twig` (upload form + delete button)
- Test: `tests/Feature/ExtensionParityTest.php` (append)

**Interfaces:**
- Consumes: `ZipInstaller::install(zip, targetRoot, 'plugin.json')` from Task 6; `PluginManager::flush/all/isActive/deactivate`; `AdminController::flash/redirect/crumbs`.
- Produces: `PluginManager::delete(string $slug): void` (refuses active; runs `uninstall.php` if present; strips the slug from `active_plugins`; removes the folder); routes `POST /admin/plugins/upload`, `POST /admin/plugins/{slug}/delete`. UI template reads `supports_zip`.

- [ ] **Step 1: Write the failing feature tests — append to `ExtensionParityTest`**

```php
    public function test_plugin_zip_upload_activate_and_delete_flow(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip tidak tersedia.');
        }

        $this->install();
        $this->login();

        $GLOBALS['mops_zip_plugin_ran'] = false;
        $zip = $this->makeZip([
            'zip-uploaded/plugin.json' => json_encode(['name' => 'Zip Uploaded', 'version' => '1.0.0', 'main' => 'plugin.php']),
            'zip-uploaded/plugin.php' => '<?php $GLOBALS["mops_zip_plugin_ran"] = true;',
        ]);

        $_FILES['zip'] = [
            'name' => 'plugin.zip',
            'tmp_name' => $zip,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($zip),
        ];

        $response = $this->post('/admin/plugins/upload', []);
        unset($_FILES['zip']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertDirectoryExists(Paths::make('plugins/zip-uploaded'));

        try {
            // Deleting an active plugin is refused, WordPress-style.
            $this->activatePlugin('zip-uploaded');
            $this->post('/admin/plugins/zip-uploaded/delete', []);
            $this->assertDirectoryExists(Paths::make('plugins/zip-uploaded'));

            $this->post('/admin/plugins/zip-uploaded/deactivate', []);
            $this->post('/admin/plugins/zip-uploaded/delete', []);
            $this->assertDirectoryDoesNotExist(Paths::make('plugins/zip-uploaded'));
        } finally {
            Paths::deleteTree(Paths::make('plugins/zip-uploaded'));
        }
    }

    public function test_plugin_delete_runs_uninstall_php(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip tidak tersedia.');
        }

        $this->install();
        $this->login();

        $marker = Paths::make('storage/tmp/uninstall-marker.txt');
        @unlink($marker);

        $zip = $this->makeZip([
            'zip-uninstall/plugin.json' => json_encode(['main' => 'plugin.php']),
            'zip-uninstall/plugin.php' => '<?php // entry',
            'zip-uninstall/uninstall.php' => '<?php file_put_contents(' . var_export($marker, true) . ', "cleaned");',
        ]);

        $_FILES['zip'] = [
            'name' => 'plugin.zip',
            'tmp_name' => $zip,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($zip),
        ];
        $this->post('/admin/plugins/upload', []);
        unset($_FILES['zip']);

        try {
            $this->post('/admin/plugins/zip-uninstall/delete', []);

            $this->assertSame('cleaned', is_file($marker) ? file_get_contents($marker) : '');
            $this->assertDirectoryDoesNotExist(Paths::make('plugins/zip-uninstall'));
        } finally {
            @unlink($marker);
            Paths::deleteTree(Paths::make('plugins/zip-uninstall'));
        }
    }

    /** @param array<string, string> $entries */
    private function makeZip(array $entries): string
    {
        $path = $this->tempDir() . '/upload.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }
```

Add `use Mops\Core\Paths;` to the top of `ExtensionParityTest`.

- [ ] **Step 2: Run to verify it fails**

Run: `composer test -- --filter ExtensionParity`
Expected: FAIL — no `/admin/plugins/upload` route (404).

- [ ] **Step 3: `PluginManager::delete()`**

```php
    /**
     * Remove an inactive plugin: run its uninstall.php (if any), forget the
     * slug and delete the folder. Mirrors WordPress' delete rules.
     */
    public function delete(string $slug): void
    {
        $plugin = $this->all()[$slug] ?? null;

        if ($plugin === null) {
            throw new \RuntimeException("Plugin '{$slug}' tidak ditemukan.");
        }

        if ($this->isActive($slug)) {
            throw new \RuntimeException("Nonaktifkan plugin '{$slug}' sebelum menghapusnya.");
        }

        $directory = (string) $plugin['path'];

        if (!Paths::isInside($directory, $this->pluginsPath)) {
            throw new \RuntimeException("Folder plugin '{$slug}' tidak aman untuk dihapus.");
        }

        $uninstall = $directory . '/uninstall.php';

        if (is_file($uninstall)) {
            try {
                (static function () use ($uninstall): void {
                    require $uninstall;
                })();
            } catch (Throwable $e) {
                $this->errors[] = "Plugin '{$slug}': uninstall.php gagal: " . $e->getMessage();
            }
        }

        // Defensive: an inactive plugin must not linger in the active list.
        $this->settings->set('active_plugins', array_values(array_filter(
            $this->activeSlugs(),
            static fn (string $active): bool => $active !== $slug,
        )));

        Paths::deleteTree($directory);
        $this->flush();
    }
```

- [ ] **Step 4: `guardZipUpload()` on `AdminController`**

```php
    /**
     * Validate a zip upload from $_FILES['zip'] (same superglobal pattern as
     * MediaController). Returns the temporary file path.
     */
    protected function guardZipUpload(mixed $file): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Ekstensi zip tidak tersedia di server ini.');
        }

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Pilih file zip yang valid.');
        }

        $temporary = (string) ($file['tmp_name'] ?? '');

        if ($temporary === '' || !is_file($temporary)) {
            throw new \RuntimeException('File upload tidak valid.');
        }

        $maxBytes = (int) (mops(\Mops\Core\Config::class)->get('uploads.max_mb', 8)) * 1024 * 1024;

        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new \RuntimeException('Ukuran zip melebihi batas ' . (int) (mops(\Mops\Core\Config::class)->get('uploads.max_mb', 8)) . ' MB.');
        }

        return $temporary;
    }
```

- [ ] **Step 5: `PluginController::upload()` / `destroy()` + constructor + index flag**

Constructor gains two params (registered automatically by the container `bind`):

```php
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
        private PluginManager $plugins,
        private \Mops\Core\ZipInstaller $installer,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry);
    }
```

In `index()`, add to the data array:

```php
            'supports_zip' => class_exists(\ZipArchive::class),
```

Add methods:

```php
    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $zip = $this->guardZipUpload($_FILES['zip'] ?? null);
            $slug = $this->installer->install($zip, Paths::make('plugins'), 'plugin.json');
            $this->plugins->flush();
            $this->activity->log('plugin_install', "Plugin diinstal: {$slug}", $this->auth->id(), 'plugin');
            $this->flash('success', "Plugin '{$slug}' terpasang. Aktifkan untuk mulai memakainya.");
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/plugins');
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');

        try {
            $this->plugins->delete($slug);
            $this->view->refresh();
            $this->activity->log('plugin_delete', "Plugin dihapus: {$slug}", $this->auth->id(), 'plugin');
            $this->flash('success', "Plugin '{$slug}' dihapus.");
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/plugins');
    }
```

Add `use Mops\Core\Paths;` and `use Mops\Core\ZipInstaller;` at the top (replace the FQCN in the constructor with `ZipInstaller`).

- [ ] **Step 6: Routes + UI**

In `app/routes.php`, after the existing plugin routes:

```php
        $group->post('/plugins/upload', Admin\PluginController::class . ':upload');
        $group->post('/plugins/{slug}/delete', Admin\PluginController::class . ':destroy');
```

In `app/Views/admin/plugins.twig`, above the plugin list grid (or in the third column under "Injection Zones"), add:

```twig
  {% if supports_zip %}
    <div class="card">
      <div class="card-header">
        <h2 class="text-sm font-semibold text-ink">Upload Plugin</h2>
      </div>
      <div class="card-body">
        <form action="/admin/plugins/upload" method="post" enctype="multipart/form-data" class="space-y-3">
          {{ csrf_field() }}
          <input type="file" name="zip" accept=".zip" required class="field">
          <p class="text-xs text-muted">Zip with <code>plugin.json</code> at its root or inside a single top folder.</p>
          <button type="submit" class="btn-primary w-full">Upload</button>
        </form>
      </div>
    </div>
  {% endif %}
```

Next to each plugin's Activate/Deactivate forms, add:

```twig
              <form action="/admin/plugins/{{ plugin.slug }}/delete" method="post"
                    onsubmit="return confirm('Delete this plugin and all its files?');">
                {{ csrf_field() }}
                <button type="submit" class="btn-secondary text-red-600" {{ plugin.is_active ? 'disabled' : '' }}>Delete</button>
              </form>
```

- [ ] **Step 7: Run to verify it passes**

Run: `composer test -- --filter ExtensionParity`
Expected: PASS (all tests in the file).

- [ ] **Step 8: Whole suite + commit**

Run: `composer check && php dev/smoke.php`
Expected: PASS.

```bash
git add app/Core/PluginManager.php app/Controllers/Admin/PluginController.php \
  app/Controllers/Admin/AdminController.php app/routes.php app/Views/admin/plugins.twig \
  tests/Feature/ExtensionParityTest.php
git commit -m "feat(plugins): zip upload + delete with uninstall.php"
```

---

### Task 8: Theme install + delete

**Files:**
- Modify: `app/Core/ThemeManager.php` (add `delete()`)
- Modify: `app/Controllers/Admin/ThemeController.php` (constructor, `index()`, `upload()`, `destroy()`)
- Modify: `app/routes.php` (upload/delete routes)
- Modify: `app/Views/admin/themes.twig` (upload form + delete button, errors block)
- Test: `tests/Feature/ExtensionParityTest.php` (append)

**Interfaces:**
- Consumes: `ThemeManager::assertActivatable()` (Task 5), `ZipInstaller::install(zip, targetRoot, 'theme.json')`, `after_switch_theme` action (Task 2).
- Produces: `ThemeManager::delete(string $slug): void` (refuses active theme and the parent of the active theme); routes `POST /admin/themes/upload`, `POST /admin/themes/{slug}/delete`.

- [ ] **Step 1: Write the failing feature test — append to `ExtensionParityTest`**

```php
    public function test_theme_zip_upload_switch_hooks_and_delete_rules(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip tidak tersedia.');
        }

        $this->install();
        $this->login();

        $zip = $this->makeZip([
            'zip-child/theme.json' => json_encode(['name' => 'Zip Child', 'version' => '1.0.0', 'parent' => 'vantablo']),
            'zip-child/templates/layout.twig' => "<html><body>{% block content %}{% endblock %}</body></html>",
            'zip-child/templates/home.twig' => 'child home',
        ]);

        $_FILES['zip'] = [
            'name' => 'theme.zip',
            'tmp_name' => $zip,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($zip),
        ];

        $response = $this->post('/admin/themes/upload', []);
        unset($_FILES['zip']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertDirectoryExists(Paths::make('themes/zip-child'));

        $switches = [];
        \Mops\Core\Hook::addAction('after_switch_theme', static function (string $new, string $old) use (&$switches): void {
            $switches[] = "{$old}->{$new}";
        });

        try {
            $this->assertSame(302, $this->post('/admin/themes/zip-child/activate', [])->getStatusCode());
            $this->assertSame('zip-child', $this->app->getContainer()->get(\Mops\Core\Settings::class)->get('active_theme'));
            $this->assertContains('vantablo->zip-child', $switches);

            // The active theme cannot be deleted.
            $this->post('/admin/themes/zip-child/delete', []);
            $this->assertDirectoryExists(Paths::make('themes/zip-child'));

            // Switch back, then delete is allowed.
            $this->post('/admin/themes/vantablo/activate', []);
            $this->post('/admin/themes/zip-child/delete', []);
            $this->assertDirectoryDoesNotExist(Paths::make('themes/zip-child'));
        } finally {
            Paths::deleteTree(Paths::make('themes/zip-child'));
        }
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test -- --filter ExtensionParity`
Expected: FAIL — no upload route.

- [ ] **Step 3: `ThemeManager::delete()`**

```php
    /**
     * Remove an inactive theme. Refuses the active theme and any theme that
     * the active theme inherits from.
     */
    public function delete(string $slug): void
    {
        $theme = $this->find($slug);

        if ($theme === null) {
            throw new RuntimeException("Tema '{$slug}' tidak ditemukan.");
        }

        if ($this->activeSlug() === $slug) {
            throw new RuntimeException("Tema aktif '{$slug}' tidak bisa dihapus.");
        }

        $active = $this->active();

        if ((string) ($active['parent'] ?? '') === $slug) {
            throw new RuntimeException("Tema '{$slug}' adalah induk tema aktif '{$active['slug']}' dan tidak bisa dihapus.");
        }

        $directory = (string) $theme['path'];

        if (!Paths::isInside($directory, $this->themesPath)) {
            throw new RuntimeException("Folder tema '{$slug}' tidak aman untuk dihapus.");
        }

        Paths::deleteTree($directory);
        $this->flush();
    }
```

- [ ] **Step 4: `ThemeController::upload()` / `destroy()` + constructor + index flags**

Constructor gains `private \Mops\Core\ZipInstaller $installer` (import `ZipInstaller`, `Paths`, `Throwable` already imported).

In `index()` add:

```php
            'supports_zip' => class_exists(\ZipArchive::class),
            'load_errors' => $this->themes->errors(),
```

Add methods:

```php
    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $slug = null;

        try {
            $zip = $this->guardZipUpload($_FILES['zip'] ?? null);
            $slug = $this->installer->install($zip, Paths::make('themes'), 'theme.json');
            $this->themes->flush();

            // Child rules (parent exists, max 2 levels) apply before it can stay.
            $this->themes->assertActivatable($slug);

            $this->view->refresh();
            $this->activity->log('theme_install', "Tema diinstal: {$slug}", $this->auth->id(), 'theme');
            $this->flash('success', "Tema '{$slug}' terpasang.");
        } catch (Throwable $e) {
            if ($slug !== null) {
                Paths::deleteTree(Paths::make('themes/' . $slug));
                $this->themes->flush();
            }

            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/themes');
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');

        try {
            $this->themes->delete($slug);
            $this->view->refresh();
            $this->activity->log('theme_delete', "Tema dihapus: {$slug}", $this->auth->id(), 'theme');
            $this->flash('success', "Tema '{$slug}' dihapus.");
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/themes');
    }
```

- [ ] **Step 5: Routes + UI**

In `app/routes.php`, after the theme activate route:

```php
        $group->post('/themes/upload', Admin\ThemeController::class . ':upload');
        $group->post('/themes/{slug}/delete', Admin\ThemeController::class . ':destroy');
```

In `app/Views/admin/themes.twig`, at the top of the content area:

```twig
  {% if load_errors|length > 0 %}
    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {% for error in load_errors %}<p>{{ error }}</p>{% endfor %}
    </div>
  {% endif %}

  {% if supports_zip %}
    <div class="card mb-5">
      <div class="card-header">
        <h2 class="text-sm font-semibold text-ink">Upload Theme</h2>
      </div>
      <div class="card-body">
        <form action="/admin/themes/upload" method="post" enctype="multipart/form-data" class="space-y-3">
          {{ csrf_field() }}
          <input type="file" name="zip" accept=".zip" required class="field">
          <p class="text-xs text-muted">Zip with <code>theme.json</code> (add <code>"parent"</code> for a child theme) and a <code>templates/</code> folder.</p>
          <button type="submit" class="btn-primary">Upload</button>
        </form>
      </div>
    </div>
  {% endif %}
```

And a Delete button per theme card, next to the Activate form:

```twig
              <form action="/admin/themes/{{ item.slug }}/delete" method="post"
                    onsubmit="return confirm('Delete this theme and all its files?');">
                {{ csrf_field() }}
                <button type="submit" class="btn-secondary text-red-600"
                        {{ item.is_active ? 'disabled' : '' }}>Delete</button>
              </form>
```

- [ ] **Step 6: Run to verify it passes**

Run: `composer test -- --filter ExtensionParity`
Expected: PASS.

- [ ] **Step 7: Whole suite + smoke + commit**

Run: `composer check && php dev/smoke.php`
Expected: PASS.

```bash
git add app/Core/ThemeManager.php app/Controllers/Admin/ThemeController.php app/routes.php \
  app/Views/admin/themes.twig tests/Feature/ExtensionParityTest.php
git commit -m "feat(themes): zip upload + delete with child-theme guardrails"
```

---

### Task 9: Documentation + final verification

**Files:**
- Modify: `docs/plugin-api.md`
- Modify: `knowledge.md`
- Modify: `docs/README.md`

**Interfaces:**
- Consumes: everything from Tasks 1-8 (documentation only — no code).

- [ ] **Step 1: `docs/plugin-api.md` — extend the WP↔MOPS table and add sections**

Add rows to the comparison table (section 1):

| WordPress | MOPS | Keterangan |
|---|---|---|
| `register_activation_hook()` | `register_activation_hook(__FILE__, $cb)` | jalan sekali saat plugin diaktifkan |
| `register_deactivation_hook()` | `register_deactivation_hook(__FILE__, $cb)` | jalan sekali saat dinonaktifkan |
| `after_switch_theme` | `after_switch_theme` | nama hook sama; plus `theme_deactivated` |
| `add_menu_page()` | `add_menu_page($slug, $title, $icon, $renderer)` | halaman admin milik plugin di `/admin/{slug}` (GET+POST) |
| `functions.php` tema | `themes/<slug>/functions.php` | dimuat setelah `plugins_loaded`, sebelum `after_setup_theme` |
| Child theme (`Template:`) | `theme.json` `"parent": "<slug>"` | fallback template + asset ke parent, maks 2 level |
| Upload/hapus plugin & tema | form Upload + tombol Delete di `/admin/plugins`, `/admin/themes` | zip lokal, validasi di staging, `uninstall.php` dijalankan saat hapus |

Add a new section "## 11. Lifecycle hooks" after section 4 documenting the exact firing order from the spec (activate → load entry → `activate_{slug}` → `plugin_activated`; deactivate → `deactivate_{slug}` → remove from list → `plugin_deactivated`), and a section "## 12. Halaman settings plugin" with a complete example:

```php
add_menu_page('cv-settings', 'CV Settings', 'document', function ($request): string {
    if ($request->getMethod() === 'POST') {
        setting('cv_title', trim((string) ($_POST['cv_title'] ?? '')));

        return '<div class="card card-body">Saved.</div>';
    }

    return mops(\Mops\Core\View::class)->render('cv-ats/settings.twig', [
        'value' => setting('cv_title', ''),
    ]);
});
```

Note in that section: reserved slugs, `csrf_field()` in forms, POST goes to the same URL, deactivating the plugin removes the page.

Document `functions.php` + child theme under a new "## 13. Tema: functions.php & child theme" section (boot order, `parent` field, what the chain resolves) and the installer rules under "## 14. Installer zip" (structures accepted, `slug` requirement for flat zips, delete rules).

- [ ] **Step 2: `knowledge.md` — status + gotchas**

In "Where things live", extend the `app/Core/` row with `AdminMenu`, `ZipInstaller`.

Add to "Gotchas learned the hard way":

- Activation/deactivation hooks are named `activate_{slug}` / `deactivate_{slug}` and only fire from `PluginManager::activate/deactivate` — never call them at boot.
- Theme `functions.php` runs after `plugins_loaded` and only when installed; errors are collected in `ThemeManager::errors()` (shown on `/admin/themes`), never fatal.
- Child themes: `templatePaths()`/asset chain are child-first; `assertActivatable()` enforces parent-exists and max 2 levels — call it after any programmatic theme install.
- Zip installs extract under `storage/tmp/` first; a half-finished install never lands in `plugins/`/`themes/`.

In "Roadmap status", extend the 0.4 line: plugin manager + hooks (+ lifecycle hooks, admin menu, zip installer, child themes — WordPress parity, spec `docs/superpowers/specs/2026-10-11-wordpress-parity-design.md`).

- [ ] **Step 3: `docs/README.md` — status checklist**

Add after the 0.4 line:

```markdown
- [x] WordPress parity: activation/deactivation hooks, `add_menu_page`, theme functions.php, child themes, zip installer + delete (spec: [2026-10-11](superpowers/specs/2026-10-11-wordpress-parity-design.md))
```

- [ ] **Step 4: Final verification**

Run:

```bash
composer check
php dev/smoke.php
```

Expected: PASS — 100+ tests green, smoke end-to-end OK.

- [ ] **Step 5: Commit**

```bash
git add docs/plugin-api.md docs/README.md knowledge.md
git commit -m "docs: plugin/theme WordPress-parity API (lifecycle, menu, installer, child themes)"
```

---

## Self-Review Notes (already applied)

- **Spec coverage:** §1 lifecycle → Tasks 1-2; §2 admin menu → Task 3; §3 functions.php → Task 4; §4 child theme → Task 5; §5 installer → Tasks 6-8; §6 tests/docs → spread across tasks + Task 9.
- **Placeholders:** none — every step carries runnable code or exact edits.
- **Type consistency:** `add_menu_page(string,string,string,callable): string`, `ZipInstaller::install(string,string,string): string`, `templatePaths(): array<int,string>`, `assertActivatable(string): void` used identically across tasks.
- **Ordering note:** Task 4 uses `chain()` which duplicates one level of the parent lookup Task 5 generalizes; when Task 5 lands, `chain()` can delegate to `templatePaths()`. Left as-is so each task stands alone.
