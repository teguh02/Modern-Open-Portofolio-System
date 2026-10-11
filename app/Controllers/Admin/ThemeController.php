<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\Auth;
use Mops\Core\Paths;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\ThemeManager;
use Mops\Core\View;
use Mops\Core\ZipInstaller;
use Mops\Repositories\ActivityRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * Theme manager. Both bundled themes (vantablo, lumica) ship on install; the
 * admin switches freely and no data is lost because themes only render.
 */
final class ThemeController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
        private ThemeManager $themes,
        private ZipInstaller $installer,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $active = $this->themes->activeSlug();

        $themes = [];

        foreach ($this->themes->all() as $slug => $theme) {
            $themes[] = array_merge($theme, ['is_active' => $slug === $active]);
        }

        return $this->admin($response, 'themes.twig', [
            'title' => 'Themes',
            'crumbs' => $this->crumbs('Themes'),
            'themes' => $themes,
            'active_slug' => $active,
        ]);
    }

    public function activate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');

        try {
            $this->themes->activate($slug);
            // The Twig loader resolves the theme folder by path, so rebuild it.
            $this->view->refresh();
            $this->activity->log('theme_activate', "Tema diaktifkan: {$slug}", $this->auth->id(), 'theme');
            $this->flash('success', "Tema '{$slug}' diaktifkan.");
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/themes');
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = (array) $request->getUploadedFiles();
        $file = $files['theme_zip'] ?? null;

        try {
            if (!$file instanceof UploadedFileInterface) {
                throw new RuntimeException('Pilih file zip tema.');
            }

            $slug = $this->installer->installUpload($file, Paths::make('themes'), 'theme.json');
            $this->themes->flush();
            $this->activity->log('theme_install', "Tema diinstal dari zip: {$slug}", $this->auth->id(), 'theme');
            $this->flash('success', "Tema '{$slug}' berhasil diinstal.");
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/themes');
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');

        try {
            $this->themes->delete($slug);
            $this->view->refresh();
            $this->activity->log('theme_delete', "Tema dihapus: {$slug}", $this->auth->id(), 'theme');
            $this->flash('success', "Tema '{$slug}' dihapus.");
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/themes');
    }
}
