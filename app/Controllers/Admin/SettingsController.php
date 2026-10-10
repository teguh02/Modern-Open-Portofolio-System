<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\Auth;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\Validator;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Mops\Repositories\AwardRepository;
use Mops\Repositories\CertificationRepository;
use Mops\Repositories\EducationRepository;
use Mops\Repositories\ExperienceRepository;
use Mops\Repositories\PostRepository;
use Mops\Repositories\ProjectRepository;
use Mops\Repositories\SkillRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Settings: site identity + per-section visibility toggles.
 *
 * Limits and section order are NOT editable here — they are hard-coded in the
 * Section Registry (spec decision). This page shows them read-only.
 */
final class SettingsController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
        private ExperienceRepository $experiences,
        private ProjectRepository $projects,
        private SkillRepository $skills,
        private EducationRepository $educations,
        private CertificationRepository $certifications,
        private AwardRepository $awards,
        private PostRepository $posts,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->admin($response, 'settings.twig', [
            'title' => 'Settings',
            'crumbs' => $this->crumbs('Settings'),
            'values' => [
                'site_name' => (string) $this->settings->get('site_name', 'MOPS'),
                'site_tagline' => (string) $this->settings->get('site_tagline', ''),
                'site_description' => (string) $this->settings->get('site_description', ''),
                'locale' => (string) $this->settings->get('locale', 'id'),
                'posts_per_page' => (string) $this->settings->get('posts_per_page', '10'),
            ],
            'sections' => $this->sectionRows(),
            'errors' => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);

        $validator = Validator::make($input, [
            'site_name' => 'required|max:120',
            'site_tagline' => 'nullable|max:120',
            'site_description' => 'nullable|max:255',
            'locale' => 'required|in:id,en',
            'posts_per_page' => 'required|integer',
        ], [
            'site_name' => 'Nama situs',
            'site_tagline' => 'Tagline',
            'site_description' => 'Deskripsi',
            'locale' => 'Bahasa',
            'posts_per_page' => 'Tulisan per halaman',
        ]);

        if ($validator->fails()) {
            return $this->admin($response, 'settings.twig', [
                'title' => 'Settings',
                'crumbs' => $this->crumbs('Settings'),
                'values' => array_merge([
                    'site_name' => '',
                    'site_tagline' => '',
                    'site_description' => '',
                    'locale' => 'id',
                    'posts_per_page' => '10',
                ], $input),
                'sections' => $this->sectionRows(),
                'errors' => $validator->errors(),
                'error_list' => $validator->errorList(),
            ], 422);
        }

        $data = $validator->validated();

        $this->settings->setMany([
            'site_name' => (string) $data['site_name'],
            'site_tagline' => (string) ($data['site_tagline'] ?? ''),
            'site_description' => (string) ($data['site_description'] ?? ''),
            'locale' => (string) $data['locale'],
            'posts_per_page' => max(1, min(50, (int) $data['posts_per_page'])),
        ]);

        // Visibility toggles: only keys the registry exposes can be written.
        $visibility = (array) ($input['sections'] ?? []);

        foreach ($this->registry->toggleable() as $section) {
            $id = (string) $section['id'];
            $this->settings->set($this->registry->settingKey($id), isset($visibility[$id]) ? '1' : '0');
        }

        $this->activity->log('update', 'Pengaturan diperbarui', $this->auth->id(), 'settings');
        $this->flash('success', 'Pengaturan disimpan.');

        return $this->redirect($response, '/admin/settings');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sectionRows(): array
    {
        $rows = [];

        foreach ($this->registry->all() as $section) {
            $id = (string) $section['id'];
            $total = $this->countFor($id);

            $rows[] = [
                'id' => $id,
                'title' => (string) $section['title'],
                'limit' => $section['limit'],
                'always_visible' => (bool) $section['always_visible'],
                'has_toggle' => (bool) $section['has_toggle'],
                'more_url' => $section['more_url'],
                'enabled' => (bool) $this->settings->bool(
                    $this->registry->settingKey($id),
                    (bool) $section['default'],
                ),
                'total' => $total,
                'visible' => $this->registry->isVisible(
                    $section,
                    (bool) $this->settings->bool($this->registry->settingKey($id), (bool) $section['default']),
                    $total,
                ),
                'default' => (bool) $section['default'],
            ];
        }

        return $rows;
    }

    private function countFor(string $id): int
    {
        return match ($id) {
            'profile', 'about' => 1,
            'experience' => $this->experiences->count(),
            'projects' => $this->projects->publishedCount(),
            'skills' => $this->skills->count(),
            'education' => $this->educations->count(),
            'certifications' => $this->certifications->count(),
            'awards' => $this->awards->count(),
            'blog' => $this->posts->publishedCount(),
            default => 0,
        };
    }
}
