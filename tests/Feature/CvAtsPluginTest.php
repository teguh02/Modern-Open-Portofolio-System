<?php

declare(strict_types=1);

namespace Mops\Tests\Feature;

use Mops\Core\HookZones;
use Mops\Tests\ApplicationTestCase;

/**
 * End-to-end proof of the WordPress-style injection model: the plugin ships as
 * a plain folder, injects its button into an existing zone, and registers its
 * own route — the core and both bundled themes stay untouched.
 */
final class CvAtsPluginTest extends ApplicationTestCase
{
    public function test_the_button_is_injected_into_the_homepage_zone_once_active(): void
    {
        $this->install();
        $this->login();
        $this->seedPortfolio();

        $this->assertStringNotContainsString('Download CV (ATS PDF)', $this->body($this->get('/')));

        $this->activatePlugin('mops-cv-ats');

        $home = $this->body($this->get('/'));

        $this->assertStringContainsString('data-plugin="mops-cv-ats"', $home);
        $this->assertStringContainsString('data-zone="home_after_sections"', $home);
        $this->assertStringContainsString('href="/cv.pdf"', $home);

        // The zone sits at the bottom of the homepage, above the footer bar.
        $this->assertLessThan(
            (int) strpos($home, 'Powered by MOPS'),
            (int) strpos($home, 'data-plugin="mops-cv-ats"'),
        );
    }

    public function test_the_button_never_leaks_into_other_pages(): void
    {
        $this->install();
        $this->login();
        $this->seedPortfolio();
        $this->activatePlugin('mops-cv-ats');

        $this->assertStringNotContainsString('Download CV (ATS PDF)', $this->body($this->get('/experience')));
        $this->assertStringNotContainsString('Download CV (ATS PDF)', $this->body($this->get('/blog')));
    }

    public function test_the_footer_note_stays_on_the_homepage_only(): void
    {
        $this->install();
        $this->login();
        $this->activatePlugin('mops-cv-ats');

        $this->assertStringContainsString('CV PDF ramah ATS', $this->body($this->get('/')));

        $other = $this->body($this->get('/experience'));
        $this->assertStringNotContainsString('CV PDF ramah ATS', $other);
        $this->assertStringContainsString('Powered by MOPS', $other);
    }

    public function test_the_cv_route_serves_a_downloadable_ats_pdf(): void
    {
        $this->install();
        $this->login();
        $this->seedPortfolio();
        $this->activatePlugin('mops-cv-ats');
        $this->reboot();

        $response = $this->get('/cv.pdf');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString(
            'attachment; filename="CV-teguh-rijanandi.pdf"',
            $response->getHeaderLine('Content-Disposition'),
        );

        $pdf = $this->body($response);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertSame(strlen($pdf), (int) $response->getHeaderLine('Content-Length'));
        $this->assertStringNotContainsString('/Filter', $pdf, 'Teks PDF harus tidak terkompresi.');

        // Real data from the database, formatted for ATS readers.
        $this->assertStringContainsString('(Teguh Rijanandi) Tj', $pdf);
        $this->assertStringContainsString('(EXPERIENCE) Tj', $pdf);
        $this->assertStringContainsString('(SKILLS) Tj', $pdf);
        $this->assertStringContainsString('(Senior Backend Engineer - Nusantara Digital, Jakarta) Tj', $pdf);
        $this->assertStringContainsString('(Mar 2024 - Present | Full-time) Tj', $pdf);
        $this->assertStringContainsString('(- Memimpin migrasi monolith.', $pdf);
        $this->assertStringContainsString('(Backend: PHP, Laravel) Tj', $pdf);
    }

    public function test_deactivating_the_plugin_removes_the_button_and_the_route(): void
    {
        $this->install();
        $this->login();
        $this->seedPortfolio();
        $this->activatePlugin('mops-cv-ats');
        $this->reboot();

        $this->assertSame(200, $this->get('/cv.pdf')->getStatusCode());

        $this->post('/admin/plugins/mops-cv-ats/deactivate', []);
        $this->reboot();

        $this->assertSame(404, $this->get('/cv.pdf')->getStatusCode());
        $this->assertStringNotContainsString('Download CV (ATS PDF)', $this->body($this->get('/')));
    }

    public function test_the_admin_plugins_page_lists_every_injection_zone(): void
    {
        $this->install();
        $this->login();
        $this->activatePlugin('mops-cv-ats');

        $page = $this->body($this->get('/admin/plugins'));

        $this->assertStringContainsString('Injection Zones', $page);

        foreach (HookZones::ids() as $zone) {
            $this->assertStringContainsString($zone, $page, "Zona '{$zone}' tidak tampil di halaman plugin.");
        }

        // The two zones this plugin fills are marked as used.
        $this->assertStringContainsString('dipakai', $page);
    }

    public function test_the_button_can_be_disabled_without_touching_the_route(): void
    {
        $this->install();
        $this->login();
        $this->seedPortfolio();
        $this->activatePlugin('mops-cv-ats');
        $this->reboot();

        add_filter('cv_ats_button_enabled', static fn (): bool => false);
        // The plugin registered its listeners when the kernel booted; the filter
        // it calls at render time is the one added above.
        $this->assertStringNotContainsString('Download CV (ATS PDF)', $this->body($this->get('/')));
        $this->assertSame(200, $this->get('/cv.pdf')->getStatusCode());
    }

    // ------------------------------------------------------------- internals

    private function seedPortfolio(): void
    {
        $this->post('/admin/profile', [
            'full_name' => 'Teguh Rijanandi',
            'headline' => 'Fullstack Developer',
            'location' => 'Yogyakarta, Indonesia',
            'email' => 'teguh@example.com',
            'phone' => '+62 812 3456 7890',
            'website' => 'https://teguh.dev',
            'short_bio' => 'Fullstack developer.',
            'bio' => '<p>Fullstack developer dengan 7 tahun pengalaman.</p>',
        ]);

        $this->post('/admin/experiences', [
            'title' => 'Senior Backend Engineer',
            'company' => 'Nusantara Digital',
            'location' => 'Jakarta',
            'employment_type' => 'Full-time',
            'start_date' => '2024-03-01',
            'is_current' => '1',
            'highlights' => "Memimpin migrasi monolith.\nMenurunkan latency API.",
        ]);

        $this->post('/admin/skills', ['name' => 'PHP', 'category' => 'Backend']);
        $this->post('/admin/skills', ['name' => 'Laravel', 'category' => 'Backend']);
    }
}
