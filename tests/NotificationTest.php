<?php

namespace TearoomOne\FtpBackup\Tests;

use Kirby\Email\Email;
use TearoomOne\FtpBackup\BackupManager;

/**
 * Failed runs must not go unnoticed: notifications, last-run status and the age warning
 */
class NotificationTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Email::$debug = true;
        Email::$emails = [];
    }

    protected function tearDown(): void
    {
        Email::$debug = false;
        Email::$emails = [];
        parent::tearDown();
    }

    public function testFailedUploadSendsAnEmail(): void
    {
        $this->app(['ftpEnabled' => true, 'notifyEmail' => 'admin@example.test', 'notifyFrom' => 'backup@example.test']);
        $this->writeContent('home/home.txt');
        $client = new FakeFtpClient();
        $client->uploadError = new \Exception('Upload failed: disk full');

        $this->managerWithClient($client)->createBackup();

        $this->assertCount(1, Email::$emails);
        $email = Email::$emails[0];
        $this->assertSame(['admin@example.test' => null], $email->to());
        $this->assertSame('backup@example.test', $email->from());
        $this->assertStringContainsString('failed', $email->subject());
        $this->assertStringContainsString('disk full', $email->body()->text());
    }

    public function testSuccessfulRunSendsNothingByDefault(): void
    {
        $this->app(['notifyEmail' => 'admin@example.test']);
        $this->writeContent('home/home.txt');

        (new BackupManager())->createBackup();

        $this->assertSame([], Email::$emails);
    }

    public function testSuccessCanBeReportedToo(): void
    {
        $this->app(['notifyEmail' => ['a@example.test', 'b@example.test'], 'notifyOnSuccess' => true]);
        $this->writeContent('home/home.txt');

        (new BackupManager())->createBackup();

        $this->assertCount(1, Email::$emails);
        $this->assertSame(['a@example.test', 'b@example.test'], array_keys(Email::$emails[0]->to()));
        $this->assertStringContainsString('succeeded', Email::$emails[0]->subject());
    }

    public function testCancelledRunSendsNothing(): void
    {
        $this->app(['notifyEmail' => 'admin@example.test', 'notifyOnSuccess' => true]);
        $this->writeContent('home/home.txt');
        $jobId = '0123abcd-4567-89ef-0123-456789abcdef';
        $manager = new BackupManager();
        file_put_contents($this->backupDir . '/.cancel-' . $jobId, '1');

        $manager->createBackup(true, $jobId);

        $this->assertSame([], Email::$emails);
    }

    public function testFailedRunIsPostedToTheWebhook(): void
    {
        $received = $this->root . '/webhook.json';
        $router = $this->root . '/router.php';
        file_put_contents($router, '<?php file_put_contents(' . var_export($received, true) . ', file_get_contents("php://input")); echo "ok";');
        $port = random_int(20000, 40000);
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->waitForPort($port);

        try {
            $this->app(['notifyWebhook' => "http://127.0.0.1:{$port}/hook", 'excludePaths' => ['home']]);
            $this->writeContent('home/home.txt');

            (new BackupManager())->createBackup();
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        $payload = json_decode((string)file_get_contents($received), true);
        $this->assertSame('error', $payload['status']);
        $this->assertStringContainsString('no files', $payload['message']);
        $this->assertArrayHasKey('site', $payload);
        $this->assertArrayHasKey('time', $payload);
    }

    public function testBrokenNotificationDoesNotBreakTheBackup(): void
    {
        $this->app(['notifyWebhook' => 'http://127.0.0.1:1/unreachable', 'notifyOnSuccess' => true]);
        $this->writeContent('home/home.txt');

        $result = (new BackupManager())->createBackup();

        $this->assertSame('success', $result['status']);
    }

    public function testLastRunIsRecorded(): void
    {
        $this->app(['ftpEnabled' => true]);
        $this->writeContent('home/home.txt');
        $client = new FakeFtpClient();
        $client->uploadError = new \Exception('Upload failed: disk full');

        $manager = $this->managerWithClient($client);
        $this->assertNull($manager->lastRun());

        $result = $manager->createBackup();
        $lastRun = (new BackupManager())->lastRun();

        $this->assertSame('warning', $lastRun['status']);
        $this->assertSame($result['data']['filename'], $lastRun['filename']);
        $this->assertStringContainsString('disk full', $lastRun['message']);
        $this->assertEqualsWithDelta(time(), $lastRun['time'], 5);
    }

    public function testOutdatedBackupsAreFlagged(): void
    {
        $this->app(['maxBackupAge' => 24]);
        $manager = new BackupManager();
        $this->assertTrue($manager->backupHealth()['stale'], 'No backup at all counts as outdated');

        $this->createLocalBackup($this->backupName($this->days(2)), 2);
        $health = $manager->backupHealth();
        $this->assertTrue($health['stale']);
        $this->assertSame(24, $health['maxBackupAge']);

        $this->createLocalBackup($this->backupName($this->days(0.5)), 0.5);
        $this->assertFalse($manager->backupHealth()['stale']);

        $this->app(['maxBackupAge' => 0]);
        $this->assertFalse((new BackupManager())->backupHealth()['stale'], 'The check is off by default');
    }

    public function testStaleJobFilesFromEarlierVersionsAreRemoved(): void
    {
        $this->writeContent('home/home.txt');
        $manager = new BackupManager();
        $old = time() - 2 * 86400;
        foreach (['.progress-aaaa', '.job-bbbb', '.cancel-cccc', '.progress-dddd.tmp'] as $name) {
            touch($this->backupDir . '/' . $name, $old);
        }
        touch($this->backupDir . '/.progress-recent');

        $manager->createBackup();

        $left = array_map('basename', glob($this->backupDir . '/.{job,cancel,progress}-*', GLOB_BRACE));
        $this->assertSame(['.progress-recent'], $left);
    }

    private function waitForPort(int $port): void
    {
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        $this->fail('Webhook test server did not start');
    }
}
