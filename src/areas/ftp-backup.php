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
                try {
                    $stats = BackupController::getStats();
                } catch (\Throwable $e) {
                    error_log('[kirby-ftp-backup] Failed to load stats in panel view: ' . $e->getMessage());
                }
                return [
                    'component' => 'ftp-backup-view',
                    'title' => 'FTP Backup',
                    'props' => [
                        'stats' => $stats
                    ]
                ];
            }
        ]
    ]
];
