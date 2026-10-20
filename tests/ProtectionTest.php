<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\BackupManager;

/**
 * Protected backups survive retention; single backups can be deleted from the Panel
 */
class ProtectionTest extends PluginTestCase
{
    public function testProtectedBackupSurvivesLocalRetention(): void
    {
        $this->app(['backupRetention' => 1]);
        $this->writeContent('home/home.txt');
        $protected = $this->backupName($this->days(30));
        $this->createLocalBackup($protected, 30);
        $this->createLocalBackup($this->backupName($this->days(2)), 2);
        $manager = new BackupManager();

        $this->assertSame('success', $manager->protectBackup($protected, true)['status']);
        $result = $manager->createBackup();

        $this->assertEqualsCanonicalizing([$protected, $result['data']['filename']], $this->localBackups());
        $listed = array_column($manager->listBackupFiles(), 'protected', 'filename');
        $this->assertTrue($listed[$protected]);
        $this->assertFalse($listed[$result['data']['filename']]);
    }

    public function testProtectedBackupSurvivesRemoteRetention(): void
    {
        $this->app(['backupRetention' => 1, 'ftpDirectory' => '/']);
        $protected = $this->backupName($this->days(30));
        $other = $this->backupName($this->days(2));
        $newest = $this->backupName($this->days(1));
        $client = new FakeFtpClient();
        foreach ([$protected, $other, $newest] as $name) {
            $client->addFile('/' . $name);
        }
        $manager = new BackupManager();
        $manager->protectBackup($protected, true);

        $manager->cleanupFtpBackups($client, $manager->getSettings());

        $this->assertSame(['/' . $other], $client->deleted);
    }

    public function testProtectionCanBeRemoved(): void
    {
        $this->app(['backupRetention' => 1]);
        $this->writeContent('home/home.txt');
        $name = $this->backupName($this->days(30));
        $this->createLocalBackup($name, 30);
        $manager = new BackupManager();
        $manager->protectBackup($name, true);

        $manager->protectBackup($name, false);
        $result = $manager->createBackup();

        $this->assertSame([$result['data']['filename']], $this->localBackups());
    }

    public function testDeleteRemovesTheLocalFile(): void
    {
        $name = $this->backupName($this->days(1));
        $this->createLocalBackup($name, 1);

        $result = (new BackupManager())->deleteBackup($name);

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $this->localBackups());
    }

    public function testDeleteCanAlsoRemoveTheCopyOnTheServer(): void
    {
        $this->app(['ftpEnabled' => true, 'ftpDirectory' => 'backups']);
        $name = $this->backupName($this->days(1));
        $this->createLocalBackup($name, 1);
        $client = new FakeFtpClient();
        $client->addFile('backups/' . $name);

        $result = $this->managerWithClient($client)->deleteBackup($name, true);

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertSame(['backups/' . $name], $client->deleted);
        $this->assertSame([], $this->localBackups());
    }

    public function testRemoteOnlyBackupCanBeDeleted(): void
    {
        $this->app(['ftpEnabled' => true, 'ftpDirectory' => 'backups']);
        $name = $this->backupName($this->days(1));
        $client = new FakeFtpClient();
        $client->addFile('backups/' . $name);

        $result = $this->managerWithClient($client)->deleteBackup($name, true);

        $this->assertSame('success', $result['status'], $result['message']);
        $this->assertSame(['backups/' . $name], $client->deleted);
    }

    public function testProtectedBackupCannotBeDeleted(): void
    {
        $name = $this->backupName($this->days(1));
        $this->createLocalBackup($name, 1);
        $manager = new BackupManager();
        $manager->protectBackup($name, true);

        $result = $manager->deleteBackup($name);

        $this->assertSame('error', $result['status']);
        $this->assertSame([$name], $this->localBackups());
    }

    public static function invalidNames(): array
    {
        return [['../config.php'], ['a/b.zip'], ['.lock'], ['backup.txt']];
    }

    /**
     * @dataProvider invalidNames
     */
    public function testInvalidNamesAreRejected(string $name): void
    {
        $manager = new BackupManager();

        $this->assertSame('error', $manager->deleteBackup($name)['status']);
        $this->assertSame('error', $manager->protectBackup($name, true)['status']);
    }
}
