<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * Base class for migration files in database/migrations/.
 *
 * Each file returns an anonymous subclass:
 *
 *   return new class extends Mops\Core\Migration {
 *       public function up(): void
 *       {
 *           $this->create('projects', function (Blueprint $t) { ... });
 *       }
 *   };
 */
abstract class Migration
{
    private ?Schema $schema = null;

    private ?Database $database = null;

    final public function boot(Database $database): static
    {
        $this->database = $database;
        $this->schema = new Schema($database);

        return $this;
    }

    abstract public function up(): void;

    public function down(): void
    {
        // Optional: most installs never roll back.
    }

    /**
     * @param callable(Blueprint): void $definition
     */
    protected function create(string $table, callable $definition): void
    {
        $this->schema()->create($table, $definition);
    }

    /**
     * @param callable(Blueprint): void $definition
     */
    protected function table(string $table, callable $definition): void
    {
        $this->schema()->table($table, $definition);
    }

    protected function drop(string $table): void
    {
        $this->schema()->dropIfExists($table);
    }

    protected function db(): Database
    {
        return $this->database ?? throw new \RuntimeException('Migration was not booted.');
    }

    protected function schema(): Schema
    {
        return $this->schema ?? throw new \RuntimeException('Migration was not booted.');
    }
}
