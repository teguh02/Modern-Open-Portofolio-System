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
                $body = $request->getParsedBody();

                return 'got:' . (string) (is_array($body) ? ($body['name'] ?? '') : '');
            }

            return '<form>form</form>';
        });

        $this->login();

        $this->assertStringContainsString('form', $this->body($this->get('/admin/demo-post')));
        $this->assertStringContainsString('got:Teguh', $this->body($this->post('/admin/demo-post', ['name' => 'Teguh'])));
    }
}
