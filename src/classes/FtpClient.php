<?php

namespace MatroochkitaPlugins\FtpBackup;

/**
 * FTP client for handling FTP operations
 */
class FtpClient
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private bool $ssl;
    private bool $passive;
    private $connection;
    
    public function __construct(
        string $host,
        int $port = 21,
        string $username = '',
        string $password = '',
        bool $ssl = false,
        bool $passive = true
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->ssl = $ssl;
        $this->passive = $passive;
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
            $this->connection = ftp_ssl_connect($this->host, $this->port);
        } else {
            $this->connection = ftp_connect($this->host, $this->port);
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
    public function upload(string $localFile, string $remoteFile): bool
    {
        if (!$this->connection) {
            throw new \Exception('Not connected to FTP server');
        }
        
        // Make sure the remote directory exists
        $this->createRemoteDirectory(dirname($remoteFile));
        
        // Upload file
        if (!ftp_put($this->connection, $remoteFile, $localFile, FTP_BINARY)) {
            throw new \Exception("Failed to upload file to FTP server: {$remoteFile}");
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
            ftp_close($this->connection);
            $this->connection = null;
        }
    }
    
    /**
     * Destructor to ensure connection is closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
