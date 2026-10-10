<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * Portable column definitions. Type names are translated per driver by Schema,
 * so migrations stay free of dialect-specific type names.
 */
final class Blueprint
{
    /** @var array<int, array<string, mixed>> */
    private array $columns = [];

    /** @var array<int, array{columns: array<int, string>, unique: bool}> */
    private array $indexes = [];

    public function __construct(private string $table, private string $driver)
    {
    }

    public function table(): string
    {
        return $this->table;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function id(string $name = 'id'): self
    {
        return $this->addColumn($name, 'id');
    }

    public function foreignId(string $name): self
    {
        return $this->addColumn($name, 'foreignId');
    }

    public function string(string $name, int $length = 255): self
    {
        return $this->addColumn($name, 'string', ['length' => $length]);
    }

    public function text(string $name): self
    {
        return $this->addColumn($name, 'text');
    }

    public function integer(string $name): self
    {
        return $this->addColumn($name, 'integer');
    }

    public function bigInteger(string $name): self
    {
        return $this->addColumn($name, 'bigInteger');
    }

    public function boolean(string $name): self
    {
        return $this->addColumn($name, 'boolean');
    }

    public function date(string $name): self
    {
        return $this->addColumn($name, 'date');
    }

    public function datetime(string $name): self
    {
        return $this->addColumn($name, 'datetime');
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): self
    {
        return $this->addColumn($name, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function json(string $name): self
    {
        return $this->addColumn($name, 'json');
    }

    public function timestamps(): self
    {
        $this->datetime('created_at')->nullable();
        $this->datetime('updated_at')->nullable();

        return $this;
    }

    public function nullable(bool $nullable = true): self
    {
        return $this->modify('nullable', $nullable);
    }

    public function default(mixed $value): self
    {
        return $this->modify('default', $value);
    }

    public function index(string ...$columns): self
    {
        $this->indexes[] = [
            'columns' => $columns === [] ? [$this->lastColumnName()] : array_values($columns),
            'unique' => false,
        ];

        return $this;
    }

    public function unique(string ...$columns): self
    {
        $this->indexes[] = [
            'columns' => $columns === [] ? [$this->lastColumnName()] : array_values($columns),
            'unique' => true,
        ];

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<int, array{columns: array<int, string>, unique: bool}>
     */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function addColumn(string $name, string $type, array $arguments = []): self
    {
        $this->columns[] = [
            'name' => $name,
            'type' => $type,
            'arguments' => $arguments,
            'nullable' => false,
            'default' => null,
            'has_default' => false,
        ];

        return $this;
    }

    private function modify(string $key, mixed $value): self
    {
        $index = count($this->columns) - 1;

        if ($index < 0) {
            throw new \LogicException('Call a column method before a modifier.');
        }

        if ($key === 'default') {
            $this->columns[$index]['has_default'] = true;
        }

        $this->columns[$index][$key] = $value;

        return $this;
    }

    private function lastColumnName(): string
    {
        $column = end($this->columns);

        return is_array($column) ? (string) $column['name'] : 'id';
    }
}
