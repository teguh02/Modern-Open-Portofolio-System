<?php

declare(strict_types=1);

namespace Mops\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Throwable;

/**
 * Renders uncaught exceptions. In debug mode the details are shown; in
 * production the visitor gets a minimal page. Security headers are applied here
 * too, because the response never passes back through the middleware stack.
 */
final class ErrorHandler
{
    public function __construct(
        private View $view,
        private bool $debug,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $status = $this->statusFor($exception);
        $response = new Response($status);

        $html = null;

        try {
            $html = $this->view->renderSafe('errors/500.twig', [
                'message' => $this->debug ? $exception->getMessage() : 'Terjadi kesalahan pada server.',
                'trace' => $this->debug ? $exception->getTraceAsString() : null,
                'type' => $exception::class,
                'file' => $this->debug ? $exception->getFile() . ':' . $exception->getLine() : null,
                'status' => $status,
            ]);
        } catch (Throwable) {
            $html = null;
        }

        if ($html === null) {
            $html = $this->plain($status, $exception);
        }

        $response->getBody()->write($html);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Security-Policy', "default-src 'self'; style-src 'self' 'unsafe-inline'")
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    private function statusFor(Throwable $exception): int
    {
        $code = (int) $exception->getCode();

        return $code >= 400 && $code <= 599 ? $code : 500;
    }

    private function plain(int $status, Throwable $exception): string
    {
        $message = $this->debug
            ? e($exception::class . ': ' . $exception->getMessage())
            : 'Terjadi kesalahan pada server.';

        return '<!doctype html><html lang="id"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Error ' . $status . '</title></head>'
            . '<body style="font:14px/1.6 system-ui;padding:40px;max-width:720px;margin:auto">'
            . '<h1 style="font-size:20px">Error ' . $status . '</h1><p>' . $message . '</p>'
            . '</body></html>';
    }
}
