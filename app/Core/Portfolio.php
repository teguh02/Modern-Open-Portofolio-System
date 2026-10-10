<?php

declare(strict_types=1);

namespace Mops\Core;

use Mops\Repositories\AwardRepository;
use Mops\Repositories\CertificationRepository;
use Mops\Repositories\EducationRepository;
use Mops\Repositories\ExperienceRepository;
use Mops\Repositories\PostRepository;
use Mops\Repositories\ProfileRepository;
use Mops\Repositories\ProjectRepository;
use Mops\Repositories\SkillRepository;

/**
 * The structured portfolio schema (`portfolio` in Twig).
 *
 * One place assembles the payload core hands to themes — and that plugins may
 * reuse through the container (`mops(Portfolio::class)`) instead of querying
 * tables or repositories themselves.
 */
final class Portfolio
{
    public function __construct(
        private ProfileRepository $profiles,
        private ExperienceRepository $experiences,
        private ProjectRepository $projects,
        private SkillRepository $skills,
        private EducationRepository $educations,
        private CertificationRepository $certifications,
        private AwardRepository $awards,
        private PostRepository $posts,
    ) {
    }

    /**
     * Full payload: every portfolio collection in display order.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [
            'profile' => $this->profiles->current(),
            'experiences' => $this->experiences->all(),
            'projects' => $this->projects->publishedSlice(null),
            'skills' => $this->skills->all(),
            'educations' => $this->educations->all(),
            'certifications' => $this->certifications->all(),
            'awards' => $this->awards->all(),
            'posts' => $this->posts->publishedSlice(null),
        ];

        /** @var array<string, mixed> $filtered */
        $filtered = Hook::applyFilters('portfolio_payload', $payload);

        return $filtered;
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return $this->profiles->current();
    }
}
