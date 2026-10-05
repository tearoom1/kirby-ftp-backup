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
    private bool $usePasvAddress;
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
        int $retryDelay = 5,
        bool $usePasvAddress = true
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
        $this->usePasvAddress = $usePasvAddress;
    }

    /**
     * Human-readable endpoint for error messages, e.g. ftps://user@host:21
     */
    public function endpoint(): string
    {
        $user = $this->username !== '' ? $this->username . '@' : '';
        return ($this->ssl ? 'ftps' : 'ftp') . "://{$user}{$this->host}:{$this->port}";
    }

    /**
     * Build an exception message that names the endpoint and the underlying PHP warning
     */
    private function error(string $message): \Exception
    {
        $detail = error_get_last()['message'] ?? '';
        error_clear_last();

        $message .= ' [' . $this->endpoint() . ']';
        if ($detail !== '') {
            $message .= ': ' . preg_replace('/^ftp_\w+\(\): /', '', $detail);
        }

        // Control connection works but the data connection cannot be opened:
        // typically a wrong passive address on the server or a blocked passive port range.
        if ($this->passive && str_contains($detail, 'php_connect_nonb')) {
            $message .= ' (passive data connection failed; check the server\'s passive address/port range'
                . ($this->usePasvAddress ? " or set 'ftpUsePasvAddress' => false" : '') . ')';
        }

        return new \Exception($message);
    }

    /**
     * Connect to the FTP server
     */
    public function connect(): void
    {
        error_clear_last();

        if ($this->ssl) {
            if (!function_exists('ftp_ssl_connect')) {
                throw new \Exception('FTP SSL is not supported on this server');
            }
            $this->connection = @ftp_ssl_connect($this->host, $this->port, $this->timeout);
        } else {
            $this->connection = @ftp_connect($this->host, $this->port, $this->timeout);
        }

        if (!$this->connection) {
            throw $this->error('Failed to connect to FTP server');
        }

        if (!@ftp_login($this->connection, $this->username, $this->password)) {
            throw $this->error('Failed to login to FTP server');
        }

        if (!$this->usePasvAddress) {
            // Ignore the IP announced in the PASV reply and reuse the control connection host
            ftp_set_option($this->connection, FTP_USEPASVADDRESS, false);
        }

        if ($this->passive && !@ftp_pasv($this->connection, true)) {
            throw $this->error('Failed to enable passive mode');
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
                $result = @ftp_nb_put(
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
                    $result = @ftp_nb_continue($this->connection);
                }

                if ($result === FTP_FINISHED) {
                    return true;
                }

                throw $this->error("FTP transfer did not finish: {$remoteFile}");
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
                if (!@ftp_mkdir($this->connection, $path)) {
                    throw $this->error("Failed to create directory on FTP server: {$path}");
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

        if (!@ftp_delete($this->connection, $remoteFile)) {
            throw $this->error("Failed to delete file from FTP server: {$remoteFile}");
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
        $rawList = @ftp_nlist($this->connection, $directory);

        if ($rawList === false) {
            throw $this->error("Failed to list directory on FTP server: {$directory}");
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
            throw $this->error("Failed to get file size from FTP server: {$remoteFile}");
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
            throw $this->error("Failed to get modified time from FTP server: {$remoteFile}");
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
