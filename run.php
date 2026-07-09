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

// Parse optional output flag and Kirby root directory.
$outputOnSuccessOverride = null;
$rootArgument = null;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--silent') {
        $outputOnSuccessOverride = false;
        continue;
    }

    if ($argument === '--verbose') {
        $outputOnSuccessOverride = true;
        continue;
    }

    if (str_starts_with($argument, '--')) {
        fwrite(STDERR, 'Unknown option: ' . $argument . PHP_EOL);
        exit(2);
    }

    if ($rootArgument !== null) {
        fwrite(STDERR, 'Only one Kirby root directory can be specified.' . PHP_EOL);
        exit(2);
    }

    $rootArgument = $argument;
}

// Determine the Kirby root directory.
$rootDir = dirname(__DIR__, 3);

if ($rootArgument !== null) {
    if (!is_dir($rootArgument)) {
        fwrite(STDERR, 'Invalid root directory: ' . $rootArgument . PHP_EOL);
        exit(2);
    }
    $rootDir = $rootArgument;
}

// Load Kirby
$bootstrapFile = realpath($rootDir . '/kirby/bootstrap.php');
if (!file_exists($bootstrapFile)) {
    fwrite(STDERR, 'Could not find bootstrap file: ' . $rootDir . '/kirby/bootstrap.php' . PHP_EOL);
    exit(2);
}
require $bootstrapFile;

// Initialize Kirby
$kirby = new Kirby\Cms\App(['options' => ['url' => '/']]);

// Initialize the backup manager and create a backup
$backupManager = new TearoomOne\FtpBackup\BackupManager();
$result = $backupManager->executeBackupWithFormatting(true);
$outputOnSuccess = $outputOnSuccessOverride
    ?? (bool)option('tearoom1.kirby-ftp-backup.cliOutputOnSuccess', false);

if ($result['exitCode'] !== 0) {
    fwrite(STDERR, $result['message'] . PHP_EOL);
} elseif ($outputOnSuccess) {
    fwrite(STDOUT, $result['message'] . PHP_EOL);
}

// Exit with appropriate code
exit($result['exitCode']);
