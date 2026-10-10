<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\Auth;
use Mops\Core\Backup;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;
use Throwable;

/**
 * Backup + restore (roadmap 1.0).
 */
final class BackupController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
        private Backup $backup,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->admin($response, 'backup.twig', [
            'title' => 'Backup',
            'crumbs' => $this->crumbs('Backup'),
            'backups' => $this->backup->all(),
            'supports_zip' => $this->backup->supportsZip(),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);
        $scope = (string) ($input['scope'] ?? 'full') === 'database' ? 'database' : 'full';

        try {
            $result = $this->backup->create($scope);
            $this->activity->log('backup', 'Backup dibuat: ' . $result['filename'], $this->auth->id(), 'backup');
            $this->flash('success', 'Backup dibuat: ' . $result['filename']);
        } catch (Throwable $e) {
            $this->flash('error', 'Backup gagal: ' . $e->getMessage());
        }

        return $this->redirect($response, '/admin/backup');
    }

    public function download(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $filename = basename((string) ($args['filename'] ?? ''));
        $path = $this->backup->path($filename);

        if ($path === null) {
            $this->flash('error', 'Backup tidak ditemukan.');

            return $this->redirect($response, '/admin/backup');
        }

        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withBody(new Stream(fopen($path, 'rb')));
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $filename = basename((string) ($args['filename'] ?? ''));

        if ($this->backup->delete($filename)) {
            $this->activity->log('backup_delete', 'Backup dihapus: ' . $filename, $this->auth->id(), 'backup');
            $this->flash('success', 'Backup dihapus.');
        } else {
            $this->flash('error', 'Gagal menghapus backup.');
        }

        return $this->redirect($response, '/admin/backup');
    }

    public function restore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $filename = basename((string) ($args['filename'] ?? ''));

        try {
            $this->backup->restore($filename);
            $this->activity->log('backup_restore', 'Backup dipulihkan: ' . $filename, $this->auth->id(), 'backup');
            $this->flash('success', 'Backup dipulihkan. Silakan masuk kembali bila perlu.');
        } catch (Throwable $e) {
            $this->flash('error', 'Restore gagal: ' . $e->getMessage());
        }

        return $this->redirect($response, '/admin/backup');
    }
}
