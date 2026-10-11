<?php

declare(strict_types=1);

namespace Mops\Core;

use Psr\Http\Message\UploadedFileInterface;
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
    public function __construct(private string $tmpRoot, private Config $config = new Config())
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

    /**
     * Validate an uploaded zip (extension + real MIME) and install it from a
     * staged copy under the tmp root. The staged copy is always removed.
     *
     * @param string $manifest 'plugin.json' or 'theme.json'
     * @return string the installed slug
     */
    public function installUpload(UploadedFileInterface $file, string $targetRoot, string $manifest): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload zip gagal — periksa ukuran file dan konfigurasi server.');
        }

        $clientName = (string) $file->getClientFilename();

        if (strtolower(pathinfo($clientName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('File harus berupa arsip .zip.');
        }

        $maxMb = (int) $this->config->get('uploads.max_mb', 8);
        $size = $file->getSize();

        // Client-reported size; when unknown (null) the server-side limit is
        // enforced by PHP itself (upload_max_filesize) — skip here.
        if ($size !== null && $size > $maxMb * 1024 * 1024) {
            throw new RuntimeException("Ukuran file melebihi batas {$maxMb} MB.");
        }

        $staged = $this->tmpRoot . '/upload-' . bin2hex(random_bytes(6)) . '.zip';
        // Copy rather than rename: PHP's upload tmp dir may live on a different
        // filesystem (tmpfs) than storage/, where rename() fails with EXDEV.
        $resource = $file->getStream()->detach();

        if (!is_resource($resource)) {
            throw new RuntimeException('Stream upload tidak valid.');
        }

        $target = fopen($staged, 'wb');

        if ($target === false || stream_copy_to_stream($resource, $target) === false) {
            if (is_resource($target)) {
                fclose($target);
            }

            throw new RuntimeException('Gagal menyalin file zip yang diunggah.');
        }

        fclose($target);

        try {
            $mime = Uploader::detectMime($staged);

            if (!in_array($mime, ['application/zip', 'application/x-zip', 'application/x-zip-compressed'], true)) {
                throw new RuntimeException("Tipe file {$mime} bukan zip yang valid.");
            }

            return $this->install($staged, $targetRoot, $manifest);
        } finally {
            @unlink($staged);
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
