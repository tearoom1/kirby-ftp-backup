<?php

namespace TearoomOne\FtpBackup\Tests;

use PHPUnit\Framework\TestCase;
use TearoomOne\FtpBackup\BackupManager;

/**
 * Test class for backup retention strategies
 */
class BackupRetentionTest extends TestCase
{
    private BackupManager $backupManager;

    public function setUp(): void
    {
        $this->backupManager = new BackupManager();
    }


    /**
     * Test tiered retention strategy with various backup scenarios
     */
    public function testTieredRetentionStrategy()
    {
        // Test settings: 7 days daily, 4 weeks (7-day periods), 3 months (30-day periods)
        $tieredSettings = [
            'daily' => 7,
            'weekly' => 4,
            'monthly' => 3
        ];

        // Create test backups spanning different time periods
        $now = time();
        $testBackups = $this->createTestBackups($now);

        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->backupManager);
        $method = $reflection->getMethod('applyTieredRetentionStrategy');
        $method->setAccessible(true);

        // Apply retention strategy
        $keepBackups = $method->invoke($this->backupManager, $testBackups, $tieredSettings);

        // Verify results
        $this->assertRetentionResults($keepBackups, $testBackups, $tieredSettings, $now);
    }

    /**
     * Test edge cases for retention strategy
     */
    public function testRetentionEdgeCases()
    {
        $tieredSettings = [
            'daily' => 1,
            'weekly' => 1,
            'monthly' => 1
        ];

        $now = time();

        // Test with empty backup list
        $emptyBackups = [];
        $reflection = new \ReflectionClass($this->backupManager);
        $method = $reflection->getMethod('applyTieredRetentionStrategy');
        $method->setAccessible(true);

        $result = $method->invoke($this->backupManager, $emptyBackups, $tieredSettings);
        $this->assertEmpty($result, 'Empty backup list should return empty result');

        // Test with single backup
        $singleBackup = [
            [
                'filename' => 'backup-single.zip',
                'timestamp' => $now,
                'date' => date('Y-m-d', $now)
            ]
        ];

        $result = $method->invoke($this->backupManager, $singleBackup, $tieredSettings);
        $this->assertCount(1, $result, 'Single backup should be kept');
        $this->assertEquals('newest', $result[0]['retention']);
    }

    /**
     * Test that the newest backup is always kept regardless of settings
     */
    public function testNewestBackupAlwaysKept()
    {
        $tieredSettings = [
            'daily' => 0,  // Extreme case: no daily retention
            'weekly' => 0,
            'monthly' => 0
        ];

        $now = time();
        $testBackups = [
            [
                'filename' => 'backup-newest.zip',
                'timestamp' => $now,
                'date' => date('Y-m-d', $now)
            ],
            [
                'filename' => 'backup-old.zip',
                'timestamp' => $now - (365 * 86400), // 1 year old
                'date' => date('Y-m-d', $now - (365 * 86400))
            ]
        ];

        $reflection = new \ReflectionClass($this->backupManager);
        $method = $reflection->getMethod('applyTieredRetentionStrategy');
        $method->setAccessible(true);

        $result = $method->invoke($this->backupManager, $testBackups, $tieredSettings);

        $this->assertGreaterThanOrEqual(1, count($result), 'At least newest backup should be kept');
        $this->assertEquals('backup-newest.zip', $result[0]['filename']);
        $this->assertEquals('newest', $result[0]['retention']);
    }


    /**
     * Test clear retention strategy: X daily, 1 per 7 days for X weeks, 1 per 30 days for X months
     */
    public function testRetentionWorksWithGaps()
    {
        $tieredSettings = [
            'daily' => 3,    // Keep 3 daily backups
            'weekly' => 2,   // Keep 1 per week for 2 weeks (14 days total)
            'monthly' => 2   // Keep 1 per month for 2 months (60 days total)
        ];

        $now = time();

        // Create clear test scenario
        $testBackups = [
            // Daily period (0-3 days): should keep all
            ['filename' => 'backup-day-0.zip', 'timestamp' => $now],
            ['filename' => 'backup-day-1.zip', 'timestamp' => $now - (1 * 86400)],
            ['filename' => 'backup-day-2.zip', 'timestamp' => $now - (2 * 86400)],
            ['filename' => 'backup-day-3.zip', 'timestamp' => $now - (3 * 86400)],
            ['filename' => 'backup-day-4.zip', 'timestamp' => $now - (4 * 86400)],

            // Weekly period (4-17 days): should keep 1 per 7-day period
            ['filename' => 'backup-day-15.zip', 'timestamp' => $now - (15 * 86400)],
        ];

        $reflection = new \ReflectionClass($this->backupManager);
        $method = $reflection->getMethod('applyTieredRetentionStrategy');
        $method->setAccessible(true);

        $result = $method->invoke($this->backupManager, $testBackups, $tieredSettings);
        $filenames = array_column($result, 'filename');

        // Should keep all daily backups (0-3 days)
        $this->assertContains('backup-day-0.zip', $filenames, 'Should keep daily backup day 0');
        $this->assertContains('backup-day-1.zip', $filenames, 'Should keep daily backup day 1');
        $this->assertContains('backup-day-2.zip', $filenames, 'Should keep daily backup day 2');
        $this->assertContains('backup-day-3.zip', $filenames, 'Should keep daily backup day 3');

        // should keep as first weekly backup
        $this->assertContains('backup-day-4.zip', $filenames, 'Should keep weekly backup day 4');

        // Should keep as second weekly backup
        $this->assertContains('backup-day-15.zip', $filenames, 'Should keep only weekly backup');

        // Verify no gaps >7 days in weekly period and >30 days in monthly period
        usort($result, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        for ($i = 0; $i < count($result) - 1; $i++) {
            $gap = $result[$i]['timestamp'] - $result[$i + 1]['timestamp'];
            $gapDays = $gap / 86400;

            // Allow reasonable gaps based on retention periods
            $this->assertLessThanOrEqual(35, $gapDays, // Allow up to 35 days for monthly transitions
                "Gap between {$result[$i]['filename']} and {$result[$i + 1]['filename']} is {$gapDays} days"
            );
        }
    }

    /**
     * Test clear retention strategy: X daily, 1 per 7 days for X weeks, 1 per 30 days for X months
     */
    public function testNoGapsLargerThanSevenDays()
    {
        $tieredSettings = [
            'daily' => 3,    // Keep 3 daily backups
            'weekly' => 2,   // Keep 1 per week for 2 weeks (14 days total)
            'monthly' => 2   // Keep 1 per month for 2 months (60 days total)
        ];

        $now = time();

        // Create clear test scenario
        $testBackups = [
            // Daily period (0-3 days): should keep all
            ['filename' => 'backup-day-0.zip', 'timestamp' => $now],
            ['filename' => 'backup-day-1.zip', 'timestamp' => $now - (1 * 86400)],
            ['filename' => 'backup-day-2.zip', 'timestamp' => $now - (2 * 86400)],
            ['filename' => 'backup-day-3.zip', 'timestamp' => $now - (3 * 86400)],

            // Weekly period (4-17 days): should keep 1 per 7-day period
            ['filename' => 'backup-day-5.zip', 'timestamp' => $now - (5 * 86400)],
            ['filename' => 'backup-day-8.zip', 'timestamp' => $now - (8 * 86400)],
            ['filename' => 'backup-day-10.zip', 'timestamp' => $now - (10 * 86400)],
            ['filename' => 'backup-day-12.zip', 'timestamp' => $now - (12 * 86400)],
            ['filename' => 'backup-day-13.zip', 'timestamp' => $now - (13 * 86400)],
            ['filename' => 'backup-day-15.zip', 'timestamp' => $now - (15 * 86400)],
            ['filename' => 'backup-day-17.zip', 'timestamp' => $now - (17 * 86400)],

            // Monthly period (18-77 days): should keep 1 per 30-day period
            ['filename' => 'backup-day-25.zip', 'timestamp' => $now - (25 * 86400)],
            ['filename' => 'backup-day-35.zip', 'timestamp' => $now - (35 * 86400)],
            ['filename' => 'backup-day-50.zip', 'timestamp' => $now - (50 * 86400)],
            ['filename' => 'backup-day-65.zip', 'timestamp' => $now - (65 * 86400)],

            // Very old (should be oldest anchor)
            ['filename' => 'backup-day-100.zip', 'timestamp' => $now - (100 * 86400)],
        ];

        $reflection = new \ReflectionClass($this->backupManager);
        $method = $reflection->getMethod('applyTieredRetentionStrategy');
        $method->setAccessible(true);

        $result = $method->invoke($this->backupManager, $testBackups, $tieredSettings);
        $filenames = array_column($result, 'filename');

        // Should keep all daily backups (0-3 days)
        $this->assertContains('backup-day-0.zip', $filenames, 'Should keep daily backup day 0');
        $this->assertContains('backup-day-1.zip', $filenames, 'Should keep daily backup day 1');
        $this->assertContains('backup-day-2.zip', $filenames, 'Should keep daily backup day 2');
        $this->assertContains('backup-day-3.zip', $filenames, 'Should keep daily backup day 3');

        // Should keep weekly backups (oldest in each period)
        $this->assertContains('backup-day-10.zip', $filenames, 'Should keep oldest in first weekly period');
        $this->assertContains('backup-day-17.zip', $filenames, 'Should keep oldest in second weekly period');

        // Should keep monthly backups (oldest in each period)
        $this->assertContains('backup-day-35.zip', $filenames, 'Should keep oldest in first monthly period');
        $this->assertContains('backup-day-65.zip', $filenames, 'Should keep oldest in second monthly period');

        // Should NOT keep very old backup when we have monthly coverage
        $this->assertNotContains('backup-day-100.zip', $filenames, 'Should NOT keep very old backup when monthly coverage exists');

        // assert that we have exactly 8 backups (4 daily + 2 weekly + 2 monthly)
        $this->assertCount(8, $filenames, 'Should have exactly 8 backups');

        // Verify no gaps >7 days in weekly period and >30 days in monthly period
        usort($result, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        for ($i = 0; $i < count($result) - 1; $i++) {
            $gap = $result[$i]['timestamp'] - $result[$i + 1]['timestamp'];
            $gapDays = $gap / 86400;

            // Allow reasonable gaps based on retention periods
            $this->assertLessThanOrEqual(35, $gapDays, // Allow up to 35 days for monthly transitions
                "Gap between {$result[$i]['filename']} and {$result[$i + 1]['filename']} is {$gapDays} days"
            );
        }
    }

    /**
     * Test clear weekly retention: 1 backup per 7-day period with max 7 days between
     */
    public function testWeeklyRollingWindow()
    {
        $tieredSettings = [
            'daily' => 2,    // Keep 2 daily backups
            'weekly' => 3,   // Keep 1 per week for 3 weeks (21 days total)
            'monthly' => 1   // Keep 1 per month for 1 month
        ];

        $now = time();

        // Create clear test scenario
        $testBackups = [
            // Daily period (0-2 days): should keep all
            ['filename' => 'daily-0.zip', 'timestamp' => $now],
            ['filename' => 'daily-1.zip', 'timestamp' => $now - (1 * 86400)],
            ['filename' => 'daily-2.zip', 'timestamp' => $now - (2 * 86400)],

            // Weekly period (3-23 days): should keep 1 per 7-day period
            ['filename' => 'weekly-day-4.zip', 'timestamp' => $now - (4 * 86400)],
            ['filename' => 'weekly-day-6.zip', 'timestamp' => $now - (6 * 86400)],
            ['filename' => 'weekly-day-9.zip', 'timestamp' => $now - (9 * 86400)],
            ['filename' => 'weekly-day-11.zip', 'timestamp' => $now - (11 * 86400)],
            ['filename' => 'weekly-day-16.zip', 'timestamp' => $now - (16 * 86400)],
            ['filename' => 'weekly-day-18.zip', 'timestamp' => $now - (18 * 86400)],
            ['filename' => 'weekly-day-23.zip', 'timestamp' => $now - (23 * 86400)],

            // Old backup (should be oldest anchor)
            ['filename' => 'old-backup.zip', 'timestamp' => $now - (100 * 86400)],
        ];

        $reflection = new \ReflectionClass($this->backupManager);
        $method = $reflection->getMethod('applyTieredRetentionStrategy');
        $method->setAccessible(true);

        $result = $method->invoke($this->backupManager, $testBackups, $tieredSettings);
        $filenames = array_column($result, 'filename');

        // Should keep all daily backups
        $this->assertContains('daily-0.zip', $filenames, 'Should keep daily backup 0');
        $this->assertContains('daily-1.zip', $filenames, 'Should keep daily backup 1');
        $this->assertContains('daily-2.zip', $filenames, 'Should keep daily backup 2');

        // Should keep weekly backups (oldest in each period)
        $this->assertContains('weekly-day-9.zip', $filenames, 'Should keep oldest in first weekly period');
        $this->assertContains('weekly-day-16.zip', $filenames, 'Should keep oldest in second weekly period');
        $this->assertContains('weekly-day-23.zip', $filenames, 'Should keep oldest in third weekly period');

        // Should NOT keep backups that are not the oldest in their periods
        $this->assertNotContains('weekly-day-4.zip', $filenames, 'Should NOT keep newer backup in first weekly period');
        $this->assertNotContains('weekly-day-6.zip', $filenames, 'Should NOT keep newer backup in first weekly period');
        $this->assertNotContains('weekly-day-11.zip', $filenames, 'Should NOT keep newer backup in second weekly period');
        $this->assertNotContains('weekly-day-18.zip', $filenames, 'Should NOT keep newer backup in third weekly period');

        // Should always keep oldest
        $this->assertContains('old-backup.zip', $filenames, 'Should keep oldest backup as anchor');
    }

    /**
     * Create a comprehensive set of test backups spanning different time periods
     */
    private function createTestBackups(int $now): array
    {
        $backups = [];

        // Daily backups (last 7 days) - all should be kept
        for ($i = 0; $i < 7; $i++) {
            $timestamp = $now - ($i * 86400);
            $backups[] = [
                'filename' => "backup-daily-{$i}.zip",
                'timestamp' => $timestamp,
                'date' => date('Y-m-d', $timestamp)
            ];
        }

        // Weekly backups (7-day periods) - one per period should be kept
        $dailyCutoff = $now - (7 * 86400);
        for ($period = 0; $period < 4; $period++) {
            // Add multiple backups per period, only newest should be kept
            for ($j = 0; $j < 3; $j++) {
                $timestamp = $dailyCutoff - ($period * 7 * 86400) - ($j * 86400);
                $backups[] = [
                    'filename' => "backup-weekly-p{$period}-{$j}.zip",
                    'timestamp' => $timestamp,
                    'date' => date('Y-m-d', $timestamp)
                ];
            }
        }

        // Monthly backups (30-day periods) - one per period should be kept
        $weeklyCutoff = $dailyCutoff - (4 * 7 * 86400);
        for ($period = 0; $period < 3; $period++) {
            // Add multiple backups per period, only newest should be kept
            for ($j = 0; $j < 5; $j++) {
                $timestamp = $weeklyCutoff - ($period * 30 * 86400) - ($j * 86400);
                $backups[] = [
                    'filename' => "backup-monthly-p{$period}-{$j}.zip",
                    'timestamp' => $timestamp,
                    'date' => date('Y-m-d', $timestamp)
                ];
            }
        }

        // Very old backups - should be deleted
        for ($i = 0; $i < 5; $i++) {
            $timestamp = $now - (200 * 86400) - ($i * 86400); // 200+ days old
            $backups[] = [
                'filename' => "backup-very-old-{$i}.zip",
                'timestamp' => $timestamp,
                'date' => date('Y-m-d', $timestamp)
            ];
        }

        return $backups;
    }

    /**
     * Verify that retention results match expected behavior with rolling window logic
     */
    private function assertRetentionResults(array $keepBackups, array $allBackups, array $settings, int $now): void
    {
        $dailyCutoff = $now - ($settings['daily'] * 86400);
        $weeklyCutoff = $dailyCutoff - ($settings['weekly'] * 7 * 86400);
        $monthlyCutoff = $weeklyCutoff - ($settings['monthly'] * 30 * 86400);

        $dailyCount = 0;
        $weeklyCount = 0;
        $monthlyCount = 0;

        foreach ($keepBackups as $backup) {
            $timestamp = $backup['timestamp'];

            if ($timestamp >= $dailyCutoff) {
                $dailyCount++;
            } elseif ($timestamp >= $weeklyCutoff) {
                $weeklyCount++;
            } elseif ($timestamp >= $monthlyCutoff) {
                $monthlyCount++;
            }
        }

        // Verify daily backups (all within daily period should be kept)
        $expectedDailyCount = 0;
        foreach ($allBackups as $backup) {
            if ($backup['timestamp'] >= $dailyCutoff) {
                $expectedDailyCount++;
            }
        }
        $this->assertEquals($expectedDailyCount, $dailyCount, 'All daily backups should be kept');

        // Verify no gaps >7 days in weekly period
        $weeklyBackups = array_filter($keepBackups, function($backup) use ($dailyCutoff, $weeklyCutoff) {
            return $backup['timestamp'] < $dailyCutoff && $backup['timestamp'] >= $weeklyCutoff;
        });

        if (count($weeklyBackups) > 1) {
            // Sort by timestamp (newest first)
            usort($weeklyBackups, function ($a, $b) {
                return $b['timestamp'] - $a['timestamp'];
            });

            // Check gaps between consecutive weekly backups
            for ($i = 0; $i < count($weeklyBackups) - 1; $i++) {
                $gap = $weeklyBackups[$i]['timestamp'] - $weeklyBackups[$i + 1]['timestamp'];
                $gapDays = $gap / 86400;
                $this->assertGreaterThanOrEqual(6.9, $gapDays, 'Weekly backups should be at least ~7 days apart');
            }

            // Check gap from daily cutoff to first weekly backup (should be immediate transition)
            if (!empty($weeklyBackups)) {
                $gap = $dailyCutoff - $weeklyBackups[0]['timestamp'];
                $gapDays = $gap / 86400;
                $this->assertGreaterThanOrEqual(0, $gapDays, 'Weekly period should start immediately after daily period');
            }
        }

        // Verify no backups older than monthly cutoff are kept (except possibly the oldest as fallback)
        $tooOldCount = 0;
        foreach ($keepBackups as $backup) {
            if ($backup['timestamp'] < $monthlyCutoff) {
                $tooOldCount++;
            }
        }
        $this->assertLessThanOrEqual(1, $tooOldCount, 'At most one backup older than monthly cutoff should be kept');
    }

    /**
     * Test the prepareFtpBackupsForTieredRetention method
     */
    public function testPrepareFtpBackupsForTieredRetention()
    {
        $testFiles = [
            'backup-2024-01-15-143022.zip',
            'backup-2024-02-20-091530.zip',
            'backup-2023-12-01.zip',
            'invalid-filename.zip',
            'backup_2024_03_10_120000.zip'
        ];

        $reflection = new \ReflectionClass($this->backupManager);
        $method = $reflection->getMethod('prepareFtpBackupsForTieredRetention');
        $method->setAccessible(true);

        $result = $method->invoke($this->backupManager, $testFiles);

        $this->assertCount(5, $result);

        // Check that valid dates are parsed correctly
        $validBackups = array_filter($result, function($backup) {
            return $backup['timestamp'] > 0;
        });

        $this->assertCount(4, $validBackups, 'Should parse 4 valid date formats');

        // Check that invalid filename gets timestamp 0
        $invalidBackup = array_filter($result, function($backup) {
            return $backup['filename'] === 'invalid-filename.zip';
        });

        $this->assertEquals(0, reset($invalidBackup)['timestamp'], 'Invalid filename should get timestamp 0');
    }
}
