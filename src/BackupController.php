<?php

namespace TearoomOne\FtpBackup;

/**
 * Access control and helpers for the Panel view
 */
class BackupController
{
    /**
     * Check whether the current user may access the plugin
     * (run backups, view stats, download). Admins always pass.
     * Additional roles can be granted via the `allowedRoles` option.
     */
    public static function canAccess(): bool
    {
        $user = kirby()->user();
        if (!$user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        $allowed = option('tearoom1.kirby-ftp-backup.allowedRoles', []);
        if (!is_array($allowed) || empty($allowed)) {
            return false;
        }
        return in_array($user->role()->name(), $allowed, true);
    }

    /**
     * Get backup statistics
     */
    public static function getStats(?BackupManager $manager = null): array
    {
        $backups = ($manager ?? new BackupManager())->listBackupFiles();
        // listBackupFiles() is sorted newest first
        $latest = $backups[0] ?? null;
        $totalSize = array_sum(array_column($backups, 'size'));

        return [
            'count' => count($backups),
            'totalSize' => $totalSize,
            'latestBackup' => $latest ? [
                'filename' => $latest['filename'],
                'size' => $latest['size'],
                'modified' => $latest['modified'],
                'formattedDate' => date('Y-m-d H:i:s', $latest['modified'])
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
}
