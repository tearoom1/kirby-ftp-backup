<?php

/**
 * Simple test runner for FTP Backup plugin retention tests
 * Run this from the command line: php test-runner.php
 */

// Bootstrap Kirby
$kirbyRoot = dirname(dirname(dirname(__DIR__)));
require_once $kirbyRoot . '/public/index.php';

// Include plugin files
require_once __DIR__ . '/src/BackupManager.php';

use TearoomOne\FtpBackup\BackupManager;

class SimpleTestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): void
    {
        echo "=== FTP Backup Plugin - Retention Strategy Tests ===\n\n";

        $this->testTieredRetentionBasic();
        $this->testTieredRetentionEdgeCases();
        $this->testNoGapsLargerThanSevenDays();
        $this->testWeeklyRollingWindow();
        $this->testWeeklyPeriodBucketingEarly();
        $this->testWeeklyPeriodBucketing();
        $this->testNewestBackupAlwaysKept();
        $this->testOldestBackupForMonthlyBuckets();
        $this->testPrepareFtpBackups();

        $this->printResults();
    }

    private function testTieredRetentionBasic(): void
    {
        echo "Testing basic tiered retention strategy...\n";

        try {
            $backupManager = new BackupManager();
            $tieredSettings = [
                'daily' => 7,
                'weekly' => 4,
                'monthly' => 3
            ];

            $now = time();
            $testBackups = $this->createTestBackups($now);

            // Use reflection to access private method
            $reflection = new \ReflectionClass($backupManager);
            $method = $reflection->getMethod('applyTieredRetentionStrategy');
            $method->setAccessible(true);

            $keepBackups = $method->invoke($backupManager, $testBackups, $tieredSettings);

            // Basic assertions
            $this->assert(count($keepBackups) > 0, "Should keep some backups");
            $this->assert(count($keepBackups) < count($testBackups), "Should delete some backups");

            // Check that newest backup is kept
            $newestKept = max(array_column($keepBackups, 'timestamp'));
            $newestAll = max(array_column($testBackups, 'timestamp'));
            $this->assert($newestKept === $newestAll, "Newest backup should be kept");

            echo "✓ Basic tiered retention test passed\n";
            $this->passed++;

        } catch (Exception $e) {
            echo "✗ Basic tiered retention test failed: " . $e->getMessage() . "\n";
            $this->failed++;
            $this->failures[] = "Basic tiered retention: " . $e->getMessage();
        }
    }

    private function testTieredRetentionEdgeCases(): void
    {
        echo "Testing edge cases...\n";

        try {
            $backupManager = new BackupManager();
            $reflection = new \ReflectionClass($backupManager);
            $method = $reflection->getMethod('applyTieredRetentionStrategy');
            $method->setAccessible(true);

            // Test empty array
            $result = $method->invoke($backupManager, [], ['daily' => 1, 'weekly' => 1, 'monthly' => 1]);
            $this->assert(empty($result), "Empty input should return empty result");

            // Test single backup
            $singleBackup = [
                [
                    'filename' => 'test.zip',
                    'timestamp' => time(),
                    'date' => date('Y-m-d')
                ]
            ];
            $result = $method->invoke($backupManager, $singleBackup, ['daily' => 1, 'weekly' => 1, 'monthly' => 1]);
            $this->assert(count($result) === 1, "Single backup should be kept");

            echo "✓ Edge cases test passed\n";
            $this->passed++;

        } catch (Exception $e) {
            echo "✗ Edge cases test failed: " . $e->getMessage() . "\n";
            $this->failed++;
            $this->failures[] = "Edge cases: " . $e->getMessage();
        }
    }

    private function testNoGapsLargerThanSevenDays(): void
    {
        echo "Testing no gaps larger than 7 days (bug fix)...\n";

        try {
            $backupManager = new BackupManager();
            $reflection = new \ReflectionClass($backupManager);
            $method = $reflection->getMethod('applyTieredRetentionStrategy');
            $method->setAccessible(true);

            // Reproduce the exact scenario from the user's screenshot
            $tieredSettings = [
                'daily' => 1,    // Keep all backups for 1 day
                'weekly' => 2,   // Keep backups with rolling 7-day window for 2 weeks
                'monthly' => 2   // Keep monthly backups
            ];

            $now = time();
            $dailyCutoff = $now - (1 * 86400);

            // Create test scenario that reproduces the original bug:
            // Backups on day 2 (Aug 9 equivalent) and day 12 (Aug 19 equivalent)
            // The old algorithm would create a 10-day gap
            $testBackups = [
                // Daily period
                ['filename' => 'backup-day-0.zip', 'timestamp' => $now, 'date' => date('Y-m-d', $now)],
                
                // Weekly period - these should be kept with no >7-day gaps
                ['filename' => 'backup-day-2.zip', 'timestamp' => $dailyCutoff - (1 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (1 * 86400))], // 2 days ago from cutoff
                ['filename' => 'backup-day-5.zip', 'timestamp' => $dailyCutoff - (4 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (4 * 86400))], // 5 days ago from cutoff  
                ['filename' => 'backup-day-9.zip', 'timestamp' => $dailyCutoff - (8 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (8 * 86400))], // 9 days ago from cutoff
                ['filename' => 'backup-day-12.zip', 'timestamp' => $dailyCutoff - (11 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (11 * 86400))], // 12 days ago from cutoff
                ['filename' => 'backup-day-15.zip', 'timestamp' => $dailyCutoff - (14 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (14 * 86400))], // 15 days ago from cutoff
            ];

            $result = $method->invoke($backupManager, $testBackups, $tieredSettings);

            // Sort results by timestamp for gap analysis
            usort($result, function ($a, $b) {
                return $b['timestamp'] - $a['timestamp'];
            });

            // Check that no gap between consecutive kept backups is >7 days
            for ($i = 0; $i < count($result) - 1; $i++) {
                $gap = $result[$i]['timestamp'] - $result[$i + 1]['timestamp'];
                $gapDays = $gap / 86400;
                
                $this->assert($gapDays <= 7.1, // Allow small floating point tolerance
                    "Gap between {$result[$i]['filename']} and {$result[$i + 1]['filename']} is {$gapDays} days, should be ≤7"
                );
            }

            // Verify specific expected behavior
            $filenames = array_column($result, 'filename');
            $this->assert(in_array('backup-day-0.zip', $filenames), 'Should keep daily backup');
            $this->assert(in_array('backup-day-2.zip', $filenames), 'Should keep first weekly backup');
            $this->assert(in_array('backup-day-9.zip', $filenames), 'Should keep backup ≥7 days after previous');

            // Should NOT have a >7-day gap like the original bug
            $this->assert(count($result) >= 3, 'Should keep enough backups to prevent large gaps');

            echo "✓ No gaps larger than 7 days test passed\n";
            $this->passed++;

        } catch (Exception $e) {
            echo "✗ No gaps larger than 7 days test failed: " . $e->getMessage() . "\n";
            $this->failed++;
            $this->failures[] = "No gaps >7 days: " . $e->getMessage();
        }
    }

    private function testWeeklyRollingWindow(): void
    {
        echo "Testing weekly rolling window logic...\n";

        try {
            $backupManager = new BackupManager();
            $reflection = new \ReflectionClass($backupManager);
            $method = $reflection->getMethod('applyTieredRetentionStrategy');
            $method->setAccessible(true);

            $tieredSettings = [
                'daily' => 3,
                'weekly' => 3,
                'monthly' => 2
            ];

            $now = time();
            $dailyCutoff = $now - (3 * 86400);

            // Create backups with specific gaps to test rolling window
            $testBackups = [
                // Daily period (should all be kept)
                ['filename' => 'daily-1.zip', 'timestamp' => $now - (1 * 86400), 'date' => date('Y-m-d', $now - (1 * 86400))],
                ['filename' => 'daily-2.zip', 'timestamp' => $now - (2 * 86400), 'date' => date('Y-m-d', $now - (2 * 86400))],
                
                // Weekly period - test rolling window
                ['filename' => 'weekly-day-4.zip', 'timestamp' => $dailyCutoff - (1 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (1 * 86400))], // 4 days ago total
                ['filename' => 'weekly-day-6.zip', 'timestamp' => $dailyCutoff - (3 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (3 * 86400))], // 6 days ago total
                ['filename' => 'weekly-day-11.zip', 'timestamp' => $dailyCutoff - (8 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (8 * 86400))], // 11 days ago total
                ['filename' => 'weekly-day-13.zip', 'timestamp' => $dailyCutoff - (10 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (10 * 86400))], // 13 days ago total
                ['filename' => 'weekly-day-18.zip', 'timestamp' => $dailyCutoff - (15 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (15 * 86400))], // 18 days ago total
            ];

            $result = $method->invoke($backupManager, $testBackups, $tieredSettings);

            $filenames = array_column($result, 'filename');

            // Should keep daily backups
            $this->assert(in_array('daily-1.zip', $filenames), "Should keep daily-1");
            $this->assert(in_array('daily-2.zip', $filenames), "Should keep daily-2");

            // Should keep first weekly backup after daily cutoff
            $this->assert(in_array('weekly-day-4.zip', $filenames), "Should keep first weekly backup");

            // Should keep weekly-day-11 (≥7 days after day-4)
            $this->assert(in_array('weekly-day-11.zip', $filenames), "Should keep backup ≥7 days after previous");

            // Should keep weekly-day-18 (≥7 days after day-11)  
            $this->assert(in_array('weekly-day-18.zip', $filenames), "Should keep backup ≥7 days after day-11");

            // Should NOT keep day-6 (too close to day-4) or day-13 (too close to day-11)
            $this->assert(!in_array('weekly-day-6.zip', $filenames), "Should NOT keep backup too close to day-4");
            $this->assert(!in_array('weekly-day-13.zip', $filenames), "Should NOT keep backup too close to day-11");

            echo "✓ Weekly rolling window test passed\n";
            $this->passed++;

        } catch (Exception $e) {
            echo "✗ Weekly rolling window test failed: " . $e->getMessage() . "\n";
            $this->failed++;
            $this->failures[] = "Weekly rolling window: " . $e->getMessage();
        }
    }

    private function testWeeklyPeriodBucketingEarly(): void
    {
        echo "Testing weekly period bucketing...\n";

        try {
            $backupManager = new BackupManager();
            $reflection = new \ReflectionClass($backupManager);
            $method = $reflection->getMethod('applyTieredRetentionStrategy');
            $method->setAccessible(true);

            $tieredSettings = ['daily' => 2, 'weekly' => 2, 'monthly' => 1];
            $now = time();
            $dailyCutoff = $now - (2 * 86400);

            // Create multiple backups in the same 7-day period after daily cutoff
            // Only the newest in each period should be kept
            $testBackups = [
                // Daily backups (should all be kept)
                ['filename' => 'daily-1.zip', 'timestamp' => $now - (1 * 86400), 'date' => date('Y-m-d', $now - (1 * 86400))],

                // Weekly period 0: 3-9 days ago (only newest should be kept)
                ['filename' => 'weekly-newer.zip', 'timestamp' => $dailyCutoff - (1 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (1 * 86400))],
                ['filename' => 'weekly-older.zip', 'timestamp' => $dailyCutoff - (3 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (3 * 86400))],

            ];

            $result = $method->invoke($backupManager, $testBackups, $tieredSettings);

            $filenames = array_column($result, 'filename');
            $retentionReasons = array_column($result, 'retention', 'filename');

            // Verify expected behavior
            $this->assert(in_array('daily-1.zip', $filenames), "Daily backup should be kept");
            $this->assert(in_array('weekly-newer.zip', $filenames), "Newer backup in weekly period should be kept");
            $this->assert(in_array('weekly-older.zip', $filenames), "Older backup in same weekly period should be kept as oldest");

            // Verify retention reasons
            $this->assert($retentionReasons['daily-1.zip'] === 'newest', "Daily backup should be marked as newest");
            $this->assert(isset($retentionReasons['weekly-newer.zip']), "Weekly backup should have retention reason");
            $this->assert($retentionReasons['weekly-older.zip'] === 'oldest-anchor', "Oldest should be marked as anchor");

            echo "✓ Weekly period bucketing test passed\n";
            $this->passed++;

        } catch (Exception $e) {
            echo "✗ Weekly period bucketing test failed: " . $e->getMessage() . "\n";
            $this->failed++;
            $this->failures[] = "Weekly bucketing: " . $e->getMessage();
        }
    }

    private function testWeeklyPeriodBucketing(): void
    {
        echo "Testing weekly period bucketing...\n";

        try {
            $backupManager = new BackupManager();
            $reflection = new \ReflectionClass($backupManager);
            $method = $reflection->getMethod('applyTieredRetentionStrategy');
            $method->setAccessible(true);

            $tieredSettings = ['daily' => 2, 'weekly' => 2, 'monthly' => 1];
            $now = time();
            $dailyCutoff = $now - (2 * 86400);

            // Create multiple backups in the same 7-day period after daily cutoff
            // Only the newest in each period should be kept
            $testBackups = [
                // Daily backups (should all be kept)
                ['filename' => 'daily-1.zip', 'timestamp' => $now - (1 * 86400), 'date' => date('Y-m-d', $now - (1 * 86400))],

                // Weekly period 0: 3-9 days ago (only newest should be kept)
                ['filename' => 'weekly-newer.zip', 'timestamp' => $dailyCutoff - (1 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (1 * 86400))],
                ['filename' => 'weekly-older.zip', 'timestamp' => $dailyCutoff - (3 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (3 * 86400))],

                // Very old backup (should be kept as oldest anchor)
                ['filename' => 'oldest.zip', 'timestamp' => $dailyCutoff - (200 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (200 * 86400))],
            ];

            $result = $method->invoke($backupManager, $testBackups, $tieredSettings);

            $filenames = array_column($result, 'filename');
            $retentionReasons = array_column($result, 'retention', 'filename');

            // Verify expected behavior
            $this->assert(in_array('daily-1.zip', $filenames), "Daily backup should be kept");
            $this->assert(in_array('weekly-newer.zip', $filenames), "Newer backup in weekly period should be kept");
            $this->assert(!in_array('weekly-older.zip', $filenames), "Older backup in same weekly period should be deleted");
            $this->assert(in_array('oldest.zip', $filenames), "Oldest backup should always be kept as anchor");

            // Verify retention reasons
            $this->assert($retentionReasons['daily-1.zip'] === 'newest', "Daily backup should be marked as newest");
            $this->assert(isset($retentionReasons['weekly-newer.zip']), "Weekly backup should have retention reason");
            $this->assert($retentionReasons['oldest.zip'] === 'oldest-anchor', "Oldest should be marked as anchor");

            echo "✓ Weekly period bucketing test passed\n";
            $this->passed++;

        } catch (Exception $e) {
            echo "✗ Weekly period bucketing test failed: " . $e->getMessage() . "\n";
            $this->failed++;
            $this->failures[] = "Weekly bucketing: " . $e->getMessage();
        }
    }

    private function testNewestBackupAlwaysKept(): void
    {
        echo "Testing that newest and oldest backups are always kept...\n";

        try {
            $backupManager = new BackupManager();
            $reflection = new \ReflectionClass($backupManager);
            $method = $reflection->getMethod('applyTieredRetentionStrategy');
            $method->setAccessible(true);

            // Extreme settings that would normally delete everything
            $tieredSettings = ['daily' => 0, 'weekly' => 0, 'monthly' => 0];
            $now = time();

            $testBackups = [
                ['filename' => 'newest.zip', 'timestamp' => $now, 'date' => date('Y-m-d', $now)],
                ['filename' => 'middle.zip', 'timestamp' => $now - (100 * 86400), 'date' => date('Y-m-d', $now - (100 * 86400))],
                ['filename' => 'oldest.zip', 'timestamp' => $now - (365 * 86400), 'date' => date('Y-m-d', $now - (365 * 86400))],
            ];

            $result = $method->invoke($backupManager, $testBackups, $tieredSettings);

            $this->assert(count($result) >= 2, "At least newest and oldest backups should be kept");

            $filenames = array_column($result, 'filename');
            $this->assert(in_array('newest.zip', $filenames), "Newest backup should be kept");
            $this->assert(in_array('oldest.zip', $filenames), "Oldest backup should be kept");
            $this->assert(!in_array('middle.zip', $filenames), "Middle backup should be deleted with extreme settings");

            // Check retention reasons
            $retentionReasons = array_column($result, 'retention', 'filename');
            $this->assert($retentionReasons['newest.zip'] === 'newest', "Newest should be marked as newest");
            $this->assert($retentionReasons['oldest.zip'] === 'oldest-anchor', "Oldest should be marked as oldest-anchor");

            echo "✓ Newest and oldest backup always kept test passed\n";
            $this->passed++;

        } catch (Exception $e) {
            echo "✗ Newest and oldest backup test failed: " . $e->getMessage() . "\n";
            $this->failed++;
            $this->failures[] = "Newest and oldest backup: " . $e->getMessage();
        }
    }

    private function testOldestBackupForMonthlyBuckets(): void
    {
        echo "Testing oldest backup is kept to fill monthly buckets...\n";

        try {
            $backupManager = new BackupManager();
            $reflection = new \ReflectionClass($backupManager);
            $method = $reflection->getMethod('applyTieredRetentionStrategy');
            $method->setAccessible(true);

            $tieredSettings = ['daily' => 0, 'weekly' => 0, 'monthly' => 3];
            $now = time();

            $testBackups = [
                ['filename' => 'newest.zip', 'timestamp' => $now, 'date' => date('Y-m-d', $now)],
                ['filename' => 'middle.zip', 'timestamp' => $now - (100 * 86400), 'date' => date('Y-m-d', $now - (100 * 86400))],
                ['filename' => 'oldest.zip', 'timestamp' => $now - (365 * 86400), 'date' => date('Y-m-d', $now - (365 * 86400))],
            ];

            $result = $method->invoke($backupManager, $testBackups, $tieredSettings);

            $this->assert(count($result) >= 2, "At least newest and oldest backups should be kept");

            $filenames = array_column($result, 'filename');
            $this->assert(in_array('newest.zip', $filenames), "Newest backup should be kept");
            $this->assert(in_array('oldest.zip', $filenames), "Oldest backup should be kept");
            $this->assert(!in_array('middle.zip', $filenames), "Middle backup should be deleted with extreme settings");

            echo "✓ Oldest backup for monthly buckets test passed\n";
            $this->passed++;

        } catch (Exception $e) {
            echo "✗ Oldest backup for monthly buckets test failed: " . $e->getMessage() . "\n";
            $this->failed++;
            $this->failures[] = "Oldest backup for monthly buckets: " . $e->getMessage();
        }
    }

    private function testPrepareFtpBackups(): void
    {
        echo "Testing FTP backup filename parsing...\n";

        try {
            $backupManager = new BackupManager();
            $reflection = new \ReflectionClass($backupManager);
            $method = $reflection->getMethod('prepareFtpBackupsForTieredRetention');
            $method->setAccessible(true);

            $testFiles = [
                'backup-2024-01-15-143022.zip',
                'backup-2024-02-20.zip',
                'invalid-filename.zip'
            ];

            $result = $method->invoke($backupManager, $testFiles);

            $this->assert(count($result) === 3, "Should process all files");

            // Check valid date parsing
            $validBackups = array_filter($result, fn($b) => $b['timestamp'] > 0);
            $this->assert(count($validBackups) === 2, "Should parse 2 valid dates");

            // Check invalid filename handling
            $invalidBackup = array_filter($result, fn($b) => $b['filename'] === 'invalid-filename.zip');
            $this->assert(reset($invalidBackup)['timestamp'] === 0, "Invalid filename should get timestamp 0");

            echo "✓ FTP backup filename parsing test passed\n";
            $this->passed++;

        } catch (Exception $e) {
            echo "✗ FTP backup filename parsing test failed: " . $e->getMessage() . "\n";
            $this->failed++;
            $this->failures[] = "FTP filename parsing: " . $e->getMessage();
        }
    }

    private function createTestBackups(int $now): array
    {
        $backups = [];

        // Daily backups (last 7 days)
        for ($i = 0; $i < 7; $i++) {
            $timestamp = $now - ($i * 86400);
            $backups[] = [
                'filename' => "backup-daily-{$i}.zip",
                'timestamp' => $timestamp,
                'date' => date('Y-m-d', $timestamp)
            ];
        }

        // Weekly backups (multiple per 7-day period)
        $dailyCutoff = $now - (7 * 86400);
        for ($period = 0; $period < 4; $period++) {
            for ($j = 0; $j < 2; $j++) {
                $timestamp = $dailyCutoff - ($period * 7 * 86400) - ($j * 86400);
                $backups[] = [
                    'filename' => "backup-weekly-p{$period}-{$j}.zip",
                    'timestamp' => $timestamp,
                    'date' => date('Y-m-d', $timestamp)
                ];
            }
        }

        // Monthly backups
        $weeklyCutoff = $dailyCutoff - (4 * 7 * 86400);
        for ($period = 0; $period < 3; $period++) {
            for ($j = 0; $j < 2; $j++) {
                $timestamp = $weeklyCutoff - ($period * 30 * 86400) - ($j * 86400);
                $backups[] = [
                    'filename' => "backup-monthly-p{$period}-{$j}.zip",
                    'timestamp' => $timestamp,
                    'date' => date('Y-m-d', $timestamp)
                ];
            }
        }

        // Very old backups
        for ($i = 0; $i < 3; $i++) {
            $timestamp = $now - (200 * 86400) - ($i * 86400);
            $backups[] = [
                'filename' => "backup-old-{$i}.zip",
                'timestamp' => $timestamp,
                'date' => date('Y-m-d', $timestamp)
            ];
        }

        return $backups;
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new Exception($message);
        }
    }

    private function printResults(): void
    {
        echo "\n=== Test Results ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        if (!empty($this->failures)) {
            echo "\nFailures:\n";
            foreach ($this->failures as $failure) {
                echo "- {$failure}\n";
            }
        }

        if ($this->failed === 0) {
            echo "\n🎉 All tests passed!\n";
        } else {
            echo "\n❌ Some tests failed.\n";
        }
    }
}

// Run tests if called directly
if (php_sapi_name() === 'cli') {
    $runner = new SimpleTestRunner();
    $runner->run();
}
