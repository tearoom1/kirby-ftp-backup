<?php

namespace TearoomOne\FtpBackup;

use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\RSA;

/**
 * SFTP client for handling SFTP operations using phpseclib
 */
class SftpClient implements FtpClientInterface
{
    public const TIMEOUT = 30;
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private ?string $privateKey;
    private ?string $passphrase;
    private int $timeout;
    private ?SFTP $sftp = null;

    public function __construct(
        string $host,
        int $port = 22,
        string $username = '',
        string $password = '',
        string|null $privateKey = null,
        string|null $passphrase = null,
        int $timeout = self::TIMEOUT
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->privateKey = $privateKey;
        $this->passphrase = $passphrase;
        $this->timeout = $timeout;
    }

    /**
     * Connect to the SFTP server
     */
    public function connect(): void
    {
        // Create SFTP connection
        $this->sftp = new SFTP($this->host, $this->port, $this->timeout);

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
    public function upload(string $localFile, string $remoteFile, ?callable $onProgress = null): bool
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

        // Upload file — phpseclib calls $progressCallback with the current byte
        // offset after each packet, so we can report real progress and cancel.
        $progressCallback = $onProgress
            ? function (int $position) use ($onProgress, $fileSize) {
                $onProgress($position, $fileSize);
            }
            : null;

        // Clear the error log before uploading so we only collect errors from this transfer.
        $this->sftp->getErrors();

        $result = $this->sftp->put($remoteFile, $localFile, SFTP::SOURCE_LOCAL_FILE, -1, -1, $progressCallback);

        // Collect all errors produced during the transfer.
        $allErrors = $this->sftp->getErrors();

        // SSH_MSG_GLOBAL_REQUEST and hostkeys messages are noise from the server,
        // not real failures — ignore them when deciding whether put() truly failed.
        $criticalErrors = array_values(array_filter($allErrors, function ($error) {
            return !str_contains($error, 'SSH_MSG_GLOBAL_REQUEST') &&
                   !str_contains($error, 'hostkeys-00@openssh.com');
        }));

        if (!$result && !empty($criticalErrors)) {
            throw new \Exception('Upload failed: ' . implode('; ', $criticalErrors));
        }

        // Verify that the remote file exists and its size matches the local file.
        // Use stat() directly to avoid phpseclib's internal attribute cache returning
        // stale results after a failed or partial transfer.
        $stat = $this->sftp->stat($remoteFile);

        if ($stat === false || !isset($stat['size'])) {
            // stat() failed — the connection may be broken after an interrupted
            // transfer. A partial file may already exist on the server.
            $detail = !empty($criticalErrors) ? ' Error: ' . implode('; ', $criticalErrors) : '';
            throw new \Exception(
                "Upload was interrupted — a partial file may remain on the server.{$detail} " .
                "Check your server's SFTP timeout and ensure the connection stays alive for large files."
            );
        }

        if ($stat['size'] !== $fileSize) {
            throw new \Exception(
                "Upload incomplete: only {$stat['size']} of {$fileSize} bytes were transferred. " .
                "The partial file remains on the server. This is usually caused by a connection timeout."
            );
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
