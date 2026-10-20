<?php

namespace TearoomOne\FtpBackup\Tests;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use TearoomOne\FtpBackup\SftpClient;

/**
 * Private keys of every common type must load; failures must say what is wrong
 */
class SftpClientTest extends PluginTestCase
{
    public static function keyTypes(): array
    {
        return [
            'ed25519' => [fn () => EC::createKey('Ed25519')],
            'ecdsa' => [fn () => EC::createKey('nistp256')],
            'rsa' => [fn () => RSA::createKey(2048)],
        ];
    }

    /**
     * @dataProvider keyTypes
     */
    public function testKeyIsLoadedBeforeConnecting(callable $createKey): void
    {
        $keyFile = $this->writeKey($createKey()->toString('OpenSSH'));

        $message = $this->connectError(new SftpClient('127.0.0.1', 1, 'user', '', $keyFile, null, 2));

        // Getting as far as the connection proves the key was accepted
        $this->assertStringContainsString('sftp://user@127.0.0.1:1', $message);
        $this->assertStringNotContainsStringIgnoringCase('key', $message);
    }

    public function testEncryptedKeyWithPassphrase(): void
    {
        $keyFile = $this->writeKey(EC::createKey('Ed25519')->withPassword('secret')->toString('OpenSSH'));

        $message = $this->connectError(new SftpClient('127.0.0.1', 1, 'user', '', $keyFile, 'secret', 2));

        $this->assertStringNotContainsStringIgnoringCase('key', $message);
    }

    public function testWrongPassphraseIsExplained(): void
    {
        $keyFile = $this->writeKey(EC::createKey('Ed25519')->withPassword('secret')->toString('OpenSSH'));

        $message = $this->connectError(new SftpClient('127.0.0.1', 1, 'user', '', $keyFile, 'wrong', 2));

        $this->assertStringContainsString('Could not load SFTP private key', $message);
        $this->assertStringContainsString($keyFile, $message);
    }

    public function testMissingKeyFileIsExplained(): void
    {
        $message = $this->connectError(new SftpClient('127.0.0.1', 1, 'user', '', $this->root . '/missing', null, 2));

        $this->assertStringContainsString('not readable', $message);
        $this->assertStringContainsString($this->root . '/missing', $message);
    }

    private function writeKey(string $key): string
    {
        $path = $this->root . '/id_test';
        file_put_contents($path, $key);
        return $path;
    }

    private function connectError(SftpClient $client): string
    {
        try {
            $client->connect();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $this->fail('connect() should fail against a closed port');
    }
}
