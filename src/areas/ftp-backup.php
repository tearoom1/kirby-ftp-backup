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
                return [
                    'component' => 'ftp-backup-view',
                    'title' => 'FTP Backup',
                    'props' => [
                        'stats' => BackupController::getStats()
                    ]
                ];
            }
        ]
    ]
];
