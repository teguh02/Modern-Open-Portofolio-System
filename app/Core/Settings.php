<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * Key/value settings stored in the `settings` table, falling back to
 * config/config.php defaults when a row is missing.
 *
 * This fallback is what makes a fresh install behave correctly: no seeding
 * code runs, the Section Registry defaults just win until an admin toggles
 * something.
 */
final class Settings
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private Database $db, private Config $config)
    {
    }

    public function get(string $key, mixed $default = null): ?string
    {
        $all = $this->all();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        $fromConfig = $this->config->get("settings.{$key}");

        if ($fromConfig !== null) {
            return (string) $fromConfig;
        }

        return $default === null ? null : (string) $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    /**
     * @return array<int, string>
     */
    public function json(string $key, array $default = []): array
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    public function set(string $key, mixed $value): void
    {
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        if (is_array($value)) {
            $value = json_encode(array_values($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $value = (string) $value;

        $existing = $this->db->table('settings')->where('key', $key)->first();

        if ($existing === null) {
            $this->db->table('settings')->insert(['key' => $key, 'value' => $value]);
        } else {
            $this->db->table('settings')->where('key', $key)->update(['value' => $value]);
        }

        $this->cache = null;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setMany(array $values): void
    {
        $this->db->transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                $this->set((string) $key, $value);
            }
        });
    }

    public function forget(string $key): void
    {
        $this->db->table('settings')->where('key', $key)->delete();
        $this->cache = null;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (!$this->db->tableExists('settings')) {
            return $this->cache = [];
        }

        $rows = $this->db->table('settings')->get();
        $values = [];

        foreach ($rows as $row) {
            $values[(string) $row['key']] = (string) $row['value'];
        }

        return $this->cache = $values;
    }

    public function flush(): void
    {
        $this->cache = null;
    }
}
