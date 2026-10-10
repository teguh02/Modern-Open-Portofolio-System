<?php

declare(strict_types=1);

namespace Mops\Repositories;

use Mops\Core\Database;
use Mops\Core\Hook;
use Mops\Core\QueryBuilder;
use Mops\Core\Str;

/**
 * Repository base. Repositories are the only place that talks to the database,
 * and they always go through the portable QueryBuilder — never raw SQL with
 * dialect-specific syntax.
 */
abstract class Repository
{
    public function __construct(protected Database $db)
    {
    }

    abstract protected function table(): string;

    /**
     * Column used for the default ordering of listings.
     */
    protected function defaultOrder(): array
    {
        return ['created_at', 'DESC'];
    }

    protected function query(): QueryBuilder
    {
        return $this->db->table($this->table());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->decorateMany($this->ordered($this->query())->get());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->query()->where('id', $id)->first();

        return $row === null ? null : $this->decorate($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBy(string $column, mixed $value): ?array
    {
        $row = $this->query()->where($column, $value)->first();

        return $row === null ? null : $this->decorate($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function where(string $column, mixed $value): array
    {
        return $this->decorateMany($this->ordered($this->query()->where($column, $value))->get());
    }

    /**
     * Fetch the newest $limit rows (used by the homepage Section Registry).
     *
     * @return array<int, array<string, mixed>>
     */
    public function slice(?int $limit): array
    {
        $query = $this->ordered($this->query());

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $this->decorateMany($query->get());
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    public function countWhere(string $column, mixed $value): int
    {
        return $this->query()->where($column, $value)->count();
    }

    /**
     * Count rows whose date column is on/after $since. The column name is
     * validated here because it is interpolated into the SQL fragment.
     */
    public function countSince(string $column, string $since): int
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
            throw new \InvalidArgumentException("Invalid column name: {$column}");
        }

        return $this->query()->whereRaw("{$column} >= ?", [$since])->count();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $data = $this->filterColumns($data);
        $now = date('Y-m-d H:i:s');

        if ($this->hasColumn('created_at') && !isset($data['created_at'])) {
            $data['created_at'] = $now;
        }

        if ($this->hasColumn('updated_at') && !isset($data['updated_at'])) {
            $data['updated_at'] = $now;
        }

        $data = Hook::applyFilters($this->table() . '_before_create', $data, $this);
        $id = $this->query()->insert($data);

        Hook::doAction($this->table() . '_created', $id, $data);

        return $this->find($id) ?? [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $data = $this->filterColumns($data);

        if ($this->hasColumn('updated_at')) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        if ($data === []) {
            return false;
        }

        $data = Hook::applyFilters($this->table() . '_before_update', $data, $id, $this);
        $this->query()->where('id', $id)->update($data);

        Hook::doAction($this->table() . '_updated', $id, $data);

        return true;
    }

    public function delete(int $id): int
    {
        Hook::doAction($this->table() . '_deleting', $id);
        $deleted = $this->query()->where('id', $id)->delete();
        Hook::doAction($this->table() . '_deleted', $id);

        return $deleted;
    }

    /**
     * @param array<int, int> $ids
     */
    public function deleteMany(array $ids): int
    {
        return $this->query()->whereIn('id', $ids)->delete();
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function paginate(int $perPage = 10, int $page = 1, ?int $limitTo = null): array
    {
        $total = $this->count();
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $pages);

        $query = $this->ordered($this->query())->paginate($perPage, $page);

        if ($limitTo !== null) {
            $query->limit($limitTo);
        }

        return [
            'items' => $this->decorateMany($query->get()),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * Ensure a slug is unique within a table.
     */
    public function uniqueSlug(string $value, ?int $ignoreId = null, string $fallback = 'item'): string
    {
        $base = Str::slug($value, $fallback);
        $slug = $base;
        $suffix = 2;

        while (true) {
            $query = $this->query()->where('slug', $slug);

            if ($ignoreId !== null) {
                $query->whereNot('id', $ignoreId);
            }

            if ($query->first() === null) {
                return $slug;
            }

            $slug = $base . '-' . $suffix++;
        }
    }

    /**
     * Column names of this repository's table. Introspection lives in the data
     * layer (Database::tableColumns) so repositories stay driver-agnostic.
     *
     * @return array<int, string>
     */
    public function columns(): array
    {
        static $cache = [];

        $table = $this->table();

        if (!isset($cache[$table])) {
            $cache[$table] = $this->db->tableColumns($table);
        }

        return $cache[$table];
    }

    public function hasColumn(string $column): bool
    {
        return in_array($column, $this->columns(), true);
    }

    /**
     * Keep only keys that exist as real columns so a stale form field can never
     * produce a SQL error.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function filterColumns(array $data): array
    {
        $columns = $this->columns();

        if ($columns === []) {
            return $data;
        }

        return array_filter(
            $data,
            static fn (string $key): bool => in_array($key, $columns, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    protected function ordered(QueryBuilder $query): QueryBuilder
    {
        [$column, $direction] = $this->defaultOrder();

        return $query->orderBy($column, $direction);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decorate(array $row): array
    {
        $row = Hook::applyFilters($this->table() . '_row', $row, $this);

        // Cast numeric strings so templates do not have to.
        if (isset($row['id'])) {
            $row['id'] = (int) $row['id'];
        }

        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function decorateMany(array $rows): array
    {
        return array_map(fn (array $row): array => $this->decorate($row), $rows);
    }

}
