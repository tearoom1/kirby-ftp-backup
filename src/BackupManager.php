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
            // Also clean up FTP backups directly
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
     * Apply tiered retention strategy to a list of backups
     * - Keep all backups from the last X days (daily)
     * - Then keep one backup per 7-day period for Y periods
     * - Then keep one backup per 30-day period for Z periods
     */
    private function applyTieredRetentionStrategy(array $backups, array $tieredSettings): array
    {
        // Sort by timestamp (newest first)
        usort($backups, function ($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        if (empty($backups)) {
            return [];
        }

        // Calculate cutoff timestamps from now
        $now = time();

        // Daily cutoff: X days ago from now
        $dailyDays = max(1, intval($tieredSettings['daily']));
        $dailyCutoff = $now - ($dailyDays * 86400);

        // Weekly cutoff: After daily period + X weeks
        $weeklyWeeks = max(1, intval($tieredSettings['weekly']));
        $weeklyCutoff = $dailyCutoff - ($weeklyWeeks * 7 * 86400);

        // Monthly cutoff: After weekly period + X months
        $monthlyMonths = max(1, intval($tieredSettings['monthly']));
        $monthlyCutoff = $weeklyCutoff - ($monthlyMonths * 30 * 86400);

        // Prepare result arrays
        $keepBackups = [];
        $toDeleteBackups = [];

        // Always keep the newest backup
        if (!empty($backups)) {
            $newestBackup = $backups[0];
            $newestBackup['retention'] = 'newest';
            $keepBackups[] = $newestBackup;
        }

        // Initialize time buckets for 7-day periods and 30-day periods
        $weeklyBuckets = [];
        $monthlyBuckets = [];

        // First pass: Keep all daily backups and place others in appropriate buckets
        foreach ($backups as $index => $backup) {
            // Skip the newest backup we already added
            if ($index === 0 && !empty($keepBackups)) {
                continue;
            }

            $timestamp = $backup['timestamp'];

            // Category 1: Keep all backups within daily retention period
            if ($timestamp >= $dailyCutoff) {
                $backup['retention'] = 'daily';
                $keepBackups[] = $backup;
                continue;
            }

            // Category 2: Put into weekly buckets (7-day periods)
            if ($timestamp >= $weeklyCutoff) {
                // Calculate which 7-day period this belongs to (counting backward from dailyCutoff)
                $periodIndex = floor(($dailyCutoff - $timestamp) / (7 * 86400));

                // Store this backup in the appropriate bucket if it's the newest we've seen for this period
                if (!isset($weeklyBuckets[$periodIndex]) || $timestamp > $weeklyBuckets[$periodIndex]['timestamp']) {
                    $backup['retention'] = 'weekly-period-' . $periodIndex;
                    $weeklyBuckets[$periodIndex] = $backup;
                } else {
                    $backup['retention'] = 'weekly-duplicate';
                    $toDeleteBackups[] = $backup;
                }
                continue;
            }

            // Category 3: Put into monthly buckets (30-day periods)
            if ($timestamp >= $monthlyCutoff) {
                // Calculate which 30-day period this belongs to (counting backward from weeklyCutoff)
                $periodIndex = floor(($weeklyCutoff - $timestamp) / (30 * 86400));

                // Store this backup in the appropriate bucket if it's the newest we've seen for this period
                if (!isset($monthlyBuckets[$periodIndex]) || $timestamp > $monthlyBuckets[$periodIndex]['timestamp']) {
                    $backup['retention'] = 'monthly-period-' . $periodIndex;
                    $monthlyBuckets[$periodIndex] = $backup;
                } else {
                    $backup['retention'] = 'monthly-duplicate';
                    $toDeleteBackups[] = $backup;
                }
                continue;
            }

            // Category 4: Too old, don't keep
            $backup['retention'] = 'too-old';
            $toDeleteBackups[] = $backup;
        }

        // Add the selected weekly and monthly backups to our keep list
        foreach ($weeklyBuckets as $backup) {
            $keepBackups[] = $backup;
        }

        foreach ($monthlyBuckets as $backup) {
            $keepBackups[] = $backup;
        }

        // as long monthlyBuckets are not full count = monthlyMonths()
        // keep the oldest backup
        if (count($backups) > 0 && count($monthlyBuckets) < $monthlyMonths) {
            $keepBackups[] = $backups[count($backups) - 1];
        }

        if ($this->isLocalDev()) {
            echo "=============== TIERED RETENTION STRATEGY ===============\n";
            echo "Settings: {$dailyDays} days daily, {$weeklyWeeks} 7-day periods, {$monthlyMonths} 30-day periods\n";
            echo "Current time: " . date('Y-m-d H:i:s', $now) . "\n";
            echo "Cutoffs:\n";
            echo "- Daily cutoff: " . date('Y-m-d H:i:s', $dailyCutoff) . "\n";
            echo "- Weekly cutoff: " . date('Y-m-d H:i:s', $weeklyCutoff) . "\n";
            echo "- Monthly cutoff: " . date('Y-m-d H:i:s', $monthlyCutoff) . "\n\n";

            echo "Keeping " . count($keepBackups) . " of " . count($backups) . " backups:\n";
            foreach ($keepBackups as $backup) {
                echo "- [{$backup['retention']}] {$backup['filename']} (" .
                     date('Y-m-d H:i:s', $backup['timestamp']) . ")\n";
            }

            if (!empty($toDeleteBackups)) {
                echo "\nDeleting " . count($toDeleteBackups) . " backups:\n";
                foreach ($toDeleteBackups as $backup) {
                    echo "- [{$backup['retention']}] {$backup['filename']} (" .
                         date('Y-m-d H:i:s', $backup['timestamp']) . ")\n";
                }
            }
        }

        return $keepBackups;
    }

    /**
     * Clean up old backups from FTP server based on retention setting
     */
    public function cleanupFtpBackups($ftpClient, $settings): array
    {
        try {
            $retentionStrategy = $settings['retentionStrategy'] ?? 'simple';
            $directory = $settings['ftpDirectory'] ?? '/';

            // List files on the FTP server
            $files = $ftpClient->listDirectory($directory);

            // Filter to only include .zip files
            $backupFiles = [];
            foreach ($files as $file) {
                if (substr($file, -4) === '.zip') {
                    $backupFiles[] = $file;
                }
            }

            // Determine files to delete based on retention strategy
            $toDelete = [];

            if ($retentionStrategy === 'tiered') {
                $backups = $this->prepareFtpBackupsForTieredRetention($backupFiles);
                $tieredSettings = $settings['tieredRetention'] ?? [
                    'daily' => 10,
                    'weekly' => 4,
                    'monthly' => 6
                ];

                $keepBackups = $this->applyTieredRetentionStrategy($backups, $tieredSettings);

                $keepFilenames = array_map(function ($backup) {
                    return $backup['filename'];
                }, $keepBackups);

                foreach ($backups as $backup) {
                    if (!in_array($backup['filename'], $keepFilenames)) {
                        $toDelete[] = $backup['filename'];
                    }
                }
            } else {
                $toDelete = $this->getFilesToDeleteSimple($backupFiles);
            }

            // Delete files from FTP
            $deletedCount = 0;
            foreach ($toDelete as $file) {
                if ($this->isLocalDev()) {
                    echo "Would delete from FTP: {$file}\n";
                } else {
                    $this->removeFromFtp($ftpClient, $settings, $file);
                }
                $deletedCount++;
            }

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

        return Response::file($filepath);
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
}
