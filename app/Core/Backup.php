<?php

declare(strict_types=1);

namespace Mops\Core;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Backup + restore (roadmap 1.0). Produces a single zip containing the database
 * and the uploads tree; uploads are never deleted, only copied.
 */
final class Backup
{
    public function __construct(
        private Config $config,
        private Database $db,
        private Settings $settings,
    ) {
    }

    public function supportsZip(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $scope = 'full'): array
    {
        $directory = Paths::ensure($this->config->path('backups'));
        $stamp = date('Y-m-d_His');
        $filename = "mops-backup-{$stamp}.zip";
        $target = $directory . '/' . $filename;

        $items = [];

        if ($this->supportsZip()) {
            $zip = new ZipArchive();

            if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Gagal membuat file backup.');
            }

            if ($this->db->isSqlite()) {
                $database = $this->db->sqlitePath();

                if (is_file($database)) {
                    $zip->addFile($database, 'database.sqlite');
                    $items[] = 'database.sqlite';
                }
            } else {
                $zip->addFromString('database.json', $this->dumpAsJson());
                $items[] = 'database.json';
            }

            if ($scope === 'full') {
                $uploads = Paths::make($this->config->get('paths.uploads', 'storage/uploads'));

                foreach ($this->filesIn($uploads) as $file) {
                    $zip->addFile($file, 'uploads/' . ltrim(substr($file, strlen($uploads)), '/'));
                    $items[] = 'uploads/' . ltrim(substr($file, strlen($uploads)), '/');
                }
            }

            $zip->addFromString('manifest.json', json_encode([
                'app' => $this->config->get('app.name'),
                'version' => App::VERSION,
                'created_at' => date('c'),
                'scope' => $scope,
                'driver' => $this->db->driver(),
                'items' => count($items),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $zip->close();
        } else {
            // No ext-zip: fall back to a copy of the SQLite database.
            if (!$this->db->isSqlite()) {
                throw new RuntimeException('Ekstensi zip tidak tersedia dan driver database bukan SQLite.');
            }

            $filename = "mops-backup-{$stamp}.sqlite";
            $target = $directory . '/' . $filename;
            copy($this->db->sqlitePath(), $target);
            $items[] = 'database.sqlite';
        }

        Hook::doAction('backup_created', $target, $scope);

        return [
            'filename' => $filename,
            'path' => $target,
            'size' => is_file($target) ? (int) filesize($target) : 0,
            'scope' => $scope,
            'items' => count($items),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $directory = $this->config->path('backups');

        if (!is_dir($directory)) {
            return [];
        }

        $files = array_merge(
            glob($directory . '/*.zip') ?: [],
            glob($directory . '/*.sqlite') ?: [],
        );

        $backups = [];

        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'size' => (int) filesize($file),
                'created_at' => date('Y-m-d H:i:s', (int) filemtime($file)),
                'readable' => $this->humanSize((int) filesize($file)),
            ];
        }

        usort($backups, static fn (array $a, array $b): int => strcmp($b['filename'], $a['filename']));

        return $backups;
    }

    public function delete(string $filename): bool
    {
        $path = $this->resolve($filename);

        if ($path === null) {
            return false;
        }

        return unlink($path);
    }

    public function path(string $filename): ?string
    {
        return $this->resolve($filename);
    }

    /**
     * Restore a zip backup: replaces the SQLite database file and re-imports
     * uploads. Only supported for the SQLite driver (documented limitation).
     */
    public function restore(string $filename): void
    {
        $path = $this->resolve($filename);

        if ($path === null) {
            throw new RuntimeException('File backup tidak ditemukan.');
        }

        if (!$this->db->isSqlite()) {
            throw new RuntimeException('Restore otomatis hanya didukung untuk driver SQLite.');
        }

        if (!$this->supportsZip() || !str_ends_with($path, '.zip')) {
            throw new RuntimeException('Restore memerlukan file .zip dan ekstensi zip.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Backup tidak bisa dibuka.');
        }

        try {
            // Safety net: keep a copy of the current database before overwriting.
            $this->create('database');
        } catch (Throwable) {
            // A failed safety copy must not block the restore the admin asked for.
        }

        $database = $this->db->sqlitePath();

        if ($zip->locateName('database.sqlite') !== false) {
            $contents = $zip->getFromName('database.sqlite');

            if ($contents === false) {
                throw new RuntimeException('Isi database di dalam backup rusak.');
            }

            file_put_contents($database, $contents);
        }

        $uploads = Paths::ensure(Paths::make($this->config->get('paths.uploads', 'storage/uploads')));

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (!str_starts_with($name, 'uploads/') || str_ends_with($name, '/')) {
                continue;
            }

            $relative = substr($name, strlen('uploads/'));
            $target = $uploads . '/' . $relative;

            if (!Paths::isInside(dirname($target), $uploads) && dirname($target) !== $uploads) {
                continue; // refuse path traversal inside the archive
            }

            Paths::ensure(dirname($target));
            file_put_contents($target, (string) $zip->getFromIndex($i));
        }

        $zip->close();
        $this->settings->flush();

        Hook::doAction('backup_restored', $filename);
    }

    /**
     * @return array<int, string>
     */
    private function filesIn(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private function dumpAsJson(): string
    {
        $tables = ['users', 'settings', 'profile', 'experiences', 'educations', 'skills',
            'certifications', 'awards', 'projects', 'posts', 'media', 'activity_log'];
        $dump = [];

        foreach ($tables as $table) {
            $dump[$table] = $this->db->tableExists($table) ? $this->db->table($table)->get() : [];
        }

        return json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function resolve(string $filename): ?string
    {
        // Reject anything that is not a plain filename inside the backups folder.
        $filename = basename($filename);
        $directory = $this->config->path('backups');
        $candidate = $directory . '/' . $filename;

        if (!is_file($candidate)) {
            return null;
        }

        return Paths::isInside($candidate, $directory) ? $candidate : null;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, $index === 0 ? 0 : 1) . ' ' . $units[$index];
    }
}
