<?php

declare(strict_types=1);

namespace Mops\Repositories;

use Mops\Core\QueryBuilder;
use Mops\Core\Str;

/**
 * Portfolio projects. Ordered newest first (spec: created_at DESC).
 */
final class ProjectRepository extends Repository
{
    /** @var array<int, string> */
    private const JSON_FIELDS = ['tech_stack', 'gallery'];

    protected function table(): string
    {
        return 'projects';
    }

    protected function defaultOrder(): array
    {
        return ['created_at', 'DESC'];
    }

    /**
     * Published projects only — what the public site may render.
     *
     * @return array<int, array<string, mixed>>
     */
    public function publishedSlice(?int $limit): array
    {
        $query = $this->published($this->query());

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $this->decorateMany($query->get());
    }

    public function publishedCount(): int
    {
        return $this->published($this->query())->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function featured(int $limit = 3): array
    {
        return $this->decorateMany(
            $this->published($this->query())->where('featured', 1)->limit($limit)->get(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublishedBySlug(string $slug): ?array
    {
        $row = $this->published($this->query())->where('slug', $slug)->first();

        return $row === null ? null : $this->decorate($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $data = $this->encodeJson($data);
        $data['slug'] = $this->uniqueSlug((string) ($data['slug'] ?? $data['title'] ?? 'proyek'), null, 'proyek');

        return parent::create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        if (isset($data['slug']) || isset($data['title'])) {
            $current = $this->find($id);
            $source = (string) ($data['slug'] ?? $data['title'] ?? ($current['title'] ?? 'proyek'));
            $data['slug'] = $this->uniqueSlug($source, $id, 'proyek');
        }

        return parent::update($id, $this->encodeJson($data));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decorate(array $row): array
    {
        $row = parent::decorate($row);

        foreach (self::JSON_FIELDS as $field) {
            $row[$field] = $this->decodeList($row[$field] ?? null);
        }

        $row['featured'] = (int) ($row['featured'] ?? 0) === 1;
        $row['is_published'] = ($row['status'] ?? 'draft') === 'published';
        $row['period'] = Str::dateRange($row['started_at'] ?? null, $row['completed_at'] ?? null, 'Sekarang');
        $row['summary_text'] = (string) ($row['summary'] ?? '') !== ''
            ? (string) $row['summary']
            : Str::excerpt((string) ($row['description'] ?? ''), 160);

        return $row;
    }

    private function published(QueryBuilder $query): QueryBuilder
    {
        return $query->where('status', 'published');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function encodeJson(array $data): array
    {
        foreach (self::JSON_FIELDS as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode(array_values($data[$field]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        return $data;
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
