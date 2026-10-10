<?php

declare(strict_types=1);

namespace MopsPlugin\CvAts;

use Mops\Core\Str;

/**
 * Turns the core portfolio payload into a CV that survives ATS parsing:
 *
 *   - one column, no tables, no columns, no images, no icons;
 *   - selectable text with standard section headings (English);
 *   - plain "-" bullets and "Start - End" date ranges;
 *   - contact details at the top as plain text;
 *   - sections only appear when they have data.
 */
final class CvBuilder
{
    /**
     * @param array<string, mixed> $portfolio payload from Mops\Core\Portfolio
     */
    public function __construct(private array $portfolio)
    {
    }

    public function build(): string
    {
        $profile = (array) ($this->portfolio['profile'] ?? []);
        $writer = new PdfWriter(
            margin: 50.0,
            title: 'CV ' . trim((string) ($profile['full_name'] ?? '')),
            author: trim((string) ($profile['full_name'] ?? '')),
            locale: 'en',
        );

        $this->header($writer, $profile);
        $this->summary($writer, $profile);
        $this->experience($writer, (array) ($this->portfolio['experiences'] ?? []));
        $this->education($writer, (array) ($this->portfolio['educations'] ?? []));
        $this->skills($writer, (array) ($this->portfolio['skills'] ?? []));
        $this->certifications($writer, (array) ($this->portfolio['certifications'] ?? []));
        $this->awards($writer, (array) ($this->portfolio['awards'] ?? []));
        $this->projects($writer, (array) ($this->portfolio['projects'] ?? []));

        return $writer->output();
    }

