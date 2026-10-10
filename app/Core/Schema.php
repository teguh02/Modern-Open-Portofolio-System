<?php

declare(strict_types=1);

namespace Mops\Core;

use RuntimeException;

/**
 * Portable schema builder. Every driver-specific type name or DDL nuance lives
 * here so migrations and repositories never contain SQLite-only SQL.
 */
final class Schema
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param callable(Blueprint): void $definition
     */
    public function create(string $table, callable $definition): void
    {
        $blueprint = new Blueprint($table, $this->db->driver());
        $definition($blueprint);

        $definitions = [];

        foreach ($blueprint->columns() as $column) {
            $definitions[] = $this->columnSql($blueprint, $column);
        }

        $sql = sprintf(
            'CREATE TABLE %s (%s)',
            $this->quote($table),
            implode(', ', $definitions),
        );

        $this->db->execute($sql);

        foreach ($blueprint->indexes() as $index) {
            $this->createIndex($table, $index['columns'], $index['unique']);
        }
    }

    /**
     * Add columns to an existing table.
     *
     * @param callable(Blueprint): void $definition
     */
    public function table(string $table, callable $definition): void
    {
        $blueprint = new Blueprint($table, $this->db->driver());
        $definition($blueprint);

        foreach ($blueprint->columns() as $column) {
            $sql = sprintf(
                'ALTER TABLE %s ADD COLUMN %s',
                $this->quote($table),
                $this->columnSql($blueprint, $column),
            );

            $this->db->execute($sql);
        }

        foreach ($blueprint->indexes() as $index) {
            $this->createIndex($table, $index['columns'], $index['unique']);
        }
    }

    /**
     * @param array<int, string> $columns
     */
    public function createIndex(string $table, array $columns, bool $unique = false): void
    {
        $name = $table . '_' . implode('_', $columns) . ($unique ? '_unique' : '_index');

        $sql = sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $unique ? 'UNIQUE ' : '',
            $this->quote($name),
            $this->quote($table),
            implode(', ', array_map(fn (string $column): string => $this->quote($column), $columns)),
        );

        $this->db->execute($sql);
    }

    public function drop(string $table): void
    {
        $this->db->execute(sprintf('DROP TABLE %s', $this->quote($table)));
    }

    public function dropIfExists(string $table): void
    {
        $this->db->execute(sprintf('DROP TABLE IF EXISTS %s', $this->quote($table)));
    }

    public function hasTable(string $table): bool
    {
        return $this->db->tableExists($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->db->tableColumns($table), true);
    }

    /**
     * @param array<string, mixed> $column
     */
    private function columnSql(Blueprint $blueprint, array $column): string
    {
        $name = (string) $column['name'];
        $type = (string) $column['type'];
        $arguments = is_array($column['arguments']) ? $column['arguments'] : [];
        $driver = $blueprint->driver();

        $sql = match ($type) {
            'id' => match ($driver) {
                'mysql' => $this->quote($name) . ' BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
                'pgsql' => $this->quote($name) . ' BIGSERIAL PRIMARY KEY',
                default => $this->quote($name) . ' INTEGER PRIMARY KEY AUTOINCREMENT',
            },
            'foreignId' => match ($driver) {
                'mysql' => $this->quote($name) . ' BIGINT UNSIGNED',
                default => $this->quote($name) . ' INTEGER',
            },
            'string' => sprintf(
                '%s VARCHAR(%d)',
                $this->quote($name),
                (int) ($arguments['length'] ?? 255),
            ),
            'text' => $this->quote($name) . ($driver === 'mysql' ? ' TEXT' : ' TEXT'),
            'integer' => $this->quote($name) . ' INTEGER',
            'bigInteger' => $this->quote($name) . ' BIGINT',
            'boolean' => $this->quote($name) . ($driver === 'pgsql' ? ' BOOLEAN' : ' INTEGER'),
            'date' => $this->quote($name) . ' DATE',
            'datetime' => $this->quote($name) . ($driver === 'pgsql' ? ' TIMESTAMP' : ' DATETIME'),
            'decimal' => sprintf(
                '%s DECIMAL(%d, %d)',
                $this->quote($name),
                (int) ($arguments['precision'] ?? 10),
                (int) ($arguments['scale'] ?? 2),
            ),
            'json' => $this->quote($name) . ($driver === 'mysql' ? ' JSON' : ' TEXT'),
            default => throw new RuntimeException("Unknown column type: {$type}"),
        };

        // Auto-increment primary keys never take null/default clauses.
        if ($type === 'id') {
            return $sql;
        }

        if (($column['nullable'] ?? false) === true) {
            $sql .= ' NULL';
        } elseif ($driver === 'sqlite') {
            // SQLite only enforces NOT NULL when it is written explicitly, and
            // ALTER TABLE ADD COLUMN cannot add a NOT NULL column without a default.
            $sql .= ($column['has_default'] ?? false) === true ? ' NOT NULL' : ' NULL';
        } else {
            $sql .= ' NOT NULL';
        }

        if (($column['has_default'] ?? false) === true) {
            $sql .= ' DEFAULT ' . $this->defaultSql($column['default'], $driver);
        }

        return $sql;
    }

    private function defaultSql(mixed $value, string $driver): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $value = str_replace("'", "''", (string) $value);

        return "'{$value}'";
    }

    private function quote(string $identifier): string
    {
        return $this->db->quoteForDriver($identifier);
    }
}
