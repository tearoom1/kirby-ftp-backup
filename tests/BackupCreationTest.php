<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\BackupManager;

/**
 * End-to-end backup runs against a temporary content folder
 */
class BackupCreationTest extends PluginTestCase
{
    public function testArchiveContainsContentButNotTheBackupDirectory(): void
    {
        $this->writeContent('home/home.txt');
        $this->writeContent('notes/1_note/note.txt');
        $this->createLocalBackup($this->backupName($this->days(1)), 1);

        $result = (new BackupManager())->createBackup();

        $this->assertSame('success', $result['status'], $result['message']);
        $entries = $this->zipEntries($this->backupDir . '/' . $result['data']['filename']);
        $this->assertContains('home/home.txt', $entries);
        $this->assertContains('notes/1_note/note.txt', $entries);
        foreach ($entries as $entry) {
            $this->assertStringNotContainsString('.backups', $entry);
        }
    }

    public function testCustomBackupDirectoryInsideContentIsExcluded(): void
    {
        $archiveDir = $this->contentDir . '/_archive';
        $this->app(['backupDirectory' => $archiveDir]);
        $this->writeContent('home/home.txt');

        $first = (new BackupManager())->createBackup();
        $second = (new BackupManager())->createBackup();

        $this->assertSame('success', $second['status'], $second['message']);
        $entries = $this->zipEntries($archiveDir . '/' . $second['data']['filename']);
        $this->assertSame(['home/', 'home/home.txt'], $entries);
        $this->assertNotSame($first['data']['filename'], $second['data']['filename']);
    }

    public function testRelativeBackupDirectoryIsResolvedFromTheProjectRoot(): void
    {
        $this->app(['backupDirectory' => 'content/_relative']);
        $this->writeContent('home/home.txt');

        $result = (new BackupManager())->createBackup();

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertFileExists($this->contentDir . '/_relative/' . $result['data']['filename']);
        $entries = $this->zipEntries($this->contentDir . '/_relative/' . $result['data']['filename']);
        $this->assertSame(['home/', 'home/home.txt'], $entries);
    }

    public function testTieredRetentionAppliesWhenFtpIsDisabled(): void
    {
        $this->app([
            'retentionStrategy' => 'tiered',
            'tieredRetention' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1],
        ]);
        $this->writeContent('home/home.txt');
        $old200 = $this->backupName($this->days(200));
        $old250 = $this->backupName($this->days(250));
        $oldest = $this->backupName($this->days(300));
        $this->createLocalBackup($old200, 200);
        $this->createLocalBackup($old250, 250);
        $this->createLocalBackup($oldest, 300);

