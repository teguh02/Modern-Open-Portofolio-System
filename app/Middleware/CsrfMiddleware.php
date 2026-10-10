<?php

declare(strict_types=1);

namespace Mops\Middleware;

use Mops\Core\Config;
use Mops\Core\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Rejects state-changing requests without a valid CSRF token.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private Csrf $csrf, private Config $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        if (in_array($method, self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $body = $this->submittedData($request);
        $header = $request->getHeaderLine('X-CSRF-Token');

        if ($this->csrf->validateRequest($body, $header !== '' ? $header : null)) {
            return $handler->handle($request);
        }

        $accepts = $request->getHeaderLine('Accept');

        if (str_contains($accepts, 'application/json')) {
            $response = new Response(419);
            $response->getBody()->write(json_encode([
                'error' => 'CSRF token tidak valid atau kedaluwarsa.',
            ], JSON_UNESCAPED_UNICODE) ?: '{}');

            return $response->withHeader('Content-Type', 'application/json');
        }

        $response = new Response(303);

        return $response->withHeader('Location', $this->referer($request));
    }

    /**
     * CSRF verification must work regardless of middleware order, so fall back to
     * PHP's own form parsing ($_POST covers urlencoded and multipart uploads)
     * and finally to parsing the raw body ourselves.
     *
     * @return array<string, mixed>
     */
    private function submittedData(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        if (is_array($_POST) && $_POST !== []) {
            return $_POST;
        }

        $contents = (string) $request->getBody();
        $request->getBody()->rewind();

        if ($contents === '') {
            return is_array($parsed) ? $parsed : [];
        }

        $data = [];
        parse_str($contents, $data);

        return $data;
    }

    private function referer(ServerRequestInterface $request): string
    {
        $referer = $request->getHeaderLine('Referer');
        $path = $referer === '' ? '/admin' : (string) (parse_url($referer, PHP_URL_PATH) ?: '/admin');

        return $path;
    }
}
