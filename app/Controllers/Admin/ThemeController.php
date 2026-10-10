<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\Auth;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\ThemeManager;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
}
