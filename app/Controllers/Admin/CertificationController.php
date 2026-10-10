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
use Mops\Repositories\CertificationRepository;
use Mops\Repositories\MediaRepository;
use Mops\Repositories\Repository;

final class CertificationController extends ResourceController
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
        private CertificationRepository $certifications,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry, $uploader, $media);
    }

    protected function routeBase(): string
    {
        return 'certifications';
    }

    protected function title(): string
    {
        return 'Sertifikasi';
    }

    protected function singular(): string
    {
        return 'sertifikasi';
    }

    protected function repository(): Repository
    {
        return $this->certifications;
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Nama Sertifikasi', 'type' => 'text', 'required' => true, 'col' => 6, 'max' => 191],
            ['name' => 'issuer', 'label' => 'Penerbit', 'type' => 'text', 'col' => 6, 'max' => 160],
            ['name' => 'issued_date', 'label' => 'Tanggal Terbit', 'type' => 'date', 'required' => true, 'col' => 4],
            ['name' => 'expiry_date', 'label' => 'Berlaku Sampai', 'type' => 'date', 'col' => 4, 'hint' => 'Kosongkan jika berlaku selamanya'],
            ['name' => 'sort_order', 'label' => 'Urutan', 'type' => 'number', 'col' => 4, 'default' => 0],
            ['name' => 'credential_id', 'label' => 'ID Kredensial', 'type' => 'text', 'col' => 6, 'max' => 160],
            ['name' => 'credential_url', 'label' => 'URL Kredensial', 'type' => 'url', 'col' => 6, 'max' => 255],
            ['name' => 'logo', 'label' => 'Logo', 'type' => 'media', 'col' => 6],
            ['name' => 'description', 'label' => 'Deskripsi', 'type' => 'textarea', 'col' => 6, 'rows' => 3],
        ];
    }

    protected function columns(): array
    {
        return [
            ['name' => 'name', 'label' => 'Sertifikasi', 'sortable' => true],
            ['name' => 'issuer', 'label' => 'Penerbit', 'sortable' => true],
            ['name' => 'issued_label', 'label' => 'Terbit'],
            ['name' => 'expiry_label', 'label' => 'Berlaku Sampai'],
            ['name' => 'is_expired', 'label' => 'Status', 'type' => 'badge'],
        ];
    }

    protected function searchable(): array
    {
        return ['name', 'issuer', 'credential_id'];
    }

    protected function defaultSort(): array
    {
        return ['issued_date', 'desc'];
    }
}
