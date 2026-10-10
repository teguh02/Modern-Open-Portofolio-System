<?php

declare(strict_types=1);

use Mops\Core\Env;

return [
    'app' => [
        'name' => Env::get('APP_NAME', 'MOPS'),
        'env' => Env::get('APP_ENV', 'production'),
        'debug' => Env::bool('APP_DEBUG', false),
        'url' => rtrim((string) Env::get('APP_URL', 'http://localhost'), '/'),
        'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
        'key' => Env::get('APP_KEY', ''),
    ],

    'database' => [
        'driver' => Env::get('DB_DRIVER', 'sqlite'),
        'database' => Env::get('DB_DATABASE', 'storage/database.sqlite'),
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => (int) Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'mops'),
        'user' => Env::get('DB_USER', ''),
        'password' => Env::get('DB_PASSWORD', ''),
    ],

    'session' => [
        'name' => Env::get('SESSION_NAME', 'mops_session'),
        'lifetime' => (int) Env::get('SESSION_LIFETIME', '7200'),
    ],

    'uploads' => [
        'max_mb' => (int) Env::get('UPLOAD_MAX_MB', '8'),
        'image_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'],
        'image_mimes' => [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/avif',
        ],
        'document_extensions' => ['pdf'],
        'document_mimes' => ['application/pdf'],
    ],

    'paths' => [
        'storage' => 'storage',
        'uploads' => 'storage/uploads',
        'cache' => 'storage/cache',
        'logs' => 'storage/logs',
        'backups' => 'storage/backups',
        'themes' => 'themes',
        'plugins' => 'plugins',
        'migrations' => 'database/migrations',
    ],

    // Every setting that exists on a fresh install. Missing rows fall back to
    // these values, which is what makes a fresh install show only identity + bio.
    'settings' => [
        'site_name' => 'MOPS',
        'site_tagline' => 'Portfolio',
        'site_description' => '',
        'locale' => 'id',
        'active_theme' => 'vantablo',
        'active_plugins' => '[]',
        'posts_per_page' => '10',
        'installed_at' => '',
    ],
];
