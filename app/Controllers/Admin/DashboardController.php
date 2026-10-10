<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\Auth;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Mops\Repositories\ExperienceRepository;
use Mops\Repositories\MediaRepository;
use Mops\Repositories\PostRepository;
use Mops\Repositories\ProfileRepository;
use Mops\Repositories\ProjectRepository;
use Mops\Repositories\SkillRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dashboard. All numbers are computed from the database — MOPS core ships no
 * analytics (page views belong to a plugin).
 */
final class DashboardController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
        private ProjectRepository $projects,
        private PostRepository $posts,
        private MediaRepository $media,
        private ExperienceRepository $experiences,
        private SkillRepository $skills,
        private ProfileRepository $profiles,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $profile = $this->profiles->current();
        $enabled = [];

        foreach ($this->registry->all() as $section) {
            $id = (string) $section['id'];

            if (($section['always_visible'] ?? false) === true) {
                continue;
            }

            $enabled[$id] = (bool) $this->settings->bool(
                $this->registry->settingKey($id),
                (bool) $section['default'],
            );
        }

        $projectTotal = $this->projects->count();
        $postTotal = $this->posts->count();

        return $this->admin($response, 'dashboard.twig', [
            'title' => 'Dashboard',
            'crumbs' => $this->crumbs('Dashboard'),
            'stats' => [
                [
                    'label' => 'Projects',
                    'value' => $projectTotal,
                    'hint' => $this->projects->publishedCount() . ' published',
                    'delta' => $this->deltaLabel('projects'),
                    'trend' => 'up',
                ],
                [
                    'label' => 'Blog Posts',
                    'value' => $postTotal,
                    'hint' => $this->posts->draftCount() . ' draft',
                    'delta' => $this->deltaLabel('posts'),
                    'trend' => 'up',
                ],
                [
                    'label' => 'Media Files',
                    'value' => $this->media->count(),
                    'hint' => $this->humanSize($this->media->totalSize()),
                    'delta' => 'storage',
                    'trend' => 'flat',
                ],
                [
                    'label' => 'Sections Enabled',
                    'value' => count(array_filter($enabled)),
                    'hint' => count($enabled) . ' sections available',
                    'delta' => 'homepage',
                    'trend' => 'flat',
                ],
            ],
            'chart' => $this->chart(),
            'mix' => [
                ['label' => 'Projects', 'value' => $projectTotal, 'color' => '#4f46e5'],
                ['label' => 'Blog posts', 'value' => $postTotal, 'color' => '#0ea5e9'],
                ['label' => 'Experience', 'value' => $this->experiences->count(), 'color' => '#22c55e'],
                ['label' => 'Skills', 'value' => $this->skills->count(), 'color' => '#f59e0b'],
            ],
            'sections' => $enabled,
            'recent' => $this->activity->recent(8),
            'profile_completeness' => $this->completeness($profile),
            'profile' => $profile,
        ]);
    }

    /**
     * Bar heights are computed here so the markup needs no chart library and no
     * JavaScript (CSP stays strict).
     *
     * @return array{columns: array<int, array<string, mixed>>, series: array<int, string>, max: int}
     */
    private function chart(): array
    {
        $labels = $this->monthLabels();
        $series = [
            'Posts' => $this->monthlyCounts($this->posts->all(), 'published_at'),
            'Projects' => $this->monthlyCounts($this->projects->all(), 'created_at'),
        ];

        $max = 1;

        foreach ($series as $values) {
            $max = max($max, max($values) ?: 0);
        }

        $columns = [];

        foreach ($labels as $index => $label) {
            $bars = [];

            foreach ($series as $name => $values) {
                $value = (int) ($values[$index] ?? 0);
                $bars[] = [
                    'name' => $name,
                    'value' => $value,
                    'percent' => (int) round(($value / $max) * 100),
                ];
            }

            $columns[] = ['label' => $label, 'bars' => $bars];
        }

        return ['columns' => $columns, 'series' => array_keys($series), 'max' => $max];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{percent: int, missing: array<int, string>}
     */
    private function completeness(array $profile): array
    {
        $checks = [
            'Nama lengkap' => ($profile['full_name'] ?? '') !== '',
            'Headline' => ($profile['headline'] ?? '') !== '',
            'Bio' => ($profile['bio'] ?? '') !== '',
            'Foto profil' => ($profile['photo'] ?? null) !== null,
            'Lokasi' => ($profile['location'] ?? '') !== '',
            'Tautan sosial' => ($profile['social_links'] ?? []) !== [],
        ];

        $missing = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
        $done = count($checks) - count($missing);

        return [
            'percent' => (int) round(($done / max(1, count($checks))) * 100),
            'missing' => $missing,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, int>
     */
    private function monthlyCounts(array $rows, string $column): array
    {
        $counts = array_fill(0, 6, 0);
        $now = new \DateTimeImmutable('first day of this month');

        foreach ($rows as $row) {
            $value = (string) ($row[$column] ?? '');

            if ($value === '') {
                continue;
            }

            $timestamp = strtotime($value);

            if ($timestamp === false) {
                continue;
            }

            $month = new \DateTimeImmutable(date('Y-m-01', $timestamp));
            $diff = (int) $now->diff($month)->format('%r%m');

            if ($diff <= 0 && $diff > -6) {
                $counts[5 + $diff]++;
            }
        }

        return $counts;
    }

    /**
     * @return array<int, string>
     */
    private function monthLabels(): array
    {
        $labels = [];

        for ($i = 5; $i >= 0; $i--) {
            $labels[] = \Mops\Core\Str::date(date('Y-m-01', strtotime("-{$i} months")), 'M');
        }

        return $labels;
    }

    private function deltaLabel(string $kind): string
    {
        $threshold = date('Y-m-d H:i:s', strtotime('-30 days'));

        $count = match ($kind) {
            'projects' => $this->projects->countSince('created_at', $threshold),
            'posts' => $this->posts->countSince('created_at', $threshold),
            default => 0,
        };

        return $count === 0 ? 'no change 30d' : "+{$count} in 30d";
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, $index === 0 ? 0 : 1) . ' ' . $units[$index];
    }
}
