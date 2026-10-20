<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\BackupManager;

/**
 * Encryption and additional folders
 */
class ArchiveOptionsTest extends PluginTestCase
{
    public function testArchiveIsEncryptedWithThePassword(): void
    {
        if (!\ZipArchive::isEncryptionMethodSupported(\ZipArchive::EM_AES_256)) {
            $this->markTestSkipped('libzip without AES support');
        }
        $this->app(['archivePassword' => 'correct horse']);
        $this->writeContent('home/home.txt', 'Title: Secret');

        $result = (new BackupManager())->createBackup();

        $this->assertSame('success', $result['status'], $result['message']);
        $zip = new \ZipArchive();
        $zip->open($this->backupDir . '/' . $result['data']['filename']);
        $this->assertSame(\ZipArchive::EM_AES_256, $zip->statName('home/home.txt')['encryption_method']);
        $this->assertFalse($zip->getFromName('home/home.txt'), 'Readable without password');
        $zip->setPassword('correct horse');
        $this->assertSame('Title: Secret', $zip->getFromName('home/home.txt'));
        $zip->close();
    }

    public function testArchiveIsNotEncryptedByDefault(): void
    {
        $this->writeContent('home/home.txt', 'Title: Open');

        $result = (new BackupManager())->createBackup();

        $zip = new \ZipArchive();
        $zip->open($this->backupDir . '/' . $result['data']['filename']);
        $this->assertSame('Title: Open', $zip->getFromName('home/home.txt'));
        $zip->close();
    }

    public function testAdditionalPathsAreArchivedUnderTheirOwnFolder(): void
    {
        $this->app(['additionalPaths' => ['site/accounts', 'site/blueprints/site.yml']]);
        $this->writeContent('home/home.txt');
        mkdir($this->root . '/site/accounts/abc', 0777, true);
        file_put_contents($this->root . '/site/accounts/abc/index.php', '<?php return [];');
        mkdir($this->root . '/site/blueprints', 0777, true);
        file_put_contents($this->root . '/site/blueprints/site.yml', 'title: Site');

        $result = (new BackupManager())->createBackup();

        $this->assertSame('success', $result['status'], $result['message']);
        $entries = $this->zipEntries($this->backupDir . '/' . $result['data']['filename']);
        $this->assertContains('home/home.txt', $entries);
        $this->assertContains('_additional/site/accounts/abc/index.php', $entries);
        $this->assertContains('_additional/site/blueprints/site.yml', $entries);
    }

    public function testExcludePatternsApplyToAdditionalPaths(): void
    {
        $this->app(['additionalPaths' => ['site/accounts'], 'excludePatterns' => ['\.cache$']]);
        $this->writeContent('home/home.txt');
        mkdir($this->root . '/site/accounts/abc', 0777, true);
        file_put_contents($this->root . '/site/accounts/abc/index.php', '<?php return [];');
        file_put_contents($this->root . '/site/accounts/abc/.cache', 'x');

        $result = (new BackupManager())->createBackup();

        $entries = $this->zipEntries($this->backupDir . '/' . $result['data']['filename']);
        $this->assertContains('_additional/site/accounts/abc/index.php', $entries);
        $this->assertNotContains('_additional/site/accounts/abc/.cache', $entries);
    }

    public static function invalidAdditionalPaths(): array
    {
        return [
            'missing' => ['site/missing', 'does not exist'],
            'content itself' => ['content', 'already'],
            'inside content' => ['content/home', 'already'],
        ];
    }

    /**
     * @dataProvider invalidAdditionalPaths
     */
    public function testInvalidAdditionalPathsAreReported(string $path, string $message): void
    {
        $this->app(['additionalPaths' => [$path]]);
        $this->writeContent('home/home.txt');

        $result = (new BackupManager())->createBackup();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString($message, $result['message']);
        $this->assertSame([], $this->localBackups());
    }
}
