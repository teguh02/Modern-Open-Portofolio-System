<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Controllers\Controller;
use Mops\Core\App;
use Mops\Core\Auth;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared admin plumbing: layout data (sidebar groups, breadcrumbs) and the
 * activity log. Admin UI language is English (see docs/admin-ui.md) while the
 * public labels stay Indonesian.
 */
abstract class AdminController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        protected Settings $settings,
        protected ActivityRepository $activity,
        protected SectionRegistry $registry,
    ) {
        parent::__construct($view, $session, $auth);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function admin(ResponseInterface $response, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        return $this->view($response, 'admin/' . $template, array_merge([
            'nav' => $this->nav(),
            'page' => [
                'title' => $data['title'] ?? 'Dashboard',
                'crumbs' => $data['crumbs'] ?? [],
            ],
            'app_version' => App::VERSION,
        ], $data), $status);
    }

    /**
     * Sidebar navigation, grouped like the admin prototype.
     *
     * @return array<int, array{label: string, items: array<int, array<string, mixed>>}>
     */
    protected function nav(): array
    {
        $path = $_SERVER['REQUEST_URI'] ?? '/admin';
        $path = (string) (parse_url($path, PHP_URL_PATH) ?: '/admin');

        $item = static function (string $label, string $href, string $icon, string $match) use ($path): array {
            $active = $match === '/admin'
                ? rtrim($path, '/') === '/admin' || rtrim($path, '/') === ''
                : str_starts_with($path, $match);

            return [
                'label' => $label,
                'href' => $href,
                'icon' => $icon,
                'active' => $active,
            ];
        };

        return [
            [
                'label' => 'Overview',
                'items' => [
                    $item('Dashboard', '/admin', 'grid', '/admin'),
                ],
            ],
            [
                'label' => 'Content',
                'items' => [
                    $item('Profile', '/admin/profile', 'user', '/admin/profile'),
                    $item('Experience', '/admin/experiences', 'briefcase', '/admin/experiences'),
                    $item('Education', '/admin/education', 'academic', '/admin/education'),
                    $item('Skills', '/admin/skills', 'sparkles', '/admin/skills'),
                    $item('Certifications', '/admin/certifications', 'badge', '/admin/certifications'),
                    $item('Awards', '/admin/awards', 'trophy', '/admin/awards'),
                    $item('Projects', '/admin/projects', 'cube', '/admin/projects'),
                    $item('Blog', '/admin/posts', 'document', '/admin/posts'),
                    $item('Media', '/admin/media', 'photo', '/admin/media'),
                ],
            ],
            [
                'label' => 'System',
                'items' => [
                    $item('Themes', '/admin/themes', 'swatch', '/admin/themes'),
                    $item('Plugins', '/admin/plugins', 'puzzle', '/admin/plugins'),
                    $item('Settings', '/admin/settings', 'cog', '/admin/settings'),
                    $item('Backup', '/admin/backup', 'archive', '/admin/backup'),
                ],
            ],
        ];
    }

    /**
     * @param array<int, array{label: string, href?: string}> $crumbs
     */
    protected function crumbs(string $current, string $parentLabel = '', string $parentHref = ''): array
    {
        $crumbs = [];

        if ($parentLabel !== '') {
            $crumbs[] = ['label' => $parentLabel, 'href' => $parentHref];
        }

        $crumbs[] = ['label' => $current];

        return $crumbs;
    }
}
