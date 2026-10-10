<?php

declare(strict_types=1);

namespace Mops\Core;

use RuntimeException;

/**
 * Runs database/migrations/*.php in filename order and records them in the
 * `migrations` table with a batch number.
 */
final class Migrator
{
    public function __construct(private Database $db, private string $directory)
    {
    }

    /**
     * @return array<int, string> names of migrations that ran
     */
    public function run(): array
    {
        $this->ensureRepository();

        $applied = $this->applied();
        $batch = $this->nextBatch();
        $ran = [];

        foreach ($this->migrationFiles() as $name => $path) {
            if (in_array($name, $applied, true)) {
                continue;
            }

            $migration = $this->load($path);

            $this->db->transactionIfNeeded(function () use ($migration, $name, $batch): void {
                $migration->boot($this->db)->up();
                $this->db->table('migrations')->insert([
                    'migration' => $name,
                    'batch' => $batch,
                    'run_at' => date('Y-m-d H:i:s'),
                ]);
            });

            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * Roll back the most recent batch.
     *
     * @return array<int, string>
     */
    public function rollback(): array
    {
        $this->ensureRepository();

        $lastBatch = (int) ($this->db->table('migrations')->select('MAX(batch) AS batch')->first()['batch'] ?? 0);

        if ($lastBatch === 0) {
            return [];
        }

        $rows = $this->db->table('migrations')->where('batch', $lastBatch)->get();
        $files = $this->migrationFiles();
        $rolledBack = [];

        foreach (array_reverse($rows) as $row) {
            $name = (string) $row['migration'];

            if (!isset($files[$name])) {
                continue;
            }

            $migration = $this->load($files[$name]);

            $this->db->transactionIfNeeded(function () use ($migration, $name): void {
                $migration->boot($this->db)->down();
                $this->db->table('migrations')->where('migration', $name)->delete();
            });

            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    /**
     * @return array<int, string>
     */
    public function applied(): array
    {
        if (!$this->db->tableExists('migrations')) {
            return [];
        }

        return array_map(
            static fn (array $row): string => (string) $row['migration'],
            $this->db->table('migrations')->orderBy('id')->get(),
        );
    }

    /**
     * @return array<int, string>
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_diff(array_keys($this->migrationFiles()), $applied));
    }

    public function isInstalled(): bool
    {
        return $this->db->tableExists('users') && $this->db->table('users')->count() > 0;
    }

    /**
     * @return array<string, string> migration name => absolute path
     */
    private function migrationFiles(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $files = glob(rtrim($this->directory, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);

        $map = [];

        foreach ($files as $file) {
            $map[pathinfo($file, PATHINFO_FILENAME)] = $file;
        }

        return $map;
    }

    private function load(string $path): Migration
    {
        $migration = require $path;

        if (!$migration instanceof Migration) {
            throw new RuntimeException("Migration {$path} must return an instance of " . Migration::class);
        }

        return $migration;
    }

    private function ensureRepository(): void
    {
        if ($this->db->tableExists('migrations')) {
            return;
        }

        (new Schema($this->db))->create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
            $table->datetime('run_at');
        });
    }

    private function nextBatch(): int
    {
        $row = $this->db->table('migrations')->select('MAX(batch) AS batch')->first();

        return (int) ($row['batch'] ?? 0) + 1;
    }
}
