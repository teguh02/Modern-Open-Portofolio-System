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
        file_put_contents($dir . '/templates/layout.twig', '<html>{% block content %}{% endblock %}</html>');
        file_put_contents($dir . '/templates/home.twig', 'home');
    }
}
