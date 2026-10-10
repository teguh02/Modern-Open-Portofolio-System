<?php

declare(strict_types=1);

namespace Mops\Repositories;

/**
 * The single profile row (identity + bio). Always id = 1.
 */
final class ProfileRepository extends Repository
{
    /** @var array<int, string> */
    private const JSON_FIELDS = ['social_links', 'focus_areas', 'stats'];

    protected function table(): string
    {
        return 'profile';
    }

    protected function defaultOrder(): array
    {
        return ['id', 'ASC'];
    }

    /**
     * Never returns null: themes always receive a profile array.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $row = $this->find(1);

        if ($row !== null) {
            return $row;
        }

        return $this->defaults();
    }

    /**
     * @return array<string, mixed>
     */
    public function save(array $data): array
    {
        $data = array_merge($this->defaults(), $data);

        foreach (self::JSON_FIELDS as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode(array_values($data[$field]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $existing = $this->find(1);

        if ($existing === null) {
            $row = $this->create($data);
        } else {
            $this->update(1, $data);
            $row = $this->find(1) ?? $existing;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'id' => 1,
            'full_name' => '',
            'headline' => '',
            'short_bio' => '',
            'bio' => '',
            'photo' => null,
            'cover_image' => null,
            'location' => '',
            'email' => '',
            'phone' => '',
            'website' => '',
            'availability' => '',
            'resume_file' => null,
            // Shipped as empty arrays: save() encodes them, decorate() decodes them,
            // so controllers always deal with arrays.
            'focus_areas' => [],
            'social_links' => [],
            'stats' => [],
            'meta_title' => '',
            'meta_description' => '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decorate(array $row): array
    {
        $row = parent::decorate($row);

        foreach (self::JSON_FIELDS as $field) {
            $row[$field] = $this->decodeList($row[$field] ?? null);
        }

        $row['is_complete'] = ($row['full_name'] ?? '') !== '' && ($row['headline'] ?? '') !== '';

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
