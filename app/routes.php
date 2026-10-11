<?php

declare(strict_types=1);

use Mops\Controllers\Admin;
use Mops\Controllers\AssetController;
use Mops\Controllers\AuthController;
use Mops\Controllers\BlogController;
use Mops\Controllers\HomeController;
use Mops\Controllers\InstallController;
use Mops\Controllers\SectionController;
use Mops\Core\App;
use Mops\Core\SectionRegistry;
use Mops\Middleware\AdminAuthMiddleware;
use Slim\App as SlimApp;
use Slim\Routing\RouteCollectorProxy;

/**
 * Routes come in two passes so plugins can inject their own in between
 * (the `routes_registered` action fires between them):
 *
 *   $main     — everything core owns
 *   $fallback — the catch-all 404, which must be registered last
 */
$main = static function (SlimApp $app, App $kernel): void {
    $registry = $kernel->get(SectionRegistry::class);

    // ---------------------------------------------------------------- public
    $app->get('/', HomeController::class . ':index')->setName('home');

    // Section detail pages. Slugs are fixed English routes (spec), the labels
    // stay localized.
    foreach ($registry->withDetailRoutes() as $section) {
        $id = (string) $section['id'];
        $url = (string) $section['more_url'];

        if ($id === 'blog') {
            continue; // blog owns its own routes below
        }

        $app->get($url, SectionController::class . ':show')->setArgument('section', $id);
    }

    $app->get('/blog', BlogController::class . ':index')->setName('blog.index');
    $app->get('/blog/{slug}', BlogController::class . ':show')->setName('blog.show');
    $app->get('/projects/{slug}', SectionController::class . ':project')->setName('projects.show');
    $app->get('/robots.txt', SectionController::class . ':robots')->setName('robots');

    // Media + theme assets are streamed by core because storage/ and themes/
    // live outside the web root.
    $app->get('/uploads/{path:.*}', AssetController::class . ':upload')->setName('uploads.show');
    $app->get('/theme/{slug}/assets/{path:.*}', AssetController::class . ':themeAsset')->setName('theme.asset');
    $app->get('/plugin/{slug}/assets/{path:.*}', AssetController::class . ':pluginAsset')->setName('plugin.asset');

    // ------------------------------------------------------------- installer
    $app->get('/install', InstallController::class . ':index')->setName('install.index');
    $app->post('/install', InstallController::class . ':store')->setName('install.store');
    $app->get('/install/requirements', InstallController::class . ':requirements')->setName('install.requirements');

    // ----------------------------------------------------------------- admin
    $app->post('/admin/login', AuthController::class . ':login')->setName('admin.login.post');
    $app->post('/admin/logout', AuthController::class . ':logout')->setName('admin.logout');

    $app->group('/admin', function (RouteCollectorProxy $group): void {
        $group->get('', Admin\DashboardController::class . ':index')->setName('admin.dashboard');
        $group->get('/', Admin\DashboardController::class . ':index');

        $group->get('/login', AuthController::class . ':showLogin')->setName('admin.login');

        // Single-record editor: identity + bio.
        $group->get('/profile', Admin\ProfileController::class . ':edit');
        $group->post('/profile', Admin\ProfileController::class . ':update');

        // Generic resource CRUD (experiences, education, skills, ...).
        foreach ([
            'experiences' => [
                Admin\ExperienceController::class,
                [['name' => 'Riwayat Kerja', 'icon' => 'briefcase']],
            ],
            'education' => [
                Admin\EducationController::class,
                [['name' => 'Pendidikan', 'icon' => 'academic-cap']],
            ],
            'skills' => [
                Admin\SkillController::class,
                [['name' => 'Keahlian', 'icon' => 'sparkles']],
            ],
            'certifications' => [
                Admin\CertificationController::class,
                [['name' => 'Sertifikasi', 'icon' => 'badge-check']],
            ],
            'awards' => [
                Admin\AwardController::class,
                [['name' => 'Penghargaan', 'icon' => 'trophy']],
            ],
            'projects' => [
                Admin\ProjectController::class,
                [['name' => 'Proyek', 'icon' => 'cube']],
            ],
            'posts' => [
                Admin\PostController::class,
                [['name' => 'Blog', 'icon' => 'document-text']],
            ],
        ] as $base => [$controller]) {
            $group->get("/{$base}", $controller . ':index');
            $group->get("/{$base}/create", $controller . ':create');
            $group->post("/{$base}", $controller . ':store');
            $group->post("/{$base}/bulk", $controller . ':bulk');
            $group->get("/{$base}/{id:[0-9]+}/edit", $controller . ':edit');
            $group->post("/{$base}/{id:[0-9]+}", $controller . ':update');
            $group->post("/{$base}/{id:[0-9]+}/delete", $controller . ':destroy');
            $group->post("/{$base}/{id:[0-9]+}/toggle", $controller . ':toggle');
        }

        $group->get('/media', Admin\MediaController::class . ':index');
        $group->get('/media/library.json', Admin\MediaController::class . ':library');
        $group->post('/media', Admin\MediaController::class . ':upload');
        $group->post('/media/{id:[0-9]+}', Admin\MediaController::class . ':update');
        $group->post('/media/{id:[0-9]+}/delete', Admin\MediaController::class . ':destroy');

        $group->get('/settings', Admin\SettingsController::class . ':index');
        $group->post('/settings', Admin\SettingsController::class . ':update');

        $group->get('/themes', Admin\ThemeController::class . ':index');
        $group->post('/themes/upload', Admin\ThemeController::class . ':upload');
        $group->post('/themes/{slug}/activate', Admin\ThemeController::class . ':activate');
        $group->post('/themes/{slug}/delete', Admin\ThemeController::class . ':destroy');

        $group->get('/plugins', Admin\PluginController::class . ':index');
        $group->post('/plugins/upload', Admin\PluginController::class . ':upload');
        $group->post('/plugins/{slug}/activate', Admin\PluginController::class . ':activate');
        $group->post('/plugins/{slug}/deactivate', Admin\PluginController::class . ':deactivate');
        $group->post('/plugins/{slug}/delete', Admin\PluginController::class . ':destroy');

        $group->get('/backup', Admin\BackupController::class . ':index');
        $group->post('/backup', Admin\BackupController::class . ':create');
        $group->get('/backup/{filename}/download', Admin\BackupController::class . ':download');
        $group->post('/backup/{filename}/delete', Admin\BackupController::class . ':destroy');
        $group->post('/backup/{filename}/restore', Admin\BackupController::class . ':restore');

        // Plugin-owned pages (add_menu_page). Registered last so static core
        // routes always win in FastRoute; the 404 for unknown slugs is thrown
        // by the controller.
        $group->get('/{menu:[a-z0-9][a-z0-9-]*}', Admin\MenuPageController::class . ':show');
        $group->post('/{menu:[a-z0-9][a-z0-9-]*}', Admin\MenuPageController::class . ':submit');
    })->add($kernel->get(AdminAuthMiddleware::class));
};

/**
 * Friendly 404 for everything else.
 *
 * `/{path:.*}` matches any path, and FastRoute refuses to add a static route
 * that a previously registered variable route would swallow — so if this ran
 * before the plugin routes, every plugin route would crash the app with a 500.
 */
$fallback = static function (SlimApp $app, App $kernel): void {
    $app->get('/{path:.*}', SectionController::class . ':notFound');
};

return ['main' => $main, 'fallback' => $fallback];
