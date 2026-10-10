<?php

declare(strict_types=1);

namespace Mops\Tests;

use Mops\Core\App;
use Mops\Core\Config;
use Mops\Core\Csrf;
use Mops\Core\Hook;
use Mops\Core\Paths;
use Mops\Core\Settings;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Boots the real application against a temporary SQLite database and drives it
 * through HTTP requests, so feature tests exercise the same stack production
 * uses (installer, CSRF, middleware, routing, plugins, themes).
 */
abstract class ApplicationTestCase extends TestCase
{
    protected SlimApp $app;

    protected string $database;

    protected function setUp(): void
    {
        Hook::reset();

        $this->database = $this->tempDatabase();

        $config = Config::fromFile(Paths::make('config/config.php'));
        $config->set('database.driver', 'sqlite');
        $config->set('database.database', $this->database);
        $config->set('app.debug', true);

        // Keep a stable session container across requests (CLI has no cookies).
        $_SESSION = [];

        $kernel = new App($config);
        $this->app = $kernel->slim();
    }

    protected function tearDown(): void
    {
        Hook::reset();
        $_SESSION = [];

        parent::tearDown();
    }

    /**
     * Write a setting through the request-scoped Settings service so its cache
     * is invalidated the way a real request would.
     */
    protected function setting(string $key, mixed $value): void
    {
        $this->app->getContainer()->get(Settings::class)->set($key, $value);
    }

    protected function install(): void
    {
        $response = $this->post('/install', [
            'site_name' => 'MOPS Test',
            'locale' => 'id',
            'name' => 'Admin',
            'email' => 'admin@mops.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]);

        $this->assertSame(302, $response->getStatusCode(), 'Installer failed: ' . $this->body($response));
    }

    protected function login(): void
    {
        $response = $this->post('/admin/login', [
            'email' => 'admin@mops.test',
            'password' => 'rahasia123',
        ]);

        $this->assertSame(302, $response->getStatusCode(), 'Login failed: ' . $this->body($response));
        $this->assertSame('/admin', $response->getHeaderLine('Location'));
    }

    /**
     * Activate a plugin the way the admin UI does.
     */
    protected function activatePlugin(string $slug): void
    {
        $response = $this->post('/admin/plugins/' . $slug . '/activate', []);

        $this->assertSame(302, $response->getStatusCode(), 'Activation failed: ' . $this->body($response));
    }

    /**
     * Rebuild the kernel against the same database.
     *
     * Routes and plugin entry files are collected while the app is built, so a
     * test that activates a plugin mid-process must boot again to see them.
     */
    protected function reboot(): void
    {
        Hook::reset();

        $config = Config::fromFile(Paths::make('config/config.php'));
        $config->set('database.driver', 'sqlite');
        $config->set('database.database', $this->database);
        $config->set('app.debug', true);

        $this->app = (new App($config))->slim();
    }

    /**
     * @param array<string, string> $headers
     */
    protected function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->handle('GET', $path, [], true, $headers);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function post(string $path, array $data): ResponseInterface
    {
        return $this->handle('POST', $path, $data, true);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    protected function handle(string $method, string $path, array $data = [], bool $withToken = true, array $headers = []): ResponseInterface
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;

        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($method === 'POST') {
            if ($withToken) {
                $data['_token'] = $this->app->getContainer()->get(Csrf::class)->token();
            }

            $request = $request->withParsedBody($data);
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        return $this->app->handle($request);
    }

    protected function body(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody()->getContents();
    }
}
