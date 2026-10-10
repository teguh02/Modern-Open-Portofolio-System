<?php

declare(strict_types=1);

namespace Mops\Controllers;

use Mops\Core\Auth;
use Mops\Core\Portfolio;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\View;
use Mops\Repositories\AwardRepository;
use Mops\Repositories\CertificationRepository;
use Mops\Repositories\EducationRepository;
use Mops\Repositories\ExperienceRepository;
use Mops\Repositories\PostRepository;
use Mops\Repositories\ProfileRepository;
use Mops\Repositories\ProjectRepository;
use Mops\Repositories\SkillRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Homepage: one LinkedIn-style page assembled from the Section Registry.
 *
 * Visibility, limits and "Lihat selengkapnya" URLs are computed here in core —
 * the theme only renders what it is handed.
 */
final class HomeController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private SectionRegistry $registry,
        private Settings $settings,
        private ProfileRepository $profiles,
        private ExperienceRepository $experiences,
        private ProjectRepository $projects,
        private SkillRepository $skills,
        private EducationRepository $educations,
        private CertificationRepository $certifications,
        private AwardRepository $awards,
        private PostRepository $posts,
        private Portfolio $portfolio,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $this->buildSections();
        $profile = $this->profiles->current();
        $portfolio = $this->portfolio->payload();

        return $this->render($response, 'home.twig', [
            'sections' => $payload,
            'portfolio' => $portfolio,
            'page' => [
                'title' => $this->settings->get('site_name', 'MOPS'),
                'meta_description' => (string) $profile['meta_description'] !== ''
                    ? (string) $profile['meta_description']
                    : (string) $profile['headline'],
                'canonical' => '/',
            ],
            'json_ld' => $this->jsonLd($profile),
        ]);
    }

    /**
     * Build the `sections` array contract from the spec:
     * id, title, items (already sliced), total, more_url.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildSections(): array
    {
        $sections = [];

        foreach ($this->registry->all() as $section) {
            $id = (string) $section['id'];
            $limit = $section['limit'] === null ? null : (int) $section['limit'];

            [$items, $total] = $this->itemsFor($id, $limit);

            $enabled = (bool) $this->settings->bool(
                $this->registry->settingKey($id),
                (bool) $section['default'],
            );

            if (!$this->registry->isVisible($section, $enabled, $total)) {
                continue;
            }

            $sections[] = [
                'id' => $id,
                'title' => (string) $section['title'],
                'items' => $items,
                'total' => $total,
                'more_url' => $this->registry->moreUrlFor($section, $total, count($items)),
                'template' => "sections/{$id}.twig",
            ];
        }

        return $sections;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function itemsFor(string $id, ?int $limit): array
    {
        return match ($id) {
            'profile' => [[$this->profiles->current()], 1],
            'about' => [[$this->profiles->current()], (string) $this->profiles->current()['bio'] !== '' ? 1 : 0],
            'experience' => [$this->experiences->slice($limit), $this->experiences->count()],
            'projects' => [$this->projects->publishedSlice($limit), $this->projects->publishedCount()],
            'skills' => [$this->skills->slice($limit), $this->skills->count()],
            'education' => [$this->educations->slice($limit), $this->educations->count()],
            'certifications' => [$this->certifications->slice($limit), $this->certifications->count()],
            'awards' => [$this->awards->slice($limit), $this->awards->count()],
            'blog' => [$this->posts->publishedSlice($limit), $this->posts->publishedCount()],
            default => [[], 0],
        };
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function jsonLd(array $profile): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $profile['full_name'] ?? '',
            'jobTitle' => $profile['headline'] ?? '',
            'description' => $profile['short_bio'] ?? '',
            'email' => $profile['email'] ?? '',
            'address' => $profile['location'] ?? '',
            'image' => isset($profile['photo']) ? '/uploads/' . (string) $profile['photo'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
