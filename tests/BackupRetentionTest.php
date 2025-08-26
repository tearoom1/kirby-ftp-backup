<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\BackupManager;
use PHPUnit\Framework\TestCase;

/**
 * Test class for backup retention strategies
 */
class BackupRetentionTest extends TestCase
{
    private BackupManager $backupManager;
    
    protected function setUp(): void
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
     * Test that the new rolling window approach prevents gaps >7 days
     * This is the key test for the bug fix
     */
    public function testNoGapsLargerThanSevenDays()
    {
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
        
        $reflection = new \ReflectionClass($this->backupManager);
        $method = $reflection->getMethod('applyTieredRetentionStrategy');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->backupManager, $testBackups, $tieredSettings);
        
        // Sort results by timestamp for gap analysis
        usort($result, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        // Check that no gap between consecutive kept backups is >7 days
        for ($i = 0; $i < count($result) - 1; $i++) {
            $gap = $result[$i]['timestamp'] - $result[$i + 1]['timestamp'];
            $gapDays = $gap / 86400;
            
            $this->assertLessThanOrEqual(7.1, $gapDays, // Allow small floating point tolerance
                "Gap between {$result[$i]['filename']} and {$result[$i + 1]['filename']} is {$gapDays} days, should be ≤7"
            );
        }
        
        // Verify specific expected behavior:
        // Should keep: day-0 (daily), day-2 (first weekly), day-9 (≥7 days after day-2), day-15 (oldest anchor)
        $filenames = array_column($result, 'filename');
        $this->assertContains('backup-day-0.zip', $filenames, 'Should keep daily backup');
        $this->assertContains('backup-day-2.zip', $filenames, 'Should keep first weekly backup');
        $this->assertContains('backup-day-9.zip', $filenames, 'Should keep backup ≥7 days after previous');
        
        // Should NOT have a >7-day gap like the original bug
        $this->assertGreaterThanOrEqual(3, count($result), 'Should keep enough backups to prevent large gaps');
    }
    
    /**
     * Test weekly rolling window logic with various scenarios
     */
    public function testWeeklyRollingWindow()
    {
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
        
        $reflection = new \ReflectionClass($this->backupManager);
        $method = $reflection->getMethod('applyTieredRetentionStrategy');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->backupManager, $testBackups, $tieredSettings);
        
        $filenames = array_column($result, 'filename');
        
        // Should keep daily backups
        $this->assertContains('daily-1.zip', $filenames);
        $this->assertContains('daily-2.zip', $filenames);
        
        // Should keep first weekly backup after daily cutoff
        $this->assertContains('weekly-day-4.zip', $filenames);
        
        // Should keep weekly-day-11 (≥7 days after day-4)
        $this->assertContains('weekly-day-11.zip', $filenames);
        
        // Should keep weekly-day-18 (≥7 days after day-11)  
        $this->assertContains('weekly-day-18.zip', $filenames);
        
        // Should NOT keep day-6 (too close to day-4) or day-13 (too close to day-11)
        $this->assertNotContains('weekly-day-6.zip', $filenames);
        $this->assertNotContains('weekly-day-13.zip', $filenames);
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
            
            // Check gap from daily cutoff to first weekly backup
            if (!empty($weeklyBackups)) {
                $gap = $dailyCutoff - $weeklyBackups[0]['timestamp'];
                $gapDays = $gap / 86400;
                $this->assertGreaterThanOrEqual(6.9, $gapDays, 'Gap from daily period to first weekly backup should be ~7 days');
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
