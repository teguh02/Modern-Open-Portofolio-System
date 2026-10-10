<?php

declare(strict_types=1);

namespace Mops\Repositories;

/**
 * Awards. Ordered newest first (spec: year DESC).
 */
final class AwardRepository extends Repository
{
    protected function table(): string
    {
        return 'awards';
    }

    protected function defaultOrder(): array
    {
        return ['year', 'DESC'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decorate(array $row): array
    {
        $row = parent::decorate($row);

        $row['year'] = (int) ($row['year'] ?? 0);

        return $row;
    }
}
