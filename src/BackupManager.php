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

        $dir = option('tearoom1.ftp-backup.backupDirectory', $kirby->root('content') . '/.backups');
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
            'ftpProtocol' => option('tearoom1.ftp-backup.ftpProtocol', 'ftp'),
            'ftpHost' => option('tearoom1.ftp-backup.ftpHost', ''),
            'ftpPort' => option('tearoom1.ftp-backup.ftpPort', 21),
            'ftpUsername' => option('tearoom1.ftp-backup.ftpUsername', ''),
            'ftpPassword' => option('tearoom1.ftp-backup.ftpPassword', ''),
            'ftpDirectory' => option('tearoom1.ftp-backup.ftpDirectory', ''),
            'ftpPassive' => option('tearoom1.ftp-backup.ftpPassive', true),
            'ftpPrivateKey' => option('tearoom1.ftp-backup.ftpPrivateKey'),
            'ftpPassphrase' => option('tearoom1.ftp-backup.ftpPassphrase'),
            // General settings
            'backupDirectory' => option('tearoom1.ftp-backup.backupDirectory', kirby()->root('content') . '/.backups'),
            'backupRetention' => option('tearoom1.ftp-backup.backupRetention', 10),
            'deleteFromFtp' => option('tearoom1.ftp-backup.deleteFromFtp', true),
            'retentionStrategy' => option('tearoom1.ftp-backup.retentionStrategy', 'simple'),
            'tieredRetention' => [
                'daily' => option('tearoom1.ftp-backup.tieredRetention.daily', 10),
                'weekly' => option('tearoom1.ftp-backup.tieredRetention.weekly', 4),
                'monthly' => option('tearoom1.ftp-backup.tieredRetention.monthly', 6)
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
        try {
            // Generate filename with date
            $date = date('Y-m-d-His');
            $filePrefix = option('tearoom1.ftp-backup.filePrefix', 'backup-');
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

            // Upload to FTP if requested
            $ftpResult = ['uploaded' => false];
            if ($uploadToFtp) {
                $ftpResult = $this->uploadToFtp($filepath, $filename);
            }

            // Cleanup old backups
            $this->cleanupOldBackups();

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
        }
    }

    /**
     * Upload a backup file to the FTP server
     */
    private function uploadToFtp(string $localFile, string $remoteFilename): array
    {
        try {
            $ftpResult = $this->initFtpClient();

            if (!$ftpResult['success']) {
                return [
                    'uploaded' => false,
                    'message' => $ftpResult['message']
                ];
            }

            $ftpClient = $ftpResult['client'];
            $directory = $ftpResult['directory'];

            $ftpClient->upload($localFile, $directory . '/' . $remoteFilename);
            $ftpClient->disconnect();

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
    private function removeFromFtp(string $filename): array
    {
        try {
            $ftpResult = $this->initFtpClient();

            if (!$ftpResult['success']) {
                return [
                    'deleted' => false,
                    'message' => $ftpResult['message']
                ];
            }

            $ftpClient = $ftpResult['client'];
            $directory = $ftpResult['directory'];

            $ftpClient->delete($directory . '/' . $filename);
            $ftpClient->disconnect();

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
    private function initFtpClient(): array
    {
        $settings = $this->getSettings();
        $ftpProtocol = $settings['ftpProtocol'] ?? 'ftp';

        // Check which connection type to use
        if ($ftpProtocol === 'sftp') {
            return $this->initSftpClient();
        }

        // Default to regular FTP
        // Check if essential settings are available
        if (empty($settings['ftpHost']) || empty($settings['ftpUsername']) || empty($settings['ftpPassword'])) {
            return [
                'success' => false,
                'message' => 'Incomplete FTP settings. Unable to perform FTP operations.'
            ];
        }

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
        $directory = $settings['ftpDirectory'] ?? '/';

        return [
            'success' => true,
            'client' => $ftpClient,
            'directory' => $directory
        ];
    }

    /**
     * Initialize the SFTP client with settings
     */
    private function initSftpClient(): array
    {
        $settings = $this->getSettings();

        // Check if essential settings are available
        if (empty($settings['ftpHost']) || empty($settings['ftpUsername']) ||
            (empty($settings['ftpPassword']) && empty($settings['ftpPrivateKey']))) {
            return [
                'success' => false,
                'message' => 'Incomplete SFTP settings. Unable to perform SFTP operations.'
            ];
        }

        try {
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
            $directory = $settings['ftpDirectory'] ?? '/';
            
            return [
                'success' => true,
                'client' => $sftpClient,
                'directory' => $directory
            ];
        } catch (\phpseclib3\Exception\NoKeyLoadedException $e) {
            return [
                'success' => false,
                'message' => 'SFTP key error: ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'SFTP connection failed: ' . $e->getMessage()
            ];
        }
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
    public function cleanupOldBackups(): void
    {
        $settings = $this->getSettings();
        $retentionStrategy = $settings['retentionStrategy'] ?? 'simple';

        if ($retentionStrategy === 'tiered') {
            $this->applyTieredRetention();
        } else {
            $this->applySimpleRetention();
        }

        if ($settings['deleteFromFtp']) {
            // Also clean up FTP backups directly
            $this->cleanupFtpBackups();
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
                F::remove($this->backupDir . '/' . $file);
            }
        }
    }

    /**
     * Apply tiered retention strategy:
     * - Keep all daily backups for X days
     * - Then keep one backup per week for X weeks
     * - Then keep one backup per month for X months
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
        $keepFilenames = array_map(function($backup) {
            return $backup['filename'];
        }, $keepBackups);

        foreach ($backups as $backup) {
            if (!in_array($backup['filename'], $keepFilenames)) {
                F::remove($backup['path']);
            }
        }
    }

    /**
     * Apply tiered retention strategy to a list of backups
     */
    private function applyTieredRetentionStrategy(array $backups, array $tieredSettings): array
    {
        // Sort by timestamp (newest first)
        usort($backups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        if (empty($backups)) {
            return [];
        }

        // Calculate cutoff timestamps
        $now = time();
        $dailyCutoff = $now - ($tieredSettings['daily'] * 86400); // X days ago
        $weeklyCutoff = $dailyCutoff - ($tieredSettings['weekly'] * 7 * 86400); // X weeks after daily cutoff
        $monthlyCutoff = $weeklyCutoff - ($tieredSettings['monthly'] * 30 * 86400); // X months after weekly cutoff

        // Determine which backups to keep
        $keepBackups = [];
        $keepDates = []; // Track dates we've already kept (for weekly/monthly)
        $keepWeeks = []; // Track weeks we've already kept
        $keepMonths = []; // Track months we've already kept

        foreach ($backups as $backup) {
            $timestamp = $backup['timestamp'];
            $date = $backup['date'] ?? date('Y-m-d', $timestamp);

            // Add week and month info if not already present
            if (!isset($backup['week'])) {
                $backup['week'] = date('Y-W', $timestamp);
            }
            if (!isset($backup['month'])) {
                $backup['month'] = date('Y-m', $timestamp);
            }

            $week = $backup['week'];
            $month = $backup['month'];

            // Keep all backups within daily retention period
            if ($timestamp >= $dailyCutoff) {
                $keepBackups[] = $backup;
                $keepDates[$date] = true;
                continue;
            }

            // Keep one backup per week within weekly retention period
            if ($timestamp >= $weeklyCutoff && !isset($keepWeeks[$week])) {
                $keepBackups[] = $backup;
                $keepWeeks[$week] = true;
                continue;
            }

            // Keep one backup per month within monthly retention period
            if ($timestamp >= $monthlyCutoff && !isset($keepMonths[$month])) {
                $keepBackups[] = $backup;
                $keepMonths[$month] = true;
                continue;
            }
        }

        return $keepBackups;
    }

    /**
     * Clean up old backups from FTP server based on retention setting
     */
    public function cleanupFtpBackups(): array
    {
        try {
            $settings = $this->getSettings();
            $retentionStrategy = $settings['retentionStrategy'] ?? 'simple';

            // Initialize FTP client
            $ftpResult = $this->initFtpClient();
            if (!$ftpResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to connect to FTP server: ' . $ftpResult['message']
                ];
            }

            $ftpClient = $ftpResult['client'];
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

                $keepFilenames = array_map(function($backup) {
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
                $this->removeFromFtp($file);
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
        usort($files, function($a, $b) {
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
     * Get stats and file list from the FTP server
     */
    public function getFtpServerStats(): array
    {
        try {
            // Initialize FTP client
            $ftpResult = $this->initFtpClient();
            if (!$ftpResult['success']) {
                return [
                    'status' => 'error',
                    'message' => 'Failed to connect to FTP server: ' . $ftpResult['message']
                ];
            }

            $ftpClient = $ftpResult['client'];
            $settings = $this->getSettings();
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
            usort($backupFiles, function($a, $b) {
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
        }
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
}
