<?php

declare(strict_types=1);

/**
 * Dev smoke test: boots MOPS against a throwaway SQLite database, runs the
 * installer, logs in, creates content and prints the status code of every
 * important route. Useful when a browser is not available.
 *
 *   php dev/smoke.php
 *
 * It prints the first part of any error page so failures are debuggable.
 */

define('MOPS', true);
define('MOPS_ROOT', dirname(__DIR__));

require MOPS_ROOT . '/vendor/autoload.php';

use Mops\Core\App;
use Mops\Core\Config;
use Mops\Core\Csrf;
use Mops\Core\Hook;
use Mops\Core\Paths;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;

$database = Paths::make('storage/smoke-test.sqlite');

if (is_file($database)) {
    unlink($database);
}

$config = Config::fromFile(Paths::make('config/config.php'));
$config->set('database.database', 'storage/smoke-test.sqlite');
$config->set('app.debug', true);

Hook::reset();
$_SESSION = [];

$kernel = new App($config);
$app = $kernel->slim();

/** @param array<string, mixed> $data */
function call(SlimApp $app, string $method, string $path, array $data = []): array
{
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['REQUEST_METHOD'] = $method;

    $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path);

    if ($method === 'POST') {
        $data['_token'] = $app->getContainer()->get(Csrf::class)->token();
        $request = $request->withParsedBody($data)->withHeader('Content-Type', 'application/x-www-form-urlencoded');
    }

    $response = $app->handle($request);
    $response->getBody()->rewind();

    return [$response->getStatusCode(), (string) $response->getBody()->getContents(), $response];
}

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }

    printf("[%s] %s%s\n", $ok ? ' ok ' : 'FAIL', $label, $detail !== '' ? ' — ' . $detail : '');
}

// ---------------------------------------------------------------- installer
[$status, $body] = call($app, 'POST', '/install', [
    'site_name' => 'MOPS Smoke',
    'locale' => 'id',
    'name' => 'Smoke Admin',
    'email' => 'smoke@mops.test',
    'password' => 'rahasia123',
    'password_confirmation' => 'rahasia123',
]);

check('installer redirects to login', $status === 302, 'status ' . $status . ' ' . substr(strip_tags($body), 0, 300));

// --------------------------------------------------------------------- login
[$status, $body] = call($app, 'POST', '/admin/login', [
    'email' => 'smoke@mops.test',
    'password' => 'rahasia123',
]);
check('admin login redirects', $status === 302, 'status ' . $status);

// ------------------------------------------------------------------ content
[$status] = call($app, 'POST', '/admin/profile', [
    'full_name' => 'Teguh Rijanandi',
    'headline' => 'Fullstack Developer',
    'short_bio' => 'Membangun produk web sejak 2016.',
    'bio' => '<p>Halo, saya Teguh. Saya membangun sistem web yang rapi dan terukur.</p>',
    'location' => 'Yogyakarta, Indonesia',
    'email' => 'halo@mops.test',
    'focus_areas' => "Laravel\nPostgreSQL\nDocker",
    'social_label' => ['GitHub'],
    'social_url' => ['https://github.com/example'],
    'social_icon' => ['github'],
    'stat_label' => ['Pengalaman'],
    'stat_value' => ['8 tahun'],
]);
check('profile saved', $status === 302, 'status ' . $status);

foreach ([
    ['/admin/experiences', [
        'title' => 'Senior Backend Engineer', 'company' => 'PT Maju Jaya',
        'employment_type' => 'Full-time', 'location' => 'Yogyakarta',
        'start_date' => '2022-03-01', 'is_current' => '1',
        'highlights' => "Migrasi arsitektur ke modular\nMenurunkan p95 latency 40%",
        'description' => '<p>Memimpin tim platform.</p>', 'sort_order' => '0',
    ]],
    ['/admin/experiences', [
        'title' => 'Backend Engineer', 'company' => 'Startup Nusantara',
        'employment_type' => 'Full-time', 'location' => 'Remote',
        'start_date' => '2019-01-01', 'end_date' => '2022-02-01', 'sort_order' => '1',
    ]],
    ['/admin/skills', ['name' => 'PHP', 'category' => 'Backend', 'level' => '95', 'years' => '8', 'sort_order' => '0']],
    ['/admin/skills', ['name' => 'PostgreSQL', 'category' => 'Database', 'level' => '85', 'years' => '6', 'sort_order' => '1']],
    ['/admin/education', [
        'school' => 'Universitas Gadjah Mada', 'degree' => 'S1', 'field_of_study' => 'Ilmu Komputer',
        'start_date' => '2012-08-01', 'end_date' => '2016-08-01', 'grade' => '3.45',
    ]],
    ['/admin/certifications', [
        'name' => 'AWS Certified Developer', 'issuer' => 'Amazon Web Services',
        'issued_date' => '2024-06-01',
    ]],
    ['/admin/awards', ['title' => 'Best Engineering Team', 'issuer' => 'IDEA', 'year' => '2025']],
] as [$path, $payload]) {
    [$status, $body] = call($app, 'POST', $path, $payload);
    check('create ' . $path, $status === 302, 'status ' . $status . ' ' . substr(preg_replace('/\s+/', ' ', strip_tags($body)), 0, 400));
}

