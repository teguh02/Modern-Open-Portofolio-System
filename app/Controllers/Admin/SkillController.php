<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\Auth;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\Uploader;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Mops\Repositories\MediaRepository;
use Mops\Repositories\Repository;
use Mops\Repositories\SkillRepository;

final class SkillController extends ResourceController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
        Uploader $uploader,
        MediaRepository $media,
        private SkillRepository $skills,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry, $uploader, $media);
    }

    protected function routeBase(): string
    {
        return 'skills';
    }

    protected function title(): string
    {
        return 'Keahlian';
    }

    protected function singular(): string
    {
        return 'keahlian';
    }

    protected function repository(): Repository
    {
        return $this->skills;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Nama Keahlian', 'type' => 'text', 'required' => true, 'col' => 6, 'max' => 120],
            ['name' => 'category', 'label' => 'Kategori', 'type' => 'text', 'col' => 6, 'max' => 120, 'hint' => 'Backend, Frontend, Design, ...'],
            ['name' => 'level', 'label' => 'Tingkat Penguasaan', 'type' => 'range', 'col' => 6, 'min' => 0, 'max' => 100, 'default' => 80],
            ['name' => 'years', 'label' => 'Pengalaman (tahun)', 'type' => 'number', 'col' => 3, 'min' => 0],
            ['name' => 'sort_order', 'label' => 'Urutan', 'type' => 'number', 'col' => 3, 'default' => 0],
            ['name' => 'is_featured', 'label' => 'Tampilkan sebagai keahlian utama', 'type' => 'toggle', 'col' => 6],
            ['name' => 'icon', 'label' => 'Ikon', 'type' => 'text', 'col' => 6, 'max' => 120, 'hint' => 'Nama ikon/slug, mis. php, figma'],
        ];
    }

    protected function columns(): array
    {
        return [
            ['name' => 'name', 'label' => 'Keahlian', 'sortable' => true],
            ['name' => 'category', 'label' => 'Kategori', 'sortable' => true],
            ['name' => 'level', 'label' => 'Level', 'type' => 'progress'],
            ['name' => 'years', 'label' => 'Tahun'],
            ['name' => 'is_featured', 'label' => 'Utama', 'type' => 'boolean'],
        ];
    }

    protected function searchable(): array
    {
        return ['name', 'category'];
    }

    protected function defaultSort(): array
    {
        return ['sort_order', 'asc'];
    }

    protected function prepare(array $data, ?array $existing = null): array
    {
        $data['level'] = max(0, min(100, (int) ($data['level'] ?? 0)));
        $data['is_featured'] = (int) ($data['is_featured'] ?? 0) === 1 ? 1 : 0;

        return $data;
    }

    protected function perPage(): int
    {
        return 25;
    }
}
