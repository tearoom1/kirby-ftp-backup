<?php

namespace TearoomOne\FtpBackup;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Response;
use Kirby\Toolkit\Data;

/**
 * Manages backup creation, listing, and FTP operations
 */
class BackupManager
{
    private string $backupDir;

    /**
     * Constructor
     */
    public function __construct()
    {
        $kirby = App::instance();

        $dir = option('tearoom1.kirby-ftp-backup.backupDirectory', $kirby->root('content') . '/.backups');
        // calculate backup directory from kirby root if not absolute
        if (strpos($dir, '/') !== 0) {
            $dir = dirname($kirby->root('site')) . '/' . $dir;
        }
        $this->backupDir = $dir;

        // Ensure backup directory exists
        if (!is_dir($this->backupDir)) {
            Dir::make($this->backupDir);
        }
    }

    /**
     * Get FTP settings
     */
    public function getSettings(): array
    {
        return [
            // FTP settings
            'ftpProtocol' => option('tearoom1.kirby-ftp-backup.ftpProtocol', 'ftp'),
            'ftpHost' => option('tearoom1.kirby-ftp-backup.ftpHost', ''),
            'ftpPort' => option('tearoom1.kirby-ftp-backup.ftpPort', 21),
            'ftpUsername' => option('tearoom1.kirby-ftp-backup.ftpUsername', ''),
            'ftpPassword' => option('tearoom1.kirby-ftp-backup.ftpPassword', ''),
            'ftpDirectory' => option('tearoom1.kirby-ftp-backup.ftpDirectory', ''),
            'ftpPassive' => option('tearoom1.kirby-ftp-backup.ftpPassive', true),
            'ftpPrivateKey' => option('tearoom1.kirby-ftp-backup.ftpPrivateKey'),
            'ftpPassphrase' => option('tearoom1.kirby-ftp-backup.ftpPassphrase'),
            // General settings
            'backupDirectory' => option('tearoom1.kirby-ftp-backup.backupDirectory', kirby()->root('content') . '/.backups'),
            'backupRetention' => option('tearoom1.kirby-ftp-backup.backupRetention', 10),
            'deleteFromFtp' => option('tearoom1.kirby-ftp-backup.deleteFromFtp', true),
            'retentionStrategy' => option('tearoom1.kirby-ftp-backup.retentionStrategy', 'simple'),
            'tieredRetention' => [
                'daily' => option('tearoom1.kirby-ftp-backup.tieredRetention.daily', 10),
                'weekly' => option('tearoom1.kirby-ftp-backup.tieredRetention.weekly', 4),
                'monthly' => option('tearoom1.kirby-ftp-backup.tieredRetention.monthly', 6)
            ]
        ];
    }

    /**
     * List available backups
     */
    public function listBackups(): array
    {
        return [
            'status' => 'success',
            'stats' => BackupController::getStats(),
            'data' => self::listBackupFiles()
        ];
    }

    /**
     * List available backups
     */
    public function listBackupFiles(): array
    {
        $files = Dir::read($this->backupDir);
        $backups = [];

        foreach ($files as $file) {
            if (F::extension($file) === 'zip') {
                $path = $this->backupDir . '/' . $file;
                $key = $this->generateDownloadKey($file);
                $backups[] = [
                    'filename' => $file,
                    'size' => F::size($path),
                    'modified' => F::modified($path),
                    'downloadUrl' => '/ftp-backup/download/' . $file . '?key=' . $key
                ];
            }
        }

        // Sort by modified date (newest first)
        usort($backups, function ($a, $b) {
            return $b['modified'] <=> $a['modified'];
        });

        return $backups;
    }

    /**
     * Create a new backup
     */
    public function createBackup(bool $uploadToFtp = true): array
    {
        $ftpClient = null;
        try {
            // Generate filename with date
            $date = date('Y-m-d-His');
            $filePrefix = option('tearoom1.kirby-ftp-backup.filePrefix', 'backup-');
            $filename = "{$filePrefix}{$date}.zip";
            $filepath = $this->backupDir . '/' . $filename;

            // Create zip archive of content folder
            $contentDir = kirby()->root('content');

            // Create zip excluding .backups directory
            $zip = new \ZipArchive();
            if ($zip->open($filepath, \ZipArchive::CREATE) !== true) {
                throw new \Exception("Cannot create zip file");
            }

            $this->addDirToZip($contentDir, $zip, '', '/.backups/');
            $zip->close();

            $settings = $this->getSettings();
            $ftpClient = $this->initFtpClient();

            // Upload to FTP if requested
            $ftpResult = ['uploaded' => false];
            if ($uploadToFtp) {
                $ftpResult = $this->uploadToFtp($ftpClient, $settings, $filepath, $filename);
            }

            // Cleanup old backups
            $this->cleanupOldBackups($ftpClient, $settings);

            return [
                'status' => 'success',
                'message' => 'Backup created successfully' .
                    ($ftpResult['uploaded'] ? ' and uploaded to FTP server' : ''),
                'data' => [
                    'filename' => $filename,
                    'size' => F::size($filepath),
                    'ftpResult' => $ftpResult
                ]
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Backup failed: ' . $e->getMessage()
            ];
        } finally {
            if ($ftpClient) {
                $ftpClient->disconnect();
            }
        }
    }

