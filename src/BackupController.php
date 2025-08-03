<?php

namespace TearoomOne\FtpBackup;

use Kirby\Http\Response;
use Kirby\Http\Request;
use Kirby\Toolkit\Str;

/**
 * Controller for panel views
 */
class BackupController
{
    /**
     * Get backup statistics
     */
    public static function getStats(): array
    {
        $manager = new BackupManager();
        $backups = $manager->listBackupFiles();

        $totalSize = 0;
        $latestBackup = null;
        $count = 0;

        if (isset($backups) && is_array($backups)) {
            $count = count($backups);

            foreach ($backups as $backup) {
                $totalSize += $backup['size'] ?? 0;

                if (!$latestBackup || ($backup['modified'] ?? 0) > ($latestBackup['modified'] ?? 0)) {
                    $latestBackup = $backup;
                }
            }
        }

        return [
            'count' => $count,
            'totalSize' => $totalSize,
            'latestBackup' => $latestBackup ? [
                'filename' => $latestBackup['filename'],
                'size' => $latestBackup['size'],
                'modified' => $latestBackup['modified'],
                'formattedDate' => date('Y-m-d H:i:s', $latestBackup['modified'])
            ] : null,
            'formattedTotalSize' => self::formatSize($totalSize)
        ];
    }

    /**
     * Format file size in human-readable format
     */
    public static function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get the cron command to be used in system crontab
     */
    public static function getCronCommand(): string
    {
        $php = PHP_BINARY;
        $script = kirby()->root('site') . '/plugins/kirby-ftp-backup/run.php';

        return "{$php} {$script}";
    }
}
