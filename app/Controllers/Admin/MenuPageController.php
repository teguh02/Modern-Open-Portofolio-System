<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\AdminMenu;
use Mops\Core\Auth;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Serves plugin-owned admin pages registered with add_menu_page(). GET and
 * POST both land here — a settings form posts back to its own page and the
 * plugin reads the method inside the renderer.
 */
final class MenuPageController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderMenuPage($request, $response, (string) ($args['menu'] ?? ''));
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->renderMenuPage($request, $response, (string) ($args['menu'] ?? ''));
    }

    private function renderMenuPage(ServerRequestInterface $request, ResponseInterface $response, string $slug): ResponseInterface
    {
        $page = AdminMenu::find($slug);

        if ($page === null) {
            throw new HttpNotFoundException($request);
        }

        $renderer = $page['renderer'];
        $html = (string) $renderer($request);

        return $this->admin($response, 'plugin-page.twig', [
            'title' => $page['title'],
            'crumbs' => $this->crumbs($page['title'], 'Plugins', '/admin/plugins'),
            'body_html' => $html,
        ]);
    }
}
