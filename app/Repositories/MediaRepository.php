<?php

declare(strict_types=1);

namespace Mops\Repositories;

use Mops\Core\Paths;

/**
 * Media library. The database stores the relative path only; the file itself
 * lives in storage/uploads/YYYY/MM/ and is streamed through a core route.
 */
final class MediaRepository extends Repository
{
    protected function table(): string
    {
        return 'media';
    }

    protected function defaultOrder(): array
    {
        return ['created_at', 'DESC'];
    }

    /**
     * @param array<string, mixed> $stored result of Uploader::store()
     * @param array<string, mixed> $extra title/alt/uploaded_by
     * @return array<string, mixed>
     */
    public function record(array $stored, array $extra = []): array
    {
        return $this->create(array_merge([
            'path' => $stored['path'] ?? '',
            'filename' => $stored['filename'] ?? '',
            'original_name' => $stored['original_name'] ?? '',
            'mime' => $stored['mime'] ?? '',
            'extension' => $stored['extension'] ?? '',
            'size' => $stored['size'] ?? 0,
            'width' => $stored['width'] ?? null,
            'height' => $stored['height'] ?? null,
            'alt' => $stored['alt'] ?? '',
            'title' => $stored['alt'] ?? '',
        ], $extra));
    }

    /**
     * Absolute disk path for a stored relative path (null when it escapes the
     * uploads folder).
     */
    public function diskPath(string $relativePath, string $uploadsRoot): ?string
    {
        $candidate = rtrim($uploadsRoot, '/') . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');

        return Paths::isInside($candidate, $uploadsRoot) ? $candidate : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function images(int $limit = 60): array
    {
        return $this->decorateMany(
            $this->query()->whereLike('mime', 'image/%')->limit($limit)->get(),
        );
    }

    public function totalSize(): int
    {
        return (int) $this->query()->sum('size');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decorate(array $row): array
    {
        $row = parent::decorate($row);

        $row['size'] = (int) ($row['size'] ?? 0);
        $row['is_image'] = str_starts_with((string) ($row['mime'] ?? ''), 'image/');
        $row['human_size'] = $this->humanSize($row['size']);
        $row['url'] = '/uploads/' . ltrim((string) ($row['path'] ?? ''), '/');

        return $row;
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
