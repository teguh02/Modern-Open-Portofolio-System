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
use Mops\Repositories\PostRepository;
use Mops\Repositories\Repository;

final class PostController extends ResourceController
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
        private PostRepository $posts,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry, $uploader, $media);
    }

    protected function routeBase(): string
    {
        return 'posts';
    }

    protected function title(): string
    {
        return 'Blog';
    }

    protected function singular(): string
    {
        return 'tulisan';
    }

    protected function repository(): Repository
    {
        return $this->posts;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'title', 'label' => 'Judul', 'type' => 'text', 'required' => true, 'col' => 8, 'max' => 191],
            ['name' => 'slug', 'label' => 'Slug', 'type' => 'text', 'col' => 4, 'max' => 191, 'hint' => 'Kosongkan untuk otomatis dari judul'],
            ['name' => 'excerpt', 'label' => 'Ringkasan', 'type' => 'textarea', 'col' => 12, 'rows' => 2, 'max' => 255],
            ['name' => 'content', 'label' => 'Isi Tulisan', 'type' => 'richtext', 'col' => 12, 'hint' => 'Gunakan toolbar untuk menyisipkan gambar dari media library'],
            ['name' => 'cover_image', 'label' => 'Gambar Sampul', 'type' => 'media', 'col' => 6],
            ['name' => 'tags', 'label' => 'Tag', 'type' => 'list', 'col' => 6, 'hint' => 'Satu tag per baris'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'col' => 4, 'options' => [
                'draft' => 'Draft',
                'published' => 'Published',
            ], 'default' => 'draft'],
            ['name' => 'published_at', 'label' => 'Tanggal Terbit', 'type' => 'datetime', 'col' => 8, 'hint' => 'Kosongkan untuk otomatis saat dipublikasikan'],
        ];
    }

    protected function columns(): array
    {
        return [
            ['name' => 'title', 'label' => 'Judul', 'sortable' => true],
            ['name' => 'published_label', 'label' => 'Terbit'],
            ['name' => 'reading_time', 'label' => 'Baca'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'badge'],
        ];
    }

    protected function searchable(): array
    {
        return ['title', 'excerpt', 'content'];
    }

    protected function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    protected function canToggle(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function prepare(array $data, ?array $existing = null): array
    {
        if (($data['slug'] ?? '') === '') {
            unset($data['slug']);
        }

        if (($data['published_at'] ?? '') === '') {
            unset($data['published_at']);
        }

        if ($existing === null) {
            $data['author_id'] = $this->auth->id();
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function describe(array $row): string
    {
        return (string) ($row['title'] ?? '#' . ($row['id'] ?? '?'));
    }
}
