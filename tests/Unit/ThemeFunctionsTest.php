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

        $database = new Database((array) $config->get('database'));
        (new \Mops\Core\Migrator($database, $config->path('migrations')))->run();

        return new Settings($database, $config);
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
