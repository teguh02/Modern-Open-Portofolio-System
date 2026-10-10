<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * The single source of truth for homepage sections: order, label, item limit,
 * default visibility and detail route.
 *
 * Per docs/superpowers/specs/2026-10-10-homepage-sections-design.md the order is
 * fixed (array order), limits are hard-coded here, and admin can only toggle
 * visibility. Nothing in a theme may re-implement this logic.
 */
final class SectionRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $sections = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->sections !== null) {
            return array_values($this->sections);
        }

        $sections = [
            [
                'id' => 'profile',
                'title' => 'Identitas',
                'limit' => null,
                'default' => true,
                'always_visible' => true,
                'has_toggle' => false,
                'more_url' => null,
                'model' => 'profile',
            ],
            [
                'id' => 'about',
                'title' => 'Tentang',
                'limit' => null,
                'default' => true,
                'always_visible' => false,
                'has_toggle' => true,
                'more_url' => null,
                'model' => 'profile',
            ],
            [
                'id' => 'experience',
                'title' => 'Riwayat Kerja',
                'limit' => 5,
                'default' => false,
                'always_visible' => false,
                'has_toggle' => true,
                'more_url' => '/experience',
                'model' => 'experience',
            ],
            [
                'id' => 'projects',
                'title' => 'Proyek',
                'limit' => 6,
                'default' => false,
                'always_visible' => false,
                'has_toggle' => true,
                'more_url' => '/projects',
                'model' => 'project',
            ],
            [
                'id' => 'skills',
                'title' => 'Keahlian',
                'limit' => 12,
                'default' => false,
                'always_visible' => false,
                'has_toggle' => true,
                'more_url' => '/skills',
                'model' => 'skill',
            ],
            [
                'id' => 'education',
                'title' => 'Pendidikan',
                'limit' => 3,
                'default' => false,
                'always_visible' => false,
                'has_toggle' => true,
                'more_url' => '/education',
                'model' => 'education',
            ],
            [
                'id' => 'certifications',
                'title' => 'Sertifikasi',
                'limit' => 5,
                'default' => false,
                'always_visible' => false,
                'has_toggle' => true,
                'more_url' => '/certifications',
                'model' => 'certification',
            ],
            [
                'id' => 'awards',
                'title' => 'Penghargaan',
                'limit' => 3,
                'default' => false,
                'always_visible' => false,
                'has_toggle' => true,
                'more_url' => '/awards',
                'model' => 'award',
            ],
            [
                'id' => 'blog',
                'title' => 'Blog Terbaru',
                'limit' => 4,
                'default' => false,
                'always_visible' => false,
                'has_toggle' => true,
                'more_url' => '/blog',
                'model' => 'post',
            ],
        ];

        /** @var array<int, array<string, mixed>> $filtered */
        $filtered = Hook::applyFilters('section_registry', $sections);

        $this->sections = [];

        foreach ($filtered as $section) {
            if (!isset($section['id'])) {
                continue;
            }

            $this->sections[(string) $section['id']] = array_merge([
                'limit' => null,
                'default' => false,
                'always_visible' => false,
                'has_toggle' => true,
                'more_url' => null,
                'model' => null,
                'title' => (string) $section['id'],
            ], $section);
        }

        return array_values($this->sections);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $section) {
            if ($section['id'] === $id) {
                return $section;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function ids(): array
    {
        return array_map(static fn (array $section): string => (string) $section['id'], $this->all());
    }

    /**
     * Sections an admin can toggle (everything except `profile`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function toggleable(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $section): bool => ($section['has_toggle'] ?? true) === true,
        ));
    }

    /**
     * Sections that have a "Lihat selengkapnya" destination.
     *
     * @return array<int, array<string, mixed>>
     */
    public function withDetailRoutes(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $section): bool => ($section['more_url'] ?? null) !== null,
        ));
    }

    public function settingKey(string $id): string
    {
        return "section_enabled_{$id}";
    }

    /**
     * Visibility rule from the spec:
     *   visible = (alwaysVisible || flag ON) && (total > 0 || alwaysVisible)
     */
    public function isVisible(array $section, bool $enabled, int $total): bool
    {
        $always = ($section['always_visible'] ?? false) === true;
        $hasData = $total > 0;

        if ($always) {
            return true;
        }

        return $enabled && $hasData;
    }

    /**
     * The "Lihat selengkapnya" button shows only when there is more to see and
     * the section owns a detail route.
     */
    public function moreUrlFor(array $section, int $total, int $rendered): ?string
    {
        $url = $section['more_url'] ?? null;

        if ($url === null || $total <= $rendered) {
            return null;
        }

        return (string) $url;
    }

    /**
     * Test helper: drop the memoised registry.
     */
    public function flush(): void
    {
        $this->sections = null;
    }
}
