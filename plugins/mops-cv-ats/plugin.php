<?php

declare(strict_types=1);

/**
 * CV ATS Download — plugin contoh injeksi.
 *
 * Yang ditunjukkan plugin ini, tanpa menyentuh core maupun tema:
 *   - menyuntik tombol ke zona `home_after_sections` (area footer homepage);
 *   - mendaftarkan route publik sendiri (`/cv.pdf`) lewat `routes_registered`;
 *   - memakai payload portofolio terstruktur dari container (`Portfolio`) —
 *     bukan query tabel;
 *   - mengirim template sendiri lewat filter `view_paths`.
 *
 * Generator PDF-nya (`src/PdfWriter.php`) ditulis tangan: teks polos satu
 * kolom, tanpa gambar/tabel/kompresi, jadi filenya tetap bisa dibaca ATS.
 */

use Mops\Core\Hook;
use Mops\Core\Portfolio;
use Mops\Core\View;
use MopsPlugin\CvAts\CvBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

if (!function_exists('mops')) {
    return;
}

$cvAts = __DIR__;

require_once $cvAts . '/src/PdfWriter.php';
require_once $cvAts . '/src/CvBuilder.php';

// Expose this plugin's templates to the Twig loader chain.
Hook::addFilter('view_paths', static function (array $paths) use ($cvAts): array {
    $paths[] = $cvAts . '/views';

    return $paths;
});

/**
 * The public download route: build the PDF from the core portfolio payload.
 */
Hook::addAction('routes_registered', static function ($app) use ($cvAts): void {
    // Not a static closure: Slim binds route handlers to the container, and a
    // static closure cannot be rebound (`bindTo` returns null → TypeError).
    $app->get('/cv.pdf', function (ServerRequestInterface $request, ResponseInterface $response) use ($cvAts): ResponseInterface {
        $builder = new CvBuilder(mops(Portfolio::class)->payload());
        $pdf = $builder->build();

        $stream = fopen('php://temp', 'r+') ?: fopen('php://memory', 'r+');
        fwrite($stream, $pdf);
        rewind($stream);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $builder->filename() . '"')
            ->withHeader('Content-Length', (string) strlen($pdf))
            ->withHeader('Cache-Control', 'private, max-age=0, must-revalidate')
            ->withBody(new \Slim\Psr7\Stream($stream));
    });
});

/**
 * Injection zone #1 — the download button, in the homepage CTA area above the
 * footer. Swap the zone id to move the button elsewhere; the plugin never
 * touches the theme.
 */
Hook::addAction('home_after_sections', static function (array $context = [], array $sections = []): void {
    /** @var bool $enabled */
    $enabled = (bool) apply_filters('cv_ats_button_enabled', true, $context);

    if (!$enabled) {
        return;
    }

    $view = mops(View::class);

    echo $view->renderSafe('cv-ats/button.twig', [
        'download_url' => '/cv.pdf',
        'zone' => 'home_after_sections',
        'hint' => (bool) apply_filters('cv_ats_show_hint', true),
    ]) ?? '';
});

/**
 * Injection zone #2 — a one-line notice in the document footer. It shows a
 * second zone working in the same plugin, and keeps the "homepage only"
 * decision by checking the current path.
 */
Hook::addAction('theme_footer', static function (array $context = []): void {
    if (($context['path'] ?? '/') !== '/') {
        return;
    }

    if (!(bool) apply_filters('cv_ats_footer_note', true)) {
        return;
    }

    printf(
        '<div class="mx-auto max-w-content px-5 pb-6 text-center text-[11px] text-muted">'
        . 'CV PDF ramah ATS tersedia di <a class="underline" href="%s">%s</a></div>',
        e('/cv.pdf'),
        e('/cv.pdf'),
    );
});
