<?php

// Exit early if plugin is disabled
if (!option('tearoom1.kirby-ftp-backup.enabled', true)) {
    return;
}

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
        // Plugin control
        'enabled' => true, // Enable/disable the entire plugin
        'ftpEnabled' => true, // Enable/disable FTP uploads (backups still created locally)
        // FTP setup
        'ftpProtocol' => 'ftp', // Connection type: 'ftp', 'ftps' or 'sftp'
        'ftpHost' => '', // FTP host
        'ftpPort' => 21, // FTP port
        'ftpUsername' => '', // FTP username
        'ftpPassword' => '', // FTP password
        'ftpDirectory' => '/', // FTP remote directory
        'ftpPassive' => true, // Use passive mode
        'ftpTimeout' => 30, // Socket response timeout in seconds (how long to wait for the server to respond to each packet, not the total transfer duration)
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
        // File filtering (regex patterns without delimiters, case-insensitive)
        'includePatterns' => [], // Array of regex patterns - if not empty, only matching files are included
        'excludePatterns' => [], // Array of regex patterns - matching files are always excluded
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
                    try {
                        $manager = new BackupManager();
                        return $manager->listBackups();
                    } catch (\Throwable $e) {
                        error_log('[kirby-ftp-backup] Error listing backups: ' . $e->getMessage());
                        return \Kirby\Http\Response::json([
                            'status' => 'error',
                            'message' => 'Error listing backups: ' . $e->getMessage()
                        ], 500);
                    }
                }
            ],
            // Create backup manually
            [
                'pattern' => 'ftp-backup/create',
                'method' => 'POST',
                'action' => function () {
                    try {
                        $jobId = kirby()->request()->get('jobId') ?? null;
                        $manager = new BackupManager();
                        return $manager->createBackup(true, $jobId ?: null);
                    } catch (\Throwable $e) {
                        error_log('[kirby-ftp-backup] Error creating backup: ' . $e->getMessage());
                        return \Kirby\Http\Response::json([
                            'status' => 'error',
                            'message' => 'Error creating backup: ' . $e->getMessage()
                        ], 500);
                    }
                }
            ],
            // Cancel a running backup
            [
                'pattern' => 'ftp-backup/cancel',
                'method' => 'POST',
                'action' => function () {
                    try {
                        $jobId = kirby()->request()->get('jobId') ?? '';
                        if (empty($jobId)) {
                            return \Kirby\Http\Response::json(['status' => 'error', 'message' => 'Missing jobId'], 400);
                        }
                        $manager = new BackupManager();
                        return $manager->cancelBackup($jobId);
                    } catch (\Throwable $e) {
                        return \Kirby\Http\Response::json(['status' => 'error', 'message' => $e->getMessage()], 500);
                    }
                }
            ],
            // Poll progress of a running backup
            [
                'pattern' => 'ftp-backup/progress/(:any)',
                'method' => 'GET',
                'action' => function (string $jobId) {
                    try {
                        $manager = new BackupManager();
                        return ['status' => 'success', 'data' => $manager->getProgress($jobId)];
                    } catch (\Throwable $e) {
                        return \Kirby\Http\Response::json(['status' => 'error', 'message' => $e->getMessage()], 500);
                    }
                }
            ],
            // Catch accidental GET requests to the create endpoint (caused by HTTP→HTTPS or www redirects
            // converting the POST method to GET — fix your canonical URL in config.php)
            [
                'pattern' => 'ftp-backup/create',
                'method' => 'GET',
                'action' => function () {
                    return \Kirby\Http\Response::json([
                        'status' => 'error',
                        'message' => 'This endpoint only accepts POST requests. A GET request was received, which is most likely caused by an HTTP redirect (e.g. HTTP→HTTPS or www→non-www) converting your POST to GET. Set the correct canonical URL in your Kirby config.php: \'url\' => \'https://yourdomain.com\''
                    ], 405);
                }
            ],
            // Check FTP settings status
            [
                'pattern' => 'ftp-backup/settings-status',
                'method' => 'GET',
                'action' => function () {
                    try {
                        $manager = new BackupManager();
                        $settings = $manager->getSettings();

                        // Check if FTP is enabled
                        $ftpEnabled = $settings['ftpEnabled'] ?? true;

                        // Check if essential FTP settings are configured
                        $configured = $ftpEnabled &&
                            !empty($settings['ftpHost']) &&
                            !empty($settings['ftpUsername']) &&
                            (!empty($settings['ftpPassword']) || !empty($settings['ftpPrivateKey']));

                        return [
                            'status' => 'success',
                            'data' => [
                                'configured' => $configured,
                                'ftpEnabled' => $ftpEnabled,
                            ]
                        ];
                    } catch (\Throwable $e) {
                        error_log('[kirby-ftp-backup] Error checking settings status: ' . $e->getMessage());
                        return \Kirby\Http\Response::json([
                            'status' => 'error',
                            'message' => 'Error checking settings: ' . $e->getMessage()
                        ], 500);
                    }
                }
            ],
            // Get FTP server stats and file list
            [
                'pattern' => 'ftp-backup/ftp-stats',
                'method' => 'GET',
                'action' => function () {
                    try {
                        $manager = new BackupManager();
                        return $manager->getFtpServerStats();
                    } catch (\Throwable $e) {
                        error_log('[kirby-ftp-backup] Error retrieving FTP stats: ' . $e->getMessage());
                        return \Kirby\Http\Response::json([
                            'status' => 'error',
                            'message' => 'Error retrieving FTP stats: ' . $e->getMessage()
                        ], 500);
                    }
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
                try {
                    $key = get('key');
                    $manager = new BackupManager();
                    return $manager->downloadBackup($filename, $key);
                } catch (\Throwable $e) {
                    error_log('[kirby-ftp-backup] Error downloading backup: ' . $e->getMessage());
                    return \Kirby\Http\Response::json([
                        'status' => 'error',
                        'message' => 'Error downloading backup: ' . $e->getMessage()
                    ], 500);
                }
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
