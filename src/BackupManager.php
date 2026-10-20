<?php

namespace TearoomOne\FtpBackup;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Response;

/**
 * Manages backup creation, listing, and FTP operations
 */
class BackupManager
{
    private const PROTOCOLS = ['ftp', 'ftps', 'sftp'];

    private string $backupDir;

    // -------------------------------------------------------------------------
    // Job / cancellation / progress helpers
    // -------------------------------------------------------------------------

    private function sanitizeJobId(string $jobId): string
    {
        return substr(preg_replace('/[^a-f0-9\-]/', '', $jobId), 0, 64);
    }

    private function jobFilePath(string $jobId): string
    {
        return $this->backupDir . '/.job-' . $this->sanitizeJobId($jobId);
    }

    private function cancelFlagPath(string $jobId): string
    {
        return $this->backupDir . '/.cancel-' . $this->sanitizeJobId($jobId);
    }

    private function progressFilePath(string $jobId): string
    {
        return $this->backupDir . '/.progress-' . $this->sanitizeJobId($jobId);
    }

    /**
     * Throw if the user requested cancellation of this job (the flag is consumed)
     */
    private function throwIfCancelled(?string $jobId): void
    {
        if (!$jobId) {
            return;
        }

        $path = $this->cancelFlagPath($jobId);
        clearstatcache(true, $path);
        if (file_exists($path)) {
            @unlink($path);
            throw new BackupCancelledException();
        }
    }

    private function writeProgress(?string $jobId, string $phase, int $current, int $total, string $message = ''): void
    {
        if (!$jobId || $this->sanitizeJobId($jobId) === '') {
            return;
        }

        $data = json_encode([
            'phase'   => $phase,
            'current' => $current,
            'total'   => $total,
            'message' => $message,
        ]);
        // Write to a temp file then rename so readers never see a partial file
        $path = $this->progressFilePath($jobId);
        $tmp  = $path . '.tmp';
        @file_put_contents($tmp, $data);
        @rename($tmp, $path);
    }

    public function getProgress(string $jobId): array
    {
        $unknown = ['phase' => 'unknown', 'current' => 0, 'total' => 0, 'message' => ''];
        $path = $this->progressFilePath($jobId);

        if ($this->sanitizeJobId($jobId) === '' || !file_exists($path)) {
            return $unknown;
        }

        $data = json_decode((string)file_get_contents($path), true);
        return is_array($data) ? $data : $unknown;
    }

    public function cancelBackup(string $jobId): array
    {
        if (!$this->isKnownJob($jobId)) {
            return ['status' => 'error', 'message' => 'Unknown backup job'];
        }

        file_put_contents($this->cancelFlagPath($jobId), '1');
        return ['status' => 'success', 'message' => 'Cancel signal sent'];
    }

    private function markJobActive(string $jobId): void
    {
        file_put_contents($this->jobFilePath($jobId), (string)time());
    }

    private function isKnownJob(string $jobId): bool
    {
        return $this->sanitizeJobId($jobId) !== '' && file_exists($this->jobFilePath($jobId));
    }

    private function cleanupJobFiles(string $jobId): void
    {
        @unlink($this->cancelFlagPath($jobId));
        @unlink($this->progressFilePath($jobId));
        @unlink($this->jobFilePath($jobId));
    }

