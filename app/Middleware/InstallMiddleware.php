<?php

declare(strict_types=1);

namespace Mops\Middleware;

use Mops\Core\App;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Forces the installer on a fresh install, and blocks /install again afterwards.
 */
final class InstallMiddleware implements MiddlewareInterface
{
    /** @var array<int, string> */
    private const ALWAYS_ALLOWED = ['/uploads', '/theme', '/assets'];

    public function __construct(private App $app)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $installed = $this->app->isInstalled();

        if (!$installed) {
            if ($this->shouldSkip($path)) {
                return $handler->handle($request);
            }

            return $this->redirect('/install');
        }

        if ($this->isInstallPath($path)) {
            return $this->redirect('/admin');
        }

        return $handler->handle($request);
    }

    private function shouldSkip(string $path): bool
    {
        if ($this->isInstallPath($path)) {
            return true;
        }

        foreach (self::ALWAYS_ALLOWED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isInstallPath(string $path): bool
    {
        return $path === '/install' || str_starts_with($path, '/install/');
    }

    private function redirect(string $location): ResponseInterface
    {
        return (new Response(302))->withHeader('Location', $location);
    }
}
