<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Tests\TestCase;
use MopsPlugin\CvAts\CvBuilder;
use MopsPlugin\CvAts\PdfWriter;

// Same files the plugin's entry point requires.
require_once MOPS_ROOT . '/plugins/mops-cv-ats/src/PdfWriter.php';
require_once MOPS_ROOT . '/plugins/mops-cv-ats/src/CvBuilder.php';

/**
 * The PDF must stay ATS-readable: uncompressed selectable text, one column,
 * plain "-" bullets, English month names, and no runtime dependency.
 */
final class CvPdfTest extends TestCase
{
    public function test_it_writes_a_structurally_valid_pdf(): void
    {
        $pdf = $this->build($this->portfolio());

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertStringContainsString('/Type /Catalog', $pdf);
        $this->assertStringContainsString('/BaseFont /Helvetica', $pdf);
        $this->assertStringContainsString('/Encoding /WinAnsiEncoding', $pdf);
    }

    public function test_every_xref_offset_points_at_its_object(): void
    {
        $pdf = $this->build($this->portfolio());
        $size = $this->assertXrefIsConsistent($pdf);

        // 1 catalog + 1 pages + 3 fonts + 1 info, then (content + page) per page.
        $this->assertSame(1, ($size - 6) % 2, 'Ukuran xref tidak sesuai pola objek.');
    }

    public function test_startxref_points_at_the_xref_table(): void
    {
        $pdf = $this->build($this->portfolio());

        $this->assertSame(1, preg_match('/startxref\s+(\d+)\s+%%EOF/', $pdf, $matches));
        $this->assertSame('xref', substr($pdf, (int) $matches[1], 4));
    }

    public function test_declared_stream_lengths_match_the_real_streams(): void
    {
        $pdf = $this->build($this->portfolio());

        $this->assertSame(1, preg_match_all(
            '/<< \/Length (\d+) >>\nstream\n/',
            $pdf,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        ));

        // One stream per page object.
        $this->assertSame(substr_count($pdf, '/Type /Page '), count($matches));

        foreach ($matches as [$prefix, $value]) {
            $length = (int) $value[0];
            $streamStart = (int) $prefix[1] + strlen((string) $prefix[0]);

            $this->assertGreaterThan(0, $length);
            $this->assertSame("\nendstream", substr($pdf, $streamStart + $length, 10));
        }
    }

    public function test_the_text_is_uncompressed_so_parsers_can_read_it(): void
    {
        $pdf = $this->build($this->portfolio());

        // No filters: ATS parsers (and grep) can read the text straight away.
        $this->assertStringNotContainsString('/Filter', $pdf);
        $this->assertStringContainsString('(Teguh Rijanandi) Tj', $pdf);
        $this->assertStringContainsString('(EXPERIENCE) Tj', $pdf);
        $this->assertStringContainsString('(EDUCATION) Tj', $pdf);
        $this->assertStringContainsString('(SKILLS) Tj', $pdf);
        $this->assertStringContainsString('(CERTIFICATIONS) Tj', $pdf);
        $this->assertStringContainsString('(SELECTED PROJECTS) Tj', $pdf);
    }

    public function test_it_uses_english_month_names_and_plain_ranges(): void
    {
        $pdf = $this->build($this->portfolio());

        $this->assertStringContainsString('(Mar 2021 - Present | Full-time) Tj', $pdf);
        $this->assertStringContainsString('(Jun 2018 - Feb 2021 | Full-time) Tj', $pdf);
        $this->assertStringNotContainsString('Sekarang', $pdf);
    }

    public function test_highlights_become_hyphen_bullets(): void
    {
        $pdf = $this->build($this->portfolio());

        $this->assertStringContainsString('(- Memimpin migrasi', $pdf);
    }

    public function test_parentheses_and_backslashes_are_escaped(): void
    {
        $payload = $this->portfolio();
        $payload['experiences'][0]['company'] = 'ACME (Pvt) Ltd \\ Co';

        $pdf = $this->build($payload);

        $this->assertStringContainsString('\\(Pvt\\)', $pdf);
        $this->assertStringContainsString('Ltd \\\\ Co', $pdf);
    }

    public function test_rich_text_is_flattened_to_plain_text(): void
    {
        $pdf = $this->build($this->portfolio());

        $this->assertStringNotContainsString('<p>', $pdf);
        $this->assertStringNotContainsString('&amp;', $pdf);
        $this->assertStringContainsString(
            '(Fullstack developer dengan 7 tahun pengalaman & fokus pada Laravel.) Tj',
            $pdf,
        );
    }

    public function test_long_content_spills_onto_new_pages(): void
    {
        $payload = $this->portfolio();
        $payload['experiences'] = [];

        for ($index = 0; $index < 40; $index++) {
            $payload['experiences'][] = [
                'title' => 'Engineer ' . $index,
                'company' => 'Perusahaan ' . $index,
                'start_date' => '2015-01-01',
                'end_date' => '2016-01-01',
                'is_current' => 0,
                'highlights' => ['Baris pertama.', 'Baris kedua.'],
                'description' => '',
            ];
        }

        $pdf = $this->build($payload);
        $pages = substr_count($pdf, '/Type /Page ');

        $this->assertXrefIsConsistent($pdf);
        $this->assertGreaterThan(2, $pages, 'Konten panjang harus memakai lebih dari dua halaman.');
        $this->assertStringContainsString("/Count {$pages}", $pdf);
        // Nothing is dropped: the last entry still lands in the document.
        $this->assertStringContainsString('(Engineer 39 - Perusahaan 39) ', $pdf);
    }

