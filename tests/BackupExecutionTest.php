<?php

namespace TearoomOne\FtpBackup\Tests;

use PHPUnit\Framework\TestCase;
use TearoomOne\FtpBackup\BackupManager;

class BackupExecutionTest extends TestCase
{
    public function testSuccessfulFtpUploadReturnsSuccess(): void
    {
        $result = $this->managerWithResult([
            'status' => 'success',
            'data' => [
                'filename' => 'backup.zip',
                'ftpResult' => [
                    'uploaded' => true,
                    'disabled' => false,
                    'message' => 'File uploaded to FTP server',
                ],
            ],
        ])->executeBackupWithFormatting(true);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['exitCode']);
    }

    public function testDisabledFtpUploadReturnsSuccess(): void
    {
        $result = $this->managerWithResult([
            'status' => 'success',
            'data' => [
                'filename' => 'backup.zip',
                'ftpResult' => [
                    'uploaded' => false,
                    'disabled' => true,
                ],
            ],
        ])->executeBackupWithFormatting(true);

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['exitCode']);
    }

    public function testFailedFtpUploadReturnsError(): void
    {
        $result = $this->managerWithResult([
            'status' => 'success',
            'data' => [
                'filename' => 'backup.zip',
                'ftpResult' => [
                    'uploaded' => false,
                    'disabled' => false,
                    'message' => 'Connection timed out',
                ],
            ],
        ])->executeBackupWithFormatting(true);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('Connection timed out', $result['message']);
    }

    private function managerWithResult(array $result): BackupManager
    {
        return new class ($result) extends BackupManager {
            public function __construct(private array $result)
            {
            }

            public function createBackup(bool $uploadToFtp = true, ?string $jobId = null): array
            {
                return $this->result;
            }
        };
    }
}