    public function filename(): string
    {
        $profile = (array) ($this->portfolio['profile'] ?? []);
        $name = trim((string) ($profile['full_name'] ?? ''));

        return 'CV-' . Str::slug($name !== '' ? $name : 'portfolio', 'portfolio') . '.pdf';
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function header(PdfWriter $writer, array $profile): void
    {
        $name = trim((string) ($profile['full_name'] ?? ''));
        $writer->line($name !== '' ? $name : 'Portfolio', 18.0, 'bold', 1.35);

        $headline = trim((string) ($profile['headline'] ?? ''));
        if ($headline !== '') {
            $writer->line($headline, 11.0, 'regular', 1.4);
        }

        $contact = array_values(array_filter([
            trim((string) ($profile['location'] ?? '')),
            trim((string) ($profile['email'] ?? '')),
            trim((string) ($profile['phone'] ?? '')),
            trim((string) ($profile['website'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));

        if ($contact !== []) {
            $writer->paragraph(implode(' | ', $contact), 9.5, 'regular', 1.4, 2.0);
        }

        $links = [];
        foreach ((array) ($profile['social_links'] ?? []) as $link) {
            $link = (array) $link;
            $url = trim((string) ($link['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $label = trim((string) ($link['label'] ?? ''));
            $links[] = $label !== '' ? $label . ': ' . $url : $url;
        }

        if ($links !== []) {
            $writer->paragraph(implode(' | ', $links), 9.5, 'regular', 1.4, 2.0);
        }

        $availability = trim((string) ($profile['availability'] ?? ''));
        if ($availability !== '') {
            $writer->paragraph($availability, 9.5, 'italic', 1.4, 2.0);
        }
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function summary(PdfWriter $writer, array $profile): void
    {
        $summary = $this->plain((string) ($profile['bio'] ?? ''));

        if ($summary === '') {
            $summary = $this->plain((string) ($profile['short_bio'] ?? ''));
        }

        if ($summary === '') {
            return;
        }

        $writer->heading('Professional Summary');
        $writer->paragraph($summary, 10.5, 'regular', 1.45, 0.0);
    }

    /**
     * @param array<int, array<string, mixed>> $experiences
     */
    private function experience(PdfWriter $writer, array $experiences): void
    {
        if ($experiences === []) {
            return;
        }

        $writer->heading('Experience');

        foreach ($experiences as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $company = trim((string) ($item['company'] ?? ''));
            $location = trim((string) ($item['location'] ?? ''));

            $headline = $title;
            if ($company !== '') {
                $headline .= ($headline !== '' ? ' - ' : '') . $company;
            }
            if ($location !== '') {
                $headline .= ', ' . $location;
            }

            $writer->paragraph($headline, 11.0, 'bold', 1.4, 1.0);

            $meta = array_values(array_filter([
                $this->range($item['start_date'] ?? null, $item['is_current'] ?? false, $item['end_date'] ?? null),
                trim((string) ($item['employment_type'] ?? '')),
            ], static fn (string $value): bool => $value !== ''));

            if ($meta !== []) {
                $writer->paragraph(implode(' | ', $meta), 9.5, 'italic', 1.4, 3.0);
            }

            foreach ((array) ($item['highlights'] ?? []) as $highlight) {
                $writer->bullet((string) $highlight);
            }

            $description = $this->plain((string) ($item['description'] ?? ''));
            if ($description !== '') {
                $writer->paragraph($description, 10.5, 'regular', 1.45, 2.0);
            }

            $writer->spacer(7.0);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $educations
     */
    private function education(PdfWriter $writer, array $educations): void
    {
        if ($educations === []) {
            return;
        }

        $writer->heading('Education');

        foreach ($educations as $item) {
            $degree = array_values(array_filter([
                trim((string) ($item['degree'] ?? '')),
                trim((string) ($item['field_of_study'] ?? '')),
            ], static fn (string $value): bool => $value !== ''));

            $school = trim((string) ($item['school'] ?? ''));
            $headline = implode(', ', $degree);
            $headline .= ($headline !== '' && $school !== '' ? ' - ' : '') . $school;

            $writer->paragraph($headline, 11.0, 'bold', 1.4, 1.0);

            $meta = array_values(array_filter([
                $this->range($item['start_date'] ?? null, $item['is_current'] ?? false, $item['end_date'] ?? null),
                trim((string) ($item['location'] ?? '')),
                trim((string) ($item['grade'] ?? '')) !== '' ? 'Grade: ' . trim((string) $item['grade']) : '',
            ], static fn (string $value): bool => $value !== ''));

            if ($meta !== []) {
                $writer->paragraph(implode(' | ', $meta), 9.5, 'regular', 1.4, 2.0);
            }

            $description = $this->plain((string) ($item['description'] ?? ''));
            if ($description !== '') {
                $writer->paragraph($description, 10.5, 'regular', 1.45, 2.0);
            }

            $writer->spacer(6.0);
        }
    }

    /**
     * Skills are grouped per category on one line each: "Backend: PHP, MySQL".
     *
     * @param array<int, array<string, mixed>> $skills
     */
    private function skills(PdfWriter $writer, array $skills): void
    {
        if ($skills === []) {
            return;
        }

        $grouped = [];

        foreach ($skills as $skill) {
            $category = trim((string) ($skill['category'] ?? ''));
            $name = trim((string) ($skill['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $grouped[$category !== '' ? $category : 'General'][] = $name;
        }

        if ($grouped === []) {
            return;
        }

        $writer->heading('Skills');

        foreach ($grouped as $category => $names) {
            $writer->paragraph(sprintf('%s: %s', $category, implode(', ', $names)), 10.5, 'regular', 1.45, 2.0);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $certifications
     */
    private function certifications(PdfWriter $writer, array $certifications): void
    {
        if ($certifications === []) {
            return;
        }

        $writer->heading('Certifications');

        foreach ($certifications as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $issuer = trim((string) ($item['issuer'] ?? ''));

            $headline = $name;
            if ($issuer !== '') {
                $headline .= ($headline !== '' ? ' - ' : '') . $issuer;
            }

            $writer->paragraph($headline, 10.5, 'bold', 1.4, 1.0);

            $issued = $this->month($item['issued_date'] ?? null);
            $expiry = $this->month($item['expiry_date'] ?? null);
            $credential = trim((string) ($item['credential_id'] ?? ''));

            $meta = array_values(array_filter([
                $issued !== '' ? 'Issued ' . $issued : '',
                $expiry !== '' ? 'Expires ' . $expiry : 'No expiry',
                $credential !== '' ? 'Credential ID: ' . $credential : '',
                trim((string) ($item['credential_url'] ?? '')),
            ], static fn (string $value): bool => $value !== ''));

            if ($meta !== []) {
                $writer->paragraph(implode(' | ', $meta), 9.5, 'regular', 1.4, 2.0);
            }

            $writer->spacer(3.0);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $awards
     */
    private function awards(PdfWriter $writer, array $awards): void
    {
        if ($awards === []) {
            return;
        }

        $writer->heading('Awards');

        foreach ($awards as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $issuer = trim((string) ($item['issuer'] ?? ''));
            $year = (int) ($item['year'] ?? 0);

            $headline = $title;
            if ($issuer !== '') {
                $headline .= ($headline !== '' ? ' - ' : '') . $issuer;
            }
            if ($year > 0) {
                $headline .= sprintf(' (%d)', $year);
            }

            $writer->paragraph($headline, 10.5, 'regular', 1.45, 2.0);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $projects
     */
    private function projects(PdfWriter $writer, array $projects): void
    {
        if ($projects === []) {
            return;
        }

        $writer->heading('Selected Projects');

        foreach ($projects as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $role = trim((string) ($item['role'] ?? ''));
            $client = trim((string) ($item['client'] ?? ''));

            $headline = $title;
            if ($role !== '') {
                $headline .= ($headline !== '' ? ' - ' : '') . $role;
            }
            if ($client !== '') {
                $headline .= sprintf(' (client: %s)', $client);
            }

            $writer->paragraph($headline, 10.5, 'bold', 1.4, 1.0);

            $summary = trim((string) ($item['summary_text'] ?? ''));
            if ($summary !== '') {
                $writer->paragraph($summary, 10.5, 'regular', 1.45, 1.5);
            }

            $stack = array_values(array_filter(array_map(
                static fn (mixed $tech): string => trim((string) $tech),
                (array) ($item['tech_stack'] ?? []),
            ), static fn (string $tech): bool => $tech !== ''));

            $meta = array_values(array_filter([
                $stack !== [] ? 'Stack: ' . implode(', ', $stack) : '',
                trim((string) ($item['project_url'] ?? '')),
                trim((string) ($item['repo_url'] ?? '')),
            ], static fn (string $value): bool => $value !== ''));

            if ($meta !== []) {
                $writer->paragraph(implode(' | ', $meta), 9.5, 'regular', 1.4, 2.0);
            }

            $writer->spacer(4.0);
        }
    }

    /**
     * "Mar 2022 - Present", using English month names (the CV is English).
     */
    private function range(mixed $start, mixed $current, mixed $end): string
    {
        $from = $this->month($start);

        if ($from === '') {
            return '';
        }

        if ((bool) $current) {
            return $from . ' - Present';
        }

        $to = $this->month($end);

        return $to === '' ? $from : $from . ' - ' . $to;
    }

    private function month(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        return Str::date($value, 'M Y', 'en');
    }

    /**
     * Rich text -> plain text with paragraph breaks kept, so the PDF has no
     * markup an ATS would have to guess about.
     */
    private function plain(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $text = preg_replace('#<(br|/p|/div|/li|/h[1-6])\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#<li[^>]*>#i', "\n- ", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
