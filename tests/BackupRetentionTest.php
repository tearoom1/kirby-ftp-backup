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
     * Test weekly period bucketing (7-day periods)
     */
    public function testWeeklyPeriodBucketing()
    {
        $tieredSettings = [
            'daily' => 3,
            'weekly' => 3,
            'monthly' => 2
        ];
        
        $now = time();
        $dailyCutoff = $now - (3 * 86400);
        
        // Create backups in different 7-day periods after daily cutoff
        $testBackups = [
            // Daily period (should all be kept)
            ['filename' => 'daily-1.zip', 'timestamp' => $now - (1 * 86400), 'date' => date('Y-m-d', $now - (1 * 86400))],
            ['filename' => 'daily-2.zip', 'timestamp' => $now - (2 * 86400), 'date' => date('Y-m-d', $now - (2 * 86400))],
            
            // Weekly period 0 (days 4-10 ago) - should keep newest
            ['filename' => 'weekly-0-newer.zip', 'timestamp' => $dailyCutoff - (2 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (2 * 86400))],
            ['filename' => 'weekly-0-older.zip', 'timestamp' => $dailyCutoff - (5 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (5 * 86400))],
            
            // Weekly period 1 (days 11-17 ago) - should keep newest
            ['filename' => 'weekly-1-newer.zip', 'timestamp' => $dailyCutoff - (8 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (8 * 86400))],
            ['filename' => 'weekly-1-older.zip', 'timestamp' => $dailyCutoff - (12 * 86400), 'date' => date('Y-m-d', $dailyCutoff - (12 * 86400))],
        ];
        
        $reflection = new \ReflectionClass($this->backupManager);
        $method = $reflection->getMethod('applyTieredRetentionStrategy');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->backupManager, $testBackups, $tieredSettings);
        
        // Should keep: newest (daily-1), daily-2, weekly-0-newer, weekly-1-newer
        $this->assertCount(4, $result);
        
        $filenames = array_column($result, 'filename');
        $this->assertContains('daily-1.zip', $filenames);
        $this->assertContains('daily-2.zip', $filenames);
        $this->assertContains('weekly-0-newer.zip', $filenames);
        $this->assertContains('weekly-1-newer.zip', $filenames);
        
        // Should NOT keep the older ones in each weekly period
        $this->assertNotContains('weekly-0-older.zip', $filenames);
        $this->assertNotContains('weekly-1-older.zip', $filenames);
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
     * Verify that retention results match expected behavior
     */
    private function assertRetentionResults(array $keepBackups, array $allBackups, array $settings, int $now): void
    {
        $dailyCutoff = $now - ($settings['daily'] * 86400);
        $weeklyCutoff = $dailyCutoff - ($settings['weekly'] * 7 * 86400);
        $monthlyCutoff = $weeklyCutoff - ($settings['monthly'] * 30 * 86400);
        
        $dailyCount = 0;
        $weeklyPeriods = [];
        $monthlyPeriods = [];
        
        foreach ($keepBackups as $backup) {
            $timestamp = $backup['timestamp'];
            
            if ($timestamp >= $dailyCutoff) {
                $dailyCount++;
            } elseif ($timestamp >= $weeklyCutoff) {
                $periodIndex = floor(($dailyCutoff - $timestamp) / (7 * 86400));
                $weeklyPeriods[$periodIndex] = true;
            } elseif ($timestamp >= $monthlyCutoff) {
                $periodIndex = floor(($weeklyCutoff - $timestamp) / (30 * 86400));
                $monthlyPeriods[$periodIndex] = true;
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
        
        // Verify weekly periods (should have at most one backup per 7-day period)
        $this->assertLessThanOrEqual($settings['weekly'], count($weeklyPeriods), 'Should not exceed weekly period limit');
        
        // Verify monthly periods (should have at most one backup per 30-day period)
        $this->assertLessThanOrEqual($settings['monthly'], count($monthlyPeriods), 'Should not exceed monthly period limit');
        
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
