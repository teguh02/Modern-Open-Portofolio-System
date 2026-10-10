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
use Mops\Repositories\AwardRepository;
use Mops\Repositories\MediaRepository;
use Mops\Repositories\Repository;

final class AwardController extends ResourceController
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
        private AwardRepository $awards,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry, $uploader, $media);
    }

    protected function routeBase(): string
    {
        return 'awards';
    }

    protected function title(): string
    {
        return 'Penghargaan';
    }

    protected function singular(): string
    {
        return 'penghargaan';
    }

    protected function repository(): Repository
    {
        return $this->awards;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'title', 'label' => 'Nama Penghargaan', 'type' => 'text', 'required' => true, 'col' => 8, 'max' => 191],
            ['name' => 'year', 'label' => 'Tahun', 'type' => 'number', 'required' => true, 'col' => 4, 'min' => 1900, 'max' => 2100, 'default' => (int) date('Y')],
            ['name' => 'issuer', 'label' => 'Pemberi', 'type' => 'text', 'col' => 6, 'max' => 160],
            ['name' => 'url', 'label' => 'Tautan', 'type' => 'url', 'col' => 6, 'max' => 255],
            ['name' => 'description', 'label' => 'Deskripsi', 'type' => 'textarea', 'col' => 12, 'rows' => 4],
            ['name' => 'sort_order', 'label' => 'Urutan', 'type' => 'number', 'col' => 4, 'default' => 0],
        ];
    }

    protected function columns(): array
    {
        return [
            ['name' => 'title', 'label' => 'Penghargaan', 'sortable' => true],
            ['name' => 'issuer', 'label' => 'Pemberi', 'sortable' => true],
            ['name' => 'year', 'label' => 'Tahun', 'sortable' => true],
        ];
    }

    protected function searchable(): array
    {
        return ['title', 'issuer'];
    }

    protected function defaultSort(): array
    {
        return ['year', 'desc'];
    }
}
