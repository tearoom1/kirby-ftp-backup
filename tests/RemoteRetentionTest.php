<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\BackupManager;

/**
 * Cleanup on the FTP server must only ever touch backups this plugin created
 */
class RemoteRetentionTest extends PluginTestCase
{
    public function testSimpleRetentionKeepsNewestOwnBackupsAndIgnoresForeignFiles(): void
    {
        $this->app(['backupRetention' => 2, 'ftpDirectory' => 'backups']);
        $client = new FakeFtpClient();

        $own = [];
        foreach ([1, 2, 3, 4] as $age) {
            $own[$age] = $this->backupName($this->days($age));
            $client->addFile('backups/' . $own[$age]);
        }
        // Sorted by name these would count as the newest "backups"
        $foreign = ['zzz-archive.zip', 'site-2099-01-01-000000.zip', 'backup-final.zip', 'notes.txt'];
        foreach ($foreign as $name) {
            $client->addFile('backups/' . $name);
        }

        $manager = new BackupManager();
        $result = $manager->cleanupFtpBackups($client, $manager->getSettings());

        $this->assertTrue($result['success']);
        $this->assertEqualsCanonicalizing(
            ['backups/' . $own[3], 'backups/' . $own[4]],
            $client->deleted
        );
    }

    public function testTieredRetentionNeverDeletesFilesItCannotDate(): void
    {
        $this->app([
            'ftpDirectory' => '/remote',
            'retentionStrategy' => 'tiered',
            'tieredRetention' => ['daily' => 1, 'weekly' => 1, 'monthly' => 1],
        ]);
        $client = new FakeFtpClient();

        $newest = $this->backupName($this->days(0));
        $old200 = $this->backupName($this->days(200));
        $old250 = $this->backupName($this->days(250));
        $oldest = $this->backupName($this->days(300));
        foreach ([$newest, $old200, $old250, $oldest, 'notes.zip', 'backup-latest.zip', 'other-2020-01-01-000000.zip'] as $name) {
            $client->addFile('/remote/' . $name);
        }

        $manager = new BackupManager();
        $manager->cleanupFtpBackups($client, $manager->getSettings());

        $this->assertEqualsCanonicalizing(['/remote/' . $old200, '/remote/' . $old250], $client->deleted);
    }

    public function testCustomPrefixOnlyMatchesThatPrefix(): void
    {
        $this->app(['backupRetention' => 1, 'filePrefix' => 'site-a_', 'ftpDirectory' => '/']);
        $client = new FakeFtpClient();

        $newer = $this->backupName($this->days(1), 'site-a_');
        $older = $this->backupName($this->days(2), 'site-a_');
        $otherSite = $this->backupName($this->days(3), 'site-b_');
        foreach ([$newer, $older, $otherSite] as $name) {
            $client->addFile('/' . $name);
        }

        $manager = new BackupManager();
        $manager->cleanupFtpBackups($client, $manager->getSettings());

        $this->assertSame(['/' . $older], $client->deleted);
        $this->assertSame([$newer, $otherSite], $client->remaining('/'));
    }

    public function testBackupsFromTheSameSecondAreOrderedBySuffix(): void
    {
        $this->app(['backupRetention' => 2, 'ftpDirectory' => '/']);
        $client = new FakeFtpClient();

        $time = $this->days(1);
        $first = $this->backupName($time);
        $second = $this->backupName($time, 'backup-', '-2');
        $third = $this->backupName($time, 'backup-', '-3');
        foreach ([$first, $second, $third] as $name) {
            $client->addFile('/' . $name);
        }

        $manager = new BackupManager();
        $manager->cleanupFtpBackups($client, $manager->getSettings());

        $this->assertSame(['/' . $first], $client->deleted);
    }

    public function testDeleteFromFtpDisabledLeavesServerUntouched(): void
    {
        $this->app(['backupRetention' => 1, 'deleteFromFtp' => false, 'ftpDirectory' => '/']);
        $client = new FakeFtpClient();
        foreach ([1, 2, 3] as $age) {
            $client->addFile('/' . $this->backupName($this->days($age)));
        }

        $manager = new BackupManager();
        $manager->cleanupOldBackups($client, $manager->getSettings());

        $this->assertSame([], $client->deleted);
    }
}
