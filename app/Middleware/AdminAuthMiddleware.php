<?php

declare(strict_types=1);

namespace Mops\Middleware;

use Mops\Core\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Guards every /admin route except /admin/login.
 */
final class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private Auth $auth)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');

        if (in_array($path, ['/admin/login', '/admin/logout'], true)) {
            return $handler->handle($request);
        }

        if (!$this->auth->check()) {
            return (new Response(302))->withHeader(
                'Location',
                '/admin/login?redirect=' . urlencode($path),
            );
        }

        return $handler->handle($request);
    }
}
