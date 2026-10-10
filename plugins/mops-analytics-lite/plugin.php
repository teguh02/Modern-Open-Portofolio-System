<?php

declare(strict_types=1);

/**
 * Analytics Lite — sample plugin.
 *
 * Demonstrates the MOPS extension contract:
 *   - it never edits core, only registers hooks;
 *   - it owns its own table (created on first load, no core migration needed);
 *   - it ships its own Twig template, registered through the `view_paths` filter;
 *   - it adds an admin dashboard card through `admin_dashboard_widgets`.
 */

use Mops\Core\Blueprint;
use Mops\Core\Database;
use Mops\Core\Hook;
use Mops\Core\Paths;
use Mops\Core\Schema;
use Mops\Core\View;

const MOPS_ANALYTICS_TABLE = 'plugin_analytics_views';

/**
 * The plugin only runs inside a booted MOPS application.
 */
if (!function_exists('mops')) {
    return;
}

// Expose this plugin's templates to the Twig loader chain.
Hook::addFilter('view_paths', static function (array $paths): array {
    $paths[] = __DIR__ . '/views';

    return $paths;
});

// Record a view for every public page render.
Hook::addAction('page_viewed', static function (string $template, array $page = []): void {
    try {
        $db = mops(Database::class);
        ensureTable($db);

        $path = (string) ($page['canonical'] ?? $template);

        $db->table(MOPS_ANALYTICS_TABLE)->insert([
            'path' => $path,
            'template' => $template,
            'viewed_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable) {
        // Analytics must never break a page render.
    }
});

// Dashboard card.
Hook::addAction('admin_dashboard_widgets', static function (): void {
    try {
        $db = mops(Database::class);
        ensureTable($db);

        $since = date('Y-m-d H:i:s', strtotime('-7 days'));

        $total = $db->table(MOPS_ANALYTICS_TABLE)->count();
        $week = $db->table(MOPS_ANALYTICS_TABLE)->whereRaw('viewed_at >= ?', [$since])->count();

        $top = $db->select(
            'SELECT path, COUNT(*) AS hits FROM ' . MOPS_ANALYTICS_TABLE
            . ' GROUP BY path ORDER BY hits DESC LIMIT 5',
        );

        $view = mops(View::class);

        echo $view->renderSafe('analytics-lite/widget.twig', [
            'total' => $total,
            'week' => $week,
            'top' => $top,
        ]) ?? '';
    } catch (Throwable $e) {
        echo '<div class="card card-body text-sm text-red-600">Analytics Lite error: ' . e($e->getMessage()) . '</div>';
    }
});

/**
 * Create the plugin's table on first use (idempotent, no core migration).
 */
function ensureTable(Database $db): void
{
    if ($db->tableExists(MOPS_ANALYTICS_TABLE)) {
        return;
    }

    (new Schema($db))->create(MOPS_ANALYTICS_TABLE, static function (Blueprint $table): void {
        $table->id();
        $table->string('path', 255);
        $table->string('template', 191);
        $table->datetime('viewed_at');
        $table->index('viewed_at');
    });
}

// Keep the storage folder tidy: nothing outside storage/ is written by plugins.
Paths::ensure(Paths::make('storage/logs'));
