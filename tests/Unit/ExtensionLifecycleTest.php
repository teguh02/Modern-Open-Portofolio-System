<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Database;
use Mops\Core\Config;
use Mops\Core\Hook;
use Mops\Core\PluginManager;
use Mops\Core\Settings;
use Mops\Core\ThemeManager;
use Mops\Tests\TestCase;

final class ExtensionLifecycleTest extends TestCase
{
    private string $workspace = '';
    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Hook::reset();

        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite is required for extension lifecycle tests.');
        }

        $this->workspace = dirname(__DIR__, 2) . '/storage/testing/extensions-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0775, true);

        $database = new Database([
            'driver' => 'sqlite',
            'database' => $this->workspace . '/test.sqlite',
        ]);
        $database->execute('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, key VARCHAR(191) NOT NULL UNIQUE, value TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $this->settings = new Settings($database, new Config());
    }

    protected function tearDown(): void
    {
        Hook::reset();
        $this->removeDirectory($this->workspace);
        parent::tearDown();
    }

    public function testPluginActivationAndDeactivationHooksRunOnce(): void
    {
        $plugins = $this->workspace . '/plugins';
        $plugin = $plugins . '/lifecycle-test';
        mkdir($plugin, 0775, true);
        file_put_contents($plugin . '/plugin.json', json_encode([
            'name' => 'Lifecycle Test',
            'version' => '1.0.0',
            'main' => 'plugin.php',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($plugin . '/plugin.php', <<<'PHP'
<?php
register_activation_hook(__FILE__, static function (): void {
    file_put_contents(__DIR__ . '/activated.txt', 'yes');
});
register_deactivation_hook(__FILE__, static function (): void {
    file_put_contents(__DIR__ . '/deactivated.txt', 'yes');
});
PHP);

        $manager = new PluginManager($plugins, $this->settings);
        $manager->activate('lifecycle-test');

        self::assertTrue($manager->isActive('lifecycle-test'));
        self::assertFileExists($plugin . '/activated.txt');

        $manager->deactivate('lifecycle-test');

        self::assertFalse($manager->isActive('lifecycle-test'));
        self::assertFileExists($plugin . '/deactivated.txt');
    }

    public function testBrokenPluginRecordsBootstrapErrorAndStaysDeactivatable(): void
    {
        $plugins = $this->workspace . '/plugins';
        $plugin = $plugins . '/broken-test';
        mkdir($plugin, 0775, true);
        file_put_contents($plugin . '/plugin.json', '{"name":"Broken","version":"1.0.0","main":"plugin.php"}');
        file_put_contents($plugin . '/plugin.php', '<?php throw new RuntimeException("bootstrap failed");');

        $manager = new PluginManager($plugins, $this->settings);
        $manager->activate('broken-test');

        self::assertTrue($manager->isActive('broken-test'));
        self::assertStringContainsString('bootstrap failed', implode("\n", $manager->errors()));

        // Recovery path: even a persisted broken plugin can be disabled.
        $fresh = new PluginManager($plugins, $this->settings);
        $fresh->deactivate('broken-test');
        self::assertFalse($fresh->isActive('broken-test'));
    }

    public function testThemeMustExposeRequiredInjectionZones(): void
    {
        $themes = $this->workspace . '/themes';
        $theme = $themes . '/incomplete';
        mkdir($theme . '/templates', 0775, true);
        file_put_contents($theme . '/theme.json', '{"name":"Incomplete","version":"1.0.0"}');
        file_put_contents($theme . '/templates/layout.twig', '<html>{% block content %}{% endblock %}</html>');
        file_put_contents($theme . '/templates/home.twig', '{% extends "layout.twig" %}');

        $manager = new ThemeManager($themes, $this->settings, 'incomplete');
        $discovered = $manager->find('incomplete');

        self::assertNotNull($discovered);
        self::assertFalse($discovered['valid']);
        self::assertContains('theme_head', $discovered['missing_zones']);
        self::assertContains('home_before_sections', $discovered['missing_zones']);

        $this->expectException(\RuntimeException::class);
        $manager->activate('incomplete');
    }

    public function testActiveThemeFunctionsFileCanRegisterHooks(): void
    {
        $themes = $this->workspace . '/themes';
        $theme = $themes . '/complete';
        mkdir($theme . '/templates', 0775, true);
        file_put_contents($theme . '/theme.json', '{"name":"Complete","version":"1.0.0"}');
        file_put_contents($theme . '/templates/layout.twig', $this->layoutWithRequiredZones());
        file_put_contents($theme . '/templates/home.twig', "{{ render_hook('home_before_sections') }}{{ render_hook('home_after_sections') }}");
        file_put_contents($theme . '/functions.php', "<?php add_filter('theme_test_value', static fn (string \$value): string => \$value . '-theme');");

        $manager = new ThemeManager($themes, $this->settings, 'complete');
        $manager->loadFunctions();
        $manager->loadFunctions();

        self::assertSame('base-theme', apply_filters('theme_test_value', 'base'));
        self::assertSame([], $manager->errors());
    }

    private function layoutWithRequiredZones(): string
    {
        $zones = [
            'theme_head', 'theme_body_open', 'main_before_content', 'main_after_content', 'theme_footer',
        ];

        return implode('', array_map(
            static fn (string $zone): string => "{{ render_hook('{$zone}') }}",
            $zones,
        ));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . '/' . $item;
            is_dir($target) ? $this->removeDirectory($target) : unlink($target);
        }

        rmdir($path);
    }
}
