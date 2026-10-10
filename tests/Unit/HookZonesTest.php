<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Hook;
use Mops\Core\HookZones;
use Mops\Core\Paths;
use Mops\Tests\TestCase;

/**
 * Injection zones are MOPS' equivalent of WordPress' `wp_head`/`wp_footer`:
 * the default templates print them, plugins fill them. These tests guard the
 * registry itself and the contract that the bundled themes keep printing every
 * required zone.
 */
final class HookZonesTest extends TestCase
{
    protected function tearDown(): void
    {
        Hook::reset();

        parent::tearDown();
    }

    public function test_every_zone_describes_where_it_is_rendered(): void
    {
        $zones = HookZones::all();

        $this->assertNotEmpty($zones);

        foreach ($zones as $id => $zone) {
            $this->assertIsString($id);
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $id, "Zona '{$id}' harus snake_case.");

            foreach (['label', 'where', 'args', 'scope'] as $key) {
                $this->assertArrayHasKey($key, $zone, "Zona '{$id}' kehilangan metadata '{$key}'.");
                $this->assertNotSame('', $zone[$key]);
            }

            $this->assertContains($zone['scope'], ['public', 'admin'], "Scope zona '{$id}' tidak dikenal.");
        }
    }

    public function test_required_theme_zones_are_registered(): void
    {
        foreach (HookZones::REQUIRED_THEME_ZONES as $zone) {
            $this->assertTrue(HookZones::exists($zone), "Zona wajib tema '{$zone}' tidak terdaftar.");
            $this->assertNotNull(HookZones::find($zone));
        }
    }

    public function test_render_captures_plugin_output(): void
    {
        Hook::addAction('theme_footer', static function (): void {
            echo '<span>dari plugin</span>';
        });

        $this->assertSame('<span>dari plugin</span>', HookZones::render('theme_footer', ['path' => '/']));
        $this->assertContains('theme_footer', HookZones::filled());
        $this->assertSame('', HookZones::render('theme_head'));
        $this->assertNotContains('theme_head', HookZones::filled());
    }

    /**
     * A theme that forgets a zone silently breaks every plugin targeting it, so
     * the bundled themes are checked against the registry.
     */
    public function test_bundled_themes_print_every_required_zone(): void
    {
        $themes = glob(Paths::make('themes/*/templates/layout.twig')) ?: [];

        $this->assertCount(2, $themes, 'MOPS rilis dengan dua tema bawaan.');

        foreach ($themes as $layout) {
            $template = (string) file_get_contents($layout);

            foreach (HookZones::REQUIRED_THEME_ZONES as $zone) {
                $this->assertStringContainsString(
                    "render_hook('{$zone}'",
                    $template,
                    "Tema {$layout} tidak mencetak zona '{$zone}'.",
                );
            }
        }
    }

    public function test_bundled_themes_print_the_homepage_zones(): void
    {
        $homes = glob(Paths::make('themes/*/templates/home.twig')) ?: [];

        $this->assertCount(2, $homes);

        foreach ($homes as $home) {
            $template = (string) file_get_contents($home);

            $this->assertStringContainsString("render_hook('home_before_sections'", $template, $home);
            $this->assertStringContainsString("render_hook('home_after_sections'", $template, $home);
        }
    }
}
