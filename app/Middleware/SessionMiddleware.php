<?php

declare(strict_types=1);

namespace Mops\Middleware;

use Mops\Core\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Starts the session before anything reads it (auth, CSRF, flash messages).
 * Runs as the outermost middleware so every later layer can rely on $_SESSION.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private Session $session)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->session->start();

        return $handler->handle($request);
    }
}