for ($i = 1; $i <= 7; $i++) {
    [$status, $body] = call($app, 'POST', '/admin/projects', [
        'title' => "Proyek {$i}",
        'slug' => '',
        'summary' => "Ringkasan proyek {$i}",
        'description' => "<p>Deskripsi proyek {$i}.</p>",
        'tech_stack' => "PHP\nSQLite\nDocker",
        'role' => 'Backend Engineer',
        'status' => 'published',
        'featured' => $i === 1 ? '1' : '0',
        'started_at' => '2024-01-01',
        'sort_order' => (string) $i,
    ]);

    if ($status !== 302) {
        check("create project {$i}", false, 'status ' . $status . ' ' . substr(preg_replace('/\s+/', ' ', strip_tags($body)), 0, 400));
    }
}

check('projects created', true);

[$status] = call($app, 'POST', '/admin/posts', [
    'title' => 'Membangun Portofolio yang Jujur',
    'slug' => '',
    'excerpt' => 'Catatan tentang menulis portofolio tanpa berlebihan.',
    'content' => '<p>Portofolio yang baik bercerita tentang keputusan, bukan hanya hasil.</p><h2>Mulai dari masalah</h2><p>Ceritakan konteks.</p>',
    'tags' => "karier\nportfolio",
    'status' => 'published',
]);
check('post created', $status === 302, 'status ' . $status);

// Enable every section (except profile/about which are already on).
[$status] = call($app, 'POST', '/admin/settings', [
    'site_name' => 'MOPS Smoke',
    'site_tagline' => 'Fullstack Developer',
    'site_description' => 'Portofolio dan tulisan Teguh.',
    'locale' => 'id',
    'posts_per_page' => '10',
    'sections' => [
        'about' => '1', 'experience' => '1', 'projects' => '1', 'skills' => '1',
        'education' => '1', 'certifications' => '1', 'awards' => '1', 'blog' => '1',
    ],
]);
check('settings saved', $status === 302, 'status ' . $status);

// ---------------------------------------------------------------- public pages
$routes = ['/', '/experience', '/projects', '/skills', '/education', '/certifications', '/awards', '/blog'];

foreach ($routes as $route) {
    [$status, $body] = call($app, 'GET', $route);
    check("GET {$route}", $status === 200, 'status ' . $status . ($status !== 200 ? ' ' . substr(strip_tags($body), 0, 600) : ''));
}

[$status, $body] = call($app, 'GET', '/');
check('homepage shows hero', str_contains($body, 'Teguh Rijanandi'));
check('homepage respects project limit', str_contains($body, 'Proyek 6') && !str_contains($body, 'Proyek 7'));
check('homepage shows more link', str_contains($body, 'Lihat selengkapnya'));
check('homepage shows skills', str_contains($body, 'PostgreSQL'));

[$status, $body] = call($app, 'GET', '/blog');
check('blog index shows post', str_contains($body, 'Membangun Portofolio yang Jujur'));
check('blog index pagination hidden for one page', !str_contains($body, 'Berikutnya'));

[$status, $body] = call($app, 'GET', '/blog/membangun-portofolio-yang-jujur');
check('single post renders', $status === 200 && str_contains($body, 'Mulai dari masalah'), 'status ' . $status);

[$status, $body] = call($app, 'GET', '/projects/proyek-1');
check('single project renders', $status === 200 && str_contains($body, 'Ringkasan proyek 1'), 'status ' . $status);

// --------------------------------------------------------------------- admin
foreach ([
    '/admin', '/admin/profile', '/admin/experiences', '/admin/education', '/admin/skills',
    '/admin/certifications', '/admin/awards', '/admin/projects', '/admin/posts', '/admin/media',
    '/admin/settings', '/admin/themes', '/admin/plugins', '/admin/backup',
] as $route) {
    [$status, $body] = call($app, 'GET', $route);
    check("GET {$route}", $status === 200, 'status ' . $status . ($status !== 200 ? ' ' . substr(strip_tags($body), 0, 600) : ''));
}

// ------------------------------------------------------------- theme switch
[$status] = call($app, 'POST', '/admin/themes/lumica/activate', []);
check('theme switch accepted', $status === 302, 'status ' . $status);

[$status, $body] = call($app, 'GET', '/');
check('lumica renders', $status === 200 && str_contains($body, 'font-serif'), 'status ' . $status);
check('lumica keeps content', str_contains($body, 'Teguh Rijanandi'));

[$status] = call($app, 'POST', '/admin/themes/vantablo/activate', []);
check('switch back to vantablo', $status === 302);

// ------------------------------------------------------------------- plugins
[$status] = call($app, 'POST', '/admin/plugins/mops-analytics-lite/activate', []);
check('plugin activates', $status === 302, 'status ' . $status);

call($app, 'GET', '/');
call($app, 'GET', '/blog');
[$status, $body] = call($app, 'GET', '/admin');
check('plugin widget shows on dashboard', str_contains($body, 'Analytics Lite'), 'status ' . $status);

// ------------------------------------------------------------------- security
[$status] = call($app, 'GET', '/uploads/../../config/config.php');
check('upload traversal blocked', $status === 404, 'status ' . $status);

[$status, $body, $response] = call($app, 'GET', '/');
check('CSP header present', str_contains($response->getHeaderLine('Content-Security-Policy'), "default-src 'self'"));
check('no eval in CSP', !str_contains($response->getHeaderLine('Content-Security-Policy'), 'unsafe-eval'));

[$status] = call($app, 'GET', '/halaman-hilang');
check('404 page', $status === 404, 'status ' . $status);

// ------------------------------------------------------------------- restore
unlink($database);

printf("\n%s\n", $failures === 0 ? 'smoke test: everything passed' : "smoke test: {$failures} failure(s)");

exit($failures === 0 ? 0 : 1);
