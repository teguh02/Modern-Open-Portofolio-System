<?php

declare(strict_types=1);

namespace Mops\Repositories;

use Mops\Core\Str;

/**
 * Education history. Ordered newest first (spec: end_date DESC).
 */
final class EducationRepository extends Repository
{
    protected function table(): string
    {
        return 'educations';
    }

    protected function defaultOrder(): array
    {
        return ['end_date', 'DESC'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decorate(array $row): array
    {
        $row = parent::decorate($row);

        $row['is_current'] = (int) ($row['is_current'] ?? 0) === 1;
        $row['period'] = Str::dateRange(
            $row['start_date'] ?? null,
            $row['is_current'] ? null : ($row['end_date'] ?? null),
            'Sekarang',
        );

        return $row;
    }
}
