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
            'host' => option('tearoom1.ftp-backup.ftpHost', ''),
            'port' => option('tearoom1.ftp-backup.ftpPort', 21),
            'username' => option('tearoom1.ftp-backup.ftpUsername', ''),
            'password' => option('tearoom1.ftp-backup.ftpPassword', ''),
            'directory' => option('tearoom1.ftp-backup.ftpDirectory', '/'),
            'ssl' => option('tearoom1.ftp-backup.ftpSsl', false),
            'passive' => option('tearoom1.ftp-backup.ftpPassive', true),
            'deleteFromFtp' => option('tearoom1.ftp-backup.deleteFromFtp', true),
            'backupDirectory' => $this->backupDir,
            'backupRetention' => option('tearoom1.ftp-backup.backupRetention', 10)
        ];
    }

    /**
     * Save FTP settings
     */
    public function saveSettings(array $settings): array
    {
        // This functionality is removed as we're using Kirby options now
        return [
            'status' => 'error',
            'message' => 'Settings must be configured in the site/config/config.php file'
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
                $backups[] = [
                    'filename' => $file,
                    'size' => F::size($path),
                    'modified' => F::modified($path),
                    'downloadUrl' => '/api/ftp-backup/download/' . $file
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
        if (empty($settings['host']) || empty($settings['username']) || empty($settings['password'])) {
            return [
                'success' => false,
                'message' => 'Incomplete FTP settings'
            ];
        }

        // Create and connect FTP client
        $ftpClient = new FtpClient(
            $settings['host'],
            (int)($settings['port'] ?? 21),
            $settings['username'],
            $settings['password'],
            (bool)($settings['ssl'] ?? false),
            (bool)($settings['passive'] ?? true)
        );

        $ftpClient->connect();
        $directory = $settings['directory'] ?? '/';

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
    private function cleanupOldBackups(): void
    {
        // Get retention value from settings, fallback to option, then default to 10
        $settings = $this->getSettings();
        $retention = (int)($settings['backupRetention'] ?? option('tearoom1.ftp-backup.backupRetention', 10));

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

                // Use deleteFromFtp setting from panel or fallback to config option
                $settings = $this->getSettings();
                $deleteFromFtp = $settings['deleteFromFtp'] ?? option('tearoom1.ftp-backup.deleteFromFtp', true);

                if ($deleteFromFtp) {
                    $this->removeFromFtp($file);
                }
            }
        }
    }

    /**
     * Download a backup file
     */
    public function downloadBackup(string $filename): Response
    {
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
