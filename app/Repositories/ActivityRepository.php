<?php

declare(strict_types=1);

namespace Mops\Repositories;

/**
 * Recent activity feed shown on the admin dashboard.
 */
final class ActivityRepository extends Repository
{
    protected function table(): string
    {
        return 'activity_log';
    }

    protected function defaultOrder(): array
    {
        return ['created_at', 'DESC'];
    }

    public function log(string $action, string $description, ?int $userId = null, ?string $subjectType = null, ?int $subjectId = null): void
    {
        try {
            $this->query()->insert([
                'user_id' => $userId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'description' => $description,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Activity logging must never break the request it describes.
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 8): array
    {
        return $this->decorateMany($this->ordered($this->query())->limit($limit)->get());
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decorate(array $row): array
    {
        $row = parent::decorate($row);

        $timestamp = strtotime((string) ($row['created_at'] ?? ''));

        $row['relative'] = $timestamp === false ? '' : $this->relative($timestamp);

        return $row;
    }

    private function relative(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'baru saja';
        }

        if ($diff < 3600) {
            return intdiv($diff, 60) . ' menit lalu';
        }

        if ($diff < 86400) {
            return intdiv($diff, 3600) . ' jam lalu';
        }

        if ($diff < 604800) {
            return intdiv($diff, 86400) . ' hari lalu';
        }

        return date('d M Y', $timestamp);
    }
}
