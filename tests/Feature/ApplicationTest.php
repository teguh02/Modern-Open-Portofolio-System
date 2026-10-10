<?php

declare(strict_types=1);

namespace Mops\Tests\Feature;

use Mops\Core\Config;
use Mops\Core\Database;
use Mops\Core\Paths;
use Mops\Core\Settings;
use Mops\Tests\ApplicationTestCase;

/**
 * Drives the real application through HTTP requests — installer, auth, admin
 * CRUD, homepage sections, blog, theme switching, plugin activation and path
 * traversal protection.
 */
final class ApplicationTest extends ApplicationTestCase
{
    public function test_fresh_install_redirects_to_the_installer(): void
    {
        $response = $this->get('/');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/install', $response->getHeaderLine('Location'));

        $this->assertSame(200, $this->get('/install')->getStatusCode());
    }

    public function test_installer_creates_the_admin_account_and_site_settings(): void
    {
        $response = $this->post('/install', [
            'site_name' => 'Portofolio Teguh',
            'locale' => 'id',
            'name' => 'Teguh',
            'email' => 'admin@mops.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/login', $response->getHeaderLine('Location'));

        $database = new Database(['driver' => 'sqlite', 'database' => $this->database]);
        $this->assertTrue($database->tableExists('users'));
        $this->assertSame(1, $database->table('users')->count());
        $this->assertSame('admin@mops.test', $database->table('users')->first()['email']);

        // No seeding: the registry defaults decide which sections show up.
        $this->assertSame(0, $database->table('settings')->where('key', 'section_enabled_experience')->count());
        $this->assertSame('vantablo', $database->table('settings')->where('key', 'active_theme')->first()['value']);

        // The installer cannot be replayed.
        $this->assertSame('/admin', $this->get('/install')->getHeaderLine('Location'));
    }

    public function test_installer_rejects_short_passwords(): void
    {
        $response = $this->post('/install', [
            'site_name' => 'MOPS',
            'name' => 'Admin',
            'email' => 'admin@mops.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_admin_area_requires_authentication(): void
    {
        $this->install();

        $response = $this->get('/admin');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith('/admin/login', $response->getHeaderLine('Location'));
    }

    public function test_admin_can_log_in_and_reach_every_page(): void
    {
        $this->install();
        $this->login();

        $this->assertSame(200, $this->get('/admin')->getStatusCode());

        foreach ([
            '/admin/profile',
            '/admin/experiences',
            '/admin/education',
            '/admin/skills',
            '/admin/certifications',
            '/admin/awards',
            '/admin/projects',
            '/admin/posts',
            '/admin/media',
            '/admin/settings',
            '/admin/themes',
            '/admin/plugins',
            '/admin/backup',
            '/admin/projects/create',
            '/admin/posts/create',
        ] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response->getStatusCode(), "GET {$path} failed: " . $this->body($response));
        }

        $this->assertStringContainsString('MOPS Admin', $this->body($this->get('/admin')));
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $this->install();

        $response = $this->post('/admin/login', [
            'email' => 'admin@mops.test',
            'password' => 'wrong-password',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('salah', $this->body($response));
    }

    public function test_admin_creates_an_experience_and_the_homepage_section_stays_hidden_until_enabled(): void
    {
        $this->install();
        $this->login();

        $response = $this->post('/admin/experiences', [
            'title' => 'Senior Backend Engineer',
            'company' => 'PT Maju Jaya',
            'employment_type' => 'Full-time',
            'location' => 'Yogyakarta',
            'start_date' => '2024-01-01',
            'end_date' => '',
            'is_current' => '1',
            'highlights' => "Migrasi arsitektur ke modular\nMenurunkan p95 latency 40%",
            'description' => '<p>Memimpin tim platform.</p>',
            'sort_order' => '0',
        ]);

        $this->assertSame(302, $response->getStatusCode());

        $database = new Database(['driver' => 'sqlite', 'database' => $this->database]);
        $row = $database->table('experiences')->first();
        $this->assertSame('Senior Backend Engineer', $row['title']);
        $this->assertSame(1, (int) $row['is_current']);
        $this->assertNull($row['end_date'], 'An ongoing role must not carry an end date.');
        $this->assertSame(['Migrasi arsitektur ke modular', 'Menurunkan p95 latency 40%'], json_decode((string) $row['highlights'], true));

        // Section is OFF by default => hidden even though data exists.
        $homepage = $this->body($this->get('/'));
        $this->assertStringNotContainsString('Senior Backend Engineer', $homepage);

        // Toggle it on through Settings, then it appears.
        $this->setting('section_enabled_experience', '1');

        $homepage = $this->body($this->get('/'));
        $this->assertStringContainsString('Riwayat Kerja', $homepage);
        $this->assertStringContainsString('Senior Backend Engineer', $homepage);

        // Detail page renders the full list.
        $detail = $this->get('/experience');
        $this->assertSame(200, $detail->getStatusCode());
        $this->assertStringContainsString('Senior Backend Engineer', $this->body($detail));

        // A section with no data stays hidden (auto-hide).
        $this->setting('section_enabled_awards', '1');
        $this->assertStringNotContainsString('Penghargaan', $this->body($this->get('/')));
    }

    public function test_resource_validation_errors_are_reported_back_to_the_form(): void
    {
        $this->install();
        $this->login();

        $response = $this->post('/admin/experiences', [
            'title' => '',
            'company' => '',
            'start_date' => '',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('wajib diisi', $this->body($response));
    }

    public function test_admin_can_publish_a_post_and_read_it_on_the_blog(): void
    {
        $this->install();
        $this->login();

        $response = $this->post('/admin/posts', [
            'title' => 'Membangun Portofolio yang Jujur',
            'slug' => '',
            'excerpt' => 'Catatan tentang menulis portofolio.',
            'content' => '<p>Isi tulisan.</p><script>alert(1)</script>',
            'tags' => "karier\nportfolio",
            'status' => 'published',
            'published_at' => '',
        ]);

        $this->assertSame(302, $response->getStatusCode());

        $database = new Database(['driver' => 'sqlite', 'database' => $this->database]);
        $post = $database->table('posts')->first();
        $this->assertSame('published', $post['status']);
        $this->assertSame('membangun-portofolio-yang-jujur', $post['slug']);
        $this->assertNotNull($post['published_at'], 'Publishing must stamp published_at.');
        $this->assertStringNotContainsString('<script>', (string) $post['content'], 'Editor output must be sanitised on save.');
        $this->assertSame(['karier', 'portfolio'], json_decode((string) $post['tags'], true));

        // Blog index and single post are public.
        $index = $this->get('/blog');
        $this->assertSame(200, $index->getStatusCode());
        $this->assertStringContainsString('Membangun Portofolio yang Jujur', $this->body($index));

        $show = $this->get('/blog/membangun-portofolio-yang-jujur');
        $this->assertSame(200, $show->getStatusCode());
        $this->assertStringContainsString('Isi tulisan.', $this->body($show));

        // Blog section shows up on the homepage once toggled on.
        $this->setting('section_enabled_blog', '1');
        $this->assertStringContainsString('Blog Terbaru', $this->body($this->get('/')));
    }

    public function test_draft_posts_are_not_public(): void
    {
        $this->install();
        $this->login();

        $this->post('/admin/posts', [
            'title' => 'Draft Rahasia',
            'slug' => 'draft-rahasia',
            'content' => '<p>Belum siap.</p>',
            'status' => 'draft',
        ]);

        $this->assertSame(404, $this->get('/blog/draft-rahasia')->getStatusCode());
        $this->assertStringNotContainsString('Draft Rahasia', $this->body($this->get('/blog')));
    }

    public function test_homepage_limits_items_per_the_section_registry(): void
    {
        $this->install();
        $this->login();

        for ($i = 1; $i <= 8; $i++) {
            $this->post('/admin/projects', [
                'title' => "Proyek {$i}",
                'slug' => "proyek-{$i}",
                'summary' => "Ringkasan {$i}",
                'status' => 'published',
                'featured' => $i === 1 ? '1' : '0',
                'tech_stack' => "PHP\nSQLite",
            ]);
        }

        $database = new Database(['driver' => 'sqlite', 'database' => $this->database]);
        $this->setting('section_enabled_projects', '1');

        $homepage = $this->body($this->get('/'));

        // Registry limit for projects is 6.
        $this->assertStringContainsString('Proyek 6', $homepage);
        $this->assertStringNotContainsString('Proyek 7', $homepage);
        // Total (8) > rendered (6) and the section owns a route => "more" link.
        $this->assertStringContainsString('Lihat selengkapnya', $homepage);

        $detail = $this->get('/projects');
        $this->assertSame(200, $detail->getStatusCode());
        $this->assertStringContainsString('Proyek 8', $this->body($detail));

        // Slug-driven project page.
        $project = $this->get('/projects/proyek-1');
        $this->assertSame(200, $project->getStatusCode());
        $this->assertStringContainsString('Ringkasan 1', $this->body($project));
    }

    public function test_switching_theme_changes_rendering_without_losing_data(): void
    {
        $this->install();
        $this->login();

        $this->post('/admin/experiences', [
            'title' => 'Engineer',
            'company' => 'MOPS',
            'start_date' => '2023-01-01',
            'is_current' => '1',
        ]);

        $database = new Database(['driver' => 'sqlite', 'database' => $this->database]);
        $this->setting('section_enabled_experience', '1');

        $vantablo = $this->body($this->get('/'));
        $this->assertStringContainsString('font-mono', $vantablo);

        $this->assertSame(302, $this->post('/admin/themes/lumica/activate', [])->getStatusCode());
        $this->assertSame('lumica', (new Settings($database, Config::fromFile(Paths::make('config/config.php'))))
            ->get('active_theme'));
        $this->assertSame('lumica', $this->app->getContainer()->get(Settings::class)->get('active_theme'));

        $lumica = $this->body($this->get('/'));
        $this->assertStringContainsString('font-serif', $lumica);
        // Same content, different theme — no data loss.
        $this->assertStringContainsString('Engineer', $lumica);
    }

    public function test_plugin_can_be_activated_and_adds_its_dashboard_widget(): void
    {
        $this->install();
        $this->login();

        $this->assertStringNotContainsString('Analytics Lite', $this->body($this->get('/admin')));

        $this->assertSame(302, $this->post('/admin/plugins/mops-analytics-lite/activate', [])->getStatusCode());

        // Visiting the public site records a view in the plugin's own table.
        $this->assertSame(200, $this->get('/')->getStatusCode());
        $this->get('/');

        $database = new Database(['driver' => 'sqlite', 'database' => $this->database]);
        $this->assertTrue($database->tableExists('plugin_analytics_views'));
        $this->assertGreaterThan(0, $database->table('plugin_analytics_views')->count());

        $this->assertStringContainsString('Analytics Lite', $this->body($this->get('/admin')));
    }

    public function test_csrf_token_is_required_for_state_changing_requests(): void
    {
        $this->install();
        $this->login();

        $response = $this->handle('POST', '/admin/experiences', [
            'title' => 'Tanpa Token',
            'company' => 'X',
            'start_date' => '2024-01-01',
        ], false);

        // Without a valid token the request is bounced back, not processed.
        $this->assertSame(303, $response->getStatusCode());

        $database = new Database(['driver' => 'sqlite', 'database' => $this->database]);
        $this->assertSame(0, $database->table('experiences')->count());
    }

    public function test_security_headers_are_sent(): void
    {
        $this->install();

        $response = $this->get('/');
        $csp = $response->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }

    public function test_unknown_routes_render_the_theme_404(): void
    {
        $this->install();

        $response = $this->get('/halaman-tidak-ada');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('tidak ditemukan', $this->body($response));
    }

    public function test_upload_route_refuses_path_traversal(): void
    {
        $this->install();

        $this->assertSame(404, $this->get('/uploads/../../config/config.php')->getStatusCode());
        $this->assertSame(404, $this->get('/uploads/..%2F..%2Fconfig%2Fconfig.php')->getStatusCode());
        $this->assertSame(404, $this->get('/uploads/tidak-ada.jpg')->getStatusCode());
    }

    public function test_plugin_assets_are_streamed_and_confined_to_their_folder(): void
    {
        $this->install();

        $folder = Paths::make('plugins/mops-cv-ats/assets');
        $created = !is_dir($folder) && mkdir($folder, 0775, true);
        file_put_contents($folder . '/probe.css', ".probe { color: red; }");

        try {
            $this->assertSame('/plugin/mops-cv-ats/assets/probe.css', plugin_asset('mops-cv-ats', 'probe.css'));

            $asset = $this->get('/plugin/mops-cv-ats/assets/probe.css');
            $this->assertSame(200, $asset->getStatusCode());
            $this->assertSame('text/css', $asset->getHeaderLine('Content-Type'));
            $this->assertStringContainsString('.probe', $this->body($asset));

            // Nothing else on disk is reachable through the plugin route.
            $this->assertSame(404, $this->get('/plugin/mops-cv-ats/assets/../plugin.php')->getStatusCode());
            $this->assertSame(404, $this->get('/plugin/tidak-ada/assets/probe.css')->getStatusCode());
            $this->assertSame(404, $this->get('/plugin/mops-analytics-lite/assets/probe.css')->getStatusCode());
        } finally {
            @unlink($folder . '/probe.css');

            if ($created) {
                @rmdir($folder);
            }
        }
    }

    public function test_admin_can_update_profile_and_section_settings(): void
    {
        $this->install();
        $this->login();

        $response = $this->post('/admin/profile', [
            'full_name' => 'Teguh Rijanandi',
            'headline' => 'Fullstack Developer',
            'short_bio' => 'Membangun produk web sejak 2016.',
            'bio' => '<p>Tentang saya.</p>',
            'location' => 'Yogyakarta, Indonesia',
            'email' => 'halo@mops.test',
            'website' => 'https://mops.test',
            'availability' => 'Terbuka untuk proyek lepas',
            'focus_areas' => "Laravel\nPostgreSQL",
            'social_label' => ['GitHub'],
            'social_url' => ['https://github.com/example'],
            'social_icon' => ['github'],
            'stat_label' => ['Pengalaman'],
            'stat_value' => ['8 tahun'],
        ]);

        $this->assertSame(302, $response->getStatusCode());

        $homepage = $this->body($this->get('/'));
        $this->assertStringContainsString('Teguh Rijanandi', $homepage);
        $this->assertStringContainsString('Tentang saya.', $homepage);
        $this->assertStringContainsString('8 tahun', $homepage);

        // Section toggles come from the Settings page and only touch known keys.
        $response = $this->post('/admin/settings', [
            'site_name' => 'Portofolio Teguh',
            'site_tagline' => 'Fullstack Developer',
            'site_description' => 'Portofolio dan tulisan.',
            'locale' => 'id',
            'posts_per_page' => '10',
            'sections' => ['about' => '1', 'skills' => '1'],
        ]);

        $this->assertSame(302, $response->getStatusCode());

        $database = new Database(['driver' => 'sqlite', 'database' => $this->database]);
        $settings = new Settings($database, Config::fromFile(Paths::make('config/config.php')));

        $this->assertSame('Portofolio Teguh', $settings->get('site_name'));
        $this->assertSame('1', $settings->get('section_enabled_skills'));
        $this->assertSame('0', $settings->get('section_enabled_blog'), 'Unchecked toggles must be stored as off.');
        $this->assertNull($settings->get('section_enabled_unknown'));
    }

    public function test_backup_can_be_created_and_listed(): void
    {
        $this->install();
        $this->login();

        $response = $this->post('/admin/backup', ['scope' => 'database']);
        $this->assertSame(302, $response->getStatusCode());

        $page = $this->body($this->get('/admin/backup'));
        $this->assertStringContainsString('mops-backup-', $page);
    }

    public function test_cli_migrator_and_status(): void
    {
        $config = Config::fromFile(Paths::make('config/config.php'));
        $config->set('database.database', $this->database);

        $database = new Database(['driver' => 'sqlite', 'database' => $this->database]);
        $migrator = new \Mops\Core\Migrator($database, $config->path('migrations'));

        $ran = $migrator->run();

        $this->assertCount(3, $ran);
        $this->assertSame([], $migrator->pending());
        $this->assertTrue($database->tableExists('projects'));
        $this->assertTrue($database->tableExists('posts'));
    }

    public function test_media_library_route_returns_json_for_the_picker(): void
    {
        $this->install();
        $this->login();

        $response = $this->get('/admin/media/library.json', ['Accept' => 'application/json']);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($this->body($response), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('items', $payload);
    }
}
