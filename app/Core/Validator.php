<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * Rule-based input validation: 'title' => 'required|max:120'.
 *
 * Supported rules: required, nullable, string, email, url, integer, numeric,
 * boolean, date, in:list, min:n, max:n, confirmed, slug, mimes:png,jpg.
 */
final class Validator
{
    /** @var array<string, array<int, string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     */
    public function __construct(
        private array $data,
        private array $rules,
        private array $labels = [],
    ) {
        $this->run();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<int, string>
     */
    public function errorList(): array
    {
        $messages = [];

        foreach ($this->errors as $fieldErrors) {
            foreach ($fieldErrors as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public function firstError(): ?string
    {
        $list = $this->errorList();

        return $list[0] ?? null;
    }

    /**
     * Only the keys that were present in the rules, normalised.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    public function value(string $field): mixed
    {
        return $this->validated[$field] ?? null;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            $label = $this->labels[$field] ?? str_replace('_', ' ', ucfirst($field));
            $nullable = in_array('nullable', $rules, true);
            $isBlank = $value === null || $value === '' || (is_array($value) && $value === []);

            if ($isBlank && $nullable) {
                // Typed columns become NULL; text columns keep an empty string so a
                // NOT NULL varchar with a default is never fed a NULL.
                $this->validated[$field] = array_intersect(['date', 'integer', 'numeric', 'boolean'], $rules) === []
                    ? ''
                    : null;
                continue;
            }

            if (!$this->applyRules($field, $label, $value, $rules)) {
                continue;
            }

            $this->validated[$field] = $this->normalize($value, $rules);
        }
    }

    /**
     * @param array<int, string> $rules
     */
    private function applyRules(string $field, string $label, mixed $value, array $rules): bool
    {
        $ok = true;
        $blank = $value === null || $value === '' || (is_array($value) && $value === []);

        foreach ($rules as $rule) {
            if ($rule === '' || in_array($rule, ['nullable', 'string'], true)) {
                continue;
            }

            [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

            // A missing value should report "wajib diisi" only — not also fail
            // every type rule that follows it.
            if ($blank && $name !== 'required') {
                continue;
            }

            $passed = match ($name) {
                'required' => !($value === null || $value === '' || (is_array($value) && $value === [])),
                'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false,
                'url' => $this->isUrl((string) $value),
                'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
                'numeric' => is_numeric($value),
                'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'on', 'off'], true),
                'date' => $this->isDate((string) $value),
                'in' => in_array((string) $value, explode(',', (string) $parameter), true),
                'slug' => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value) === 1,
                'min' => mb_strlen((string) $value) >= (int) $parameter,
                'max' => mb_strlen((string) $value) <= (int) $parameter,
                'confirmed' => ($this->data["{$field}_confirmation"] ?? null) === $value,
                default => true,
            };

            if (!$passed) {
                $message = match ($name) {
                    'required' => "{$label} wajib diisi.",
                    'email' => "{$label} harus berupa email yang valid.",
                    'url' => "{$label} harus berupa URL yang valid.",
                    'integer' => "{$label} harus berupa angka bulat.",
                    'numeric' => "{$label} harus berupa angka.",
                    'boolean' => "{$label} harus berupa ya/tidak.",
                    'date' => "{$label} harus berupa tanggal yang valid.",
                    'in' => "{$label} berisi nilai yang tidak dikenal.",
                    'slug' => "{$label} hanya boleh huruf kecil, angka, dan tanda hubung.",
                    'min' => "{$label} minimal {$parameter} karakter.",
                    'max' => "{$label} maksimal {$parameter} karakter.",
                    'confirmed' => "Konfirmasi {$label} tidak cocok.",
                    default => "{$label} tidak valid.",
                };

                $this->errors[$field][] = $message;
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @param array<int, string> $rules
     */
    private function normalize(mixed $value, array $rules): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (in_array('integer', $rules, true) && $value !== null && $value !== '') {
            return (int) $value;
        }

        if (in_array('numeric', $rules, true) && $value !== null && $value !== '') {
            return (float) $value;
        }

        if (in_array('boolean', $rules, true)) {
            return in_array($value, [true, 1, '1', 'on', 'true', 'yes'], true) ? 1 : 0;
        }

        if ($value === '' || $value === null) {
            // Only typed columns become NULL: a varchar column that defaults to an
            // empty string should stay an empty string, not NULL.
            $typed = ['date', 'integer', 'numeric', 'boolean'];

            return array_intersect($typed, $rules) === [] ? '' : null;
        }

        return $value;
    }

    private function isDate(string $value): bool
    {
        $timestamp = strtotime($value);

        return $timestamp !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1;
    }

    private function isUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        // Allow root-relative links in content fields.
        return str_starts_with($value, '/');
    }
}
