<?php

declare(strict_types=1);

namespace MopsPlugin\CvAts;

/**
 * Minimal, dependency-free PDF writer for text documents.
 *
 * Why hand-rolled instead of a PDF library: an ATS parser needs plain,
 * selectable text in a single column. This writer emits exactly that — base-14
 * Helvetica with WinAnsi encoding, no images, no tables, no SVG, no
 * compression (so the text stays greppable inside the file). No new Composer
 * dependency, which keeps the plugin self-contained.
 *
 * Layout is intentionally simple: one column, absolute positioning per line.
 * Character widths are approximate Helvetica metrics used only to wrap lines
 * (the viewer supplies its own precise metrics when rendering).
 */
final class PdfWriter
{
    public const PAGE_WIDTH = 595.28;
    public const PAGE_HEIGHT = 841.89;

    /** @var array<int, string> completed page content streams */
    private array $pages = [];

    private string $content = '';

    private float $y;

    private float $marginLeft;

    private float $marginRight;

    private float $marginTop;

    private float $marginBottom;

    /** @var array<string, string> font key => PDF resource name */
    private array $fonts = [
        'regular' => 'F1',
        'bold' => 'F2',
        'italic' => 'F3',
    ];

    public function __construct(
        float $margin = 50.0,
        private string $title = 'Document',
        private string $author = '',
        private string $locale = 'en',
    ) {
        $this->marginLeft = $margin;
        $this->marginRight = $margin;
        $this->marginTop = $margin;
        $this->marginBottom = $margin;
        $this->y = self::PAGE_HEIGHT - $this->marginTop;
    }

    public function textWidth(): float
    {
        return self::PAGE_WIDTH - $this->marginLeft - $this->marginRight;
    }

    public function cursor(): float
    {
        return $this->y;
    }

    /**
     * Wrap and write a paragraph. Embedded newlines create hard line breaks.
     */
    public function paragraph(
        string $text,
        float $size = 10.5,
        string $font = 'regular',
        float $leading = 1.45,
        float $spaceAfter = 5.0,
    ): void {
        $text = trim($this->normalise($text));

        if ($text === '') {
            return;
        }

        foreach (preg_split('/\n+/', $text) ?: [] as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            foreach ($this->wrap($block, $size, $font) as $line) {
                $this->writeLine($line, $size, $font, $leading);
            }
        }

        $this->space($spaceAfter);
    }

    /**
     * Single line without wrapping (truncated when too long).
     */
    public function line(string $text, float $size = 10.5, string $font = 'regular', float $leading = 1.45): void
    {
        $this->writeLine($text, $size, $font, $leading);
    }

    /**
     * Uppercase section heading with a rule underneath — the shape ATS parsers
     * recognise most reliably.
     */
    public function heading(string $text, float $size = 11.5, float $spaceBefore = 12.0): void
    {
        $this->space($spaceBefore);
        $this->writeLine($this->upper($text), $size, 'bold', 1.3);

        $this->y -= 4.0;
        $this->content .= sprintf(
            "0.45 0.45 0.45 RG 0.6 w %.2F %.2F m %.2F %.2F l S\n",
            $this->marginLeft,
            $this->y,
            $this->marginLeft + $this->textWidth(),
            $this->y,
        );
        $this->y -= 10.0;
    }

    /**
     * Bullet list item with a hanging indent (plain "-", the ATS-friendly one).
     */
    public function bullet(string $text, float $size = 10.5, float $leading = 1.45): void
    {
        $text = trim($this->normalise($text));

        if ($text === '') {
            return;
        }

        $indent = 12.0;
        $lines = $this->wrap($text, $size, 'regular', $this->textWidth() - $indent);

        foreach ($lines as $index => $line) {
            $this->writeLine(
                $index === 0 ? '- ' . $line : $line,
                $size,
                'regular',
                $leading,
                $this->marginLeft + ($index === 0 ? 0.0 : $indent),
                $index > 0 ? $indent : 0.0,
            );
        }
    }

    public function spacer(float $height = 6.0): void
    {
        $this->space($height);
    }

