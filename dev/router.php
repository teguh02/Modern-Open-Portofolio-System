<?php

declare(strict_types=1);

/**
 * Router for the PHP built-in server: `php -S 127.0.0.1:8000 -t public dev/router.php`.
 * Lives outside public/ so it can never be requested over HTTP.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/../public' . $path;

// Let the built-in server handle real static files (CSS, JS, fonts, images).
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/../public/index.php';