    /**
     * Upload a backup file to the FTP server
     */
    private function uploadToFtp(FtpClientInterface $ftpClient, array $settings, string $localFile, string $remoteFilename): array
    {
        try {

            $directory = $settings['ftpDirectory'] ?? '/';

            if ($this->isLocalDev()) {
                echo "Would upload: {$localFile} to " .$directory . '/' . $remoteFilename. "\n";
            } else {
                $ftpClient->upload($localFile, $directory . '/' . $remoteFilename);
            }

            return [
                'uploaded' => true,
                'message' => 'File uploaded to FTP server'
            ];
        } catch (\Exception $e) {
            return [
                'uploaded' => false,
                'message' => 'FTP upload failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Remove a backup file from the FTP server
     */
    private function removeFromFtp(FtpClientInterface $ftpClient, array $settings, string $filename): array
    {
        try {


            $directory = $settings['ftpDirectory'] ?? '/';
            $ftpClient->delete($directory . '/' . $filename);

            return [
                'deleted' => true,
                'message' => 'File deleted from FTP server'
            ];
        } catch (\Exception $e) {
            return [
                'deleted' => false,
                'message' => 'FTP deletion failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Initialize the FTP client with settings
     */
    private function initFtpClient(): FtpClientInterface
    {
        $settings = $this->getSettings();

        // Default to regular FTP
        $ftpProtocol = $settings['ftpProtocol'] ?? 'ftp';

        // Check if essential settings are available
        if (!in_array($ftpProtocol, ['ftp', 'ftps', 'sftp']) ||
            empty($settings['ftpHost']) ||
            !$this->hasCredentials($settings)) {
            throw new \Exception('Invalid FTP settings');
        }

        // Check which connection type to use
        if ($ftpProtocol === 'sftp') {
            return $this->initSftpClient($settings);
        }
        return $this->initStdFtpClient($settings, $ftpProtocol);

    }

    /**
     * Initialize the SFTP client with settings
     */
    private function initSftpClient($settings): SftpClient
    {
        // Create and connect SFTP client
        $sftpClient = new SftpClient(
            $settings['ftpHost'],
            (int)($settings['ftpPort'] ?? 22),
            $settings['ftpUsername'],
            $settings['ftpPassword'] ?? '',
            $settings['ftpPrivateKey'] ?? null,
            $settings['ftpPassphrase'] ?? null
        );

        $sftpClient->connect();

        return $sftpClient;
    }

    public function initStdFtpClient(array $settings, mixed $ftpProtocol): FtpClient
    {
        // Create and connect FTP client
        $ftpClient = new FtpClient(
            $settings['ftpHost'],
            (int)($settings['ftpPort'] ?? 21),
            $settings['ftpUsername'],
            $settings['ftpPassword'],
            $ftpProtocol === 'ftps',
            (bool)($settings['ftpPassive'] ?? true)
        );

        $ftpClient->connect();

        return $ftpClient;
    }

    /**
     * Helper function to add directory contents to zip
     */
    private function addDirToZip(string $dir, \ZipArchive $zip, string $zipDir = '', string $exclude = ''): void
    {
        $files = new \DirectoryIterator($dir);

        foreach ($files as $file) {
            if ($file->isDot()) {
                continue;
            }

            $filePath = $file->getPathname();
            $relativePath = $zipDir . '/' . $file->getFilename();

            // Skip excluded paths
            if ($exclude && strpos($relativePath, $exclude) !== false) {
                continue;
            }

            if ($file->isDir()) {
                // Add empty directory
                $zip->addEmptyDir(ltrim($relativePath, '/'));
                // Add directory contents
                $this->addDirToZip($filePath, $zip, $relativePath, $exclude);
            } else {
                // Add file
                $zip->addFile($filePath, ltrim($relativePath, '/'));
            }
        }
    }

    /**
     * Clean up old backups based on retention settings
     */
    public function cleanupOldBackups(FtpClientInterface $ftpClient, array $settings): void
    {
        $retentionStrategy = $settings['retentionStrategy'] ?? 'simple';

        if ($retentionStrategy === 'tiered') {
            $this->applyTieredRetention();
        } else {
            $this->applySimpleRetention();
        }

        if ($settings['deleteFromFtp']) {
            $this->cleanupFtpBackups($ftpClient, $settings);
        }
    }

    /**
     * Apply simple retention strategy (keep X most recent backups)
     */
    private function applySimpleRetention(): void
    {
        $settings = $this->getSettings();
        $retention = $settings['backupRetention'] ?? 10;

        $files = Dir::read($this->backupDir);

        $backups = [];
        foreach ($files as $file) {
            if (F::extension($file) === 'zip') {
                $path = $this->backupDir . '/' . $file;
                $backups[$file] = F::modified($path);
            }
        }

        // Sort by modified date (oldest first)
        asort($backups);

        // Delete oldest files if we have more than retention limit
        $count = count($backups);
        if ($count > $retention) {
            $toDelete = array_slice(array_keys($backups), 0, $count - $retention);

            foreach ($toDelete as $file) {
                if ($this->isLocalDev()) {
                    echo "Would delete from local: {$file}\n";
                } else {
                    F::remove($this->backupDir . '/' . $file);
                }
            }
        }
    }

    /**
     * Apply tiered retention strategy:
     * - Keep all daily backups for X days
     * - Then keep one backup per 7-day period for Y periods
     * - Then keep one backup per 30-day period for Z periods
     */
    private function applyTieredRetention(): void
    {
        $settings = $this->getSettings();
        $tieredSettings = $settings['tieredRetention'] ?? [
            'daily' => 10,
            'weekly' => 4,
            'monthly' => 6
        ];

        // Get all backup files
        $files = Dir::read($this->backupDir);
        $backups = [];

        foreach ($files as $file) {
            if (F::extension($file) === 'zip') {
                $path = $this->backupDir . '/' . $file;
                $modified = F::modified($path);
                $backups[] = [
                    'filename' => $file,
                    'path' => $path,
                    'date' => date('Y-m-d', $modified),
                    'timestamp' => $modified
                ];
            }
        }

        // Apply tiered retention
        $keepBackups = $this->applyTieredRetentionStrategy($backups, $tieredSettings);

        // Delete backups that aren't in the keep list
        $keepFilenames = array_map(function ($backup) {
            return $backup['filename'];
        }, $keepBackups);

        foreach ($backups as $backup) {
            if (!in_array($backup['filename'], $keepFilenames)) {

                if ($this->isLocalDev()) {
                    echo "Would delete from local: {$file}\n";
                } else {
                    F::remove($backup['path']);
                }
            }
        }
    }

    /**
     * Apply tiered retention strategy - simple and obvious approach
     *
     * Strategy:
     * 1. Keep X daily backups (one per day)
     * 2. Keep 1 backup per 7-day period for X weeks (max 7 days between)
     * 3. Keep 1 backup per 30-day period for X months (max 30 days between)
     * 4. Always keep the oldest backup as anchor
     */
    private function applyTieredRetentionStrategy(array $backups, array $settings): array
    {
        if (empty($backups)) {
            return [];
        }

        // Sort newest first
        usort($backups, fn ($a, $b) => $b['timestamp'] - $a['timestamp']);

        $now = time();
        $dailyDays = max(1, intval($settings['daily']));
        $weeklyWeeks = max(1, intval($settings['weekly']));
        $monthlyMonths = max(1, intval($settings['monthly']));

        $keepBackups = [];

        // 1. DAILY PERIOD: Keep all backups within X days
        foreach ($backups as $i => $backup) {
            $ageDays = ($now - $backup['timestamp']) / 86400;
            if ($ageDays <= $dailyDays) {
                $keepBackups[] = $this->markBackup($backup, $i === 0 ? 'newest' : 'daily');
            }
        }

        // 2. WEEKLY PERIOD: Keep oldest backup in each 7-day period
        for ($week = 0; $week < $weeklyWeeks; $week++) {
            $weekStart = $dailyDays + ($week * 7);
            $weekEnd = $dailyDays + (($week + 1) * 7);

            $weeklyBackups = array_filter($backups, function($backup) use ($now, $weekStart, $weekEnd) {
                $ageDays = ($now - $backup['timestamp']) / 86400;
                return $ageDays > $weekStart && $ageDays <= $weekEnd;
            });

            if (!empty($weeklyBackups)) {
                // Keep the oldest backup in this week (furthest timestamp)
                $oldestWeekly = array_reduce($weeklyBackups, function($oldest, $current) {
                    return ($oldest === null || $current['timestamp'] < $oldest['timestamp']) ? $current : $oldest;
                });
                $keepBackups[] = $this->markBackup($oldestWeekly, 'weekly');
            }
        }

        // 3. MONTHLY PERIOD: Keep oldest backup in each 30-day period
        for ($month = 0; $month < $monthlyMonths; $month++) {
            $monthStart = $dailyDays + ($weeklyWeeks * 7) + ($month * 30);
            $monthEnd = $dailyDays + ($weeklyWeeks * 7) + (($month + 1) * 30);

            $monthlyBackups = array_filter($backups, function($backup) use ($now, $monthStart, $monthEnd) {
                $ageDays = ($now - $backup['timestamp']) / 86400;
                return $ageDays > $monthStart && $ageDays <= $monthEnd;
            });

            if (!empty($monthlyBackups)) {
                // Keep the oldest backup in this month (furthest timestamp)
                $oldestMonthly = array_reduce($monthlyBackups, function($oldest, $current) {
                    return ($oldest === null || $current['timestamp'] < $oldest['timestamp']) ? $current : $oldest;
                });
                $keepBackups[] = $this->markBackup($oldestMonthly, 'monthly');
            }
        }

        // 4. ALWAYS KEEP OLDEST as anchor
        $this->ensureOldestBackup($backups, $keepBackups);

        $this->debugRetention($backups, $keepBackups, $dailyDays, $weeklyWeeks, $monthlyMonths);

        return $keepBackups;
    }

    private function markBackup(array $backup, string $type): array
    {
        $backup['retention'] = $type;
        return $backup;
    }

    private function ensureOldestBackup(array $allBackups, array &$keepBackups): void
    {
        if (empty($allBackups)) {
            return;
        }

        // Check if we have any monthly backups already kept
        $monthlyBackups = array_filter(
            $keepBackups,
            fn ($backup) =>
            isset($backup['retention']) && $backup['retention'] === 'monthly'
        );

        // If we have monthly backups, the oldest monthly backup is our anchor
        if (!empty($monthlyBackups)) {
            // Sort monthly backups by timestamp (oldest first)
            usort($monthlyBackups, fn ($a, $b) => $a['timestamp'] - $b['timestamp']);
            $oldestMonthly = $monthlyBackups[0];

            // Update the oldest monthly backup to be the anchor
            foreach ($keepBackups as &$backup) {
                if ($backup['filename'] === $oldestMonthly['filename']) {
                    $backup['retention'] = 'oldest-anchor';
                    break;
                }
            }
            return;
        }

        // If no monthly backups, keep the very oldest as anchor (fallback for new setups)
        $oldest = end($allBackups);
        $oldest['retention'] = 'oldest-anchor';

        // Add if not already kept
        $filenames = array_column($keepBackups, 'filename');
        if (!in_array($oldest['filename'], $filenames)) {
            $keepBackups[] = $oldest;
        }
    }

    private function debugRetention(array $all, array $keep, int $daily, int $weekly, int $monthly): void
    {
        if (!$this->isLocalDev()) {
            return;
        }

        echo "=== TIERED RETENTION ===\n";
        echo "Settings: {$daily}d daily, {$weekly}w weekly, {$monthly}m monthly\n";
        echo "Keeping " . count($keep) . " of " . count($all) . " backups:\n";
        foreach ($keep as $backup) {
            echo "- [{$backup['retention']}] {$backup['filename']}\n";
        }
    }


    /**
     * Clean up old backups from FTP server based on retention setting
     */
    public function cleanupFtpBackups($ftpClient, $settings): array
    {
        try {
            $directory = $settings['ftpDirectory'] ?? '/';
            $files = $ftpClient->listDirectory($directory);

            // Filter to .zip files only
            $backupFiles = array_filter($files, fn ($file) => substr($file, -4) === '.zip');

            $toDelete = $this->determineFilesToDelete($backupFiles, $settings);
            $deletedCount = $this->deleteFilesFromFtp($toDelete, $ftpClient, $settings);

            return [
                'success' => true,
                'message' => "Deleted {$deletedCount} old backups from FTP server"
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error cleaning up FTP backups: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Determine which files to delete based on retention strategy
     */
    private function determineFilesToDelete(array $backupFiles, array $settings): array
    {
        $retentionStrategy = $settings['retentionStrategy'] ?? 'simple';

        if ($retentionStrategy === 'tiered') {
            return $this->getTieredFilesToDelete($backupFiles, $settings);
        }

        return $this->getSimpleFilesToDelete($backupFiles, $settings);
    }

    /**
     * Get files to delete using tiered retention
     */
    private function getTieredFilesToDelete(array $backupFiles, array $settings): array
    {
        $backups = $this->prepareFtpBackupsForTieredRetention($backupFiles);
        $tieredSettings = $settings['tieredRetention'] ?? [
            'daily' => 10,
            'weekly' => 4,
            'monthly' => 6
        ];

        $keepBackups = $this->applyTieredRetentionStrategy($backups, $tieredSettings);
        $keepFilenames = array_column($keepBackups, 'filename');

        return array_filter($backupFiles, fn ($file) => !in_array($file, $keepFilenames));
    }

    /**
     * Get files to delete using simple retention
     */
    private function getSimpleFilesToDelete(array $backupFiles, array $settings): array
    {
        $retention = $settings['backupRetention'] ?? 10;

        // Sort files by name (assuming they contain dates/timestamps)
        usort($backupFiles, fn ($a, $b) => strcmp($b, $a));

        return array_slice($backupFiles, $retention);
    }

    /**
     * Delete files from FTP server
     */
    private function deleteFilesFromFtp(array $filesToDelete, $ftpClient, array $settings): int
    {
        $deletedCount = 0;

        foreach ($filesToDelete as $file) {
            if ($this->isLocalDev()) {
                echo "Would delete from FTP: {$file}\n";
            } else {
                $this->removeFromFtp($ftpClient, $settings, $file);
            }
            $deletedCount++;
        }

        return $deletedCount;
    }

    /**
     * Get stats and file list from the FTP server
     */
    public function getFtpServerStats(): array
    {
        $ftpClient = null;
        try {
            $settings = $this->getSettings();
            $ftpClient = $this->initFtpClient();

            $directory = $settings['ftpDirectory'] ?? '/';

            // List files on the FTP server
            $files = $ftpClient->listDirectory($directory);

            // Filter to only include .zip files and get their details
            $backupFiles = [];
            $totalSize = 0;
            $latestModified = 0;

            foreach ($files as $file) {
                if (substr($file, -4) === '.zip') {
                    try {
                        // Try to get file size and modified time
                        $fileSize = $ftpClient->getFileSize($directory . '/' . $file);
                        $fileModified = $ftpClient->getModifiedTime($directory . '/' . $file);

                        $backupFiles[] = [
                            'filename' => $file,
                            'size' => $fileSize,
                            'formattedSize' => BackupController::formatSize($fileSize),
                            'modified' => $fileModified,
                            'formattedDate' => date('Y-m-d H:i:s', $fileModified)
                        ];

                        $totalSize += $fileSize;
                        if ($fileModified > $latestModified) {
                            $latestModified = $fileModified;
                        }
                    } catch (\Exception $e) {
                        // Skip files we can't get details for
                        $backupFiles[] = [
                            'filename' => $file,
                            'size' => 0,
                            'formattedSize' => 'Unknown',
                            'modified' => 0,
                            'formattedDate' => 'Unknown'
                        ];
                    }
                }
            }

            // Sort by modified date (newest first)
            usort($backupFiles, function ($a, $b) {
                return $b['modified'] <=> $a['modified'];
            });

            // Disconnect from FTP
            $ftpClient->disconnect();

            // Return stats
            return [
                'status' => 'success',
                'data' => [
                    'files' => $backupFiles,
                    'count' => count($backupFiles),
                    'totalSize' => $totalSize,
                    'formattedTotalSize' => BackupController::formatSize($totalSize),
                    'latestModified' => $latestModified > 0 ? date('Y-m-d H:i:s', $latestModified) : 'None'
                ]
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error retrieving FTP server stats: ' . $e->getMessage()
            ];
        } finally {
            if ($ftpClient) {
                $ftpClient->disconnect();
            }
        }
    }

    /**
     * Prepare FTP backup files for tiered retention by extracting dates from filenames
     */
    private function prepareFtpBackupsForTieredRetention(array $files): array
    {
        $backups = [];
        $now = time();

        foreach ($files as $filename) {
            // Try to extract date from filename using regex
            if (preg_match('/(\d{4})[-_]?(\d{2})[-_]?(\d{2})[-_]?(\d{2})?[-_]?(\d{2})?[-_]?(\d{2})?/i', $filename, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];

                // Handle hour/minute/second if available
                $hour = isset($matches[4]) ? $matches[4] : '00';
                $minute = isset($matches[5]) ? $matches[5] : '00';
                $second = isset($matches[6]) ? $matches[6] : '00';

                $dateString = "{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}";
                $timestamp = strtotime($dateString);

                // If we got a valid timestamp
                if ($timestamp) {
                    $backups[] = [
                        'filename' => $filename,
                        'timestamp' => $timestamp,
                        'date' => date('Y-m-d', $timestamp),
                        'month' => date('Y-m', $timestamp),
                        'week' => date('Y-W', $timestamp)
                    ];
                }
            } else {
                // If we can't parse the date, put it at the bottom of the list
                // This ensures we don't delete files we can't parse properly
                $backups[] = [
                    'filename' => $filename,
                    'timestamp' => 0, // Very old timestamp ensures it's at bottom of list
                    'date' => '',
                    'month' => '',
                    'week' => ''
                ];
            }
        }

        return $backups;
    }

    /**
     * Get files to delete using simple retention (keep X most recent)
     */
    private function getFilesToDeleteSimple(array $files): array
    {
        $settings = $this->getSettings();
        $retention = $settings['backupRetention'] ?? 10;

        // Sort files by name (assuming they contain dates/timestamps in format)
        usort($files, function ($a, $b) {
            return strcmp($b, $a); // Sort in descending order (newest first)
        });

        // Keep only the specified number of backups
        return array_slice($files, $retention);
    }

    /**
     * Generate a secure key for download links
     * The key is based on hostname and expires after 24 hours
     */
    private function generateDownloadKey(string $filename): string
    {
        // Use the current date (resets every 24 hours) and host as the basis for the key
        $date = date('Y-m-d');
        $host = kirby()->site()->url();
        $salt = 'ftp-backup-secure-key'; // Additional salt for security

        // Create a hash that changes daily and is specific to this host and file
        return hash('sha256', $filename . $date . $host . $salt);
    }

    /**
     * Validate a download key for a given filename
     */
    public function validateDownloadKey(string $filename, string $key): bool
    {
        $validKey = $this->generateDownloadKey($filename);
        return hash_equals($validKey, $key);
    }

    /**
     * Download a backup file
     */
    public function downloadBackup(string $filename, string $key): Response
    {
        if (!$this->validateDownloadKey($filename, $key)) {
            return Response::json([
                'status' => 'error',
                'message' => 'Invalid download key. Please refresh the page.'
            ], 403);
        }

        $filepath = $this->backupDir . '/' . $filename;

        if (!F::exists($filepath)) {
            return Response::json([
                'status' => 'error',
                'message' => 'Backup file not found'
            ], 404);
        }

        return Response::download($filepath);
    }

    private function hasCredentials(array $settings): bool
    {
        // do not check for password as it may be empty
        return !empty($settings['ftpUsername']) || !empty($settings['ftpPrivateKey']);
    }

    /**
     * @return bool
     */
    public function isLocalDev(): bool
    {
        return false && option('debug', false) && defined('STDIN');
    }

    /**
     * Execute backup and format result message
     * Unified method for both CLI and URL execution
     *
     * @param bool $uploadToFtp Whether to upload to FTP server
     * @return array ['success' => bool, 'message' => string, 'exitCode' => int]
     */
    public function executeBackupWithFormatting(bool $uploadToFtp = true): array
    {
        try {
            $result = $this->createBackup($uploadToFtp);

            if ($result['status'] === 'success') {
                $message = "Backup created successfully: " . ($result['data']['filename'] ?? 'unknown');

                if (isset($result['data']['ftpResult']) && $result['data']['ftpResult']['uploaded']) {
                    $message .= "\n" . ($result['data']['ftpResult']['message'] ?? '');
                } else {
                    $message .= "\nBackup not uploaded to FTP: " . ($result['data']['ftpResult']['message'] ?? 'Unknown error');
                }

                return [
                    'success' => true,
                    'message' => $message,
                    'exitCode' => 0
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error creating backup: ' . ($result['message'] ?? 'Unknown error'),
                    'exitCode' => 1
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Fatal error: ' . $e->getMessage(),
                'exitCode' => 1
            ];
        }
    }
}
