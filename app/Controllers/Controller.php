<?php

declare(strict_types=1);

namespace Mops\Controllers;

use Mops\Core\App;
use Mops\Core\Auth;
use Mops\Core\Session;
use Mops\Core\View;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Response;

/**
 * Shared controller helpers: render, redirect, flash, back.
 */
abstract class Controller
{
    public function __construct(
        protected View $view,
        protected Session $session,
        protected Auth $auth,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(ResponseInterface $response, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        // Core fires the event; anything that wants to track or decorate public
        // page views (analytics, SEO extras) does it in a plugin.
        do_action('page_viewed', $template, $data['page'] ?? []);

        $response->getBody()->write($this->view->renderPublic($template, $this->share($data)));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function view(ResponseInterface $response, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        $response->getBody()->write($this->view->render($template, $this->share($data)));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function share(array $data): array
    {
        return array_merge([
            'flash_messages' => $this->session->flashes(),
            'current_user' => $this->auth->user(),
            'app_version' => App::VERSION,
        ], $data);
    }

    protected function redirect(ResponseInterface $response, string $location, int $status = 302): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus($status);
    }

    protected function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    protected function flash(string $type, string $message): void
    {
        $this->session->flash($type, $message);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function text(ResponseInterface $response, string $body, string $contentType = 'text/plain', int $status = 200): ResponseInterface
    {
        $response->getBody()->write($body);

        return $response->withHeader('Content-Type', $contentType)->withStatus($status);
    }

    /**
     * 404 page for the active theme.
     */
    protected function notFoundResponse(ResponseInterface $response, string $message = 'Halaman tidak ditemukan.'): ResponseInterface
    {
        return $this->render($response, 'errors/404.twig', ['message' => $message], 404);
    }

    protected function newResponse(): Response
    {
        return new Response();
    }
}
