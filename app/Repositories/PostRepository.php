<?php

declare(strict_types=1);

namespace Mops\Repositories;

use Mops\Core\QueryBuilder;
use Mops\Core\Str;

/**
 * Blog posts. Ordered newest first (spec: published_at DESC), paginated 10/page
 * — the only section that paginates.
 */
final class PostRepository extends Repository
{
    private const JSON_FIELDS = ['tags'];

    protected function table(): string
    {
        return 'posts';
    }

    protected function defaultOrder(): array
    {
        return ['published_at', 'DESC'];
    }

    /**
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

    public function draftCount(): int
    {
        return $this->query()->where('status', 'draft')->count();
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
     * Paginated public index.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginatePublished(int $perPage = 10, int $page = 1): array
    {
        $total = $this->publishedCount();
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $pages);

        $items = $this->decorateMany(
            $this->published($this->query())->paginate($perPage, $page)->get(),
        );

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<int, string> $tags
     * @return array<int, array<string, mixed>>
     */
    public function withTag(string $tag, int $limit = 4): array
    {
        return $this->decorateMany(
            $this->published($this->query())->whereLike('tags', '%' . $tag . '%')->limit($limit)->get(),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $data = $this->prepare($data);

        return parent::create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $current = $this->find($id);

        return parent::update($id, $this->prepare($data, $current, $id));
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

        $row['is_published'] = ($row['status'] ?? 'draft') === 'published';
        $row['published_label'] = Str::date($row['published_at'] ?? null, 'd M Y');
        $row['reading_time'] = Str::readingTime((string) ($row['content'] ?? ''));
        $row['excerpt_text'] = (string) ($row['excerpt'] ?? '') !== ''
            ? (string) $row['excerpt']
            : Str::excerpt((string) ($row['content'] ?? ''), 180);

        return $row;
    }

    private function published(QueryBuilder $query): QueryBuilder
    {
        return $query->where('status', 'published');
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
    private function prepare(array $data, ?array $current = null, ?int $id = null): array
    {
        if (isset($data['tags']) && is_array($data['tags'])) {
            $data['tags'] = json_encode(array_values($data['tags']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (isset($data['content'])) {
            $data['content'] = Str::sanitizeHtml($data['content']);
        }

        if (isset($data['title']) || isset($data['slug'])) {
            $source = (string) ($data['slug'] ?? $data['title'] ?? ($current['title'] ?? 'post'));
            $data['slug'] = $this->uniqueSlug($source, $id, 'post');
        }

        $status = $data['status'] ?? ($current['status'] ?? null);

        if ($status === 'published' && empty($data['published_at']) && empty($current['published_at'])) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }

        return $data;
    }

    /**
     * @return array<int, string>
     */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
