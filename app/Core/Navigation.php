<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * Public navigation for themes.
 *
 * Visibility (toggle ON + has data) is computed here in core and handed to the
 * theme as a ready-made list — themes never query the database or re-implement
 * the visibility rule.
 */
final class Navigation
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $items = null;

    /** @var array<string, string> section id => table */
    private const TABLES = [
        'experience' => 'experiences',
        'projects' => 'projects',
        'skills' => 'skills',
        'education' => 'educations',
        'certifications' => 'certifications',
        'awards' => 'awards',
        'blog' => 'posts',
    ];

    public function __construct(
        private Database $db,
        private SectionRegistry $registry,
        private Settings $settings,
    ) {
    }

    /**
     * Links for sections that are enabled, have data and own a detail route.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $items = [];

        foreach ($this->registry->withDetailRoutes() as $section) {
            $id = (string) $section['id'];
            $enabled = (bool) $this->settings->bool(
                $this->registry->settingKey($id),
                (bool) $section['default'],
            );

            if (!$enabled) {
                continue;
            }

            $items[] = [
                'id' => $id,
                'title' => (string) $section['title'],
                'url' => (string) $section['more_url'],
                'total' => $this->count($id),
            ];
        }

        /** @var array<int, array<string, mixed>> $filtered */
        $filtered = Hook::applyFilters('navigation_items', $items);

        return $this->items = $filtered;
    }

    /**
     * @return array<int, string>
     */
    public function urls(): array
    {
        return array_map(static fn (array $item): string => (string) $item['url'], $this->items());
    }

    public function count(string $sectionId): int
    {
        $table = self::TABLES[$sectionId] ?? null;

        if ($table === null || !$this->db->tableExists($table)) {
            return 0;
        }

        try {
            $query = $this->db->table($table);

            // Only published content is visible on the public site.
            if (in_array($sectionId, ['projects', 'blog'], true)) {
                $query->where('status', 'published');
            }

            return $query->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function flush(): void
    {
        $this->items = null;
    }
}
