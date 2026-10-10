<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\App;
use Mops\Core\Auth;
use Mops\Core\Paths;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\Uploader;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Mops\Repositories\MediaRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Media library. Files live in storage/uploads/YYYY/MM/ and are streamed through
 * /uploads/... — the database only stores the relative path.
 */
final class MediaController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
        private MediaRepository $media,
        private Uploader $uploader,
        private \Mops\Core\Config $config,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = trim((string) ($query['q'] ?? ''));
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = 24;

        $items = $this->media->all();

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => str_contains(mb_strtolower((string) $item['original_name']), $needle)
                    || str_contains(mb_strtolower((string) $item['alt']), $needle),
            ));
        }

        $total = count($items);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        return $this->admin($response, 'media.twig', [
            'title' => 'Media',
            'crumbs' => $this->crumbs('Media'),
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'search' => $search,
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total, 'per_page' => $perPage],
            'total_size' => $this->media->totalSize(),
            'max_upload_mb' => (int) $this->config->get('uploads.max_mb', 8),
        ]);
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $files = $_FILES['files'] ?? null;

        if (!is_array($files) || ($files['name'][0] ?? '') === '') {
            $this->flash('error', 'Pilih minimal satu file.');

            return $this->redirect($response, '/admin/media');
        }

        $normalised = $this->normalise($files);
        $stored = $this->uploader->storeMany($normalised);
        $errors = $this->uploader->errors();

        foreach ($stored as $file) {
            $this->media->record($file, ['uploaded_by' => $this->auth->id()]);
        }

        if ($stored !== []) {
            $this->activity->log(
                'upload',
                count($stored) . ' file diunggah',
                $this->auth->id(),
                'media',
            );
            $this->flash('success', count($stored) . ' file berhasil diunggah.');
        }

        if ($errors !== []) {
            $this->flash('error', implode(' ', $errors));
        }

        return $this->redirect($response, '/admin/media');
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $row = $this->media->find($id);

        if ($row === null) {
            $this->flash('error', 'Media tidak ditemukan.');

            return $this->redirect($response, '/admin/media');
        }

        $input = (array) ($request->getParsedBody() ?? []);

        $this->media->update($id, [
            'alt' => trim((string) ($input['alt'] ?? $row['alt'])),
            'title' => trim((string) ($input['title'] ?? $row['title'])),
        ]);

        $this->flash('success', 'Detail media diperbarui.');

        return $this->redirect($response, '/admin/media');
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $row = $this->media->find($id);

        if ($row === null) {
            $this->flash('error', 'Media tidak ditemukan.');

            return $this->redirect($response, '/admin/media');
        }

        $uploads = Paths::make('storage/uploads');
        $path = $this->media->diskPath((string) $row['path'], $uploads);

        if ($path !== null && is_file($path)) {
            unlink($path);
        }

        $this->media->delete($id);
        $this->activity->log('delete', 'Media dihapus: ' . $row['original_name'], $this->auth->id(), 'media', $id);
        $this->flash('success', 'Media dihapus.');

        return $this->redirect($response, '/admin/media');
    }

    /**
     * JSON feed used by the media picker inside resource forms.
     */
    public function library(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $items = array_map(static fn (array $item): array => [
            'id' => $item['id'],
            'path' => $item['path'],
            'url' => $item['url'],
            'name' => $item['original_name'],
            'alt' => $item['alt'],
        ], $this->media->images(200));

        return $this->json($response, ['items' => $items]);
    }

    /**
     * Turn the $_FILES['files'] column layout into a flat list of entries.
     *
     * @param array<string, mixed> $files
     * @return array<int, array<string, mixed>>
     */
    private function normalise(array $files): array
    {
        $entries = [];
        $names = (array) ($files['name'] ?? []);
        $total = count($names);

        for ($i = 0; $i < $total; $i++) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $entries[] = [
                'name' => (string) ($files['name'][$i] ?? ''),
                'type' => (string) ($files['type'][$i] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files['size'][$i] ?? 0),
            ];
        }

        return $entries;
    }
}
