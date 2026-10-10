<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * Small string + date helpers shared by controllers, repositories and themes.
 */
final class Str
{
    public static function slug(string $value, string $fallback = 'item'): string
    {
        $value = trim($value);

        // Transliterate common accented characters before stripping.
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
            $value = $converted === false ? $value : $converted;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value === '' ? $fallback : substr($value, 0, 191);
    }

    public static function limit(string $value, int $characters = 160, string $end = '…'): string
    {
        $value = trim(strip_tags($value));

        if (mb_strlen($value) <= $characters) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $characters, 'UTF-8')) . $end;
    }

    /**
     * Drop the emptiest tags left behind by a rich-text editor.
     */
    public static function cleanHtml(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return trim(preg_replace('/<p>\s*(&nbsp;|\s)*<\/p>/', '', $html) ?? $html);
    }

    /**
     * Very small allow-list sanitiser for editor output. Author-only content
     * still gets filtered so a stray paste cannot inject scripts.
     */
    public static function sanitizeHtml(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $allowed = '<p><br><strong><b><em><i><u><s><ul><ol><li><a><h2><h3><h4><blockquote><code><pre><img><figure><figcaption><span><hr><table><thead><tbody><tr><th><td>';

        $html = strip_tags($html, $allowed);
        // Remove inline event handlers and javascript: URLs.
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/javascript\s*:/i', '', $html) ?? $html;

        return trim($html);
    }

    public static function excerpt(string $html, int $characters = 180): string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($html)) ?? '';

        return self::limit(trim($text), $characters);
    }

    public static function readingTime(string $html): int
    {
        $words = str_word_count(strip_tags($html));

        return max(1, (int) ceil($words / 200));
    }

    public static function date(?string $value, string $format = 'M Y', string $locale = 'id'): string
    {
        if ($value === null || $value === '' || $value === '0000-00-00') {
            return '';
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return '';
        }

        $months = [
            'id' => [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
            'en' => [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        ];

        $monthsFull = [
            'id' => [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
            'en' => [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        ];

        $month = (int) date('n', $timestamp);
        $table = $months[$locale] ?? $months['en'];
        $tableFull = $monthsFull[$locale] ?? $monthsFull['en'];

        // The month name itself contains date() format characters ("Mar" is M, a, r),
        // so escape every character before the format string reaches date().
        // Only unescaped tokens are replaced, which keeps `\M` a literal "M".
        $output = preg_replace('/(?<!\\\\)F/', self::escapeFormat($tableFull[$month]), $format) ?? $format;
        $output = preg_replace('/(?<!\\\\)M/', self::escapeFormat($table[$month]), $output) ?? $output;

        return date($output, $timestamp);
    }

    /**
     * Escape every character so date() prints the text verbatim.
     */
    private static function escapeFormat(string $text): string
    {
        return implode('', array_map(
            static fn (string $character): string => '\\' . $character,
            str_split($text),
        ));
    }

    public static function dateRange(?string $start, ?string $end, string $present = 'Sekarang'): string
    {
        $from = self::date($start, 'M Y');

        if ($start === null || $start === '') {
            return '';
        }

        if ($end === null || $end === '') {
            return $from . ' — ' . $present;
        }

        return $from . ' — ' . self::date($end, 'M Y');
    }

    /**
     * @param array<int, string>|string|null $value
     * @return array<int, string>
     */
    public static function toList(array|string|null $value, string $separator = ','): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = explode($separator, $value);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }
}
