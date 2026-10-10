<?php

declare(strict_types=1);

namespace Mops\Controllers;

use Mops\Core\Auth;
use Mops\Core\Hook;
use Mops\Core\SectionRegistry;
use Mops\Core\Session;
use Mops\Core\View;
use Mops\Repositories\AwardRepository;
use Mops\Repositories\CertificationRepository;
use Mops\Repositories\EducationRepository;
use Mops\Repositories\ExperienceRepository;
use Mops\Repositories\ProfileRepository;
use Mops\Repositories\ProjectRepository;
use Mops\Repositories\SkillRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Detail pages for every section that owns a route: /experience, /projects,
 * /skills, /education, /certifications, /awards, plus a single project view.
 *
 * Detail pages render the full list without pagination (except /blog, which
 * paginates 10/page) — see the homepage-sections spec.
 */
final class SectionController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private SectionRegistry $registry,
        private ProfileRepository $profiles,
        private ExperienceRepository $experiences,
        private ProjectRepository $projects,
        private SkillRepository $skills,
        private EducationRepository $educations,
        private CertificationRepository $certifications,
        private AwardRepository $awards,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['section'] ?? '');
        $section = $this->registry->find($id);

        if ($section === null || $section['more_url'] === null) {
            return $this->notFoundResponse($response);
        }

        $items = $this->items($id);
        $profile = $this->profiles->current();

        return $this->render($response, "pages/{$id}.twig", [
            'section' => [
                'id' => $id,
                'title' => (string) $section['title'],
                'total' => count($items),
                'more_url' => null,
            ],
            'items' => $items,
            'portfolio' => Hook::applyFilters('portfolio_payload', [
                'profile' => $profile,
                'skills' => $this->skills->all(),
            ]),
            'page' => [
                'title' => (string) $section['title'],
                'meta_description' => (string) ($profile['headline'] ?? ''),
                'canonical' => (string) $section['more_url'],
            ],
        ]);
    }

    public function project(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $project = $this->projects->findPublishedBySlug((string) ($args['slug'] ?? ''));

        if ($project === null) {
            return $this->notFoundResponse($response, 'Proyek tidak ditemukan.');
        }

        return $this->render($response, 'pages/project.twig', [
            'project' => $project,
            'related' => array_values(array_filter(
                $this->projects->publishedSlice(4),
                static fn (array $item): bool => (int) $item['id'] !== (int) $project['id'],
            )),
            'portfolio' => ['profile' => $this->profiles->current()],
            'page' => [
                'title' => (string) $project['title'],
                'meta_description' => (string) $project['summary_text'],
                'canonical' => '/projects/' . (string) $project['slug'],
            ],
        ]);
    }

    public function robots(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $siteName = (string) setting('site_name', 'MOPS');

        return $this->text(
            $response,
            "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /install\n\n# {$siteName}\n",
            'text/plain',
        );
    }

    public function notFound(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->notFoundResponse($response);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(string $id): array
    {
        return match ($id) {
            'experience' => $this->experiences->all(),
            'projects' => $this->projects->publishedSlice(null),
            'skills' => $this->skills->all(),
            'education' => $this->educations->all(),
            'certifications' => $this->certifications->all(),
            'awards' => $this->awards->all(),
            default => [],
        };
    }
}
