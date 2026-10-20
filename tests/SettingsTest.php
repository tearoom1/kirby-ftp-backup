<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\BackupManager;

/**
 * The Panel status and the actual connection must agree on what "configured" means
 */
class SettingsTest extends PluginTestCase
{
    public static function configurations(): array
    {
        return [
            'password login' => [['ftpHost' => 'h', 'ftpUsername' => 'u', 'ftpPassword' => 'p'], true],
            'empty password' => [['ftpHost' => 'h', 'ftpUsername' => 'u'], true],
            'key login' => [['ftpProtocol' => 'sftp', 'ftpHost' => 'h', 'ftpUsername' => 'u', 'ftpPrivateKey' => '/k'], true],
            'key without user' => [['ftpProtocol' => 'sftp', 'ftpHost' => 'h', 'ftpPrivateKey' => '/k'], false],
            'missing host' => [['ftpUsername' => 'u', 'ftpPassword' => 'p'], false],
            'unknown protocol' => [['ftpProtocol' => 'scp', 'ftpHost' => 'h', 'ftpUsername' => 'u'], false],
            'ftp disabled' => [['ftpEnabled' => false, 'ftpHost' => 'h', 'ftpUsername' => 'u'], false],
        ];
    }

    /**
     * @dataProvider configurations
     */
    public function testFtpConfigurationCheck(array $options, bool $expected): void
    {
        $this->app(array_merge(['ftpEnabled' => true], $options));
        $manager = new BackupManager();

        $this->assertSame($expected, $manager->isFtpConfigured());

        if ($expected === false && ($options['ftpEnabled'] ?? true)) {
            $this->assertNotSame('', $manager->ftpConfigurationError());
        }
    }
}
