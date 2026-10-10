<?php

declare(strict_types=1);

namespace Mops\Controllers;

use Mops\Core\Auth;
use Mops\Core\Csrf;
use Mops\Core\Session;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin authentication.
 */
final class AuthController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private Csrf $csrf,
        private ActivityRepository $activity,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->auth->check()) {
            return $this->redirect($response, '/admin');
        }

        $redirect = (string) ($request->getQueryParams()['redirect'] ?? '/admin');

        return $this->view($response, 'admin/auth/login.twig', [
            'redirect' => $this->safeRedirect($redirect),
            'errors' => [],
            'email' => '',
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $redirect = $this->safeRedirect((string) ($input['redirect'] ?? '/admin'));

        if ($email === '' || $password === '') {
            return $this->view($response, 'admin/auth/login.twig', [
                'redirect' => $redirect,
                'errors' => ['Email dan password wajib diisi.'],
                'email' => $email,
            ], 422);
        }

        if (!$this->auth->attempt($email, $password)) {
            $this->activity->log('login_failed', "Login gagal untuk {$email}");

            return $this->view($response, 'admin/auth/login.twig', [
                'redirect' => $redirect,
                'errors' => ['Email atau password salah.'],
                'email' => $email,
            ], 422);
        }

        // New session => new CSRF token.
        $this->csrf->rotate();
        $this->activity->log('login', 'Masuk ke admin', $this->auth->id(), 'user', $this->auth->id());
        $this->flash('success', 'Selamat datang kembali.');

        return $this->redirect($response, $redirect);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->auth->id();

        if ($userId !== null) {
            $this->activity->log('logout', 'Keluar dari admin', $userId, 'user', $userId);
        }

        $this->auth->logout();
        $this->session->flash('success', 'Anda telah keluar.');

        return $this->redirect($response, '/admin/login');
    }

    /**
     * Only allow same-site redirects.
     */
    private function safeRedirect(string $target): string
    {
        if ($target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return '/admin';
        }

        return $target;
    }
}
