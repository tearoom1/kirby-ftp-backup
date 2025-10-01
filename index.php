<?php

@include_once __DIR__ . '/vendor/autoload.php';

// Register autoloader
load([
    'TearoomOne\\FtpBackup\\BackupManager' => 'src/BackupManager.php',
    'TearoomOne\\FtpBackup\\FtpClientInterface' => 'src/FtpClientInterface.php',
    'TearoomOne\\FtpBackup\\FtpClient' => 'src/FtpClient.php',
    'TearoomOne\\FtpBackup\\SftpClient' => 'src/SftpClient.php',
    'TearoomOne\\FtpBackup\\BackupController' => 'src/BackupController.php',
], __DIR__);

use TearoomOne\FtpBackup\BackupManager;

Kirby::plugin('tearoom1/kirby-ftp-backup', [
    // Plugin information
    'name' => 'FTP Backup',
    'description' => 'Plugin to create and manage site backups with FTP functionality',

    // Plugin options
    'options' => [
        // FTP setup
        'ftpProtocol' => 'ftp', // Connection type: 'ftp', 'ftps' or 'sftp'
        'ftpHost' => '', // FTP host
        'ftpPort' => 21, // FTP port
        'ftpUsername' => '', // FTP username
        'ftpPassword' => '', // FTP password
        'ftpDirectory' => '/', // FTP remote directory
        'ftpPassive' => true, // Use passive mode
        // general settings
        'backupDirectory' => kirby()->root('content') . '/.backups',
        'backupRetention' => 10, // Number of backups to keep
        'deleteFromFtp' => true, // Delete backups from FTP server
        'filePrefix' => 'backup-', // Prefix for backup filenames
        'retentionStrategy' => 'simple', // 'simple' or 'tiered'
        'tieredRetention' => [
            'daily' => 10,    // Keep all backups for the first 10 days
            'weekly' => 4,    // Then keep 1 per week for 4 weeks
            'monthly' => 6    // Then keep 1 per month for 6 months
        ],
        // URL execution settings
        'urlExecutionToken' => '', // Token required for URL-based backup execution
        'urlExecutionEnabled' => false, // Enable/disable URL-based backup execution
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
            // Check FTP settings status
            [
                'pattern' => 'ftp-backup/settings-status',
                'method' => 'GET',
                'action' => function () {
                    $manager = new BackupManager();
                    $settings = $manager->getSettings();

                    // Check if essential FTP settings are configured
                    $configured = !empty($settings['ftpHost']) &&
                        !empty($settings['ftpUsername']) &&
                        (!empty($settings['ftpPassword'] || !empty($settings['ftpPrivateKey'])));

                    return [
                        'status' => 'success',
                        'data' => [
                            'configured' => $configured,
                            'settings' => $settings,
                        ]
                    ];
                }
            ],
            // Get FTP server stats and file list
            [
                'pattern' => 'ftp-backup/ftp-stats',
                'method' => 'GET',
                'action' => function () {
                    $manager = new BackupManager();
                    return $manager->getFtpServerStats();
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
        // Execute backup via URL (with token authentication)
        [
            'pattern' => 'ftp-backup/execute',
            'method' => 'GET',
            'action' => function () {
                // Check if URL execution is enabled
                $enabled = option('tearoom1.kirby-ftp-backup.urlExecutionEnabled', false);
                if (!$enabled) {
                    return new Kirby\Http\Response('URL execution is disabled', 'text/plain', 403);
                }

                // Get and validate token
                $providedToken = get('token');
                $configuredToken = option('tearoom1.kirby-ftp-backup.urlExecutionToken', '');

                if (empty($configuredToken)) {
                    return new Kirby\Http\Response('No token configured', 'text/plain', 403);
                }

                if (empty($providedToken) || !hash_equals($configuredToken, $providedToken)) {
                    return new Kirby\Http\Response('Invalid or missing token', 'text/plain', 403);
                }

                // Execute backup
                $manager = new BackupManager();
                $result = $manager->executeBackupWithFormatting(true);

                // Return appropriate HTTP response
                $statusCode = $result['success'] ? 200 : 500;
                return new Kirby\Http\Response($result['message'], 'text/plain', $statusCode);
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
