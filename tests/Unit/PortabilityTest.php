<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Tests\TestCase;

/**
 * Architecture guard rails from AGENTS.md:
 *   - repositories must stay portable (no SQLite-specific SQL);
 *   - themes must never reach for the data layer;
 *   - the web root must only expose /public.
 */
final class PortabilityTest extends TestCase
{
    /** @var array<int, string> */
    private const FORBIDDEN_IN_REPOSITORIES = [
        'AUTOINCREMENT',
        'PRAGMA ',
        'strftime(',
        'datetime(',
        'INSERT OR REPLACE',
        'LAST_INSERT_ROWID',
        'sqlite_master',
    ];

    public function test_repositories_contain_no_driver_specific_sql(): void
    {
        $files = glob(MOPS_ROOT . '/app/Repositories/*.php') ?: [];
        $this->assertNotEmpty($files, 'Expected repository classes to exist.');

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            foreach (self::FORBIDDEN_IN_REPOSITORIES as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $contents,
                    basename($file) . ' must not contain driver-specific SQL (' . $needle . ').',
                );
            }
        }
    }

    public function test_themes_only_render_and_never_query_data(): void
    {
        $files = glob(MOPS_ROOT . '/themes/*/templates/**/*.twig') ?: [];

        $extra = glob(MOPS_ROOT . '/themes/*/templates/*.twig') ?: [];
        $files = array_merge($files, $extra);

        $this->assertNotEmpty($files, 'Expected bundled theme templates.');

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            foreach (['mops(', 'Database', 'SELECT ', 'FROM ', 'Repository'] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    str_replace(MOPS_ROOT . '/', '', $file) . ' must not touch the data layer.',
                );
            }
        }
    }

    public function test_both_bundled_themes_ship_on_install(): void
    {
        foreach (['vantablo', 'lumica'] as $theme) {
            $this->assertFileExists(MOPS_ROOT . "/themes/{$theme}/theme.json");
            $this->assertFileExists(MOPS_ROOT . "/themes/{$theme}/templates/layout.twig");
            $this->assertFileExists(MOPS_ROOT . "/themes/{$theme}/templates/home.twig");
        }
    }

    public function test_bundled_themes_declare_every_template_they_promise(): void
    {
        foreach (['vantablo', 'lumica'] as $slug) {
            $manifest = json_decode((string) file_get_contents(MOPS_ROOT . "/themes/{$slug}/theme.json"), true);
            $this->assertIsArray($manifest);
            $this->assertArrayHasKey('templates', $manifest);

            foreach ($manifest['templates'] as $template) {
                $this->assertFileExists(
                    MOPS_ROOT . "/themes/{$slug}/templates/{$template}",
                    "Theme {$slug} declares {$template} but the file is missing.",
                );
            }
        }
    }

    public function test_sensitive_folders_stay_outside_the_public_root(): void
    {
        foreach (['app', 'config', 'storage', 'themes', 'plugins', 'vendor', 'database'] as $folder) {
            $this->assertDirectoryExists(MOPS_ROOT . '/' . $folder);
            $this->assertDirectoryDoesNotExist(MOPS_ROOT . '/public/' . $folder, "{$folder} must not live under public/.");
        }

        $this->assertFileExists(MOPS_ROOT . '/public/index.php');

        // The dev router must live outside the document root too.
        $this->assertFileDoesNotExist(MOPS_ROOT . '/public/router.php');
    }

    public function test_no_cdn_references_remain_in_runtime_code(): void
    {
        $files = array_merge(
            glob(MOPS_ROOT . '/themes/*/templates/**/*.twig') ?: [],
            glob(MOPS_ROOT . '/app/Views/**/*.twig') ?: [],
        );

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('cdn.tailwindcss.com', $contents, $file);
            $this->assertStringNotContainsString('unpkg.com', $contents, $file);
        }
    }
}
