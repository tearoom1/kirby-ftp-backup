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
            $dir = $kirby->root('base') . '/' . $dir;
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
            'ftpHost' => option('tearoom1.ftp-backup.ftpHost', ''),
            'ftpPort' => option('tearoom1.ftp-backup.ftpPort', 21),
            'ftpUsername' => option('tearoom1.ftp-backup.ftpUsername', ''),
            'ftpPassword' => option('tearoom1.ftp-backup.ftpPassword', ''),
            'ftpDirectory' => option('tearoom1.ftp-backup.ftpDirectory', ''),
            'ftpSsl' => option('tearoom1.ftp-backup.ftpSsl', false),
            'ftpPassive' => option('tearoom1.ftp-backup.ftpPassive', true),
            'backupDirectory' => option('tearoom1.ftp-backup.backupDirectory', kirby()->root('content') . '/.backups'),
            'backupRetention' => option('tearoom1.ftp-backup.backupRetention', 10),
            'deleteFromFtp' => option('tearoom1.ftp-backup.deleteFromFtp', true)
        ];
    }

    /**
     * Save FTP settings
     */
    public function saveSettings(array $settings): array
    {
        // Settings are now managed via config options only
        return [
            'status' => 'error',
            'message' => 'Settings are managed via site config options only.'
        ];
    }

    /**
     * List available backups
     */
    public function listBackups(): array
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

        return [
            'status' => 'success',
            'data' => $backups
        ];
    }

    /**
     * Create a new backup
     */
    public function createBackup(bool $uploadToFtp = true): array
    {
        try {
            // Generate filename with date
            $date = date('Y-m-d-His');
            $filename = "backup-{$date}.zip";
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

        // Check if essential settings are available
        if (empty($settings['ftpHost']) || empty($settings['ftpUsername']) || empty($settings['ftpPassword'])) {
            return [
                'success' => false,
                'message' => 'Incomplete FTP settings'
            ];
        }

        // Create and connect FTP client
        $ftpClient = new FtpClient(
            $settings['ftpHost'],
            (int)($settings['ftpPort'] ?? 21),
            $settings['ftpUsername'],
            $settings['ftpPassword'],
            (bool)($settings['ftpSsl'] ?? false),
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
     * Clean up old backups based on retention setting
     */
    public function cleanupOldBackups(): void
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

        if ($settings['deleteFromFtp']) {
            // Also clean up FTP backups directly
            $this->cleanupFtpBackups();
        }
    }

    /**
     * Clean up old backups from FTP server based on retention setting
     */
    public function cleanupFtpBackups(): array
    {
        try {
            $settings = $this->getSettings();
            $backupRetention = $settings['backupRetention'] ?? 10;

            // Skip cleanup if retention is set to 0 (keep all backups)
            if ($backupRetention <= 0) {
                return [
                    'success' => true,
                    'message' => 'Retention set to keep all backups, skipping FTP cleanup'
                ];
            }

            // Initialize FTP client
            $ftpResult = $this->initFtpClient();
            if (!$ftpResult['success']) {
                return [
                    'success' => false,
                    'message' => 'Failed to connect to FTP server: ' . $ftpResult['message']
                ];
            }

            $ftpClient = $ftpResult['client'];
            $directory = $settings['ftpDirectory'] ?? '';

            // List files on the FTP server
            $files = $ftpClient->listDirectory($directory);
            
            // Filter to only include .zip files
            $backups = [];
            foreach ($files as $file) {
                if (substr($file, -4) === '.zip') {
                    $backups[] = $file;
                }
            }

            // Sort backups by filename (assuming they contain dates/timestamps)
            usort($backups, function($a, $b) {
                return strcmp($b, $a); // Sort in descending order (newest first)
            });

            // Keep only the specified number of backups
            $toDelete = array_slice($backups, $backupRetention);
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
}
