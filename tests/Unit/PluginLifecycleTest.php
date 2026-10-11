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

        $database = new Database((array) $config->get('database'));
        (new \Mops\Core\Migrator($database, $config->path('migrations')))->run();

        return new Settings($database, $config);
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
