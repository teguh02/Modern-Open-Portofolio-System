<?php

declare(strict_types=1);

namespace Mops\Repositories;

use Mops\Core\Str;

/**
 * Certifications. Ordered newest first (spec: issued_date DESC).
 */
final class CertificationRepository extends Repository
{
    protected function table(): string
    {
        return 'certifications';
    }

    protected function defaultOrder(): array
    {
        return ['issued_date', 'DESC'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decorate(array $row): array
    {
        $row = parent::decorate($row);

        $row['issued_label'] = Str::date($row['issued_date'] ?? null, 'M Y');
        $row['expiry_label'] = Str::date($row['expiry_date'] ?? null, 'M Y');
        $row['is_expired'] = $this->isExpired($row['expiry_date'] ?? null);

        return $row;
    }

    private function isExpired(?string $expiry): bool
    {
        if ($expiry === null || $expiry === '') {
            return false;
        }

        $timestamp = strtotime($expiry);

        return $timestamp !== false && $timestamp < time();
    }
}