    public function test_an_empty_portfolio_still_produces_a_readable_document(): void
    {
        $pdf = (new CvBuilder([]))->build();

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertStringContainsString('/Count 1', $pdf);
        $this->assertStringContainsString('(Portfolio) Tj', $pdf);
    }

    public function test_filename_is_a_slug(): void
    {
        $this->assertSame('CV-teguh-rijanandi.pdf', (new CvBuilder($this->portfolio()))->filename());
        $this->assertSame('CV-portfolio.pdf', (new CvBuilder(['profile' => []]))->filename());
    }

    public function test_columns_stay_inside_the_page(): void
    {
        $pdf = $this->build($this->portfolio());

        $this->assertSame(495.28, round(PdfWriter::PAGE_WIDTH - 100.0, 2));

        preg_match_all('/([\d.]+) ([\d.]+) Td/', $pdf, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches);

        foreach ($matches as [, $x, $y]) {
            $this->assertGreaterThanOrEqual(50.0, (float) $x);
            $this->assertLessThanOrEqual(545.28, (float) $x);
            $this->assertGreaterThanOrEqual(50.0, (float) $y);
            $this->assertLessThanOrEqual(PdfWriter::PAGE_HEIGHT - 50.0, (float) $y);
        }
    }

    // ------------------------------------------------------------- internals

    /**
     * @param array<string, mixed> $payload
     */
    private function build(array $payload): string
    {
        return (new CvBuilder($payload))->build();
    }

    /**
     * Walk the xref table and assert every offset lands on its own object.
     */
    private function assertXrefIsConsistent(string $pdf): int
    {
        $this->assertSame(1, preg_match('/xref\n0 (\d+)\n/', $pdf, $matches));
        $size = (int) $matches[1];

        $header = "xref\n0 {$size}\n";
        $entriesStart = (int) strpos($pdf, $header) + strlen($header);
        $this->assertStringContainsString("/Size {$size}", $pdf);

        for ($id = 1; $id < $size; $id++) {
            $entry = substr($pdf, $entriesStart + ($id * 20), 20);
            $this->assertMatchesRegularExpression('/^\d{10} \d{5} n $/', $entry, "Entri xref objek {$id} rusak.");
            $offset = (int) substr($entry, 0, 10);
            $marker = "{$id} 0 obj";
            $this->assertSame(
                $marker,
                substr($pdf, $offset, strlen($marker)),
                "Offset xref objek {$id} tidak menunjuk ke objeknya.",
            );
        }

        return $size;
    }

    /**
     * @return array<string, mixed>
     */
    private function portfolio(): array
    {
        return [
            'profile' => [
                'full_name' => 'Teguh Rijanandi',
                'headline' => 'Fullstack Developer',
                'location' => 'Yogyakarta, Indonesia',
                'email' => 'teguh@example.com',
                'phone' => '+62 812 3456 7890',
                'website' => 'https://teguh.dev',
                'availability' => '',
                'bio' => '<p>Fullstack developer dengan 7 tahun pengalaman &amp; fokus pada Laravel.</p>',
                'social_links' => [
                    ['label' => 'GitHub', 'url' => 'https://github.com/teguh'],
                ],
            ],
            'experiences' => [
                [
                    'title' => 'Senior Backend Engineer',
                    'company' => 'Nusantara Digital',
                    'location' => 'Jakarta',
                    'employment_type' => 'Full-time',
                    'start_date' => '2021-03-01',
                    'end_date' => null,
                    'is_current' => 1,
                    'highlights' => ['Memimpin migrasi monolith tanpa downtime.'],
                    'description' => '<p>Platform pembayaran internal.</p>',
                ],
                [
                    'title' => 'Backend Developer',
                    'company' => 'TokoKita',
                    'location' => 'Bandung',
                    'employment_type' => 'Full-time',
                    'start_date' => '2018-06-01',
                    'end_date' => '2021-02-28',
                    'is_current' => 0,
                    'highlights' => [],
                    'description' => '',
                ],
            ],
            'educations' => [
                [
                    'school' => 'Universitas Gadjah Mada',
                    'degree' => 'S1',
                    'field_of_study' => 'Ilmu Komputer',
                    'location' => 'Yogyakarta',
                    'start_date' => '2014-08-01',
                    'end_date' => '2018-05-31',
                    'is_current' => 0,
                    'grade' => '3.62',
                    'description' => '',
                ],
            ],
            'skills' => [
                ['name' => 'PHP', 'category' => 'Backend'],
                ['name' => 'Laravel', 'category' => 'Backend'],
                ['name' => 'PostgreSQL', 'category' => 'Database'],
                ['name' => 'Docker', 'category' => ''],
            ],
            'certifications' => [
                [
                    'name' => 'AWS Certified Solutions Architect',
                    'issuer' => 'Amazon Web Services',
                    'issued_date' => '2022-05-01',
                    'expiry_date' => '2025-05-01',
                    'credential_id' => 'AWS-123456',
                    'credential_url' => '',
                ],
            ],
            'awards' => [
                ['title' => 'Best Engineering Team', 'issuer' => 'Nusantara Digital', 'year' => 2023],
            ],
            'projects' => [
                [
                    'title' => 'MOPS',
                    'role' => 'Lead Developer',
                    'client' => 'Open Source',
                    'summary_text' => 'Portfolio CMS self-hosted.',
                    'tech_stack' => ['PHP', 'SQLite'],
                    'project_url' => 'https://mops.example.com',
                    'repo_url' => '',
                ],
            ],
        ];
    }
}
