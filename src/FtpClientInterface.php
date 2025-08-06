<?php

namespace TearoomOne\FtpBackup;

interface FtpClientInterface
{
    public function connect();
    public function upload(string $localFile, string $remoteFile);
    public function createRemoteDirectory(string $directory);
    public function disconnect();
    public function delete(string $remoteFile);
    public function listDirectory(string $directory);
    public function getFileSize(string $remoteFile);
    public function getModifiedTime(string $remoteFile);

}
