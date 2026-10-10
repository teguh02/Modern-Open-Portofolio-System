<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * Session + flash message handling. Cookies are httpOnly and SameSite=Lax.
 */
final class Session
{
    private bool $started = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config, private bool $autoStart = true)
    {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        // CLI (tests, bin/mops, dev scripts) has no cookies or headers, so the
        // in-memory array is the session. Starting a real session here would
        // overwrite whatever the caller already put in $_SESSION.
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }

            $this->started = true;

            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        session_name((string) ($this->config['name'] ?? 'mops_session'));
        session_set_cookie_params([
            'lifetime' => (int) ($this->config['lifetime'] ?? 7200),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => $this->isSecureRequest(),
        ]);

        session_start();
        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Queue a one-request message for the admin UI.
     */
    public function flash(string $type, string $message): void
    {
        $messages = $this->get('_flash', []);
        $messages[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $messages);
    }

    /**
     * @return array<int, array{type: string, message: string}>
     */
    public function flashes(): array
    {
        /** @var array<int, array{type: string, message: string}> $messages */
        $messages = $this->pull('_flash', []);

        return is_array($messages) ? $messages : [];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function flashInput(array $input): void
    {
        unset($input['_token'], $input['password'], $input['password_confirmation']);
        $this->set('_old_input', $input);
    }

    /**
     * @return array<string, mixed>
     */
    public function oldInput(): array
    {
        /** @var array<string, mixed> $input */
        $input = $this->pull('_old_input', []);

        return is_array($input) ? $input : [];
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->started = false;
    }

    private function isSecureRequest(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
