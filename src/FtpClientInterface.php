<?php

namespace TearoomOne\FtpBackup;

interface FtpClientInterface
{
    public function connect(): void;

    /**
     * $onProgress is called periodically with ($bytesSent, $totalBytes).
     * It may throw BackupCancelledException to abort the upload.
     */
    public function upload(string $localFile, string $remoteFile, ?callable $onProgress = null): bool;

    public function createRemoteDirectory(string $directory): void;

    public function disconnect(): void;

    public function delete(string $remoteFile): bool;

    /**
     * Download a remote file into a local file (binary, streamed to disk)
     */
    public function download(string $remoteFile, string $localFile): void;

    /**
     * @return string[] Names (not paths) of the entries in the directory
     */
    public function listDirectory(string $directory): array;

    public function getFileSize(string $remoteFile): int;

    public function getModifiedTime(string $remoteFile): int;
}
