<?php

/**
 * Kirby FTP Backup Cron Script
 * 
 * This script is designed to be called from a cron job to create and upload backups automatically.
 * Example crontab entry to run daily at 2 AM:
 * 0 2 * * * /usr/bin/php /path/to/site/ftp-backup.php
 */

// Determine the Kirby root directory
$rootDir = __DIR__ . '/../../../';

// Load Kirby
require $rootDir . '/kirby/bootstrap.php';

// Initialize Kirby
$kirby = new Kirby\Cms\App([
    'roots' => [
        'index' => $rootDir
    ]
]);

// Initialize the backup manager and create a backup
try {
    // Use namespaced class
    $backupManager = new TearoomOne\FtpBackup\BackupManager();
    $result = $backupManager->createBackup(true);
    
    // Log the result
    if ($result['status'] === 'success') {
        echo "Backup created successfully: " . ($result['data']['filename'] ?? 'unknown') . PHP_EOL;
        
        if (isset($result['data']['ftpResult']) && $result['data']['ftpResult']['uploaded']) {
            echo "Backup uploaded to FTP server: " . ($result['data']['ftpResult']['message'] ?? '') . PHP_EOL;
        } else {
            echo "Backup not uploaded to FTP: " . ($result['data']['ftpResult']['message'] ?? 'Unknown error') . PHP_EOL;
        }
    } else {
        echo "Error creating backup: " . ($result['message'] ?? 'Unknown error') . PHP_EOL;
    }
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

exit(0);
