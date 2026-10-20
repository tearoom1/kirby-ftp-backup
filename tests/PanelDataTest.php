<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\BackupManager;

/**
 * Data behind the Panel view: connection test and the preview of the next cleanup
 */
class PanelDataTest extends PluginTestCase
{
    public function testConnectionTestWalksThroughAllSteps(): void
    {
        $this->app(['ftpEnabled' => true, 'ftpHost' => 'h', 'ftpUsername' => 'u', 'ftpDirectory' => 'backups']);
        $client = new FakeFtpClient();

        $result = $this->managerWithClient($client)->testConnection();

        $this->assertSame('success', $result['status']);
        $this->assertSame(['settings', 'connect', 'write', 'list'], array_column($result['steps'], 'step'));
        $this->assertSame([true, true, true, true], array_column($result['steps'], 'ok'));
        // The test file is written into the backup directory and removed again
        $this->assertCount(1, $client->uploaded);
        $this->assertStringStartsWith('backups/.ftp-backup-test-', $client->uploaded[0]);
        $this->assertSame($client->uploaded, $client->deleted);
        $this->assertFalse($client->connected);
    }

    public function testConnectionTestStopsAtTheFirstFailure(): void
    {
        $this->app(['ftpEnabled' => true, 'ftpHost' => 'h', 'ftpUsername' => 'u']);
        $client = new FakeFtpClient();
        $client->connectError = new \Exception('Failed to login to FTP server [ftp://u@h:21]');

        $result = $this->managerWithClient($client)->testConnection();

        $this->assertSame('error', $result['status']);
        $steps = $result['steps'];
        $this->assertSame(['settings', 'connect'], array_column($steps, 'step'));
        $this->assertFalse(end($steps)['ok']);
        $this->assertStringContainsString('login', end($steps)['message']);
    }

    public function testConnectionTestReportsMissingSettings(): void
    {
        $this->app(['ftpEnabled' => true, 'ftpHost' => '']);

        $result = (new BackupManager())->testConnection();

        $this->assertSame('error', $result['status']);
        $this->assertSame('settings', $result['steps'][0]['step']);
        $this->assertStringContainsString('ftpHost', $result['steps'][0]['message']);
    }

    public function testConnectionTestReportsAFailedWrite(): void
    {
        $this->app(['ftpEnabled' => true, 'ftpHost' => 'h', 'ftpUsername' => 'u']);
        $client = new FakeFtpClient();
        $client->uploadError = new \Exception('Permission denied');

        $result = $this->managerWithClient($client)->testConnection();

        $this->assertSame('error', $result['status']);
        $this->assertSame('write', end($result['steps'])['step']);
        $this->assertStringContainsString('Permission denied', end($result['steps'])['message']);
    }

    public function testLocalListMarksWhatTheNextRunDeletes(): void
    {
        $this->app(['backupRetention' => 2]);
        $names = [];
        foreach ([1, 2, 3, 4] as $age) {
            $names[$age] = $this->backupName($this->days($age));
            $this->createLocalBackup($names[$age], $age);
        }
        $this->createLocalBackup('manual.zip', 10);

        $flags = array_column((new BackupManager())->listBackupFiles(), 'deleteNext', 'filename');

        // The next run adds one backup and keeps two: only the newest existing one stays
        $this->assertSame(
            [$names[1] => false, $names[2] => true, $names[3] => true, $names[4] => true, 'manual.zip' => false],
            array_intersect_key($flags, array_flip([...$names, 'manual.zip']))
        );
    }

    public function testServerListMarksWhatTheNextRunDeletes(): void
    {
        $this->app(['ftpEnabled' => true, 'ftpHost' => 'h', 'ftpUsername' => 'u', 'ftpDirectory' => '/', 'backupRetention' => 2]);
        $client = new FakeFtpClient();
        $keep = $this->backupName($this->days(1));
        $drop = $this->backupName($this->days(2));
        $client->addFile('/' . $keep, $this->days(1));
        $client->addFile('/' . $drop, $this->days(2));
        $client->addFile('/other.zip');

        $stats = $this->managerWithClient($client)->getFtpServerStats();

        $flags = array_column($stats['data']['files'], 'deleteNext', 'filename');
        ksort($flags);
        $expected = [$keep => false, $drop => true, 'other.zip' => false];
        ksort($expected);
        $this->assertSame($expected, $flags);
    }

    public function testServerListMarksNothingWhenServerCleanupIsOff(): void
    {
        $this->app(['ftpEnabled' => true, 'ftpHost' => 'h', 'ftpUsername' => 'u', 'ftpDirectory' => '/', 'backupRetention' => 1, 'deleteFromFtp' => false]);
        $client = new FakeFtpClient();
        $client->addFile('/' . $this->backupName($this->days(1)));
        $client->addFile('/' . $this->backupName($this->days(2)));

        $stats = $this->managerWithClient($client)->getFtpServerStats();

        $this->assertSame([false, false], array_column($stats['data']['files'], 'deleteNext'));
    }
}
