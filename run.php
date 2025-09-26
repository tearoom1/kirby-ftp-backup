<?php

/**
 * Kirby FTP Backup Cron Script
 *
 * This script is designed to be called from a cron job to create and upload backups automatically.
 * Example crontab entry to run daily at 2 AM:
 * 0 2 * * * /usr/bin/php /path/to/site/ftp-backup.php
 */

// check if we are indeed on the command line
if (php_sapi_name() !== 'cli') {
    die();
}

// Determine the Kirby root directory
$rootDir = dirname(__DIR__, 3);

// check if a root path is passed as an argument, if so overwrite $rootDir
if (count($argv) === 2) {
    if (!is_dir($argv[1])) {
        die('Invalid root directory: ' . $argv[1]);
    }
    $rootDir = $argv[1];
}

// Load Kirby
$bootstrapFile = realpath($rootDir . '/kirby/bootstrap.php');
if (!file_exists($bootstrapFile)) {
    die('Could not find bootstrap file: ' . $bootstrapFile);
}
require $bootstrapFile;

// Initialize Kirby
$kirby = new Kirby\Cms\App(['options' => ['url' => '/']]);

echo "Starting Kirby Backup with bootstrap path: " . $bootstrapFile . PHP_EOL;

// Initialize the backup manager and create a backup
$backupManager = new TearoomOne\FtpBackup\BackupManager();
$result = $backupManager->executeBackupWithFormatting(true);

// Output the result message
echo $result['message'] . PHP_EOL;

// Exit with appropriate code
exit($result['exitCode']);
