<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * Session-based authentication for the admin area.
 */
final class Auth
{
    private const SESSION_KEY = 'auth_user_id';

    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    private bool $resolved = false;

    public function __construct(
        private Database $db,
        private Session $session,
    ) {
    }

    public function attempt(string $email, string $password): bool
    {
        $user = $this->db->table('users')->where('email', strtolower(trim($email)))->first();

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        $this->login($user);

        return true;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function login(array $user): void
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, (int) $user['id']);
        $this->cached = $user;
        $this->resolved = true;

        $this->db->table('users')->where('id', (int) $user['id'])->update([
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function logout(): void
    {
        $this->session->forget(self::SESSION_KEY);
        $this->cached = null;
        $this->resolved = true;
        $this->session->destroy();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->cached;
        }

        $this->resolved = true;
        $id = $this->session->get(self::SESSION_KEY);

        if (!is_int($id) && !is_string($id)) {
            return $this->cached = null;
        }

        if (!$this->db->tableExists('users')) {
            return $this->cached = null;
        }

        $user = $this->db->table('users')->where('id', (int) $id)->first();

        return $this->cached = $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        $user = $this->user();

        return $user === null ? null : (int) $user['id'];
    }

    public function isAdmin(): bool
    {
        $user = $this->user();

        return $user !== null && ($user['role'] ?? '') === 'admin';
    }

    public function passwordHash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
