<?php

declare(strict_types=1);

namespace Mops\Repositories;

/**
 * Skills. Ordered by manual sort_order (spec: sort_order ASC).
 */
final class SkillRepository extends Repository
{
    protected function table(): string
    {
        return 'skills';
    }

    protected function defaultOrder(): array
    {
        return ['sort_order', 'ASC'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decorate(array $row): array
    {
        $row = parent::decorate($row);

        $row['level'] = max(0, min(100, (int) ($row['level'] ?? 0)));
        $row['is_featured'] = (int) ($row['is_featured'] ?? 0) === 1;

        return $row;
    }

    /**
     * Skills grouped by category, ready for the homepage grid.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function grouped(?int $limit = null): array
    {
        $skills = $this->slice($limit);
        $grouped = [];

        foreach ($skills as $skill) {
            $category = trim((string) ($skill['category'] ?? '')) ?: 'Lainnya';
            $grouped[$category][] = $skill;
        }

        return $grouped;
    }

    /**
     * @return array<int, string>
     */
    public function categories(): array
    {
        $categories = [];

        foreach ($this->all() as $skill) {
            $category = trim((string) ($skill['category'] ?? ''));

            if ($category !== '' && !in_array($category, $categories, true)) {
                $categories[] = $category;
            }
        }

        sort($categories);

        return $categories;
    }
}
