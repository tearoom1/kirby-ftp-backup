<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\BackupManager;
use TearoomOne\FtpBackup\FileDownloadResponse;

/**
 * Backups that only exist on the server: download and copy to this web server
 */
class ServerDownloadTest extends PluginTestCase
{
    private FakeFtpClient $client;
    private string $name;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app(['ftpEnabled' => true, 'ftpHost' => 'h', 'ftpUsername' => 'u', 'ftpPassword' => 'p', 'ftpDirectory' => '/backups']);
        $this->name = $this->backupName($this->days(5));
        $this->client = new FakeFtpClient();
        $this->client->addFile('/backups/' . $this->name);
        $this->client->files['/backups/' . $this->name]['content'] = 'zip-content';
    }

    private function manager(): BackupManager
    {
        return $this->managerWithClient($this->client);
    }

    private function token(array $result): string
    {
        $this->assertSame('success', $result['status'], $result['message'] ?? '');
        $this->assertMatchesRegularExpression('#/ftp-backup/server-download/([a-f0-9]{32})$#', $result['data']['url']);
        return basename($result['data']['url']);
    }

    public static function invalidFilenames(): array
    {
        return [
            'path traversal' => ['../site/config/config.php'],
            'traversal with zip' => ['../../backup.zip'],
            'nested path' => ['sub/backup.zip'],
            'not a zip' => ['backup.txt'],
            'hidden file' => ['.download-x.zip'],
        ];
    }

    /**
     * @dataProvider invalidFilenames
     */
    public function testInvalidFilenamesAreRejectedWithoutConnecting(string $filename): void
    {
        $this->assertSame('error', $this->manager()->prepareServerDownload($filename)['status']);
        $this->assertSame('error', $this->manager()->copyFromServer($filename)['status']);
        $this->assertSame(0, $this->client->connects);
    }

    public function testFileMustBeInTheServerListing(): void
    {
        $result = $this->manager()->prepareServerDownload($this->backupName($this->days(9)));

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not on the FTP server', $result['message']);
        $this->assertSame([], $this->client->downloaded);
    }

    public function testDownloadIsSentOnceAndTheTemporaryFileRemoved(): void
    {
        $token = $this->token($this->manager()->prepareServerDownload($this->name));

        $response = (new BackupManager())->serverDownload($token);

        $this->assertInstanceOf(FileDownloadResponse::class, $response);
        $this->assertTrue($response->deletesAfterSend());
        $this->assertSame('attachment; filename="' . $this->name . '"', $response->headers()['Content-Disposition']);
        $this->assertSame('11', (string)$response->headers()['Content-Length']);
        $this->assertSame([], $this->localBackups(), 'Not kept as a local backup');
    }

    public function testDownloadRouteSendsTheFileOnceThroughKirby(): void
    {
        $token = $this->token($this->manager()->prepareServerDownload($this->name));

        // Boot Kirby with the plugin in a child process and render the route like
        // index.php does (sending discards all output buffers, including PHPUnit's)
        $pluginRoot = $this->root . '/site/plugins';
        mkdir($pluginRoot, 0777, true);
        symlink(dirname(__DIR__), $pluginRoot . '/kirby-ftp-backup');
        $render = function () use ($token): string {
            $script = sprintf(
                'require %s; $kirby = new Kirby\Cms\App(["roots" => ["index" => %s]]); $kirby->impersonate("kirby");'
                . ' echo $kirby->render("ftp-backup/server-download/%s");',
                var_export(dirname(__DIR__) . '/kirby/bootstrap.php', true),
                var_export($this->root, true),
                $token
            );
            return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script));
        };

        $this->assertSame('zip-content', $render());
        $this->assertSame([], glob($this->backupDir . '/.download-*') ?: [], 'Temporary file removed');
        $this->assertStringContainsString('not found', $render(), 'Only once');
    }

    public function testTemporaryFileIsNotListedAsLocalBackup(): void
    {
        $this->token($this->manager()->prepareServerDownload($this->name));

        $this->assertSame([], (new BackupManager())->listBackupFiles());
    }

    public function testInvalidTokensAreRejected(): void
    {
        $this->assertSame(400, (new BackupManager())->serverDownload('../../x')->code());
        $this->assertSame(404, (new BackupManager())->serverDownload(str_repeat('a', 32))->code());
    }

    public function testCopyMakesItALocalBackup(): void
    {
        $result = $this->manager()->copyFromServer($this->name);

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertSame([$this->name], $this->localBackups());
        $this->assertSame('zip-content', file_get_contents($this->backupDir . '/' . $this->name));
        $this->assertSame([], glob($this->backupDir . '/.download-*') ?: []);
    }

    public function testCopyDoesNotOverwriteALocalBackup(): void
    {
        $this->createLocalBackup($this->name);

        $result = $this->manager()->copyFromServer($this->name);

        $this->assertSame('error', $result['status']);
        $this->assertSame(0, $this->client->connects);
    }

    public function testFailedDownloadLeavesNoTemporaryFile(): void
    {
        $this->client->connectError = new \Exception('Connection refused');

        $result = $this->manager()->prepareServerDownload($this->name);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Connection refused', $result['message']);
        $this->assertSame([], glob($this->backupDir . '/.download-*') ?: []);
    }
}
