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
use Mops\Repositories\ExperienceRepository;
use Mops\Repositories\MediaRepository;
use Mops\Repositories\Repository;

final class ExperienceController extends ResourceController
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
        private ExperienceRepository $experiences,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry, $uploader, $media);
    }

    protected function routeBase(): string
    {
        return 'experiences';
    }

    protected function title(): string
    {
        return 'Riwayat Kerja';
    }

    protected function singular(): string
    {
        return 'riwayat kerja';
    }

    protected function repository(): Repository
    {
        return $this->experiences;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'title', 'label' => 'Posisi', 'type' => 'text', 'required' => true, 'col' => 6, 'max' => 160],
            ['name' => 'company', 'label' => 'Perusahaan', 'type' => 'text', 'required' => true, 'col' => 6, 'max' => 160],
            ['name' => 'employment_type', 'label' => 'Tipe Pekerjaan', 'type' => 'select', 'col' => 6, 'options' => [
                'Full-time' => 'Full-time',
                'Part-time' => 'Part-time',
                'Freelance' => 'Freelance',
                'Contract' => 'Kontrak',
                'Internship' => 'Magang',
            ]],
            ['name' => 'location', 'label' => 'Lokasi', 'type' => 'text', 'col' => 6, 'max' => 160, 'hint' => 'Kosongkan untuk Remote'],
            ['name' => 'start_date', 'label' => 'Mulai', 'type' => 'date', 'required' => true, 'col' => 4],
            ['name' => 'end_date', 'label' => 'Selesai', 'type' => 'date', 'col' => 4, 'hint' => 'Diabaikan jika masih bekerja'],
            ['name' => 'is_current', 'label' => 'Masih bekerja di sini', 'type' => 'toggle', 'col' => 4],
            ['name' => 'company_url', 'label' => 'URL Perusahaan', 'type' => 'url', 'col' => 6, 'max' => 255],
            ['name' => 'logo', 'label' => 'Logo', 'type' => 'media', 'col' => 6],
            ['name' => 'highlights', 'label' => 'Poin Pencapaian', 'type' => 'list', 'col' => 12, 'hint' => 'Satu poin per baris'],
            ['name' => 'description', 'label' => 'Deskripsi', 'type' => 'richtext', 'col' => 12],
            ['name' => 'sort_order', 'label' => 'Urutan', 'type' => 'number', 'col' => 4, 'default' => 0],
        ];
    }

    protected function columns(): array
    {
        return [
            ['name' => 'title', 'label' => 'Posisi', 'sortable' => true],
            ['name' => 'company', 'label' => 'Perusahaan', 'sortable' => true],
            ['name' => 'period', 'label' => 'Periode'],
            ['name' => 'duration', 'label' => 'Durasi'],
            ['name' => 'is_current', 'label' => 'Status', 'type' => 'badge'],
        ];
    }

    protected function searchable(): array
    {
        return ['title', 'company', 'location'];
    }

    protected function defaultSort(): array
    {
        return ['start_date', 'desc'];
    }

    protected function prepare(array $data, ?array $existing = null): array
    {
        $current = (int) ($data['is_current'] ?? 0) === 1;
        $data['is_current'] = $current ? 1 : 0;

        if ($current) {
            $data['end_date'] = null;
        }

        return $data;
    }
}
