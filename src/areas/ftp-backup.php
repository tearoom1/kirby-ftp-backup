<?php

use TearoomOne\FtpBackup\BackupController;

return [
    'label' => 'FTP Backup',
    'icon' => 'upload',
    'menu' => fn () => BackupController::canAccess(),
    'link' => 'ftp-backup',
    'views' => [
        [
            'pattern' => 'ftp-backup',
            'action' => function () {
                if (!BackupController::canAccess()) {
                    throw new \Kirby\Exception\PermissionException('You are not allowed to access FTP Backup');
                }
                $stats = null;
                $server = null;
                try {
                    $manager = new \TearoomOne\FtpBackup\BackupManager();
                    $stats = BackupController::getStats($manager);
                    // A valid cached server list, so the view is complete without connecting
                    $server = $manager->cachedServerStats();
                } catch (\Throwable $e) {
                    error_log('[kirby-ftp-backup] Failed to load stats in panel view: ' . $e->getMessage());
                }
                return [
                    'component' => 'ftp-backup-view',
                    'title' => 'FTP Backup',
                    'props' => [
                        'stats' => $stats,
                        'server' => $server,
                        // Only admins see the setup help with the config example
                        'isAdmin' => kirby()->user()?->isAdmin() ?? false,
                    ]
                ];
            }
        ]
    ]
];
