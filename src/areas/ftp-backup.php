<?php

use TearoomOne\FtpBackup\BackupController;

return [
    'label' => 'FTP Backup',
    'icon' => 'upload',
    'menu' => true,
    'link' => 'ftp-backup',
    'views' => [
        [
            'pattern' => 'ftp-backup',
            'action' => function () {
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
