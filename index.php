<?php

use Kirby\Cms\App;
use Kirby\Http\Response;
use MatroochkitaPlugins\FtpBackup\BackupManager;

// Register autoloader
load([
    'MatroochkitaPlugins\\FtpBackup\\BackupManager' => 'src/classes/BackupManager.php',
    'MatroochkitaPlugins\\FtpBackup\\FtpClient' => 'src/classes/FtpClient.php',
    'MatroochkitaPlugins\\FtpBackup\\BackupController' => 'src/classes/BackupController.php',
], __DIR__);

Kirby::plugin('tearoom1/ftp-backup', [
    // Plugin options
    'options' => [
        'backupDirectory' => kirby()->root('content') . '/.backups',
        'backupRetention' => 10, // Number of backups to keep
        'backupSchedule' => '0 0 * * *', // Daily at midnight
    ],
    
    // Panel areas registration
    'areas' => [
        'ftp-backup' => require __DIR__ . '/src/areas/ftp-backup.php',
    ],
    
    // API routes
    'api' => [
        'routes' => [
            // Get FTP settings
            [
                'pattern' => 'ftp-backup/settings',
                'method' => 'GET',
                'action' => function () {
                    $manager = new BackupManager();
                    return $manager->getSettings();
                }
            ],
            // Save FTP settings
            [
                'pattern' => 'ftp-backup/settings',
                'method' => 'POST',
                'action' => function () {
                    $manager = new BackupManager();
                    $request = kirby()->request();
                    
                    $settings = [
                        'host' => $request->get('host'),
                        'port' => (int)$request->get('port', 21),
                        'username' => $request->get('username'),
                        'password' => $request->get('password'),
                        'directory' => $request->get('directory', '/'),
                        'passive' => (bool)$request->get('passive', true),
                        'ssl' => (bool)$request->get('ssl', false),
                    ];
                    
                    return $manager->saveSettings($settings);
                }
            ],
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
            // Download backup
            [
                'pattern' => 'ftp-backup/download/(:any)',
                'method' => 'GET',
                'action' => function (string $filename) {
                    $manager = new BackupManager();
                    return $manager->downloadBackup($filename);
                }
            ]
        ]
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
