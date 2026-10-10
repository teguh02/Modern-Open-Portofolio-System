<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * CSRF token generation and verification.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(private Session $session, private string $fieldName = '_token')
    {
    }

    public function fieldName(): string
    {
        return $this->fieldName;
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars($this->fieldName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8'),
        );
    }

    public function validate(?string $token): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);

        if (!is_string($expected) || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function validateRequest(array $input, ?string $headerToken = null): bool
    {
        $token = $input[$this->fieldName] ?? $headerToken;

        return $this->validate(is_string($token) ? $token : null);
    }

    public function rotate(): void
    {
        $this->session->set(self::SESSION_KEY, bin2hex(random_bytes(32)));
    }
}
