<?php

declare(strict_types=1);

namespace Mops\Controllers;

use Mops\Core\Auth;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\View;
use Mops\Repositories\ProfileRepository;
use Mops\Repositories\PostRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Blog index + single post. The only paginated section (10 per page).
 */
final class BlogController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        private PostRepository $posts,
        private ProfileRepository $profiles,
        private Settings $settings,
    ) {
        parent::__construct($view, $session, $auth);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, $this->settings->int('posts_per_page', 10));

        $result = $this->posts->paginatePublished($perPage, $page);
        $profile = $this->profiles->current();

        return $this->render($response, 'blog/index.twig', [
            'posts' => $result['items'],
            'pagination' => $result,
            'portfolio' => ['profile' => $profile],
            'page' => [
                'title' => 'Blog',
                'meta_description' => (string) ($profile['headline'] ?? ''),
                'canonical' => '/blog',
            ],
        ]);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $post = $this->posts->findPublishedBySlug((string) ($args['slug'] ?? ''));

        if ($post === null) {
            return $this->notFoundResponse($response, 'Tulisan tidak ditemukan.');
        }

        $profile = $this->profiles->current();

        return $this->render($response, 'blog/show.twig', [
            'post' => $post,
            'related' => $this->related($post),
            'portfolio' => ['profile' => $profile],
            'page' => [
                'title' => (string) $post['title'],
                'meta_description' => (string) $post['excerpt_text'],
                'canonical' => '/blog/' . (string) $post['slug'],
                'published_at' => $post['published_at'] ?? null,
                'cover' => $post['cover_image'] ?? null,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<int, array<string, mixed>>
     */
    private function related(array $post): array
    {
        $tags = is_array($post['tags'] ?? null) ? $post['tags'] : [];

        if ($tags !== []) {
            $related = $this->posts->withTag((string) $tags[0], 3);

            if ($related !== []) {
                return array_values(array_filter(
                    $related,
                    static fn (array $item): bool => (int) $item['id'] !== (int) $post['id'],
                ));
            }
        }

        return array_values(array_filter(
            $this->posts->publishedSlice(4),
            static fn (array $item): bool => (int) $item['id'] !== (int) $post['id'],
        ));
    }
}
