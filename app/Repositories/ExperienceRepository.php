<?php

declare(strict_types=1);

namespace Mops\Repositories;

/**
 * Work history. Ordered newest first (spec: start_date DESC).
 */
final class ExperienceRepository extends Repository
{
    protected function table(): string
    {
        return 'experiences';
    }

    protected function defaultOrder(): array
    {
        return ['start_date', 'DESC'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        return parent::create($this->encodeHighlights($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        return parent::update($id, $this->encodeHighlights($data));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function decorate(array $row): array
    {
        $row = parent::decorate($row);

        $row['is_current'] = (int) ($row['is_current'] ?? 0) === 1;
        $row['highlights'] = $this->decodeList($row['highlights'] ?? null);
        $row['period'] = $this->period($row['start_date'] ?? null, $row['end_date'] ?? null, $row['is_current']);
        $row['duration'] = $this->duration($row['start_date'] ?? null, $row['end_date'] ?? null, $row['is_current']);

        return $row;
    }

    /**
     * Highlights are stored as JSON text so the column stays portable.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function encodeHighlights(array $data): array
    {
        if (isset($data['highlights']) && is_array($data['highlights'])) {
            $data['highlights'] = json_encode(
                array_values($data['highlights']),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function period(?string $start, ?string $end, bool $current): string
    {
        return \Mops\Core\Str::dateRange($start, $current ? null : $end, 'Sekarang');
    }

    private function duration(?string $start, ?string $end, bool $current): string
    {
        if ($start === null || $start === '') {
            return '';
        }

        $from = strtotime($start);
        $to = $current || $end === null || $end === '' ? time() : strtotime($end);

        if ($from === false || $to === false || $to < $from) {
            return '';
        }

        $months = (int) round(($to - $from) / (30 * 24 * 60 * 60));

        if ($months < 12) {
            return max(1, $months) . ' bln';
        }

        $years = intdiv($months, 12);
        $rest = $months % 12;

        return $rest === 0 ? "{$years} thn" : "{$years} thn {$rest} bln";
    }
}
