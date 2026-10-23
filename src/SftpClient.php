<?php

namespace TearoomOne\FtpBackup;

use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

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
    private int $keepAlive;
    private ?SFTP $sftp = null;

    public function __construct(
        string $host,
        int $port = 22,
        string $username = '',
        string $password = '',
        string|null $privateKey = null,
        string|null $passphrase = null,
        int $timeout = self::TIMEOUT,
        int $keepAlive = 0
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->privateKey = $privateKey;
        $this->passphrase = $passphrase;
        $this->timeout = $timeout;
        $this->keepAlive = $keepAlive;
    }

    /**
     * Human-readable endpoint for error messages, e.g. sftp://user@host:22
     */
    public function endpoint(): string
    {
        $user = $this->username !== '' ? $this->username . '@' : '';
        return "sftp://{$user}{$this->host}:{$this->port}";
    }

    /**
     * Connect to the SFTP server
     */
    public function connect(): void
    {
        // Load the key first so a bad key is reported as such, not as a login failure
        $key = $this->privateKey ? $this->loadPrivateKey() : null;

        $this->sftp = new SFTP($this->host, $this->port, $this->timeout);

        if ($this->keepAlive > 0) {
            $this->sftp->setKeepAlive($this->keepAlive);
        }

        try {
            $loggedIn = $this->sftp->login($this->username, $key ?? $this->password);
        } catch (\Throwable $e) {
            throw new \Exception('SFTP connection failed [' . $this->endpoint() . ']: ' . $e->getMessage(), 0, $e);
        }

        if (!$loggedIn) {
            throw new \Exception($this->loginError($key ? 'Invalid key or passphrase' : 'Invalid credentials'));
        }
    }

    /**
     * Load the private key file; RSA, ECDSA and Ed25519 keys are supported
     */
    private function loadPrivateKey(): PrivateKey
    {
        $contents = is_readable($this->privateKey) ? file_get_contents($this->privateKey) : false;
        if ($contents === false) {
            throw new \Exception("SFTP private key file is not readable: {$this->privateKey}");
        }

        try {
            return PublicKeyLoader::loadPrivateKey($contents, $this->passphrase ?: false);
        } catch (\Throwable $e) {
            throw new \Exception(
                "Could not load SFTP private key {$this->privateKey}: wrong passphrase or unsupported key format",
                0,
                $e
            );
        }
    }

    /**
     * Distinguish an unreachable host from rejected credentials
     */
    private function loginError(string $credentialsMessage): string
    {
        $reason = $this->sftp->isConnected()
            ? $credentialsMessage
            : 'Could not connect' . ($this->sftp->getLastError() ? ': ' . $this->sftp->getLastError() : '');

        return 'SFTP authentication failed [' . $this->endpoint() . ']: ' . $reason;
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

        // phpseclib keeps SSH-layer errors for the lifetime of the connection.
        // Snapshot the current count so diagnostics below only inspect this upload.
        $previousErrorCount = count($this->sftp->getErrors());

        $result = $this->sftp->put($remoteFile, $localFile, SFTP::SOURCE_LOCAL_FILE, -1, -1, $progressCallback);

        // Collect all errors produced during the transfer.
        $allErrors = array_slice($this->sftp->getErrors(), $previousErrorCount);
        $lastError = !empty($allErrors) ? $allErrors[count($allErrors) - 1] : '';

        // SSH_MSG_GLOBAL_REQUEST and hostkeys messages are noise — keepalives and
        // server-side host-key rotation notices, not real failures.
        $criticalErrors = array_values(array_filter($allErrors, function ($error) {
            return !$this->isNonCriticalSshError($error);
        }));

        if (!empty($criticalErrors)) {
            error_log('[kirby-ftp-backup] SFTP put() errors: ' . implode('; ', $criticalErrors));
        }
        if ($lastError && !$this->isNonCriticalSshError($lastError)) {
            error_log('[kirby-ftp-backup] SFTP last error: ' . $lastError);
        }

        if (!$result && !empty($criticalErrors)) {
            throw new \Exception('Upload failed: ' . implode('; ', $criticalErrors));
        }

        // Verify that the remote file exists and its size matches the local file.
        // If the connection was broken during transfer (e.g. by a phpseclib/OpenSSH
        // protocol quirk), reconnect first and then stat — this gives an accurate
        // picture of what is actually on the server.
        $stat = $this->sftp->stat($remoteFile);

        if ($stat === false) {
            // Connection may be broken — reconnect and try once more.
            try {
                $this->connect();
                $stat = $this->sftp->stat($remoteFile);
            } catch (\Exception $e) {
                // Reconnect failed; report with whatever diagnostic info we have.
            }
        }

        if ($stat === false || !isset($stat['size'])) {
            $parts = [];
            if (!empty($criticalErrors)) {
                $parts[] = implode('; ', $criticalErrors);
            } elseif ($lastError && !$this->isNonCriticalSshError($lastError)) {
                $parts[] = $lastError;
            }
            $detail = !empty($parts) ? ' (' . implode('; ', $parts) . ')' : '';
            throw new \Exception(
                "Upload failed: could not verify file on server after transfer.{$detail} " .
                "Check your PHP error log for full details."
            );
        }

        if ($stat['size'] !== $fileSize) {
            throw new \Exception(
                "Upload incomplete: only {$stat['size']} of {$fileSize} bytes transferred. " .
                "A partial file remains on the server."
            );
        }

        return true;
    }

    private function isNonCriticalSshError(string $error): bool
    {
        return str_contains($error, 'SSH_MSG_GLOBAL_REQUEST') ||
               str_contains($error, 'hostkeys-00@openssh.com');
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
     * Download a file from the SFTP server into a local file
     */
    public function download(string $remoteFile, string $localFile): void
    {
        if (!$this->sftp) {
            throw new \Exception('Not connected to SFTP server');
        }

        if ($this->sftp->get($remoteFile, $localFile) === false) {
            throw new \Exception("Failed to download from SFTP server: {$remoteFile} [" . $this->endpoint() . ']');
        }
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
