<?php

namespace TearoomOne\FtpBackup\Tests;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\TestCase;
use TearoomOne\FtpBackup\BackupManager;
use TearoomOne\FtpBackup\FtpClientInterface;

/**
 * Runs each test against a fresh Kirby instance in its own temporary directory
 */
abstract class PluginTestCase extends TestCase
{
    protected string $root;
    protected string $contentDir;
    protected string $backupDir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ftp-backup-test-' . bin2hex(random_bytes(6));
        $this->contentDir = $this->root . '/content';
        $this->backupDir = $this->contentDir . '/.backups';
        Dir::make($this->contentDir);
        $this->app();
    }

    protected function tearDown(): void
    {
        // Restore permissions changed by a test so the directory can be removed
        exec('chmod -R u+rwx ' . escapeshellarg($this->root));
        Dir::remove($this->root);
    }

    /**
     * Boot Kirby with the given plugin options (FTP is off unless a test enables it)
     */
    protected function app(array $options = []): App
    {
        return new App([
            'roots' => [
                'index' => $this->root,
                'content' => $this->contentDir,
                'site' => $this->root . '/site',
            ],
            'options' => [
                'tearoom1.kirby-ftp-backup' => array_merge(['ftpEnabled' => false], $options),
            ],
        ]);
    }

    /**
     * A manager that talks to the given client instead of a real server
     */
    protected function managerWithClient(FakeFtpClient $client): BackupManager
    {
        return new class ($client) extends BackupManager {
            public function __construct(private FakeFtpClient $client)
            {
                parent::__construct();
            }

            protected function createFtpClient(array $settings): FtpClientInterface
            {
                $this->client->connect();
                return $this->client;
            }
        };
    }

    /**
     * Backup filename as the plugin generates it
     */
    protected function backupName(int $timestamp, string $prefix = 'backup-', string $suffix = ''): string
    {
        return $prefix . date('Y-m-d-His', $timestamp) . $suffix . '.zip';
    }

    /**
     * Create a local backup file with the given age in days
     */
    protected function createLocalBackup(string $filename, float $ageDays = 0): string
    {
        Dir::make($this->backupDir);
        $path = $this->backupDir . '/' . $filename;
        file_put_contents($path, 'zip');
        touch($path, (int)(time() - $ageDays * 86400));
        return $path;
    }

    protected function writeContent(string $relativePath, string $contents = 'Title: Test'): void
    {
        $path = $this->contentDir . '/' . $relativePath;
        Dir::make(dirname($path));
        file_put_contents($path, $contents);
    }

    /**
     * Filenames of all zip files in the local backup directory
     */
    protected function localBackups(): array
    {
        $files = glob($this->backupDir . '/*.zip') ?: [];
        $names = array_map('basename', $files);
        sort($names);
        return $names;
    }

    /**
     * Entry names inside a zip archive
     */
    protected function zipEntries(string $path): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, "Could not open {$path}");
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($entries);
        return $entries;
    }

    protected function days(float $days): int
    {
        return (int)(time() - $days * 86400);
    }
}