    /**
     * Take the lock that prevents two backups from running at the same time
     *
     * @return resource|null The lock handle, or null if another backup holds the lock
     */
    private function acquireLock()
    {
        $handle = @fopen($this->backupDir . '/.lock', 'c');
        if ($handle === false) {
            throw new \Exception('Could not open lock file in backup directory: ' . $this->backupDir);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /**
     * @param resource|null $handle
     */
    private function releaseLock($handle): void
    {
        if ($handle) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $kirby = App::instance();

        $dir = $this->option('backupDirectory', $kirby->root('content') . '/.backups');
        // calculate backup directory from kirby root if not absolute
        if (strpos($dir, '/') !== 0) {
            $dir = dirname($kirby->root('site')) . '/' . $dir;
        }
        $this->backupDir = rtrim($dir, '/');

        // Ensure backup directory exists
        if (!is_dir($this->backupDir)) {
            try {
                Dir::make($this->backupDir);
            } catch (\Throwable $e) {
                error_log('[kirby-ftp-backup] Failed to create backup directory "' . $this->backupDir . '": ' . $e->getMessage());
                throw new \Exception('Backup directory could not be created: ' . $this->backupDir, 0, $e);
            }
        }
    }

    private function option(string $key, mixed $default = null): mixed
    {
        return option('tearoom1.kirby-ftp-backup.' . $key, $default);
    }

    /**
     * Get FTP settings
     */
    public function getSettings(): array
    {
        return [
            // Plugin control
            'enabled' => $this->option('enabled', true),
            'ftpEnabled' => (bool)$this->option('ftpEnabled', true),
            // FTP settings
            'ftpProtocol' => $this->option('ftpProtocol', 'ftp'),
            'ftpHost' => (string)$this->option('ftpHost', ''),
            'ftpPort' => $this->option('ftpPort'),
            'ftpUsername' => (string)$this->option('ftpUsername', ''),
            'ftpPassword' => (string)$this->option('ftpPassword', ''),
            'ftpDirectory' => (string)$this->option('ftpDirectory', '/'),
            'ftpPassive' => (bool)$this->option('ftpPassive', true),
            'ftpPrivateKey' => $this->option('ftpPrivateKey'),
            'ftpPassphrase' => $this->option('ftpPassphrase'),
            // General settings
            'backupDirectory' => $this->backupDir,
            'backupRetention' => (int)$this->option('backupRetention', 10),
            'deleteFromFtp' => (bool)$this->option('deleteFromFtp', true),
            'retentionStrategy' => $this->option('retentionStrategy', 'simple'),
            'tieredRetention' => [
                'daily' => $this->option('tieredRetention.daily', 10),
                'weekly' => $this->option('tieredRetention.weekly', 4),
                'monthly' => $this->option('tieredRetention.monthly', 6)
            ],
            // File filtering
            'includePatterns' => $this->option('includePatterns', []),
            'excludePatterns' => $this->option('excludePatterns', []),
            'excludePaths' => $this->option('excludePaths', []),
            'excludeContentWatch' => $this->option('excludeContentWatch', false),
            'excludeDrafts' => $this->option('excludeDrafts', false),
            // Connection behaviour
            'ftpTimeout' => (int)$this->option('ftpTimeout', 30),
            'ftpMaxRetries' => (int)$this->option('ftpMaxRetries', 3),
            'ftpRetryDelay' => (int)$this->option('ftpRetryDelay', 5),
            'ftpUsePasvAddress' => (bool)$this->option('ftpUsePasvAddress', true),
            'ftpKeepAlive' => (int)$this->option('ftpKeepAlive', 0)
        ];
    }

    /**
     * Describe what is missing from the FTP settings (empty string if they are complete)
     */
    public function ftpConfigurationError(?array $settings = null): string
    {
        $settings ??= $this->getSettings();
        $protocol = $settings['ftpProtocol'];

        if (!in_array($protocol, self::PROTOCOLS, true)) {
            return "Invalid FTP settings: unknown ftpProtocol '{$protocol}' (use 'ftp', 'ftps' or 'sftp')";
        }
        if ($settings['ftpHost'] === '') {
            return 'Invalid FTP settings: ftpHost is not set';
        }
        // The password may legitimately be empty (key login or anonymous FTP)
        if ($settings['ftpUsername'] === '') {
            return 'Invalid FTP settings: ftpUsername is not set';
        }

        return '';
    }

    /**
     * Whether backups will be uploaded: FTP is enabled and fully configured
     */
    public function isFtpConfigured(): bool
    {
        $settings = $this->getSettings();
        return $settings['ftpEnabled'] && $this->ftpConfigurationError($settings) === '';
    }

    /**
     * List available backups
     */
    public function listBackups(): array
    {
        return [
            'status' => 'success',
            'stats' => BackupController::getStats($this),
            'data' => $this->listBackupFiles()
        ];
    }

    /**
     * List all zip files in the backup directory, newest first
     */
    public function listBackupFiles(): array
    {
        $backups = [];

        foreach (Dir::read($this->backupDir) as $file) {
            if (F::extension($file) === 'zip') {
                $path = $this->backupDir . '/' . $file;
                $backups[] = [
                    'filename' => $file,
                    'size' => F::size($path),
                    'modified' => F::modified($path),
                    'downloadUrl' => kirby()->url('index') . '/ftp-backup/download/' . rawurlencode($file)
                ];
            }
        }

        usort($backups, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $backups;
    }

    /**
     * Create a new backup
     */
    public function createBackup(bool $uploadToFtp = true, ?string $jobId = null): array
    {
        if ($jobId !== null && $jobId !== '' && $this->sanitizeJobId($jobId) === '') {
            return ['status' => 'error', 'message' => 'Invalid backup job id'];
        }
        $jobId = $jobId ?: null;

        $lock = null;
        $ftpClient = null;
        $filepath = null;
        $archiveComplete = false;

        try {
            $lock = $this->acquireLock();
            if ($lock === null) {
                // The job files (if any) belong to the run that holds the lock
                $jobId = null;
                return ['status' => 'error', 'message' => 'Another backup is already running'];
            }

            // Large sites take a while; finish the run even if the request is aborted
            @set_time_limit(0);
            ignore_user_abort(true);

            if ($jobId) {
                $this->markJobActive($jobId);
            }
            $this->writeProgress($jobId, 'starting', 0, 0, 'Preparing backup…');

            $filename = $this->backupFilename(time());
            $filepath = $this->backupDir . '/' . $filename;

            $this->writeProgress($jobId, 'zip', 0, 0, 'Compressing archive…');
            $this->createArchive($filepath, $jobId);
            $archiveComplete = true;

            // close() is blocking, so this is the earliest point to react
            // to a cancel pressed while the archive was being written
            $this->throwIfCancelled($jobId);

            $settings = $this->getSettings();
            $ftpEnabled = $settings['ftpEnabled'];
            $ftpResult = ['uploaded' => false, 'disabled' => !$ftpEnabled];

            if ($uploadToFtp && $ftpEnabled) {
                $this->writeProgress($jobId, 'upload', 0, 1, 'Uploading to FTP server…');
                try {
                    $ftpClient = $this->createFtpClient($settings);
                    $ftpResult = $this->uploadToFtp($ftpClient, $settings, $filepath, $filename, $jobId);
                } catch (BackupCancelledException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $ftpResult = ['uploaded' => false, 'message' => 'FTP upload failed: ' . $e->getMessage()];
                }
                $ftpResult['disabled'] = false;

                $this->throwIfCancelled($jobId);
            }

            $this->writeProgress($jobId, 'cleanup', 0, 1, 'Cleaning up old backups…');
            // Only rotate the server when this run actually added a backup there
            $this->cleanupOldBackups($ftpResult['uploaded'] ? $ftpClient : null, $settings, $jobId);

            $message = 'Backup created successfully';
            if (!$ftpEnabled) {
                $message .= ' (FTP upload disabled)';
            } elseif ($ftpResult['uploaded']) {
                $message .= ' and uploaded to FTP server';
            }

            $this->writeProgress($jobId, 'done', 1, 1, $message);

            return [
                'status' => 'success',
                'message' => $message,
                'data' => [
                    'filename' => $filename,
                    'size' => F::size($filepath),
                    'ftpResult' => $ftpResult
                ]
            ];
        } catch (BackupCancelledException $e) {
            if ($filepath && file_exists($filepath)) {
                @unlink($filepath);
            }
            $this->writeProgress($jobId, 'cancelled', 0, 0, 'Backup was cancelled');
            return ['status' => 'cancelled', 'message' => 'Backup was cancelled'];
        } catch (\Throwable $e) {
            error_log('[kirby-ftp-backup] Backup failed: ' . $e->getMessage());
            // Never leave a half-written archive that looks like a valid backup
            if (!$archiveComplete && $filepath && file_exists($filepath)) {
                @unlink($filepath);
            }
            $this->writeProgress($jobId, 'error', 0, 0, $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Backup failed: ' . $e->getMessage()
            ];
        } finally {
            $ftpClient?->disconnect();
            if ($jobId) {
                $this->cleanupJobFiles($jobId);
            }
            $this->releaseLock($lock);
        }
    }

    /**
     * Filename for a backup created at the given time; a numeric suffix
     * keeps backups from the same second apart
     */
    private function backupFilename(int $time): string
    {
        $base = $this->option('filePrefix', 'backup-') . date('Y-m-d-His', $time);
        $filename = $base . '.zip';

        for ($sequence = 2; file_exists($this->backupDir . '/' . $filename); $sequence++) {
            $filename = "{$base}-{$sequence}.zip";
        }

        return $filename;
    }

    /**
     * Pick the backups this plugin created from a list of filenames, newest first.
     * Anything else (other prefixes, manual uploads, unknown names) is ignored,
     * so retention never deletes files it does not own.
     */
    private function ownBackups(array $filenames): array
    {
        $prefix = preg_quote((string)$this->option('filePrefix', 'backup-'), '/');
        $pattern = '/^' . $prefix . '(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})(\d{2})(?:-(\d+))?\.zip$/';

        $backups = [];
        foreach ($filenames as $filename) {
            if (!preg_match($pattern, $filename, $m)) {
                continue;
            }
            $timestamp = mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
            $backups[] = [
                'filename' => $filename,
                'timestamp' => $timestamp,
                'sequence' => (int)($m[7] ?? 1),
            ];
        }

        usort($backups, fn ($a, $b) => [$b['timestamp'], $b['sequence']] <=> [$a['timestamp'], $a['sequence']]);

        return $backups;
    }

    /**
     * Decide which of the given files the retention settings no longer keep
     */
    private function selectBackupsToDelete(array $filenames, array $settings): array
    {
        $backups = $this->ownBackups($filenames);

        if ($settings['retentionStrategy'] === 'tiered') {
            $keep = array_column($this->applyTieredRetentionStrategy($backups, $settings['tieredRetention']), 'filename');
            return array_values(array_diff(array_column($backups, 'filename'), $keep));
        }

        // Always keep at least the newest backup
        $retention = max(1, $settings['backupRetention']);
        return array_column(array_slice($backups, $retention), 'filename');
    }

    /**
     * Write the content folder into a new zip archive
     */
    private function createArchive(string $filepath, ?string $jobId): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($filepath, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \Exception("Cannot create zip file: {$filepath}");
        }

        try {
            $fileCount = $this->addDirToZip(kirby()->root('content'), $zip, '', $jobId);
            if ($fileCount === 0) {
                throw new \Exception('The archive contains no files: no content files matched the backup filters');
            }
            $entryCount = $zip->numFiles;
        } catch (\Throwable $e) {
            // Drop the queued entries so nothing is written on close
            $zip->unchangeAll();
            @$zip->close();
            throw $e;
        }

        // Files are only read here, so unreadable files surface now
        error_clear_last();
        if (@$zip->close() !== true) {
            $detail = error_get_last()['message'] ?? 'unknown error';
            throw new \Exception('Could not write zip file: ' . $detail);
        }

        $this->verifyArchive($filepath, $entryCount);
    }

    /**
     * Re-open the written archive and check its structure and entry count
     */
    private function verifyArchive(string $filepath, int $expectedEntries): void
    {
        $zip = new \ZipArchive();
        $status = $zip->open($filepath, \ZipArchive::CHECKCONS | \ZipArchive::RDONLY);
        if ($status !== true) {
            throw new \Exception("The written archive is damaged (zip error {$status}): {$filepath}");
        }

        $entries = $zip->numFiles;
        $zip->close();

        if ($entries !== $expectedEntries) {
            throw new \Exception("The written archive has {$entries} entries instead of {$expectedEntries}: {$filepath}");
        }
    }

    /**
     * Upload a backup file to the FTP server
     */
    private function uploadToFtp(FtpClientInterface $ftpClient, array $settings, string $localFile, string $remoteFilename, ?string $jobId = null): array
    {
        // Clients report every few kilobytes; check and report at most twice a second
        $lastReport = 0.0;
        $onProgress = $jobId ? function (int $sent, int $total) use ($jobId, &$lastReport) {
            $now = microtime(true);
            if ($now - $lastReport < 0.5 && $sent < $total) {
                return;
            }
            $lastReport = $now;
            $this->throwIfCancelled($jobId);
            if ($sent > 0) {
                $this->writeProgress($jobId, 'upload', $sent, $total, 'Uploading to FTP server…');
            }
        } : null;

        $ftpClient->upload($localFile, $this->remotePath($settings['ftpDirectory'], $remoteFilename), $onProgress);

        return [
            'uploaded' => true,
            'message' => 'File uploaded to FTP server'
        ];
    }

    /**
     * Join the configured remote directory and a filename; a relative
     * directory stays relative to the login directory
     */
    private function remotePath(string $directory, string $filename): string
    {
        return rtrim($directory, '/') . '/' . $filename;
    }

    /**
     * Create and connect the client for the configured protocol
     */
    protected function createFtpClient(array $settings): FtpClientInterface
    {
        $error = $this->ftpConfigurationError($settings);
        if ($error !== '') {
            throw new \Exception($error);
        }

        if ($settings['ftpProtocol'] === 'sftp') {
            $client = new SftpClient(
                $settings['ftpHost'],
                (int)($settings['ftpPort'] ?? 22),
                $settings['ftpUsername'],
                $settings['ftpPassword'],
                $settings['ftpPrivateKey'] ?: null,
                $settings['ftpPassphrase'] ?: null,
                $settings['ftpTimeout'],
                $settings['ftpKeepAlive']
            );
        } else {
            $client = new FtpClient(
                $settings['ftpHost'],
                (int)($settings['ftpPort'] ?? 21),
                $settings['ftpUsername'],
                $settings['ftpPassword'],
                $settings['ftpProtocol'] === 'ftps',
                $settings['ftpPassive'],
                $settings['ftpTimeout'],
                $settings['ftpMaxRetries'],
                $settings['ftpRetryDelay'],
                $settings['ftpUsePasvAddress']
            );
        }

        $client->connect();

        return $client;
    }

    /**
     * Check if a file path should be included based on regex patterns
     */
    private function shouldIncludeFile(string $relativePath, bool $isDir = false): bool
    {
        $includePatterns = $this->option('includePatterns', []);
        $excludePatterns = $this->option('excludePatterns', []);
        $excludePaths = $this->option('excludePaths', []);

        // Normalize path separators for consistent matching
        $normalizedPath = ltrim(str_replace('\\', '/', $relativePath), '/');

        if ($this->option('excludeContentWatch', false) && basename($normalizedPath) === '.content-watch.json') {
            return false;
        }

        if ($this->option('excludeDrafts', false) && preg_match('#(^|/)_drafts(/|$)#', $normalizedPath) === 1) {
            return false;
        }

        foreach ((array)$excludePaths as $path) {
            $path = trim(str_replace('\\', '/', (string)$path), '/');
            if ($path === '') {
                continue;
            }

            if ($normalizedPath === $path || str_starts_with($normalizedPath, $path . '/') || fnmatch($path, $normalizedPath)) {
                return false;
            }
        }

        // Patterns get # delimiters (to avoid conflicts with / in paths) and are case-insensitive
        foreach ((array)$excludePatterns as $pattern) {
            if (@preg_match('#' . $pattern . '#i', $normalizedPath)) {
                return false;
            }
        }

        // Include patterns select files; folders are always walked so
        // matching files inside them are found
        if (!empty($includePatterns) && !$isDir) {
            foreach ((array)$includePatterns as $pattern) {
                if (@preg_match('#' . $pattern . '#i', $normalizedPath)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    /**
     * Whether a directory is the backup directory (or the legacy default
     * `.backups` folder), which must never end up in an archive
     */
    private function isBackupDirectory(string $path, string $relativePath): bool
    {
        return $relativePath === '.backups' || realpath($path) === realpath($this->backupDir);
    }

    /**
     * Add a directory's contents to the zip
     *
     * @return int Number of files added
     */
    private function addDirToZip(string $dir, \ZipArchive $zip, string $zipDir = '', ?string $jobId = null): int
    {
        $fileCount = 0;

        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isDot()) {
                continue;
            }

            $filePath = $file->getPathname();
            $relativePath = ltrim($zipDir . '/' . $file->getFilename(), '/');

            if ($file->isDir() && $this->isBackupDirectory($filePath, $relativePath)) {
                continue;
            }

            if (!$this->shouldIncludeFile($relativePath, $file->isDir())) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($relativePath);
                $fileCount += $this->addDirToZip($filePath, $zip, $relativePath, $jobId);
                continue;
            }

            // Check for cancellation before each file so large files
            // do not block the cancel signal until after they are queued.
            $this->throwIfCancelled($jobId);

            if (!$zip->addFile($filePath, $relativePath)) {
                throw new \Exception("Could not add file to zip: {$relativePath}");
            }
            $fileCount++;
        }

        return $fileCount;
    }

    /**
     * Apply the retention settings locally and, if a client is given, on the FTP server
     */
    public function cleanupOldBackups(?FtpClientInterface $ftpClient, array $settings, ?string $jobId = null): void
    {
        foreach ($this->selectBackupsToDelete(Dir::read($this->backupDir), $settings) as $file) {
            $this->throwIfCancelled($jobId);
            F::remove($this->backupDir . '/' . $file);
        }

        $this->throwIfCancelled($jobId);

        if ($ftpClient && $settings['deleteFromFtp']) {
            $this->cleanupFtpBackups($ftpClient, $settings, $jobId);
        }
    }

    /**
     * Apply tiered retention strategy - simple and obvious approach
     *
     * Strategy:
     * 1. Keep X daily backups (one per day)
     * 2. Keep 1 backup per 7-day period for X weeks (max 7 days between)
     * 3. Keep 1 backup per 30-day period for X months (max 30 days between)
     * 4. Always keep the oldest backup as anchor
     */
    private function applyTieredRetentionStrategy(array $backups, array $settings): array
    {
        if (empty($backups)) {
            return [];
        }

        // Sort newest first
        usort($backups, fn ($a, $b) => $b['timestamp'] - $a['timestamp']);

        $now = time();
        $dailyDays = max(1, intval($settings['daily']));
        $weeklyWeeks = max(1, intval($settings['weekly']));
        $monthlyMonths = max(1, intval($settings['monthly']));

        $keepBackups = [];

        // 1. DAILY PERIOD: Keep all backups within X days
        foreach ($backups as $i => $backup) {
            $ageDays = ($now - $backup['timestamp']) / 86400;
            if ($ageDays <= $dailyDays) {
                $keepBackups[] = $this->markBackup($backup, $i === 0 ? 'newest' : 'daily');
            }
        }

        // 2. WEEKLY PERIOD: Keep oldest backup in each 7-day period
        for ($week = 0; $week < $weeklyWeeks; $week++) {
            $oldest = $this->oldestBetween($backups, $now, $dailyDays + ($week * 7), $dailyDays + (($week + 1) * 7));
            if ($oldest) {
                $keepBackups[] = $this->markBackup($oldest, 'weekly');
            }
        }

        // 3. MONTHLY PERIOD: Keep oldest backup in each 30-day period
        $monthlyStart = $dailyDays + ($weeklyWeeks * 7);
        for ($month = 0; $month < $monthlyMonths; $month++) {
            $oldest = $this->oldestBetween($backups, $now, $monthlyStart + ($month * 30), $monthlyStart + (($month + 1) * 30));
            if ($oldest) {
                $keepBackups[] = $this->markBackup($oldest, 'monthly');
            }
        }

        // 4. ALWAYS KEEP OLDEST as anchor
        $this->ensureOldestBackup($backups, $keepBackups);

        return $keepBackups;
    }

    /**
     * The oldest backup whose age in days lies in (startDays, endDays]
     */
    private function oldestBetween(array $backups, int $now, int $startDays, int $endDays): ?array
    {
        $oldest = null;
        foreach ($backups as $backup) {
            $ageDays = ($now - $backup['timestamp']) / 86400;
            if ($ageDays > $startDays && $ageDays <= $endDays && ($oldest === null || $backup['timestamp'] < $oldest['timestamp'])) {
                $oldest = $backup;
            }
        }
        return $oldest;
    }

    private function markBackup(array $backup, string $type): array
    {
        $backup['retention'] = $type;
        return $backup;
    }

    private function ensureOldestBackup(array $allBackups, array &$keepBackups): void
    {
        if (empty($allBackups)) {
            return;
        }

        // Check if we have any monthly backups already kept
        $monthlyBackups = array_filter(
            $keepBackups,
            fn ($backup) => ($backup['retention'] ?? null) === 'monthly'
        );

        // If we have monthly backups, the oldest monthly backup is our anchor
        if (!empty($monthlyBackups)) {
            usort($monthlyBackups, fn ($a, $b) => $a['timestamp'] - $b['timestamp']);
            $oldestMonthly = $monthlyBackups[0];

            foreach ($keepBackups as &$backup) {
                if ($backup['filename'] === $oldestMonthly['filename']) {
                    $backup['retention'] = 'oldest-anchor';
                    break;
                }
            }
            return;
        }

        // If no monthly backups, keep the very oldest as anchor (fallback for new setups)
        $oldest = end($allBackups);
        $oldest['retention'] = 'oldest-anchor';

        if (!in_array($oldest['filename'], array_column($keepBackups, 'filename'), true)) {
            $keepBackups[] = $oldest;
        }
    }

    /**
     * Remove backups from the FTP server that the retention settings no longer keep
     */
    public function cleanupFtpBackups(FtpClientInterface $ftpClient, array $settings, ?string $jobId = null): array
    {
        try {
            $directory = $settings['ftpDirectory'];
            $this->throwIfCancelled($jobId);
            $toDelete = $this->selectBackupsToDelete($ftpClient->listDirectory($directory), $settings);

            $failed = [];
            foreach ($toDelete as $file) {
                $this->throwIfCancelled($jobId);
                try {
                    $ftpClient->delete($this->remotePath($directory, $file));
                } catch (\Throwable $e) {
                    $failed[] = $e->getMessage();
                }
            }

            if ($failed) {
                error_log('[kirby-ftp-backup] Could not delete old FTP backups: ' . implode('; ', $failed));
            }

            $deleted = count($toDelete) - count($failed);
            return [
                'success' => empty($failed),
                'message' => "Deleted {$deleted} old backups from FTP server"
            ];
        } catch (BackupCancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('[kirby-ftp-backup] Error cleaning up FTP backups: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error cleaning up FTP backups: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get stats and file list from the FTP server
     */
    public function getFtpServerStats(): array
    {
        $settings = $this->getSettings();

        if (!$settings['ftpEnabled']) {
            return [
                'status' => 'error',
                'message' => 'FTP is disabled'
            ];
        }

        $ftpClient = null;
        $directory = $settings['ftpDirectory'];
        $protocol = $settings['ftpProtocol'];
        $connection = [
            'host' => $settings['ftpHost'],
            'port' => (int)($settings['ftpPort'] ?? ($protocol === 'sftp' ? 22 : 21)),
            'path' => $directory,
            'protocol' => strtoupper((string)$protocol)
        ];

        try {
            $ftpClient = $this->createFtpClient($settings);

            $backupFiles = [];
            $totalSize = 0;
            $latestModified = 0;

            foreach ($ftpClient->listDirectory($directory) as $file) {
                if (F::extension($file) !== 'zip') {
                    continue;
                }

                try {
                    $path = $this->remotePath($directory, $file);
                    $fileSize = $ftpClient->getFileSize($path);
                    $fileModified = $ftpClient->getModifiedTime($path);

                    $backupFiles[] = [
                        'filename' => $file,
                        'size' => $fileSize,
                        'formattedSize' => BackupController::formatSize($fileSize),
                        'modified' => $fileModified,
                        'formattedDate' => date('Y-m-d H:i:s', $fileModified)
                    ];

                    $totalSize += $fileSize;
                    $latestModified = max($latestModified, $fileModified);
                } catch (\Throwable $e) {
                    // Still list files we can't get details for
                    $backupFiles[] = [
                        'filename' => $file,
                        'size' => 0,
                        'formattedSize' => 'Unknown',
                        'modified' => 0,
                        'formattedDate' => 'Unknown'
                    ];
                }
            }

            usort($backupFiles, fn ($a, $b) => $b['modified'] <=> $a['modified']);

            return [
                'status' => 'success',
                'data' => [
                    'connection' => $connection,
                    'files' => $backupFiles,
                    'count' => count($backupFiles),
                    'totalSize' => $totalSize,
                    'formattedTotalSize' => BackupController::formatSize($totalSize),
                    'latestModified' => $latestModified > 0 ? date('Y-m-d H:i:s', $latestModified) : 'None'
                ]
            ];
        } catch (\Throwable $e) {
            error_log('[kirby-ftp-backup] Error retrieving FTP server stats: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Error retrieving FTP server stats: ' . $e->getMessage(),
                'connection' => $connection
            ];
        } finally {
            $ftpClient?->disconnect();
        }
    }

    /**
     * Download a backup file
     */
    public function downloadBackup(string $filename): Response
    {
        // Reject anything that isn't a plain filename to block path traversal
        if ($filename !== basename($filename) || F::extension($filename) !== 'zip') {
            return Response::json([
                'status' => 'error',
                'message' => 'Invalid filename'
            ], 400);
        }

        $filepath = $this->backupDir . '/' . $filename;

        if (!is_file($filepath)) {
            return Response::json([
                'status' => 'error',
                'message' => 'Backup file not found'
            ], 404);
        }

        return new FileDownloadResponse($filepath);
    }

    /**
     * Execute backup and format result message
     * Unified method for CLI, cron and URL execution
     *
     * @param bool $uploadToFtp Whether to upload to FTP server
     * @return array ['success' => bool, 'message' => string, 'exitCode' => int]
     */
    public function executeBackupWithFormatting(bool $uploadToFtp = true): array
    {
        try {
            $result = $this->createBackup($uploadToFtp);

            if ($result['status'] !== 'success') {
                return [
                    'success' => false,
                    'message' => 'Error creating backup: ' . ($result['message'] ?? 'Unknown error'),
                    'exitCode' => 1
                ];
            }

            $message = 'Backup created successfully: ' . ($result['data']['filename'] ?? 'unknown');
            $ftpResult = $result['data']['ftpResult'] ?? [];

            if ($ftpResult['uploaded'] ?? false) {
                $message .= "\n" . ($ftpResult['message'] ?? '');
            } elseif (!($ftpResult['disabled'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Backup created locally but FTP upload failed: ' . ($ftpResult['message'] ?? 'Unknown error'),
                    'exitCode' => 1
                ];
            }

            return [
                'success' => true,
                'message' => $message,
                'exitCode' => 0
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Fatal error: ' . $e->getMessage(),
                'exitCode' => 1
            ];
        }
    }
}
