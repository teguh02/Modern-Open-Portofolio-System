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

        $database = new Database((array) $config->get('database'));
        (new \Mops\Core\Migrator($database, $config->path('migrations')))->run();

        return new Settings($database, $config);
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
