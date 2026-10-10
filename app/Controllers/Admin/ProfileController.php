<?php

declare(strict_types=1);

namespace Mops\Controllers\Admin;

use Mops\Core\Auth;
use Mops\Core\SectionRegistry;
use Mops\Core\Settings;
use Mops\Core\Session;
use Mops\Core\Uploader;
use Mops\Core\View;
use Mops\Repositories\ActivityRepository;
use Mops\Repositories\MediaRepository;
use Mops\Repositories\ProfileRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Identity + bio editor (the two sections enabled on a fresh install).
 */
final class ProfileController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        Auth $auth,
        Settings $settings,
        ActivityRepository $activity,
        SectionRegistry $registry,
        private ProfileRepository $profiles,
        private MediaRepository $media,
        private Uploader $uploader,
    ) {
        parent::__construct($view, $session, $auth, $settings, $activity, $registry);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->admin($response, 'profile.twig', [
            'title' => 'Profile',
            'crumbs' => $this->crumbs('Profile'),
            'values' => $this->values(),
            'errors' => [],
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = (array) ($request->getParsedBody() ?? []);

        $errors = [];

        if (trim((string) ($input['full_name'] ?? '')) === '') {
            $errors['full_name'][] = 'Nama lengkap wajib diisi.';
        }

        $email = trim((string) ($input['email'] ?? ''));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'Email tidak valid.';
        }

        $website = trim((string) ($input['website'] ?? ''));

        if ($website !== '' && filter_var($website, FILTER_VALIDATE_URL) === false && !str_starts_with($website, '/')) {
            $errors['website'][] = 'URL website tidak valid.';
        }

        if ($errors !== []) {
            return $this->admin($response, 'profile.twig', [
                'title' => 'Profile',
                'crumbs' => $this->crumbs('Profile'),
                'values' => array_merge($this->values(), $input),
                'errors' => $errors,
                'error_list' => array_merge(...array_values($errors)),
            ], 422);
        }

        $data = [
            'full_name' => (string) ($input['full_name'] ?? ''),
            'headline' => (string) ($input['headline'] ?? ''),
            'short_bio' => (string) ($input['short_bio'] ?? ''),
            'bio' => \Mops\Core\Str::sanitizeHtml((string) ($input['bio'] ?? '')),
            'location' => (string) ($input['location'] ?? ''),
            'email' => $email,
            'phone' => (string) ($input['phone'] ?? ''),
            'website' => $website,
            'availability' => (string) ($input['availability'] ?? ''),
            'meta_title' => (string) ($input['meta_title'] ?? ''),
            'meta_description' => (string) ($input['meta_description'] ?? ''),
            'focus_areas' => $this->lines($input['focus_areas'] ?? null),
            'social_links' => $this->socialLinks($input),
            'stats' => $this->stats($input),
        ];

        // Uploads replace the stored media path when attached.
        foreach (['photo', 'resume_file'] as $field) {
            $stored = $this->upload($field);

            if ($stored !== null) {
                $data[$field] = $stored;
            } elseif (!empty($input[$field])) {
                $data[$field] = (string) $input[$field];
            }
        }

        $this->profiles->save($data);
        $this->activity->log('update', 'Profil diperbarui', $this->auth->id(), 'profile', 1);
        $this->flash('success', 'Profil berhasil disimpan.');

        return $this->redirect($response, '/admin/profile');
    }

    /**
     * @return array<string, mixed>
     */
    private function values(): array
    {
        $profile = $this->profiles->current();

        return array_merge($profile, [
            'focus_areas' => implode("\n", array_map(
                static fn (mixed $item): string => is_array($item) ? (string) ($item['label'] ?? '') : (string) $item,
                (array) $profile['focus_areas'],
            )),
            'social_rows' => $this->rows($profile['social_links'], ['label' => '', 'url' => '', 'icon' => '']),
            'stat_rows' => $this->rows($profile['stats'], ['label' => '', 'value' => '']),
        ]);
    }

    /**
     * Existing rows plus three blank ones, so the form also works without
     * JavaScript (rows are plain indexed inputs).
     *
     * @param array<int, array<string, mixed>> $items
     * @param array<string, string> $template
     * @return array<int, array<string, string>>
     */
    private function rows(array $items, array $template): array
    {
        $rows = [];

        foreach ($items as $item) {
            $row = [];

            foreach (array_keys($template) as $key) {
                $row[$key] = (string) ($item[$key] ?? '');
            }

            $rows[] = $row;
        }

        for ($i = 0; $i < 3; $i++) {
            $rows[] = $template;
        }

        return $rows;
    }

    private function upload(string $field): ?string
    {
        $file = $_FILES[$field . '_upload'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $kind = $field === 'resume_file' ? 'document' : 'image';
        $stored = $this->uploader->store($file, $kind);

        if ($stored === null) {
            $this->flash('error', implode(' ', $this->uploader->errors()));

            return null;
        }

        $media = $this->media->record($stored, [
            'alt' => (string) setting('site_name', 'MOPS'),
            'uploaded_by' => $this->auth->id(),
        ]);

        return (string) $media['path'];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function socialLinks(array $input): array
    {
        $labels = (array) ($input['social_label'] ?? []);
        $urls = (array) ($input['social_url'] ?? []);
        $icons = (array) ($input['social_icon'] ?? []);
        $removed = (array) ($input['social_remove'] ?? []);
        $links = [];

        foreach ($urls as $index => $url) {
            $url = trim((string) $url);

            if ($url === '' || isset($removed[$index])) {
                continue;
            }

            $links[] = [
                'label' => trim((string) ($labels[$index] ?? '')),
                'url' => $url,
                'icon' => trim((string) ($icons[$index] ?? '')),
            ];
        }

        return $links;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function stats(array $input): array
    {
        $labels = (array) ($input['stat_label'] ?? []);
        $values = (array) ($input['stat_value'] ?? []);
        $removed = (array) ($input['stat_remove'] ?? []);
        $stats = [];

        foreach ($values as $index => $value) {
            $value = trim((string) $value);
            $label = trim((string) ($labels[$index] ?? ''));

            if (($value === '' && $label === '') || isset($removed[$index])) {
                continue;
            }

            $stats[] = ['label' => $label, 'value' => $value];
        }

        return $stats;
    }

    /**
     * @return array<int, string>
     */
    private function lines(mixed $raw): array
    {
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw)) {
            $items = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        } else {
            $items = [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $line): string => trim((string) $line), $items),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
