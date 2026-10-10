<?php

declare(strict_types=1);

namespace Mops\Core;

use RuntimeException;

/**
 * Dot-notation config repository: Config::get('app.debug').
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $items;

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Load a config file that returns an array.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Config file not found: {$path}");
        }

        /** @var array<string, mixed> $items */
        $items = require $path;

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->items;

        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Resolve a configured path against the project root.
     */
    public function path(string $key): string
    {
        $relative = (string) $this->get("paths.{$key}", $key);

        return Paths::make($relative);
    }
}
