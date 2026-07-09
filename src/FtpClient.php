<?php

namespace TearoomOne\FtpBackup;

/**
 * FTP client for handling FTP operations
 */
class FtpClient implements FtpClientInterface
{
    public const TIMEOUT = 30;
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private bool $ssl;
    private bool $passive;
    private int $timeout;
    private int $maxRetries;
    private int $retryDelay;
    private $connection;

    public function __construct(
        string $host,
        int $port = 21,
        string $username = '',
        string $password = '',
        bool $ssl = false,
        bool $passive = true,
        int $timeout = self::TIMEOUT,
        int $maxRetries = 3,
        int $retryDelay = 5
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->ssl = $ssl;
        $this->passive = $passive;
        $this->timeout = $timeout;
        $this->maxRetries = max(0, $maxRetries);
        $this->retryDelay = max(0, $retryDelay);
    }

    /**
     * Connect to the FTP server
     */
    public function connect(): void
    {
        if ($this->ssl) {
            if (!function_exists('ftp_ssl_connect')) {
                throw new \Exception('FTP SSL is not supported on this server');
            }
            $this->connection = ftp_ssl_connect($this->host, $this->port, $this->timeout);
        } else {
            $this->connection = ftp_connect($this->host, $this->port, $this->timeout);
        }

        if (!$this->connection) {
            throw new \Exception("Failed to connect to FTP server: {$this->host}:{$this->port}");
        }

        if (!ftp_login($this->connection, $this->username, $this->password)) {
            throw new \Exception('Failed to login to FTP server: Invalid credentials');
        }

        if ($this->passive) {
            ftp_pasv($this->connection, true);
        }
    }

    /**
     * Upload a file to the FTP server
     */
    public function upload(string $localFile, string $remoteFile, ?callable $onProgress = null): bool
    {
        if (!$this->connection) {
            throw new \Exception('Not connected to FTP server');
        }

        // Make sure the remote directory exists
        $this->createRemoteDirectory(dirname($remoteFile));

        $localSize = filesize($localFile);
        if ($localSize === false) {
            throw new \Exception("Failed to determine local file size: {$localFile}");
        }

        $attempt = 0;
        $offset = 0;

        while (true) {
            try {
                // Resume both the local read and remote write at the same byte
                // after a transient timeout or dropped data connection.
                $result = ftp_nb_put(
                    $this->connection,
                    $remoteFile,
                    $localFile,
                    FTP_BINARY,
                    $offset
                );

                while ($result === FTP_MOREDATA) {
                    if ($onProgress) {
                        $onProgress(0, 0);
                    }
                    $result = ftp_nb_continue($this->connection);
                }

                if ($result === FTP_FINISHED) {
                    return true;
                }

                throw new \Exception("FTP transfer did not finish: {$remoteFile}");
            } catch (\Throwable $e) {
                // Never turn a deliberate cancellation into an automatic retry.
                if ((int)$e->getCode() === 499 || $attempt >= $this->maxRetries) {
                    throw $e;
                }

                $attempt++;
                $this->disconnect();

                if ($this->retryDelay > 0) {
                    sleep($this->retryDelay);
                }

                $this->connect();
                $remoteSize = ftp_size($this->connection, $remoteFile);
                if ($remoteSize < 0 || $remoteSize > $localSize) {
                    throw new \Exception(
                        "Cannot safely resume FTP upload at remote size {$remoteSize}: {$remoteFile}",
                        0,
                        $e
                    );
                }
                $offset = $remoteSize;
            }
        }
    }

    /**
     * Create remote directory recursively if it doesn't exist
     */
    public function createRemoteDirectory(string $directory): void
    {
        if ($directory === '/' || $directory === '.') {
            return;
        }

        $parts = explode('/', $directory);
        $path = '';

        foreach ($parts as $part) {
            if (!$part) {
                continue;
            }

            $path .= '/' . $part;

            // Try to change to directory, create if fails
            if (@ftp_chdir($this->connection, $path) === false) {
                if (!ftp_mkdir($this->connection, $path)) {
                    throw new \Exception("Failed to create directory on FTP server: {$path}");
                }
                ftp_chdir($this->connection, $path);
            }
        }

        // Return to root directory
        ftp_chdir($this->connection, '/');
    }

    /**
     * Disconnect from the FTP server
     */
    public function disconnect(): void
    {
        if ($this->connection) {
            // Suppress SSL EOF warning from vsftpd: it closes the connection
            // without sending a proper close_notify, which is benign on disconnect.
            @ftp_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Delete a file from the FTP server
     */
    public function delete(string $remoteFile): bool
    {
        if (!$this->connection) {
            throw new \Exception('Not connected to FTP server');
        }

        if (!ftp_delete($this->connection, $remoteFile)) {
            throw new \Exception("Failed to delete file from FTP server: {$remoteFile}");
        }

        return true;
    }

    /**
     * List files in a directory on the FTP server
     */
    public function listDirectory(string $directory): array
    {
        if (!$this->connection) {
            throw new \Exception('Not connected to FTP server');
        }

        // Normalize directory path
        $directory = rtrim($directory, '/');
        if ($directory === '') {
            $directory = '/';
        }

        // Get raw listing
        $rawList = ftp_nlist($this->connection, $directory);

        if ($rawList === false) {
            throw new \Exception("Failed to list directory on FTP server: {$directory}");
        }

        // Filter out parent directory entries and get just filenames
        $files = [];
        foreach ($rawList as $item) {
            $filename = basename($item);
            if ($filename !== '.' && $filename !== '..') {
                $files[] = $filename;
            }
        }

        return $files;
    }

    /**
     * Get file size from FTP server
     */
    public function getFileSize(string $remoteFile): int
    {
        if (!$this->connection) {
            throw new \Exception('Not connected to FTP server');
        }

        $size = ftp_size($this->connection, $remoteFile);

        if ($size < 0) {
            throw new \Exception("Failed to get file size from FTP server: {$remoteFile}");
        }

        return $size;
    }

    /**
     * Get file modified time from FTP server
     */
    public function getModifiedTime(string $remoteFile): int
    {
        if (!$this->connection) {
            throw new \Exception('Not connected to FTP server');
        }

        $time = ftp_mdtm($this->connection, $remoteFile);

        if ($time < 0) {
            throw new \Exception("Failed to get modified time from FTP server: {$remoteFile}");
        }

        return $time;
    }

    /**
     * Destructor to ensure connection is closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