    /**
     * Finish the document and return the raw PDF bytes.
     */
    public function output(): string
    {
        $this->finishPage();

        $pageCount = count($this->pages);

        // Object ids: 1 catalog, 2 pages, 3-5 fonts, 6 info, then 2 per page.
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';
        $objects[6] = sprintf(
            '<< /Title (%s) /Author (%s) /Producer (MOPS CV ATS plugin) /CreationDate (%s) >>',
            $this->escape($this->title),
            $this->escape($this->author),
            $this->pdfDate(),
        );

        $kids = [];

        foreach ($this->pages as $index => $stream) {
            $contentId = 7 + ($index * 2);
            $pageId = $contentId + 1;

            $objects[$contentId] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($stream),
                $stream,
            );

            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> /ProcSet [/PDF /Text] >> '
                . '/Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentId,
            );

            $kids[] = $pageId . ' 0 R';
        }

        $objects[2] = sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            implode(' ', $kids),
            $pageCount,
        );

        ksort($objects);

        $total = count($objects) + 1; // + free object 0
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $body);
        }

        $xrefOffset = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n", $total);
        $pdf .= "0000000000 65535 f \n";

        for ($id = 1; $id < $total; $id++) {
            $offset = $offsets[$id] ?? 0;
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info 6 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $total,
            $xrefOffset,
        );

        return $pdf;
    }

    public function pageCount(): int
    {
        return count($this->pages) + ($this->content === '' ? 0 : 1);
    }

    // ------------------------------------------------------------- internals

    private function writeLine(
        string $text,
        float $size,
        string $font,
        float $leading,
        ?float $x = null,
        float $extraIndent = 0.0,
    ): void {
        $this->ensureSpace($size * $leading);

        $resource = $this->fonts[$font] ?? $this->fonts['regular'];
        $positionX = $x ?? $this->marginLeft;

        $this->content .= sprintf(
            "BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $resource,
            $size,
            $positionX + $extraIndent,
            $this->y,
            $this->escape($text),
        );

        $this->y -= $size * $leading;
    }

    private function space(float $height): void
    {
        $this->y -= $height;
    }

    private function ensureSpace(float $needed): void
    {
        if ($this->y - $needed >= $this->marginBottom) {
            return;
        }

        $this->finishPage();
    }

    private function finishPage(): void
    {
        if (trim($this->content) === '') {
            return;
        }

        $this->pages[] = $this->content;
        $this->content = '';
        $this->y = self::PAGE_HEIGHT - $this->marginTop;
    }

    /**
     * @return array<int, string>
     */
    private function wrap(string $text, float $size, string $font, ?float $width = null): array
    {
        $width ??= $this->textWidth();
        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if ($this->measure($candidate, $size, $font) <= $width || $current === '') {
                $current = $candidate;
                continue;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * Approximate Helvetica advance width in points.
     */
    private function measure(string $text, float $size, string $font): float
    {
        $units = 0;

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $units += $this->charWidth($character);
        }

        $factor = $font === 'bold' ? 1.06 : ($font === 'italic' ? 1.02 : 1.0);

        return ($units / 1000) * $size * $factor;
    }

    private function charWidth(string $character): int
    {
        static $widths = [
            ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667,
            "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333,
            '.' => 278, '/' => 278, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584,
            '?' => 556, '@' => 1015, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556,
            '`' => 333, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
            'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
            'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722,
            'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722,
            'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611,
            'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556,
            'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556,
            'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556,
            'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500,
        ];

        if (isset($widths[$character])) {
            return $widths[$character];
        }

        if ($character >= '0' && $character <= '9') {
            return 556;
        }

        // Accented/latin-1 characters are close to their base letter width.
        return 556;
    }

    /**
     * PDF strings are byte-oriented; WinAnsi = CP1252. Transliterate what
     * cannot be represented instead of emitting broken bytes.
     */
    private function escape(string $text): string
    {
        $encoded = @iconv('UTF-8', 'CP1252//TRANSLIT', $text);

        if ($encoded === false) {
            $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }

    private function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function upper(string $text): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    }

    private function pdfDate(): string
    {
        return date('YmdHis');
    }
}
