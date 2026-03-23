<?php

namespace TearoomOne\FtpBackup;

interface FtpClientInterface
{
    public function connect();
    /**
     * $onProgress is called periodically with ($bytesSent, $totalBytes).
     * It may throw RuntimeException(499) to abort the upload.
     */
    public function upload(string $localFile, string $remoteFile, ?callable $onProgress = null);
    public function createRemoteDirectory(string $directory);
    public function disconnect();
    public function delete(string $remoteFile);
    public function listDirectory(string $directory);
    public function getFileSize(string $remoteFile);
    public function getModifiedTime(string $remoteFile);

}