        $result = (new BackupManager())->createBackup();

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertEqualsCanonicalizing([$oldest, $result['data']['filename']], $this->localBackups());
    }

    public function testLocalRetentionIgnoresForeignZipFiles(): void
    {
        $this->app(['backupRetention' => 2]);
        $this->writeContent('home/home.txt');
        $this->createLocalBackup('manual-export.zip', 400);
        $keep = $this->backupName($this->days(1));
        $this->createLocalBackup($keep, 1);
        $this->createLocalBackup($this->backupName($this->days(2)), 2);
        $this->createLocalBackup($this->backupName($this->days(3)), 3);

        $result = (new BackupManager())->createBackup();

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertEqualsCanonicalizing(
            ['manual-export.zip', $keep, $result['data']['filename']],
            $this->localBackups()
        );
    }

    public function testSecondRunIsRejectedWhileABackupIsRunning(): void
    {
        $this->writeContent('home/home.txt');
        $manager = new BackupManager();

        $lock = fopen($this->backupDir . '/.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $result = $manager->createBackup();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('already running', $result['message']);
        $this->assertSame([], $this->localBackups());

        // The lock is free again afterwards
        $this->assertSame('success', $manager->createBackup()['status']);
    }

    public function testFilenamesFromTheSameSecondGetASuffix(): void
    {
        $manager = new BackupManager();
        $method = new \ReflectionMethod($manager, 'backupFilename');
        $time = mktime(12, 0, 0, 1, 15, 2026);

        $this->assertSame('backup-2026-01-15-120000.zip', $method->invoke($manager, $time));

        $this->createLocalBackup('backup-2026-01-15-120000.zip');
        $this->assertSame('backup-2026-01-15-120000-2.zip', $method->invoke($manager, $time));

        $this->createLocalBackup('backup-2026-01-15-120000-2.zip');
        $this->assertSame('backup-2026-01-15-120000-3.zip', $method->invoke($manager, $time));
    }

    public function testFailedArchiveIsReportedAndRemoved(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Root can read any file');
        }
        $this->writeContent('home/home.txt');
        $this->writeContent('secret/secret.txt');
        chmod($this->contentDir . '/secret/secret.txt', 0000);

        $result = (new BackupManager())->createBackup();

        $this->assertSame('error', $result['status']);
        $this->assertSame([], $this->localBackups());
    }

    public function testBackupWithNothingToArchiveIsAnError(): void
    {
        $this->app(['excludePaths' => ['home']]);
        $this->writeContent('home/home.txt');

        $result = (new BackupManager())->createBackup();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('empty', $result['message']);
        $this->assertSame([], $this->localBackups());
    }

    public function testCancelledBackupLeavesNoFilesBehind(): void
    {
        $this->writeContent('home/home.txt');
        $jobId = '0123abcd-4567-89ef-0123-456789abcdef';
        $manager = new BackupManager();
        file_put_contents($this->backupDir . '/.cancel-' . $jobId, '1');

        $result = $manager->createBackup(true, $jobId);

        $this->assertSame('cancelled', $result['status']);
        $this->assertSame([], $this->localBackups());
        $this->assertSame([], glob($this->backupDir . '/.{job,cancel,progress}-*', GLOB_BRACE));
    }

    public function testFailedConnectionStillKeepsAndRotatesTheLocalBackup(): void
    {
        $this->app(['ftpEnabled' => true, 'backupRetention' => 1]);
        $this->writeContent('home/home.txt');
        $this->createLocalBackup($this->backupName($this->days(1)), 1);
        $client = new FakeFtpClient();
        $client->connectError = new \Exception('Failed to connect to FTP server [ftp://u@example.test:21]');

        $result = $this->managerWithClient($client)->createBackup();

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertFalse($result['data']['ftpResult']['uploaded']);
        $this->assertStringContainsString('example.test', $result['data']['ftpResult']['message']);
        $this->assertSame([$result['data']['filename']], $this->localBackups());
    }

    public function testUploadGoesToTheConfiguredDirectoryAndRotatesRemotely(): void
    {
        $this->app(['ftpEnabled' => true, 'ftpDirectory' => 'backups/', 'backupRetention' => 2]);
        $this->writeContent('home/home.txt');
        $client = new FakeFtpClient();
        $keep = $this->backupName($this->days(1));
        $drop = $this->backupName($this->days(2));
        $client->addFile('backups/' . $keep);
        $client->addFile('backups/' . $drop);
        $client->addFile('backups/unrelated.zip');

        $result = $this->managerWithClient($client)->createBackup();

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertTrue($result['data']['ftpResult']['uploaded']);
        $this->assertSame(['backups/' . $result['data']['filename']], $client->uploaded);
        $this->assertSame(['backups/' . $drop], $client->deleted);
        $this->assertFalse($client->connected, 'Connection should be closed after the run');
    }

    public function testFailedUploadIsReportedButLocalBackupIsKept(): void
    {
        $this->app(['ftpEnabled' => true]);
        $this->writeContent('home/home.txt');
        $client = new FakeFtpClient();
        $client->uploadError = new \Exception('Upload failed: disk full');

        $result = $this->managerWithClient($client)->createBackup();

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['data']['ftpResult']['uploaded']);
        $this->assertStringContainsString('disk full', $result['data']['ftpResult']['message']);
        $this->assertSame([$result['data']['filename']], $this->localBackups());
    }
}
