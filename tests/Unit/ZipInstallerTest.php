<?php

declare(strict_types=1);

namespace Mops\Tests\Unit;

use Mops\Core\Paths;
use Mops\Core\ZipInstaller;
use Mops\Tests\TestCase;
use RuntimeException;
use Slim\Psr7\UploadedFile;
use ZipArchive;

final class ZipInstallerTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip tidak tersedia.');
        }

        $this->work = $this->tempDir();
    }

    public function test_rejects_parent_traversal_entries(): void
    {
        $zip = $this->zip(['../evil.php' => '<?php']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak aman');

        $this->installer()->install($zip, $this->work . '/plugins', 'plugin.json');
    }

    public function test_rejects_absolute_path_entries(): void
    {
        $zip = $this->zip(['/etc/passwd' => 'x']);

        $this->expectException(RuntimeException::class);

        $this->installer()->install($zip, $this->work . '/plugins', 'plugin.json');
    }

    public function test_installs_a_zip_with_one_top_folder(): void
    {
        $zip = $this->zip([
            'demo-plugin/plugin.json' => json_encode(['main' => 'plugin.php']),
            'demo-plugin/plugin.php' => '<?php // entry',
        ]);
        $target = $this->work . '/plugins';

        $slug = $this->installer()->install($zip, $target, 'plugin.json');

        $this->assertSame('demo-plugin', $slug);
        $this->assertFileExists($target . '/demo-plugin/plugin.php');
        $this->assertFileExists($target . '/demo-plugin/plugin.json');
    }

    public function test_flat_zip_uses_the_manifest_slug(): void
    {
        $zip = $this->zip([
            'plugin.json' => json_encode(['slug' => 'flat-plugin', 'main' => 'plugin.php']),
            'plugin.php' => '<?php // entry',
        ]);
        $target = $this->work . '/plugins';

        $slug = $this->installer()->install($zip, $target, 'plugin.json');

        $this->assertSame('flat-plugin', $slug);
        $this->assertFileExists($target . '/flat-plugin/plugin.php');
    }

    public function test_flat_zip_without_a_slug_is_rejected(): void
    {
        $zip = $this->zip([
            'plugin.json' => json_encode(['main' => 'plugin.php']),
            'plugin.php' => '<?php // entry',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slug');

        $this->installer()->install($zip, $this->work . '/plugins', 'plugin.json');
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        Paths::ensure($this->work . '/plugins/exists');
        $zip = $this->zip(['exists/plugin.json' => '{}', 'exists/plugin.php' => '<?php']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah ada');

        $this->installer()->install($zip, $this->work . '/plugins', 'plugin.json');
    }

    public function test_install_upload_rejects_a_non_zip_extension(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.zip');

        $this->installer()->installUpload($this->upload('plugin.txt', 'x'), $this->work . '/plugins', 'plugin.json');
    }

    public function test_install_upload_rejects_a_fake_zip(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bukan zip');

        $this->installer()->installUpload($this->upload('plugin.zip', 'bukan zip sebenarnya'), $this->work . '/plugins', 'plugin.json');
    }

    public function test_install_upload_stages_and_installs_a_real_zip(): void
    {
        $zip = $this->zip([
            'demo/plugin.json' => json_encode(['main' => 'plugin.php']),
            'demo/plugin.php' => '<?php // entry',
        ]);
        $file = new UploadedFile($zip, 'demo.zip', 'application/zip', (int) filesize($zip), UPLOAD_ERR_OK);

        $slug = $this->installer()->installUpload($file, $this->work . '/plugins', 'plugin.json');

        $this->assertSame('demo', $slug);
        $this->assertFileExists($this->work . '/plugins/demo/plugin.php');
    }

    private function upload(string $clientName, string $content): UploadedFile
    {
        $path = $this->work . '/upload-' . bin2hex(random_bytes(3)) . '.tmp';
        file_put_contents($path, $content);

        return new UploadedFile($path, $clientName, 'application/octet-stream', strlen($content), UPLOAD_ERR_OK);
    }

    private function installer(): ZipInstaller
    {
        return new ZipInstaller($this->work . '/tmp');
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $path = $this->work . '/upload-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }
}
