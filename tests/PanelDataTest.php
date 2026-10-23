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

    public function testBackupTimeComesFromTheFilenameNotTheFileTime(): void
    {
        $this->app(['filePrefix' => 'basic-', 'maxBackupAge' => 36]);
        $created = $this->days(10);
        // Copied or restored later: the file time is newer than the backup
        $this->createLocalBackup($this->backupName($created, 'basic-'), 0);
        $this->createLocalBackup($this->backupName($this->days(20), 'basic-'), 0);
        // A name of another format keeps its file time
        $this->createLocalBackup('manual.zip', 15);

        $manager = new BackupManager();
        $list = $manager->listBackupFiles();

        $this->assertSame(
            [$this->backupName($created, 'basic-'), 'manual.zip', $this->backupName($this->days(20), 'basic-')],
            array_column($list, 'filename'),
            'Sorted by creation time, newest first'
        );
        $this->assertSame($created, $list[0]['modified']);
        $this->assertEqualsWithDelta($this->days(15), $list[1]['modified'], 1);
        // The age check uses the same time: 10 days is older than 36 hours
        $health = $manager->backupHealth();
        $this->assertSame($created, $health['latestBackup']);
        $this->assertTrue($health['stale']);
    }

    public function testServerListTakesTheTimeFromTheFilename(): void
    {
        $this->app(['ftpEnabled' => true, 'ftpHost' => 'h', 'ftpUsername' => 'u', 'ftpDirectory' => 'backups']);
        $created = $this->days(3);
        $client = new FakeFtpClient();
        $client->addFile('backups/' . $this->backupName($created), time());
        $client->addFile('backups/other.zip', $this->days(1));

        $data = $this->managerWithClient($client)->getFtpServerStats()['data'];

        $times = array_column($data['files'], 'modified', 'filename');
        $this->assertSame($created, $times[$this->backupName($created)]);
        $this->assertSame($this->days(1), $times['other.zip']);
        $this->assertSame('other.zip', $data['files'][0]['filename']);
    }
}
