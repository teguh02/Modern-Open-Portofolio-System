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
use Mops\Repositories\EducationRepository;
use Mops\Repositories\MediaRepository;
use Mops\Repositories\Repository;

final class EducationController extends ResourceController
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
        private EducationRepository $educations,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry, $uploader, $media);
    }

    protected function routeBase(): string
    {
        return 'education';
    }

    protected function title(): string
    {
        return 'Pendidikan';
    }

    protected function singular(): string
    {
        return 'pendidikan';
    }

    protected function repository(): Repository
    {
        return $this->educations;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'school', 'label' => 'Institusi', 'type' => 'text', 'required' => true, 'col' => 6, 'max' => 160],
            ['name' => 'degree', 'label' => 'Jenjang', 'type' => 'text', 'col' => 6, 'max' => 160, 'hint' => 'S1, S2, SMK, ...'],
            ['name' => 'field_of_study', 'label' => 'Program Studi', 'type' => 'text', 'col' => 6, 'max' => 160],
            ['name' => 'location', 'label' => 'Lokasi', 'type' => 'text', 'col' => 6, 'max' => 160],
            ['name' => 'start_date', 'label' => 'Mulai', 'type' => 'date', 'required' => true, 'col' => 4],
            ['name' => 'end_date', 'label' => 'Selesai', 'type' => 'date', 'col' => 4],
            ['name' => 'is_current', 'label' => 'Masih menempuh', 'type' => 'toggle', 'col' => 4],
            ['name' => 'grade', 'label' => 'IPK / Nilai', 'type' => 'text', 'col' => 4, 'max' => 64],
            ['name' => 'logo', 'label' => 'Logo', 'type' => 'media', 'col' => 8],
            ['name' => 'description', 'label' => 'Deskripsi', 'type' => 'textarea', 'col' => 12, 'rows' => 4],
            ['name' => 'sort_order', 'label' => 'Urutan', 'type' => 'number', 'col' => 4, 'default' => 0],
        ];
    }

    protected function columns(): array
    {
        return [
            ['name' => 'school', 'label' => 'Institusi', 'sortable' => true],
            ['name' => 'degree', 'label' => 'Jenjang'],
            ['name' => 'field_of_study', 'label' => 'Program Studi'],
            ['name' => 'period', 'label' => 'Periode'],
            ['name' => 'is_current', 'label' => 'Status', 'type' => 'badge'],
        ];
    }

    protected function searchable(): array
    {
        return ['school', 'degree', 'field_of_study'];
    }

    protected function defaultSort(): array
    {
        return ['end_date', 'desc'];
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
