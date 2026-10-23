<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\BackupManager;

/**
 * The list of backups on the server is cached, so a Panel visit does not always connect
 */
class ServerCacheTest extends PluginTestCase
{
    private FakeFtpClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new FakeFtpClient();
        $this->client->addFile('/backups/' . $this->backupName($this->days(1)), 0, 100);
    }

    private function ftpApp(array $options = []): void
    {
        $this->app([
            'ftpEnabled' => true, 'ftpHost' => 'h', 'ftpUsername' => 'u', 'ftpPassword' => 'p',
            'ftpDirectory' => '/backups', ...$options,
        ]);
    }

    private function manager(): BackupManager
    {
        return $this->managerWithClient($this->client);
    }

    /**
     * Move the cached listing back in time, as if it was read `$minutes` ago
     */
    private function ageCache(int $minutes): void
    {
        $manager = new BackupManager();
        $key = (fn () => $this->serverCacheKey($this->getSettings()))->call($manager);
        $cache = kirby()->cache('tearoom1.kirby-ftp-backup');
        $entry = $cache->get($key);
        $entry['checkedAt'] -= $minutes * 60;
        $cache->set($key, $entry);
    }

    public function testSecondCallUsesTheCachedListing(): void
    {
        $this->ftpApp();

        $first = $this->manager()->getFtpServerStats();
        $second = $this->manager()->getFtpServerStats();

        $this->assertSame(1, $this->client->connects);
        $this->assertFalse($first['data']['cached']);
        $this->assertTrue($second['data']['cached']);
        $this->assertSame($first['data']['files'], $second['data']['files']);
        $this->assertSame($first['data']['checkedAt'], $second['data']['checkedAt']);
        $this->assertEqualsWithDelta(time(), $second['data']['checkedAt'], 2);
    }

    public function testListingExpiresAfterTheConfiguredMinutes(): void
    {
        $this->ftpApp(['serverCacheMinutes' => 10]);
        $this->manager()->getFtpServerStats();

        $this->ageCache(9);
        $this->manager()->getFtpServerStats();
        $this->assertSame(1, $this->client->connects, 'Still valid after 9 minutes');

        $this->ageCache(2);
        $result = $this->manager()->getFtpServerStats();
        $this->assertSame(2, $this->client->connects, 'Read again after 11 minutes');
        $this->assertFalse($result['data']['cached']);
    }

    public function testFreshAlwaysReadsTheServer(): void
    {
        $this->ftpApp();
        $this->manager()->getFtpServerStats();

        $result = $this->manager()->getFtpServerStats(true);

        $this->assertSame(2, $this->client->connects);
        $this->assertFalse($result['data']['cached']);
    }

    public function testZeroMinutesTurnsTheCacheOff(): void
    {
        $this->ftpApp(['serverCacheMinutes' => 0]);

        $this->manager()->getFtpServerStats();
        $this->manager()->getFtpServerStats();

        $this->assertSame(2, $this->client->connects);
        $this->assertNull((new BackupManager())->cachedServerStats());
    }

    public function testUploadInvalidatesTheListing(): void
    {
        $this->ftpApp();
        $this->writeContent('home/home.txt');
        $this->manager()->getFtpServerStats();

        $backup = $this->manager()->createBackup();
        $this->assertSame('success', $backup['status'], $backup['message']);
        $connectsAfterBackup = $this->client->connects;
        $this->assertNull((new BackupManager())->cachedServerStats());

        $result = $this->manager()->getFtpServerStats();

        $this->assertSame($connectsAfterBackup + 1, $this->client->connects);
        $this->assertContains($backup['data']['filename'], array_column($result['data']['files'], 'filename'));
    }

    public function testRetentionCleanupOnTheServerInvalidatesTheListing(): void
    {
        $this->ftpApp(['backupRetention' => 1]);
        $this->client->addFile('/backups/' . $this->backupName($this->days(2)), 0, 100);
        $this->manager()->getFtpServerStats();

        $manager = new BackupManager();
        $manager->cleanupFtpBackups($this->client, $manager->getSettings());

        $this->assertNull($manager->cachedServerStats());
        $this->assertCount(1, $this->manager()->getFtpServerStats()['data']['files']);
    }

    public function testDeletingTheServerCopyInvalidatesTheListing(): void
    {
        $this->ftpApp();
        $name = $this->backupName($this->days(1));
        $this->manager()->getFtpServerStats();

        $result = $this->manager()->deleteBackup($name, true);
        $this->assertSame('success', $result['status'], $result['message']);

        $this->assertSame([], $this->manager()->getFtpServerStats()['data']['files']);
    }

    public function testUnreachableServerReturnsTheLastListingMarkedStale(): void
    {
        $this->ftpApp();
        $first = $this->manager()->getFtpServerStats();
        $this->client->connectError = new \Exception('Connection refused');

        $result = $this->manager()->getFtpServerStats(true);

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['data']['stale']);
        $this->assertStringContainsString('Connection refused', $result['data']['error']);
        $this->assertSame($first['data']['files'], $result['data']['files']);
        $this->assertSame($first['data']['checkedAt'], $result['data']['checkedAt']);
    }

    public function testUnreachableServerWithoutCacheIsAnError(): void
    {
        $this->ftpApp();
        $this->client->connectError = new \Exception('Connection refused');

        $result = $this->manager()->getFtpServerStats();

        $this->assertSame('error', $result['status']);
    }

    public function testCachedListingReflectsProtectionChangedLater(): void
    {
        $this->ftpApp();
        $name = $this->backupName($this->days(1));
        $this->manager()->getFtpServerStats();

        (new BackupManager())->protectBackup($name, true);
        $result = $this->manager()->getFtpServerStats();

        $this->assertTrue($result['data']['cached']);
        $this->assertTrue($result['data']['files'][0]['protected']);
    }

    public function testPanelPropsGetTheCachedListingWithoutConnecting(): void
    {
        $this->ftpApp();
        $this->assertNull((new BackupManager())->cachedServerStats(), 'Nothing cached yet');

        $this->manager()->getFtpServerStats();
        $stats = (new BackupManager())->cachedServerStats();

        $this->assertSame(1, $this->client->connects);
        $this->assertSame(1, $stats['count']);
        $this->assertSame(100, $stats['totalSize']);
    }

    public function testAnotherTargetDoesNotUseTheCachedListing(): void
    {
        $this->ftpApp();
        $this->manager()->getFtpServerStats();

        $this->ftpApp(['ftpDirectory' => '/other']);
        $result = $this->manager()->getFtpServerStats();

        $this->assertSame(2, $this->client->connects);
        $this->assertSame([], $result['data']['files']);
    }
}
