<?php

declare(strict_types=1);

namespace Mops\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO connection factory. Repositories never build DSNs or poke at driver
 * specifics — they go through Database::table() (portable query builder).
 */
final class Database
{
    private ?PDO $pdo = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config)
    {
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'sqlite');
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = $this->driver();

        try {
            $this->pdo = match ($driver) {
                'sqlite' => new PDO('sqlite:' . $this->sqlitePath(), null, null, $this->options()),
                'mysql' => new PDO(
                    sprintf(
                        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                        $this->config['host'],
                        (int) $this->config['port'],
                        $this->config['name'],
                    ),
                    (string) $this->config['user'],
                    (string) $this->config['password'],
                    $this->options(),
                ),
                'pgsql' => new PDO(
                    sprintf(
                        'pgsql:host=%s;port=%d;dbname=%s',
                        $this->config['host'],
                        (int) $this->config['port'],
                        $this->config['name'],
                    ),
                    (string) $this->config['user'],
                    (string) $this->config['password'],
                    $this->options(),
                ),
                default => throw new RuntimeException("Unsupported database driver: {$driver}"),
            };
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return $this->pdo;
    }

    public function sqlitePath(): string
    {
        $path = (string) $this->config['database'];

        if ($path === ':memory:') {
            return $path;
        }

        $absolute = Paths::make($path);
        Paths::ensure(dirname($absolute));

        return $absolute;
    }

    public function isSqlite(): bool
    {
        return $this->driver() === 'sqlite';
    }

    /**
     * Start a portable query on a table.
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * @param callable(QueryBuilder): mixed $callback
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Run a callback inside a transaction, reusing an active one if present
     * (the installer wraps migrations, and PDO has no nested transactions).
     */
    public function transactionIfNeeded(callable $callback): mixed
    {
        if ($this->pdo()->inTransaction()) {
            return $callback($this);
        }

        return $this->transaction($callback);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastInsertId(string $table, string $primaryKey = 'id'): ?array
    {
        return $this->table($table)->where($primaryKey, (int) $this->pdo()->lastInsertId())->first();
    }

    public function tableExists(string $table): bool
    {
        try {
            if ($this->isSqlite()) {
                $row = $this->selectOne(
                    "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                    [$table],
                );
            } elseif ($this->driver() === 'mysql') {
                $row = $this->selectOne('SHOW TABLES LIKE ?', [$table]);
            } else {
                $row = $this->selectOne(
                    'SELECT table_name FROM information_schema.tables WHERE table_name = ?',
                    [$table],
                );
            }
        } catch (PDOException) {
            return false;
        }

        return $row !== null;
    }

    /**
     * Column names of a table, resolved per driver so repositories never embed
     * driver-specific introspection SQL.
     *
     * @return array<int, string>
     */
    public function tableColumns(string $table): array
    {
        try {
            if ($this->isSqlite()) {
                $rows = $this->select('PRAGMA table_info(' . $this->quoteForDriver($table) . ')');

                return array_map(static fn (array $row): string => (string) $row['name'], $rows);
            }

            if ($this->driver() === 'mysql') {
                $rows = $this->select(sprintf('SHOW COLUMNS FROM %s', $this->quoteForDriver($table)));

                return array_map(
                    static fn (array $row): string => (string) ($row['Field'] ?? $row['field'] ?? ''),
                    $rows,
                );
            }

            $rows = $this->select(
                'SELECT column_name FROM information_schema.columns WHERE table_name = ?',
                [$table],
            );

            return array_map(
                static fn (array $row): string => (string) ($row['column_name'] ?? $row['COLUMN_NAME'] ?? ''),
                $rows,
            );
        } catch (PDOException) {
            return [];
        }
    }

    public function quoteForDriver(string $identifier): string
    {
        $quote = $this->driver() === 'mysql' ? '`' : '"';

        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($this->driver() === 'sqlite') {
            $options[PDO::ATTR_CASE] = PDO::CASE_NATURAL;
        }

        return $options;
    }
}
