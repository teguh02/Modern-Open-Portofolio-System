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
use Mops\Repositories\ProjectRepository;
use Mops\Repositories\Repository;

final class ProjectController extends ResourceController
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
        private ProjectRepository $projects,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry, $uploader, $media);
    }

    protected function routeBase(): string
    {
        return 'projects';
    }

    protected function title(): string
    {
        return 'Proyek';
    }

    protected function singular(): string
    {
        return 'proyek';
    }

    protected function repository(): Repository
    {
        return $this->projects;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'title', 'label' => 'Nama Proyek', 'type' => 'text', 'required' => true, 'col' => 8, 'max' => 191],
            ['name' => 'slug', 'label' => 'Slug', 'type' => 'text', 'col' => 4, 'max' => 191, 'hint' => 'Kosongkan untuk otomatis'],
            ['name' => 'summary', 'label' => 'Ringkasan', 'type' => 'text', 'col' => 12, 'max' => 255, 'hint' => 'Muncul di kartu homepage'],
            ['name' => 'description', 'label' => 'Deskripsi', 'type' => 'richtext', 'col' => 12],
            ['name' => 'thumbnail', 'label' => 'Thumbnail', 'type' => 'media', 'col' => 6],
            ['name' => 'tech_stack', 'label' => 'Tech Stack', 'type' => 'list', 'col' => 6, 'hint' => 'Satu teknologi per baris'],
            ['name' => 'role', 'label' => 'Peran', 'type' => 'text', 'col' => 4, 'max' => 160],
            ['name' => 'client', 'label' => 'Klien', 'type' => 'text', 'col' => 4, 'max' => 160],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'col' => 4, 'options' => [
                'draft' => 'Draft',
                'published' => 'Published',
            ], 'default' => 'draft'],
            ['name' => 'project_url', 'label' => 'URL Proyek', 'type' => 'url', 'col' => 6, 'max' => 255],
            ['name' => 'repo_url', 'label' => 'URL Repositori', 'type' => 'url', 'col' => 6, 'max' => 255],
            ['name' => 'started_at', 'label' => 'Mulai', 'type' => 'date', 'col' => 4],
            ['name' => 'completed_at', 'label' => 'Selesai', 'type' => 'date', 'col' => 4],
            ['name' => 'featured', 'label' => 'Proyek unggulan', 'type' => 'toggle', 'col' => 4],
            ['name' => 'gallery', 'label' => 'Galeri', 'type' => 'list', 'col' => 12, 'hint' => 'Satu path media per baris (salin dari Media Library)'],
            ['name' => 'sort_order', 'label' => 'Urutan', 'type' => 'number', 'col' => 4, 'default' => 0],
        ];
    }

    protected function columns(): array
    {
        return [
            ['name' => 'title', 'label' => 'Proyek', 'sortable' => true],
            ['name' => 'role', 'label' => 'Peran'],
            ['name' => 'period', 'label' => 'Periode'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'badge'],
            ['name' => 'featured', 'label' => 'Unggulan', 'type' => 'boolean'],
        ];
    }

    protected function searchable(): array
    {
        return ['title', 'role', 'client', 'summary'];
    }

    protected function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    protected function canToggle(): bool
    {
        return true;
    }

    protected function prepare(array $data, ?array $existing = null): array
    {
        $data['featured'] = (int) ($data['featured'] ?? 0) === 1 ? 1 : 0;

        if (($data['slug'] ?? '') === '') {
            unset($data['slug']);
        }

        return $data;
    }
}
