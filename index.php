<?php

use TearoomOne\FtpBackup\BackupManager;

// Register autoloader
load([
    'TearoomOne\\FtpBackup\\BackupManager' => 'src/classes/BackupManager.php',
    'TearoomOne\\FtpBackup\\FtpClient' => 'src/classes/FtpClient.php',
    'TearoomOne\\FtpBackup\\BackupController' => 'src/classes/BackupController.php',
], __DIR__);

Kirby::plugin('tearoom1/ftp-backup', [
    // Plugin information
    'name' => 'FTP Backup',
    'description' => 'Plugin to create and manage site backups with FTP functionality',

    // Plugin options
    'options' => [
        'backupDirectory' => kirby()->root('content') . '/.backups',
        'backupRetention' => 10, // Number of backups to keep
        'deleteFromFtp' => true, // Delete backups from FTP server
        'ftpHost' => '', // FTP host
        'ftpPort' => 21, // FTP port
        'ftpUsername' => '', // FTP username
        'ftpPassword' => '', // FTP password
        'ftpDirectory' => '/', // FTP remote directory
        'ftpSsl' => false, // Use SSL/TLS
        'ftpPassive' => true, // Use passive mode
        'retentionStrategy' => 'simple', // 'simple' or 'tiered'
        'tieredRetention' => [
            'daily' => 10,    // Keep all backups for the first 10 days
            'weekly' => 4,    // Then keep 1 per week for 4 weeks
            'monthly' => 6    // Then keep 1 per month for 6 months
        ]
    ],

    // Panel areas registration
    'areas' => [
        'ftp-backup' => require __DIR__ . '/src/areas/ftp-backup.php',
    ],

    // API routes
    'api' => [
        'routes' => [
            // List backups
            [
                'pattern' => 'ftp-backup/backups',
                'method' => 'GET',
                'action' => function () {
                    $manager = new BackupManager();
                    return $manager->listBackups();
                }
            ],
            // Create backup manually
            [
                'pattern' => 'ftp-backup/create',
                'method' => 'POST',
                'action' => function () {
                    $manager = new BackupManager();
                    return $manager->createBackup();
                }
            ],
            // Get backup stats
            [
                'pattern' => 'ftp-backup/stats',
                'method' => 'GET',
                'action' => function () {
                    return BackupController::getStats();
                }
            ],
        ]
    ],

    // Panel routes
    'routes' => [
        // Download backup (with secure key as query param)
        [
            'pattern' => 'ftp-backup/download/(:any)',
            'method' => 'GET',
            'action' => function (string $filename) {
                $key = get('key');
                $manager = new BackupManager();
                return $manager->downloadBackup($filename, $key);
            }
        ],
    ],

    // CLI commands
    'commands' => [
        'ftp-backup:create' => [
            'description' => 'Create a new backup and upload it to the FTP server',
            'action' => function () {
                $manager = new BackupManager();
                return $manager->createBackup();
            }
        ]
    ]
]);
