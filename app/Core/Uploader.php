<?php

declare(strict_types=1);

namespace Mops\Core;

/**
 * Upload handling. Files are written to storage/uploads/YYYY/MM/ and only the
 * relative path is stored in the database (never base64, never inside public/).
 */
final class Uploader
{
    /** @var array<int, string> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $config uploads config block
     */
    public function __construct(private array $config, private string $uploadRoot)
    {
    }

    /**
     * @param array<string, mixed> $file a single entry from $_FILES
     * @return array<string, mixed>|null stored file descriptor, or null on failure
     */
    public function store(array $file, string $kind = 'image'): ?array
    {
        $this->errors = [];

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->errors[] = 'Tidak ada file yang dipilih.';

            return null;
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $this->errors[] = $this->uploadErrorMessage((int) $file['error']);

            return null;
        }

        $maxBytes = (int) ($this->config['max_mb'] ?? 8) * 1024 * 1024;

        if (($file['size'] ?? 0) > $maxBytes) {
            $this->errors[] = 'Ukuran file melebihi batas ' . (int) ($this->config['max_mb'] ?? 8) . ' MB.';

            return null;
        }

        $originalName = (string) ($file['name'] ?? 'file');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $temporary = (string) ($file['tmp_name'] ?? '');

        if ($temporary === '' || !is_uploaded_file($temporary)) {
            $this->errors[] = 'File upload tidak valid.';

            return null;
        }

        [$extensions, $mimes] = $this->allowedFor($kind);

        if (!in_array($extension, $extensions, true)) {
            $this->errors[] = 'Ekstensi .' . $extension . ' tidak diizinkan.';

            return null;
        }

        $mime = self::detectMime($temporary);

        if (!in_array($mime, $mimes, true)) {
            $this->errors[] = "Tipe file {$mime} tidak diizinkan.";

            return null;
        }

        // Never trust the uploaded name or extension for storage.
        $filename = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $relative = date('Y') . '/' . date('m') . '/' . $filename;
        $directory = rtrim($this->uploadRoot, '/') . '/' . date('Y') . '/' . date('m');

        Paths::ensure($directory);

        if (!move_uploaded_file($temporary, $directory . '/' . $filename)) {
            $this->errors[] = 'Gagal menyimpan file.';

            return null;
        }

        @chmod($directory . '/' . $filename, 0644);

        $dimensions = @getimagesize($directory . '/' . $filename) ?: [];

        return [
            'path' => $relative,
            'filename' => $filename,
            'original_name' => $originalName,
            'extension' => $extension,
            'mime' => $mime,
            'size' => (int) ($file['size'] ?? 0),
            'width' => isset($dimensions[0]) ? (int) $dimensions[0] : null,
            'height' => isset($dimensions[1]) ? (int) $dimensions[1] : null,
            'alt' => pathinfo($originalName, PATHINFO_FILENAME),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $files entries from $_FILES['x'] (multi)
     * @return array<int, array<string, mixed>>
     */
    public function storeMany(array $files, string $kind = 'image'): array
    {
        $stored = [];

        foreach ($files as $file) {
            $result = $this->store($file, $kind);

            if ($result !== null) {
                $stored[] = $result;
            }
        }

        return $stored;
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function allowedFor(string $kind): array
    {
        if ($kind === 'document') {
            return [
                array_values((array) ($this->config['document_extensions'] ?? ['pdf'])),
                array_values((array) ($this->config['document_mimes'] ?? ['application/pdf'])),
            ];
        }

        return [
            array_values((array) ($this->config['image_extensions'] ?? [])),
            array_values((array) ($this->config['image_mimes'] ?? [])),
        ];
    }

    public static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);

                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return (string) (mime_content_type($path) ?: 'application/octet-stream');
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File terlalu besar.',
            UPLOAD_ERR_PARTIAL => 'Upload terputus, coba lagi.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk.',
            UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh ekstensi PHP.',
            default => 'Upload gagal.',
        };
    }
}
