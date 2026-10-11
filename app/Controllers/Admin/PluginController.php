<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\Auth;
use Mops\Core\Hook;
use Mops\Core\HookZones;
use Mops\Core\Paths;
use Mops\Core\PluginManager;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\View;
use Mops\Core\ZipInstaller;
use Mops\Repositories\ActivityRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * Plugin manager. Plugins extend MOPS only through hooks/events and manifests.
 */
final class PluginController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
        private PluginManager $plugins,
        private ZipInstaller $installer,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $plugins = [];

        foreach ($this->plugins->all() as $slug => $plugin) {
            $plugins[] = array_merge($plugin, ['is_active' => $this->plugins->isActive($slug)]);
        }

        return $this->admin($response, 'plugins.twig', [
            'title' => 'Plugins',
            'crumbs' => $this->crumbs('Plugins'),
            'plugins' => $plugins,
            'hooks' => Hook::registeredHooks(),
            // Every place a plugin may inject output, so authors can pick one
            // instead of editing a theme (the WordPress way).
            'zones' => HookZones::all(),
            'filled_zones' => HookZones::filled(),
            'load_errors' => $this->plugins->errors(),
            'supports_zip' => class_exists(\ZipArchive::class),
        ]);
    }

    public function activate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');

        try {
            $this->plugins->activate($slug);
            // Pick up any template paths the plugin registered.
            $this->view->refresh();
            $this->activity->log('plugin_activate', "Plugin diaktifkan: {$slug}", $this->auth->id(), 'plugin');
            $this->flash('success', "Plugin '{$slug}' diaktifkan.");
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/plugins');
    }

    public function deactivate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');

        try {
            $this->plugins->deactivate($slug);
            $this->view->refresh();
            $this->activity->log('plugin_deactivate', "Plugin dinonaktifkan: {$slug}", $this->auth->id(), 'plugin');
            $this->flash('success', "Plugin '{$slug}' dinonaktifkan.");
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/plugins');
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = (array) $request->getUploadedFiles();
        $file = $files['plugin_zip'] ?? null;

        try {
            if (!$file instanceof UploadedFileInterface) {
                throw new RuntimeException('Pilih file zip plugin.');
            }

            $slug = $this->installer->installUpload($file, Paths::make('plugins'), 'plugin.json');
            $this->plugins->flush();
            $this->activity->log('plugin_install', "Plugin diinstal dari zip: {$slug}", $this->auth->id(), 'plugin');
            $this->flash('success', "Plugin '{$slug}' berhasil diinstal.");
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/plugins');
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');

        try {
            $this->plugins->delete($slug);
            $this->view->refresh();
            $this->activity->log('plugin_delete', "Plugin dihapus: {$slug}", $this->auth->id(), 'plugin');
            $this->flash('success', "Plugin '{$slug}' dihapus.");
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect($response, '/admin/plugins');
    }
}
