<?php

declare(strict_types=1);

namespace Mops\Repositories;

use Mops\Core\Str;

/**
 * Admin users. Only the admin role exists in 0.1.
 */
final class UserRepository extends Repository
{
    protected function table(): string
    {
        return 'users';
    }

    protected function defaultOrder(): array
    {
        return ['created_at', 'ASC'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', strtolower(trim($email)));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $data['email'] = strtolower(trim((string) ($data['email'] ?? '')));
        $data['name'] = trim((string) ($data['name'] ?? 'Admin'));

        if (!empty($data['password'])) {
            $data['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }

        unset($data['password']);
        $data['role'] = (string) ($data['role'] ?? 'admin');

        return parent::create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        if (!empty($data['password'])) {
            $data['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }

        unset($data['password']);

        return parent::update($id, $data);
    }

    public function updatePassword(int $id, string $plainPassword): bool
    {
        return parent::update($id, ['password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT)]);
    }

    public function initial(string $name): string
    {
        return mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : Str::slug($name, 'A'), 0, 1, 'UTF-8'));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decorate(array $row): array
    {
        $row = parent::decorate($row);
        unset($row['password_hash']);

        $row['initial'] = $this->initial((string) ($row['name'] ?? ''));

        return $row;
    }
}
