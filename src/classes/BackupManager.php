<?php

namespace TearoomOne\FtpBackup;

use Kirby\Cms\App;
use Kirby\Data\Data;
use Kirby\Filesystem\F;
use Kirby\Filesystem\Dir;
use Kirby\Http\Response;
use Kirby\Toolkit\A;

/**
 * Manages backup creation, listing, and FTP operations
 */
class BackupManager
{
    private string $settingsFile;
    private string $backupDir;

    public function __construct()
    {
        $kirby = App::instance();
        $this->settingsFile = $kirby->root('config') . '/ftp-backup-settings.json';
        $this->backupDir = option('tearoom1.ftp-backup.backupDirectory', $kirby->root('content') . '/.backups');
        
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
        if (!F::exists($this->settingsFile)) {
            return [
                'host' => '',
                'port' => 21,
                'username' => '',
                'password' => '',
                'directory' => '/',
                'passive' => true,
                'ssl' => false,
            ];
        }
        
        $settings = Data::read($this->settingsFile);
        
        // Don't expose actual password in response, just indicate if it's set
        if (isset($settings['password']) && !empty($settings['password'])) {
            $settings['password'] = '********';
            $settings['hasPassword'] = true;
        } else {
            $settings['password'] = '';
            $settings['hasPassword'] = false;
        }
        
        return $settings;
    }

    /**
     * Save FTP settings
     */
    public function saveSettings(array $settings): array
    {
        // If password field contains asterisks (masked), keep the existing password
        if (isset($settings['password']) && $settings['password'] === '********') {
            $existing = F::exists($this->settingsFile) ? Data::read($this->settingsFile) : [];
            $settings['password'] = $existing['password'] ?? '';
        }
        
        // Remove hasPassword field if present
        unset($settings['hasPassword']);
        
        // Save settings
        try {
            Data::write($this->settingsFile, $settings);
            
            // Return masked password in response
            if (!empty($settings['password'])) {
                $settings['password'] = '********';
                $settings['hasPassword'] = true;
            } else {
                $settings['hasPassword'] = false;
            }
            
            return [
                'status' => 'success',
                'message' => 'Settings saved successfully',
                'data' => $settings
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to save settings: ' . $e->getMessage()
            ];
        }
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
            // Get FTP settings
            if (!F::exists($this->settingsFile)) {
                return [
                    'uploaded' => false,
                    'message' => 'No FTP settings found'
                ];
            }
            
            $settings = Data::read($this->settingsFile);
            if (empty($settings['host']) || empty($settings['username']) || empty($settings['password'])) {
                return [
                    'uploaded' => false,
                    'message' => 'Incomplete FTP settings'
                ];
            }
            
            // Create FTP client and upload
            $ftpClient = new FtpClient(
                $settings['host'],
                $settings['port'] ?? 21,
                $settings['username'],
                $settings['password'],
                $settings['ssl'] ?? false,
                $settings['passive'] ?? true
            );
            
            $ftpClient->connect();
            $directory = $settings['directory'] ?? '/';
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
        $retention = option('tearoom1.ftp-backup.backupRetention', 10);
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
