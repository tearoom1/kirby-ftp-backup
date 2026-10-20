<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\BackupManager;
use TearoomOne\FtpBackup\FileDownloadResponse;

class DownloadTest extends PluginTestCase
{
    public static function invalidFilenames(): array
    {
        return [
            'path traversal' => ['../site/config/config.php'],
            'nested path' => ['sub/backup.zip'],
            'not a zip' => ['backup.txt'],
            'hidden job file' => ['.lock'],
        ];
    }

    /**
     * @dataProvider invalidFilenames
     */
    public function testInvalidFilenamesAreRejected(string $filename): void
    {
        $response = (new BackupManager())->downloadBackup($filename);

        $this->assertSame(400, $response->code());
    }

    public function testMissingBackupReturnsNotFound(): void
    {
        $response = (new BackupManager())->downloadBackup('backup-2026-01-01-000000.zip');

        $this->assertSame(404, $response->code());
    }

    public function testBackupIsStreamedInsteadOfLoadedIntoMemory(): void
    {
        $path = $this->createLocalBackup('backup-2026-01-01-000000.zip');
        file_put_contents($path, str_repeat('x', 4096));

        $response = (new BackupManager())->downloadBackup('backup-2026-01-01-000000.zip');

        $this->assertInstanceOf(FileDownloadResponse::class, $response);
        $this->assertSame(200, $response->code());
        $this->assertSame('application/zip', $response->type());
        $this->assertSame('', $response->body(), 'The archive must not be read into the response body');
        $this->assertSame(realpath($path), realpath($response->path()));
        $headers = $response->headers();
        $this->assertSame('4096', (string)$headers['Content-Length']);
        $this->assertSame('attachment; filename="backup-2026-01-01-000000.zip"', $headers['Content-Disposition']);
    }

    public function testStreamedResponseOutputsTheExactFileBytes(): void
    {
        $path = $this->createLocalBackup('backup-2026-01-01-000000.zip');
        $bytes = random_bytes(300000);
        file_put_contents($path, $bytes);

        // Run in a child process: sending discards all output buffers, including PHPUnit's
        $script = sprintf(
            'require %s; require %s; echo new TearoomOne\FtpBackup\FileDownloadResponse(%s);',
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            var_export(dirname(__DIR__) . '/src/FileDownloadResponse.php', true),
            var_export($path, true)
        );
        // Stray output before the response must not end up in the archive
        $output = shell_exec(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('ob_start(); echo "\n\n"; ' . $script)
        );

        $this->assertSame(strlen($bytes), strlen($output));
        $this->assertTrue($bytes === $output, 'Streamed bytes differ from the file');
    }
}
