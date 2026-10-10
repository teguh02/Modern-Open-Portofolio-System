<?php

declare(strict_types=1);

namespace Mops\Core;

use RuntimeException;

/**
 * Portable query builder. Repositories use this instead of raw SQL so the same
 * code runs on SQLite, MySQL and PostgreSQL. Any dialect-specific fragment
 * (random ordering, upsert, DDL) is isolated in this class.
 */
final class QueryBuilder
{
    /** @var array<int, array{type: string, column?: string, operator?: string, value?: mixed, sql?: string, bindings?: array<int, mixed>}> */
    private array $wheres = [];

    /** @var array<int, array{column: string, direction: string}> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var array<int, string> */
    private array $columns = ['*'];

    /** @var array<int, string> */
    private array $groups = [];

    private ?string $joinClause = null;

    public function __construct(private Database $db, private string $table)
    {
    }

    public function select(string ...$columns): self
    {
        if ($columns !== []) {
            $this->columns = $columns;
        }

        return $this;
    }

    public function where(string $column, mixed $value): self
    {
        return $this->addWhere('basic', $column, '=', $value);
    }

    public function whereNot(string $column, mixed $value): self
    {
        return $this->addWhere('basic', $column, '!=', $value);
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = ['type' => 'null', 'column' => $column];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = ['type' => 'notnull', 'column' => $column];

        return $this;
    }

    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            // An empty IN list can never match; keep the query valid.
            $this->wheres[] = ['type' => 'raw', 'sql' => '1 = 0', 'bindings' => []];

