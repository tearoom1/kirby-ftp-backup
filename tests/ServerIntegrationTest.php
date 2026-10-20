<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\FtpClient;
use TearoomOne\FtpBackup\FtpClientInterface;
use TearoomOne\FtpBackup\SftpClient;

/**
 * Runs the clients against real servers. Skipped unless these are set:
 *
 *   FTP_BACKUP_TEST_FTP=ftp://user:pass@host:21/absolute/writable/dir
 *   FTP_BACKUP_TEST_SFTP=sftp://user@host:22/absolute/writable/dir
 *   FTP_BACKUP_TEST_SFTP_KEY=/path/to/private/key (optional, otherwise the URL password is used)
 *   FTP_BACKUP_TEST_SFTP_KEY_PASSPHRASE=... (optional)
 *   FTP_BACKUP_TEST_<FTP|SFTP>_RELATIVE=upload (optional writable directory relative to the login directory)
 */
class ServerIntegrationTest extends PluginTestCase
{
    private string $absoluteDirectory = '/';
    private string $relativeDirectory = '';

    public static function servers(): array
    {
        return ['ftp' => ['ftp'], 'sftp' => ['sftp']];
    }

    /**
     * @dataProvider servers
     */
    public function testRoundTripInRelativeAndAbsoluteDirectories(string $protocol): void
    {
        $client = $this->client($protocol);
        $client->connect();
        $run = $this->relativeDirectory . 'it-' . bin2hex(random_bytes(4));
        $absolute = rtrim($this->absoluteDirectory, '/') . '/' . basename($run) . '/abs';

        try {
            $local = $this->root . '/upload.zip';
            file_put_contents($local, random_bytes(200000));

            foreach (["{$run}/nested", $absolute] as $directory) {
                $remote = $directory . '/backup.zip';
                $progress = [];
                $this->assertTrue($client->upload($local, $remote, function (int $sent, int $total) use (&$progress) {
                    $progress[] = [$sent, $total];
                }));
                // Progress is reported in bytes, never goes backwards and ends at the file size
                $this->assertNotEmpty($progress);
                $this->assertSame([200000, 200000], end($progress));
                $sent = array_column($progress, 0);
                $sorted = $sent;
                sort($sorted);
                $this->assertSame($sorted, $sent);
                $this->assertContains('backup.zip', $client->listDirectory($directory));
                $this->assertSame(200000, $client->getFileSize($remote));
                $this->assertEqualsWithDelta(time(), $client->getModifiedTime($remote), 120);
                $this->assertTrue($client->delete($remote));
                $this->assertNotContains('backup.zip', $client->listDirectory($directory));
            }
        } finally {
            $client->disconnect();
        }
    }

    public function testFtpRelativeDirectoryStaysInsideTheLoginDirectory(): void
    {
        $client = $this->client('ftp');
        $client->connect();
        $run = $this->relativeDirectory . 'it-' . bin2hex(random_bytes(4));

        try {
            $local = $this->root . '/upload.zip';
            file_put_contents($local, 'zip');
            $client->upload($local, "{$run}/backup.zip");

            // A second upload after the first must still resolve against the login directory
            $client->upload($local, "{$run}/backup-2.zip");
            $this->assertEqualsCanonicalizing(['backup.zip', 'backup-2.zip'], $client->listDirectory($run));
            $this->assertContains(basename($run), $client->listDirectory($this->relativeDirectory ?: '.'));

            $client->delete("{$run}/backup.zip");
            $client->delete("{$run}/backup-2.zip");
        } finally {
            $client->disconnect();
        }
    }

    private function client(string $protocol): FtpClientInterface
    {
        $url = getenv('FTP_BACKUP_TEST_' . strtoupper($protocol));
        if (!$url) {
            $this->markTestSkipped('Set FTP_BACKUP_TEST_' . strtoupper($protocol) . ' to run against a real server');
        }
        $parts = parse_url($url);
        $user = urldecode($parts['user'] ?? '');
        $password = urldecode($parts['pass'] ?? '');
        $this->absoluteDirectory = $parts['path'] ?? '/';
        $relative = trim((string)getenv('FTP_BACKUP_TEST_' . strtoupper($protocol) . '_RELATIVE'), '/');
        $this->relativeDirectory = $relative === '' ? '' : $relative . '/';

        if ($protocol === 'sftp') {
            $key = getenv('FTP_BACKUP_TEST_SFTP_KEY') ?: null;
            $passphrase = getenv('FTP_BACKUP_TEST_SFTP_KEY_PASSPHRASE') ?: null;
            return new SftpClient($parts['host'], $parts['port'] ?? 22, $user, $password, $key, $passphrase, 10);
        }

        return new FtpClient($parts['host'], $parts['port'] ?? 21, $user, $password, false, true, 10, 1, 0);
    }
}
