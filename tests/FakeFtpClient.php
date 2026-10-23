<?php

namespace TearoomOne\FtpBackup\Tests;

use TearoomOne\FtpBackup\FtpClientInterface;

/**
 * In-memory FTP client that records what the plugin does on the "server"
 */
class FakeFtpClient implements FtpClientInterface
{
    /** @var array<string, array{size: int, mtime: int}> remote path => file info */
    public array $files = [];
    public array $uploaded = [];
    public array $deleted = [];
    public bool $connected = false;
    /** Number of connections, to see whether a cached listing was used */
    public int $connects = 0;
    public ?\Throwable $connectError = null;
    public ?\Throwable $uploadError = null;

    public function addFile(string $path, int $mtime = 0, int $size = 3): void
    {
        $this->files[$path] = ['size' => $size, 'mtime' => $mtime ?: time()];
    }

    public function connect(): void
    {
        if ($this->connectError) {
            throw $this->connectError;
        }
        $this->connected = true;
        $this->connects++;
    }

    public function upload(string $localFile, string $remoteFile, ?callable $onProgress = null): bool
    {
        if ($this->uploadError) {
            throw $this->uploadError;
        }
        $size = filesize($localFile);
        if ($onProgress) {
            $onProgress($size, $size);
        }
        $this->addFile($remoteFile, time(), $size);
        $this->files[$remoteFile]['content'] = file_get_contents($localFile);
        $this->uploaded[] = $remoteFile;
        return true;
    }

    public function createRemoteDirectory(string $directory): void
    {
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function delete(string $remoteFile): bool
    {
        unset($this->files[$remoteFile]);
        $this->deleted[] = $remoteFile;
        return true;
    }

    public function listDirectory(string $directory): array
    {
        $directory = rtrim($directory, '/') ?: '/';
        $names = [];
        foreach (array_keys($this->files) as $path) {
            if (dirname($path) === $directory) {
                $names[] = basename($path);
            }
        }
        return $names;
    }

    /** Remote paths that were downloaded */
    public array $downloaded = [];

    public function download(string $remoteFile, string $localFile): void
    {
        if (!isset($this->files[$remoteFile])) {
            throw new \Exception("Not found: {$remoteFile}");
        }
        file_put_contents($localFile, $this->files[$remoteFile]['content'] ?? str_repeat('x', $this->files[$remoteFile]['size']));
        $this->downloaded[] = $remoteFile;
    }

    public function getFileSize(string $remoteFile): int
    {
        return $this->files[$remoteFile]['size'];
    }

    public function getModifiedTime(string $remoteFile): int
    {
        return $this->files[$remoteFile]['mtime'];
    }

    /**
     * Basenames of files left in a remote directory, sorted
     */
    public function remaining(string $directory): array
    {
        $names = $this->listDirectory($directory);
        sort($names);
        return $names;
    }
}