            return $this;
        }

        $this->wheres[] = ['type' => 'in', 'column' => $column, 'value' => array_values($values)];

        return $this;
    }

    public function whereLike(string $column, string $value): self
    {
        return $this->addWhere('basic', $column, 'LIKE', $value);
    }

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = ['type' => 'raw', 'sql' => $sql, 'bindings' => array_values($bindings)];

        return $this;
    }

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $type = strtoupper($type) === 'LEFT' ? 'LEFT JOIN' : 'INNER JOIN';
        $this->joinClause = sprintf(
            '%s %s ON %s %s %s',
            $type,
            $this->quoteTable($table),
            $this->quoteIdentifier($first),
            $operator,
            $this->quoteIdentifier($second),
        );

        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function limit(?int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function offset(?int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    public function paginate(int $perPage, int $page): self
    {
        $page = max(1, $page);

        return $this->limit($perPage)->offset(($page - 1) * $perPage);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        return $this->db->select($this->toSelectSql(), $this->bindings());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $this->limit(1);

        return $this->db->selectOne($this->toSelectSql(), $this->bindings());
    }

    public function count(string $column = '*'): int
    {
        $builder = clone $this;
        $builder->columns = ["COUNT({$column}) AS aggregate"];
        $builder->orders = [];
        $builder->limit = null;
        $builder->offset = null;
        $builder->groups = [];

        $row = $this->db->selectOne($builder->toSelectSql(), $builder->bindings());

        return (int) ($row['aggregate'] ?? 0);
    }

    public function exists(): bool
    {
        return $this->first() !== null;
    }

    public function sum(string $column): float
    {
        $builder = clone $this;
        $builder->columns = ["COALESCE(SUM({$column}), 0) AS aggregate"];
        $builder->orders = [];

        $row = $this->db->selectOne($builder->toSelectSql(), $builder->bindings());

        return (float) ($row['aggregate'] ?? 0);
    }

    public function insert(array $data): int
    {
        [$sql, $bindings] = $this->buildInsert($data);

        $this->db->execute($sql, $bindings);

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function update(array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $assignments = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $assignments[] = $this->quoteIdentifier($column) . ' = ?';
            $bindings[] = $this->normalize($value);
        }

        [$whereSql, $whereBindings] = $this->compileWheres();

        $sql = sprintf(
            'UPDATE %s SET %s%s',
            $this->quoteTable($this->table),
            implode(', ', $assignments),
            $whereSql,
        );

        return $this->db->execute($sql, [...$bindings, ...$whereBindings]);
    }

    public function delete(): int
    {
        [$whereSql, $whereBindings] = $this->compileWheres();

        return $this->db->execute(
            sprintf('DELETE FROM %s%s', $this->quoteTable($this->table), $whereSql),
            $whereBindings,
        );
    }

    /**
     * Insert or update a single row by a unique column (upsert), portable
     * across the three supported drivers.
     */
    public function upsert(array $data, string $uniqueColumn): int
    {
        $existing = (clone $this)->where($uniqueColumn, $data[$uniqueColumn] ?? null)->first();

        if ($existing === null) {
            return $this->insert($data);
        }

        $this->where($uniqueColumn, $data[$uniqueColumn]);
        $this->update($data);

        return (int) $existing['id'];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildInsert(array $data): array
    {
        $columns = array_keys($data);
        $quotedColumns = array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteTable($this->table),
            implode(', ', $quotedColumns),
            implode(', ', $placeholders),
        );

        return [$sql, array_map(fn (mixed $value): mixed => $this->normalize($value), array_values($data))];
    }

    public function toSelectSql(): string
    {
        $sql = sprintf(
            'SELECT %s FROM %s',
            implode(', ', $this->columns),
            $this->quoteTable($this->table),
        );

        if ($this->joinClause !== null) {
            $sql .= ' ' . $this->joinClause;
        }

        [$whereSql] = $this->compileWheres();
        $sql .= $whereSql;

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(
                fn (string $column): string => $this->quoteIdentifier($column),
                $this->groups,
            ));
        }

        if ($this->orders !== []) {
            $orders = array_map(
                fn (array $order): string => $this->quoteIdentifier($order['column']) . ' ' . $order['direction'],
                $this->orders,
            );
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . (int) $this->limit;
        }

        if ($this->offset !== null) {
            // MySQL requires LIMIT before OFFSET; both dialects accept a large LIMIT.
            if ($this->limit === null) {
                $sql .= ' LIMIT 18446744073709551615';
            }

            $sql .= ' OFFSET ' . (int) $this->offset;
        }

        return $sql;
    }

    /**
     * @return array<int, mixed>
     */
    public function bindings(): array
    {
        [, $bindings] = $this->compileWheres();

        return $bindings;
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function compileWheres(): array
    {
        if ($this->wheres === []) {
            return ['', []];
        }

        $clauses = [];
        $bindings = [];

        foreach ($this->wheres as $where) {
            switch ($where['type']) {
                case 'basic':
                    $clauses[] = sprintf(
                        '%s %s ?',
                        $this->quoteIdentifier((string) $where['column']),
                        $where['operator'],
                    );
                    $bindings[] = $this->normalize($where['value']);
                    break;
                case 'in':
                    $placeholders = implode(', ', array_fill(0, count($where['value']), '?'));
                    $clauses[] = sprintf('%s IN (%s)', $this->quoteIdentifier($where['column']), $placeholders);
                    foreach ($where['value'] as $value) {
                        $bindings[] = $this->normalize($value);
                    }
                    break;
                case 'null':
                    $clauses[] = $this->quoteIdentifier($where['column']) . ' IS NULL';
                    break;
                case 'notnull':
                    $clauses[] = $this->quoteIdentifier($where['column']) . ' IS NOT NULL';
                    break;
                case 'raw':
                    $clauses[] = '(' . $where['sql'] . ')';
                    foreach ($where['bindings'] as $value) {
                        $bindings[] = $this->normalize($value);
                    }
                    break;
            }
        }

        return [' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    private function addWhere(string $type, string $column, string $operator, mixed $value): self
    {
        $this->wheres[] = [
            'type' => $type,
            'column' => $column,
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    private function quoteTable(string $table): string
    {
        if (str_contains($table, ' ')) {
            // Allow "table alias" segments while still quoting each side.
            $parts = preg_split('/\s+/', trim($table)) ?: [$table];

            return implode(' ', array_map(fn (string $part): string => $this->quoteTable($part), $parts));
        }

        if (str_contains($table, '.')) {
            return implode('.', array_map(
                fn (string $part): string => $this->quoteIdentifier($part),
                explode('.', $table),
            ));
        }

        return $this->quoteIdentifier($table);
    }

    private function quoteIdentifier(string $identifier): string
    {
        // Support function calls / aliases written directly in a column slot.
        if (preg_match('/[^A-Za-z0-9_.*]/', $identifier) === 1) {
            return $identifier;
        }

        $quote = $this->db->driver() === 'mysql' ? '`' : '"';

        $parts = explode('.', $identifier);

        return implode('.', array_map(
            fn (string $part): string => $part === '*' ? '*' : $quote . $part . $quote,
            $parts,
        ));
    }

    private function normalize(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }
}
