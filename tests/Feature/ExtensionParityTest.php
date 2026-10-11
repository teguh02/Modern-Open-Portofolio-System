<?php

declare(strict_types=1);

namespace Mops\Tests\Feature;

use Mops\Core\Paths;
use Mops\Tests\ApplicationTestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\UploadedFile;
use ZipArchive;

final class ExtensionParityTest extends ApplicationTestCase
{
    protected function tearDown(): void
    {
        foreach (['plugins/mops-e2e-demo', 'plugins/mops-e2e-active', 'plugins/mops-e2e-uninstall', 'themes/mops-e2e-theme'] as $fixture) {
            Paths::deleteTree(Paths::make($fixture));
        }

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
                $body = $request->getParsedBody();

                return 'got:' . (string) (is_array($body) ? ($body['name'] ?? '') : '');
            }

            return '<form>form</form>';
        });

        $this->login();

        $this->assertStringContainsString('form', $this->body($this->get('/admin/demo-post')));
        $this->assertStringContainsString('got:Teguh', $this->body($this->post('/admin/demo-post', ['name' => 'Teguh'])));
    }

    public function test_admin_can_install_a_plugin_from_zip_and_delete_it(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip tidak tersedia.');
        }

        $this->install();
        $this->login();

        $this->assertStringContainsString('plugin_zip', $this->body($this->get('/admin/plugins')));

        $response = $this->postUpload('/admin/plugins/upload', [], [
            'plugin_zip' => $this->zipUpload('mops-e2e-demo', 'plugin.json', ['main' => 'plugin.php']),
        ]);

        $this->assertSame(302, $response->getStatusCode(), $this->body($response));
        $target = Paths::make('plugins/mops-e2e-demo');
        $this->assertDirectoryExists($target);
        $this->assertFileExists($target . '/plugin.php');

        // Installing the same slug again is refused without touching the folder.
        $again = $this->postUpload('/admin/plugins/upload', [], [
            'plugin_zip' => $this->zipUpload('mops-e2e-demo', 'plugin.json', ['main' => 'plugin.php']),
        ]);
        $this->assertSame(302, $again->getStatusCode());
        $this->assertFileExists($target . '/plugin.php');

        $delete = $this->post('/admin/plugins/mops-e2e-demo/delete', []);
        $this->assertSame(302, $delete->getStatusCode());
        $this->assertDirectoryDoesNotExist($target);
    }

    public function test_deleting_an_active_plugin_is_refused_until_it_is_deactivated(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip tidak tersedia.');
        }

        $this->install();
        $this->login();

        $this->postUpload('/admin/plugins/upload', [], [
            'plugin_zip' => $this->zipUpload('mops-e2e-active', 'plugin.json', ['main' => 'plugin.php']),
        ]);
        $this->activatePlugin('mops-e2e-active');
        $target = Paths::make('plugins/mops-e2e-active');

        $this->post('/admin/plugins/mops-e2e-active/delete', []);
        $this->assertDirectoryExists($target, 'An active plugin must not be deletable.');

        $this->post('/admin/plugins/mops-e2e-active/deactivate', []);
        $this->post('/admin/plugins/mops-e2e-active/delete', []);
        $this->assertDirectoryDoesNotExist($target);
    }

    public function test_plugin_delete_runs_uninstall_php(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip tidak tersedia.');
        }

        $this->install();
        $this->login();

        $marker = Paths::make('storage/tmp/uninstall-marker.txt');
        @unlink($marker);

        $this->postUpload('/admin/plugins/upload', [], [
            'plugin_zip' => $this->zipUpload('mops-e2e-uninstall', 'plugin.json', ['main' => 'plugin.php'], [
                'uninstall.php' => '<?php file_put_contents(' . var_export($marker, true) . ', "cleaned");',
            ]),
        ]);

        try {
            $this->post('/admin/plugins/mops-e2e-uninstall/delete', []);

            $this->assertSame('cleaned', is_file($marker) ? file_get_contents($marker) : '');
            $this->assertDirectoryDoesNotExist(Paths::make('plugins/mops-e2e-uninstall'));
        } finally {
            @unlink($marker);
            Paths::deleteTree(Paths::make('plugins/mops-e2e-uninstall'));
        }
    }

    public function test_admin_can_install_a_theme_from_zip_and_delete_it(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip tidak tersedia.');
        }

        $this->install();
        $this->login();

        $this->assertStringContainsString('theme_zip', $this->body($this->get('/admin/themes')));

        $upload = $this->zipUpload('mops-e2e-theme', 'theme.json', ['name' => 'E2E'], [
            'templates/layout.twig' => '<html>{% block content %}{% endblock %}</html>',
            'templates/home.twig' => 'home',
        ]);

        $response = $this->postUpload('/admin/themes/upload', [], ['theme_zip' => $upload]);

        $this->assertSame(302, $response->getStatusCode(), $this->body($response));
        $target = Paths::make('themes/mops-e2e-theme');
        $this->assertDirectoryExists($target);
        $this->assertFileExists($target . '/templates/home.twig');

        $delete = $this->post('/admin/themes/mops-e2e-theme/delete', []);
        $this->assertSame(302, $delete->getStatusCode());
        $this->assertDirectoryDoesNotExist($target);
    }

    /**
     * A staged zip wrapped as an uploaded file, the way the browser would send it.
     *
     * @param array<string, mixed> $manifest
     * @param array<string, string> $extraEntries
     */
    private function zipUpload(string $slug, string $manifest, array $manifestData, array $extraEntries = []): UploadedFile
    {
        $path = $this->tempDir() . '/fixture.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($slug . '/' . $manifest, (string) json_encode($manifestData));

        foreach ($extraEntries as $name => $content) {
            $zip->addFromString($slug . '/' . $name, $content);
        }

        if ($manifest === 'plugin.json') {
            $zip->addFromString($slug . '/plugin.php', '<?php // e2e fixture');
        }

        $zip->close();

        return new UploadedFile($path, $slug . '.zip', 'application/zip', (int) filesize($path), \UPLOAD_ERR_OK);
    }
}
