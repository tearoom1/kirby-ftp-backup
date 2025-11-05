<?php

namespace TearoomOne\FtpBackup;

use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\RSA;

/**
 * SFTP client for handling SFTP operations using phpseclib
 */
class SftpClient implements FtpClientInterface
{
    public const TIMEOUT = 60;
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private ?string $privateKey;
    private ?string $passphrase;
    private ?SFTP $sftp = null;

    public function __construct(
        string $host,
        int $port = 22,
        string $username = '',
        string $password = '',
        string|null $privateKey = null,
        string|null $passphrase = null
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->privateKey = $privateKey;
        $this->passphrase = $passphrase;
    }

    /**
     * Connect to the SFTP server
     */
    public function connect(): void
    {
        // Create SFTP connection
        $this->sftp = new SFTP($this->host, $this->port, self::TIMEOUT);

        // Authenticate with private key or password
        if ($this->privateKey) {
            // Use private key authentication
            $key = RSA::loadPrivateKey(file_get_contents($this->privateKey), $this->passphrase);

            if (!$this->sftp->login($this->username, $key)) {
                throw new \Exception('SFTP authentication failed: Invalid key or passphrase');
            }
        } else {
            // Use password authentication
            if (!$this->sftp->login($this->username, $this->password)) {
                throw new \Exception('SFTP authentication failed: Invalid credentials');
            }
        }
    }

    /**
     * Upload a file to the SFTP server
     */
    public function upload(string $localFile, string $remoteFile): bool
    {
        if (!$this->sftp) {
            throw new \Exception('Not connected to SFTP server');
        }

        // Check if local file exists and is readable
        if (!file_exists($localFile)) {
            throw new \Exception("Local file does not exist: {$localFile}");
        }

        if (!is_readable($localFile)) {
            throw new \Exception("Local file is not readable: {$localFile}");
        }

        $fileSize = filesize($localFile);
        if ($fileSize === false || $fileSize === 0) {
            throw new \Exception("Local file is empty or cannot read size: {$localFile}");
        }

        // Make sure the remote directory exists
        $remoteDir = dirname($remoteFile);
        $this->createRemoteDirectory($remoteDir);

        // Upload file
        $result = $this->sftp->put($remoteFile, $localFile, SFTP::SOURCE_LOCAL_FILE);
        
        // Get all errors/warnings
        $errors = $this->sftp->getErrors();
        
        // Filter out non-critical SSH messages
        $criticalErrors = array_filter($errors, function($error) {
            return !str_contains($error, 'SSH_MSG_GLOBAL_REQUEST') &&
                   !str_contains($error, 'hostkeys-00@openssh.com');
        });
        
        if (!$result) {
            if (!empty($criticalErrors)) {
                $errorMsg = implode(', ', $criticalErrors);
                throw new \Exception("Failed to upload file to SFTP server: {$remoteFile}. Error: {$errorMsg}");
            }
            // put() returned false but no critical errors - continue to verification
        }

        // Verify uploaded file exists and size matches local file
        try {
            // Check if file exists first
            if (!$this->sftp->file_exists($remoteFile)) {
                throw new \Exception("Upload verification failed: Remote file does not exist at {$remoteFile}");
            }
            
            // Get file size using stat
            $stat = $this->sftp->stat($remoteFile);
            if ($stat === false) {
                // File exists but can't get stats - skip verification
                return true;
            }
            
            $remoteSize = $stat['size'] ?? false;
            if ($remoteSize === false) {
                // Can't get size from stat - skip verification
                return true;
            }
            
            if ($remoteSize !== $fileSize) {
                throw new \Exception("Upload verification failed. Local size: {$fileSize} bytes, Remote size: {$remoteSize} bytes");
            }
        } catch (\Exception $e) {
            // If it's our own exception, re-throw it
            if (strpos($e->getMessage(), 'Upload verification failed') === 0) {
                throw $e;
            }
            // Otherwise continue (verification optional)
        }

        return true;
    }

    /**
     * Create remote directory recursively if it doesn't exist
     */
    public function createRemoteDirectory(string $directory): void
    {
        if ($directory === '/' || $directory === '.') {
            return;
        }

        if (!$this->sftp) {
            throw new \Exception('Not connected to SFTP server');
        }

        // Normalize directory path
        $directory = rtrim($directory, '/');
        if (empty($directory)) {
            return;
        }

        // Check if directory already exists
        if ($this->sftp->is_dir($directory)) {
            return;
        }

        // Create directory recursively
        if (!$this->sftp->mkdir($directory, -1, true)) {
            throw new \Exception("Failed to create directory on SFTP server: {$directory}");
        }
    }

    /**
     * Disconnect from the SFTP server
     */
    public function disconnect(): void
    {
        $this->sftp = null;
    }

    /**
     * Delete a file from the SFTP server
     */
    public function delete(string $remoteFile): bool
    {
        if (!$this->sftp) {
            throw new \Exception('Not connected to SFTP server');
        }

        if (!$this->sftp->delete($remoteFile, false)) {
            throw new \Exception("Failed to delete file from SFTP server: {$remoteFile}");
        }

        return true;
    }

    /**
     * List files in a directory on the SFTP server
     */
    public function listDirectory(string $directory): array
    {
        if (!$this->sftp) {
            throw new \Exception('Not connected to SFTP server');
        }

        // Normalize directory path
        $directory = rtrim($directory, '/');
        if ($directory === '') {
            $directory = '/';
        }

        // Get directory listing (exclude . and ..)
        $listing = $this->sftp->nlist($directory);

        if ($listing === false) {
            throw new \Exception("Failed to list directory on SFTP server: {$directory}");
        }

        // Filter out . and .. entries
        return array_values(array_filter($listing, function ($item) {
            return $item !== '.' && $item !== '..';
        }));
    }

    /**
     * Get file size from SFTP server
     */
    public function getFileSize(string $remoteFile): int
    {
        if (!$this->sftp) {
            throw new \Exception('Not connected to SFTP server');
        }

        $stat = $this->sftp->stat($remoteFile);

        if ($stat === false) {
            throw new \Exception("File does not exist on SFTP server: {$remoteFile}");
        }

        return $stat['size'];
    }

    /**
     * Get file modified time from SFTP server
     */
    public function getModifiedTime(string $remoteFile): int
    {
        if (!$this->sftp) {
            throw new \Exception('Not connected to SFTP server');
        }

        $stat = $this->sftp->stat($remoteFile);

        if ($stat === false) {
            throw new \Exception("File does not exist on SFTP server: {$remoteFile}");
        }

        return $stat['mtime'];
    }

    /**
     * Destructor to ensure connection is closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
