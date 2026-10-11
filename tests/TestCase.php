<?php

declare(strict_types=1);

namespace Mops\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Shared helpers. Feature tests boot the real application against a temporary
 * SQLite database so every assertion goes through the same stack production uses.
 */
abstract class TestCase extends BaseTestCase
{
    /** @var array<int, string> */
    private array $temporaryFiles = [];

    /** @var array<int, string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->temporaryFiles = [];

        foreach ($this->temporaryDirectories as $directory) {
            \Mops\Core\Paths::deleteTree($directory);
        }

        $this->temporaryDirectories = [];
        $_SESSION = [];

        parent::tearDown();
    }

    protected function tempDatabase(): string
    {
        $path = MOPS_TEST_ROOT . '/db-' . bin2hex(random_bytes(4)) . '.sqlite';

        if (is_file($path)) {
            @unlink($path);
        }

        $this->temporaryFiles[] = $path;

        return $path;
    }

    protected function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/mops-dir-' . bin2hex(random_bytes(4));

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }

        $this->temporaryDirectories[] = $path;

        return $path;
    }
}
