<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * WordPress-style hook system. Plugins and themes extend MOPS through these
 * three calls only — never by editing core files.
 */
final class Hook
{
    /** @var array<string, array<int, array{callback: callable, priority: int}>> */
    private static array $actions = [];

    /** @var array<string, array<int, array{callback: callable, priority: int}>> */
    private static array $filters = [];

    /** @var array<string, int> */
    private static array $fired = [];

    public static function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        self::$actions[$hook][] = ['callback' => $callback, 'priority' => $priority];
        self::sortQueue($hook, self::$actions);
    }

    public static function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        self::$filters[$hook][] = ['callback' => $callback, 'priority' => $priority];
        self::sortQueue($hook, self::$filters);
    }

    public static function removeAction(string $hook, callable $callback): void
    {
        self::remove($hook, $callback, self::$actions);
    }

    public static function removeFilter(string $hook, callable $callback): void
    {
        self::remove($hook, $callback, self::$filters);
    }

    /**
     * Run every listener for a hook.
     */
    public static function doAction(string $hook, mixed ...$args): int
    {
        self::$fired[$hook] = (self::$fired[$hook] ?? 0) + 1;

        foreach (self::$actions[$hook] ?? [] as $listener) {
            ($listener['callback'])(...$args);
        }

        return count(self::$actions[$hook] ?? []);
    }

    /**
     * Pass a value through every listener, each receiving the previous result.
     */
    public static function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach (self::$filters[$hook] ?? [] as $listener) {
            $value = ($listener['callback'])($value, ...$args);
        }

        return $value;
    }

    public static function hasAction(string $hook): bool
    {
        return (self::$actions[$hook] ?? []) !== [];
    }

    public static function hasFilter(string $hook): bool
    {
        return (self::$filters[$hook] ?? []) !== [];
    }

    public static function didAction(string $hook): bool
    {
        return isset(self::$fired[$hook]);
    }

    public static function firedCount(string $hook): int
    {
        return self::$fired[$hook] ?? 0;
    }

    /**
     * @return array<int, string>
     */
    public static function registeredHooks(): array
    {
        $names = array_unique([...array_keys(self::$actions), ...array_keys(self::$filters)]);
        sort($names);

        return $names;
    }

    /**
     * Test helper: drop every registered listener.
     */
    public static function reset(): void
    {
        self::$actions = [];
        self::$filters = [];
        self::$fired = [];
    }

    /**
     * @param array<string, array<int, array{callback: callable, priority: int}>> $queue
     */
    private static function sortQueue(string $hook, array &$queue): void
    {
        usort(
            $queue[$hook],
            static fn (array $a, array $b): int => $a['priority'] <=> $b['priority'],
        );
    }

    /**
     * @param array<string, array<int, array{callback: callable, priority: int}>> $queue
     */
    private static function remove(string $hook, callable $callback, array &$queue): void
    {
        if (!isset($queue[$hook])) {
            return;
        }

        $queue[$hook] = array_values(array_filter(
            $queue[$hook],
            static fn (array $listener): bool => $listener['callback'] !== $callback,
        ));
    }
}
