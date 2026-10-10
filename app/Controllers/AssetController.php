<?php

declare(strict_types=1);

namespace Mops\Controllers;

use Mops\Core\Auth;
use Mops\Core\Paths;
use Mops\Core\PluginManager;
use Mops\Core\Session;
use Mops\Core\ThemeManager;
use Mops\Core\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

/**
 * Streams files that live outside the web root: uploads (storage/uploads/...),
 * theme assets (themes/<slug>/assets/...) and plugin assets
 * (plugins/<slug>/assets/...).
 *
 * Every handler refuses path traversal and never exposes anything else on disk.
 */
final class AssetController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private ThemeManager $themes,
        private PluginManager $plugins,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $relative = (string) ($args['path'] ?? '');
        $root = Paths::make('storage/uploads');
        $path = $root . '/' . ltrim(str_replace('\\', '/', $relative), '/');

        if (!is_file($path) || !Paths::isInside($path, $root)) {
            return $this->notFoundResponse($response, 'File tidak ditemukan.');
        }

        return $this->stream($response, $path);
    }

    public function themeAsset(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $path = $this->themes->assetPath((string) ($args['slug'] ?? ''), (string) ($args['path'] ?? ''));

        if ($path === null) {
            return $this->notFoundResponse($response, 'Aset tema tidak ditemukan.');
        }

        return $this->stream($response, $path);
    }

    public function pluginAsset(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $path = $this->plugins->assetPath((string) ($args['slug'] ?? ''), (string) ($args['path'] ?? ''));

        if ($path === null) {
            return $this->notFoundResponse($response, 'Aset plugin tidak ditemukan.');
        }

        return $this->stream($response, $path);
    }

    private function stream(ResponseInterface $response, string $path): ResponseInterface
    {
        $mime = $this->mime($path);

        // SVG is a scripting vector: force a download-free, sandboxed response.
        if ($mime === 'image/svg+xml') {
            $response = $response->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
        }

        $stream = new Stream(fopen($path, 'rb'));

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withBody($stream);
    }

    /**
     * Known asset extensions win over content sniffing: with
     * `X-Content-Type-Options: nosniff`, a small CSS/JS file that libmagic reads
     * as "text/plain" would be refused by the browser.
     */
    private function mime(string $path): string
    {
        static $byExtension = [
            'css' => 'text/css',
            'js' => 'text/javascript',
            'mjs' => 'text/javascript',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
        ];

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $byExtension[$extension] ?? (mime_content_type($path) ?: 'application/octet-stream');
    }
}
