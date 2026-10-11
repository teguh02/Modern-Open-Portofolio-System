<?php

declare(strict_types=1);

namespace Mops\Core;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Installs a plugin or theme from a zip uploaded by an admin.
 *
 * Zip Slip protection, validation in a staging folder under storage/tmp, and
 * an atomic rename into plugins/ or themes/ — never a partial install.
 */
final class ZipInstaller
{
    public function __construct(private string $tmpRoot)
    {
        Paths::ensure($this->tmpRoot);
    }

    /**
     * @param string $manifest 'plugin.json' or 'theme.json'
     * @return string the installed slug
     */
    public function install(string $zipPath, string $targetRoot, string $manifest): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi zip tidak tersedia di server ini.');
        }

        if (!is_file($zipPath)) {
            throw new RuntimeException('File zip tidak ditemukan.');
        }

        $extractDir = $this->tmpRoot . '/install-' . bin2hex(random_bytes(6));
        Paths::ensure($extractDir);

        try {
            $this->extract($zipPath, $extractDir);
            [$root, $slug] = $this->detect($extractDir, $manifest);

            $target = rtrim($targetRoot, '/') . '/' . $slug;

            if (is_dir($target)) {
                throw new RuntimeException("Slug '{$slug}' sudah ada — hapus dulu atau gunakan nama lain.");
            }

            Paths::ensure($targetRoot);

            if (!@rename($root, $target)) {
                throw new RuntimeException("Gagal memindahkan '{$slug}' ke tujuan.");
            }

            return $slug;
        } finally {
            Paths::deleteTree($extractDir);
        }
    }

    private function extract(string $zipPath, string $extractDir): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('File zip tidak bisa dibuka.');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $name = str_replace('\\', '/', $name);

                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                if (str_starts_with($name, '/') || preg_match('#^[a-zA-Z]:#', $name) === 1 || str_contains($name, '..')) {
                    throw new RuntimeException("Entry zip tidak aman: {$name}");
                }

                if ($this->isSymlink($zip, $i)) {
                    throw new RuntimeException("Entry zip berupa symlink: {$name}");
                }

                if ($zip->extractTo($extractDir, $name) !== true) {
                    throw new RuntimeException("Gagal mengekstrak entry: {$name}");
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;

        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        // Unix mode bits live in the high 16 bits; 0120000 = symlink.
        return $opsys === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0170000) === 0120000);
    }

    /**
     * @return array{0: string, 1: string} [content root, slug]
     */
    private function detect(string $extractDir, string $manifest): array
    {
        $entries = array_values(array_filter(scandir($extractDir) ?: [], static fn (string $e): bool => $e !== '.' && $e !== '..'));
        $dirs = array_values(array_filter($entries, static fn (string $e): bool => is_dir($extractDir . '/' . $e)));
        $files = array_values(array_filter($entries, static fn (string $e): bool => is_file($extractDir . '/' . $e)));

        if (count($dirs) === 1 && count($files) === 0) {
            $slug = $dirs[0];

            if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
                throw new RuntimeException("Nama folder '{$slug}' bukan slug yang valid.");
            }

            if (!is_file($extractDir . '/' . $slug . '/' . $manifest)) {
                throw new RuntimeException("Folder '{$slug}' tidak berisi {$manifest}.");
            }

            return [$extractDir . '/' . $slug, $slug];
        }

        if (!is_file($extractDir . '/' . $manifest)) {
            throw new RuntimeException("Zip tidak berisi {$manifest} di root-nya.");
        }

        $decoded = json_decode((string) file_get_contents($extractDir . '/' . $manifest), true);
        $slug = is_array($decoded) ? ($decoded['slug'] ?? null) : null;

        if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
            throw new RuntimeException('Zip datar wajib punya field "slug" yang valid di ' . $manifest . '.');
        }

        return [$extractDir, $slug];
    }
}
