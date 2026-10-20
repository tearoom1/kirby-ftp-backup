<?php

namespace TearoomOne\FtpBackup;

/**
 * Thrown when the user cancels a running backup from the Panel
 */
class BackupCancelledException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Backup cancelled by user', 499);
    }
}
