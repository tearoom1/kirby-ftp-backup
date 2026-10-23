<?php

namespace TearoomOne\FtpBackup;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Remote;
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
            'health' => $this->backupHealth(),
            'data' => $this->listBackupFiles()
        ];
    }

    /**
     * List all zip files in the backup directory, newest first
     */
    public function listBackupFiles(): array
    {
        $backups = [];
        $files = Dir::read($this->backupDir);
        $deleteNext = $this->deletedByNextRun($files, $this->getSettings());

        foreach ($files as $file) {
            if (F::extension($file) === 'zip') {
                $path = $this->backupDir . '/' . $file;
                $backups[] = [
                    'filename' => $file,
                    'size' => F::size($path),
                    'modified' => $this->backupTime($file, fn () => F::modified($path)),
                    'protected' => in_array($file, $this->protectedBackups(), true),
                    'deleteNext' => in_array($file, $deleteNext, true),
                    'downloadUrl' => kirby()->url('index') . '/ftp-backup/download/' . rawurlencode($file)
                ];
            }
        }

        usort($backups, fn ($a, $b) => [$b['modified'], $b['filename']] <=> [$a['modified'], $a['filename']]);

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

        $result = $this->runBackup($uploadToFtp, $jobId ?: null);

        // A start rejected because another backup runs says nothing about that backup
        if (empty($result['concurrent'])) {
            $this->finishRun($result);
        }
        unset($result['concurrent']);

        return $result;
    }

    private function runBackup(bool $uploadToFtp, ?string $jobId): array
    {
        $lock = null;
        $ftpClient = null;
        $filepath = null;
        $archiveComplete = false;

        try {
            $lock = $this->acquireLock();
            if ($lock === null) {
                // The job files (if any) belong to the run that holds the lock
                $jobId = null;
                return ['status' => 'error', 'message' => 'Another backup is already running', 'concurrent' => true];
            }

            $this->removeStaleJobFiles();

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

    // -------------------------------------------------------------------------
    // Run status and notifications
    // -------------------------------------------------------------------------

    /**
     * Remove job files that crashed or older runs left behind
     */
    private function removeStaleJobFiles(): void
    {
        $limit = time() - 86400;
        foreach (Dir::read($this->backupDir) as $file) {
            $path = $this->backupDir . '/' . $file;
            if (preg_match('/^\.(job|cancel|progress)-/', $file) && (int)@filemtime($path) < $limit) {
                @unlink($path);
            }
        }
    }

    /**
     * Status of the most recent run, or null if none was recorded yet
     */
    public function lastRun(): ?array
    {
        $path = $this->backupDir . '/.last-run.json';
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        return is_array($data) ? $data : null;
    }

    /**
     * Whether the newest local backup is older than `maxBackupAge` hours (0 turns the check off)
     */
    public function backupHealth(): array
    {
        $maxAge = (int)$this->option('maxBackupAge', 0);
        $latest = $this->listBackupFiles()[0]['modified'] ?? null;

        return [
            'maxBackupAge' => $maxAge,
            'latestBackup' => $latest,
            'stale' => $maxAge > 0 && ($latest === null || $latest < time() - $maxAge * 3600),
            'lastRun' => $this->lastRun(),
        ];
    }

    /**
     * Record the run and send notifications
     */
    private function finishRun(array $result): void
    {
        $ftpResult = $result['data']['ftpResult'] ?? [];
        $status = $result['status'];
        $message = $result['message'] ?? '';
        if ($status === 'success' && !($ftpResult['uploaded'] ?? false) && !($ftpResult['disabled'] ?? true)) {
            // The backup exists locally, but the copy on the server is missing
            $status = 'warning';
            $message = $ftpResult['message'] ?? 'FTP upload failed';
        }

        $run = [
            'time' => time(),
            'status' => $status,
            'message' => $message,
            'filename' => $result['data']['filename'] ?? null,
            'uploaded' => (bool)($ftpResult['uploaded'] ?? false),
        ];
        @file_put_contents($this->backupDir . '/.last-run.json', json_encode($run));

        if ($status === 'error' || $status === 'warning' || ($status === 'success' && $this->option('notifyOnSuccess', false))) {
            $this->notify($run);
        }
    }

    /**
     * Send the run result by email and/or webhook; failures are only logged
     */
    protected function notify(array $run): void
    {
        $kirby = kirby();
        $site = $kirby->url('index');
        $title = (string)$kirby->site()->content()->get('title')->value() ?: $site;
        $subjects = [
            'success' => 'Backup succeeded',
            'warning' => 'Backup upload failed',
            'error' => 'Backup failed',
        ];

        $recipients = array_filter((array)$this->option('notifyEmail', []));
        if ($recipients) {
            try {
                $recipients = array_values($recipients);
                $kirby->email([
                    // Without a configured sender, the first recipient sends to itself
                    'from' => $this->option('notifyFrom') ?: $recipients[0],
                    'to' => $recipients,
                    'subject' => "[{$title}] {$subjects[$run['status']]}",
                    'body' => implode("\n", [
                        'Site: ' . $site,
                        'Time: ' . date('Y-m-d H:i:s', $run['time']),
                        'Status: ' . $run['status'],
                        'Backup: ' . ($run['filename'] ?? '-'),
                        '',
                        $run['message'],
                    ]),
                ]);
            } catch (\Throwable $e) {
                error_log('[kirby-ftp-backup] Could not send notification email: ' . $e->getMessage());
            }
        }

        $webhook = (string)$this->option('notifyWebhook', '');
        if ($webhook !== '') {
            try {
                $response = Remote::request($webhook, [
                    'method' => 'POST',
                    'headers' => ['Content-Type' => 'application/json'],
                    'data' => json_encode(['site' => $site, 'title' => $title] + $run),
                    'timeout' => 10,
                ]);
                if ($response->code() >= 400) {
                    throw new \Exception('HTTP ' . $response->code());
                }
            } catch (\Throwable $e) {
                error_log('[kirby-ftp-backup] Could not call notification webhook: ' . $e->getMessage());
            }
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
        $backups = [];
        foreach ($filenames as $filename) {
            $parsed = $this->parseBackupName($filename);
            if ($parsed === null) {
                continue;
            }
            $backups[] = ['filename' => $filename, ...$parsed];
        }

        usort($backups, fn ($a, $b) => [$b['timestamp'], $b['sequence']] <=> [$a['timestamp'], $a['sequence']]);

        return $backups;
    }

    /**
     * Creation time and sequence encoded in a backup filename of this plugin
     * (`<prefix>YYYY-MM-DD-HHMMSS[-N].zip`), or null for any other name
     *
     * @return array{timestamp: int, sequence: int}|null
     */
    private function parseBackupName(string $filename): ?array
    {
        $prefix = preg_quote((string)$this->option('filePrefix', 'backup-'), '/');
        $pattern = '/^' . $prefix . '(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})(\d{2})(?:-(\d+))?\.zip$/';
        if (!preg_match($pattern, $filename, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }

        return [
            'timestamp' => (int)mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]),
            'sequence' => (int)($m[7] ?? 1),
        ];
    }

    /**
     * When a backup was created: the time in its filename, so copying or touching
     * the file does not change it; the file time only for names of other formats
     */
    private function backupTime(string $filename, callable $fileTime): int
    {
        return $this->parseBackupName($filename)['timestamp'] ?? (int)$fileTime();
    }

    /**
     * Decide which of the given files the retention settings no longer keep
     */
    private function selectBackupsToDelete(array $filenames, array $settings): array
    {
        // Protected backups are neither deleted nor counted
        $backups = $this->ownBackups(array_diff($filenames, $this->protectedBackups()));

        if ($settings['retentionStrategy'] === 'tiered') {
            $keep = array_column($this->applyTieredRetentionStrategy($backups, $settings['tieredRetention']), 'filename');
            return array_values(array_diff(array_column($backups, 'filename'), $keep));
        }

        // Always keep at least the newest backup
        $retention = max(1, $settings['backupRetention']);
        return array_column(array_slice($backups, $retention), 'filename');
    }

    /**
     * Files the next run will delete: it adds one backup, then applies retention
     */
    private function deletedByNextRun(array $filenames, array $settings): array
    {
        $next = $this->option('filePrefix', 'backup-') . date('Y-m-d-His') . '-999999.zip';
        return array_values(array_diff($this->selectBackupsToDelete([...$filenames, $next], $settings), [$next]));
    }

    /**
     * Write the content folder into a new zip archive
     */
    private function createArchive(string $filepath, ?string $jobId): void
    {
        // Check the options before anything is written
        $additionalPaths = $this->additionalPaths();
        $password = (string)$this->option('archivePassword', '');
        if ($password !== '' && !\ZipArchive::isEncryptionMethodSupported(\ZipArchive::EM_AES_256)) {
            throw new \Exception('archivePassword is set, but this server\'s zip library cannot encrypt archives (AES-256)');
        }

        $zip = new \ZipArchive();
        if ($zip->open($filepath, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new \Exception("Cannot create zip file: {$filepath}");
        }

        try {
            $fileCount = $this->addDirToZip(kirby()->root('content'), $zip, '', $jobId);
            foreach ($additionalPaths as $name => $path) {
                $fileCount += is_dir($path)
                    ? $this->addDirToZip($path, $zip, $name, $jobId)
                    : $this->addFileToZip($zip, $path, $name);
            }
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

        $this->verifyArchive($filepath, $entryCount, $password);
    }

    /**
     * Resolve `additionalPaths` to real paths, keyed by their name in the archive
     * (`_additional/` plus the path relative to the project root)
     */
    private function additionalPaths(): array
    {
        // Resolve symlinks (e.g. a deploy `current` link, or /var -> /private/var on macOS)
        // the same way as the configured paths, or the prefix comparison below fails
        $base = dirname(kirby()->root('site'));
        $base = realpath($base) ?: $base;
        $content = realpath(kirby()->root('content'));
        $paths = [];

        foreach ((array)$this->option('additionalPaths', []) as $path) {
            $path = rtrim(str_replace('\\', '/', (string)$path), '/');
            if ($path === '') {
                continue;
            }

            $absolute = str_starts_with($path, '/') ? $path : $base . '/' . $path;
            $real = realpath($absolute);
            if ($real === false) {
                throw new \Exception("Additional path does not exist: {$path}");
            }
            if ($real === $content || str_starts_with($real, $content . '/')) {
                throw new \Exception("Additional path is already part of the content folder: {$path}");
            }

            $relative = str_starts_with($real, $base . '/') ? substr($real, strlen($base) + 1) : ltrim($real, '/');
            $paths['_additional/' . $relative] = $real;
        }

        return $paths;
    }

    /**
     * Add one file to the zip, encrypted if `archivePassword` is set
     *
     * @return int 1 (the number of files added)
     */
    private function addFileToZip(\ZipArchive $zip, string $path, string $name): int
    {
        if (!$zip->addFile($path, $name)) {
            throw new \Exception("Could not add file to zip: {$name}");
        }

        $password = (string)$this->option('archivePassword', '');
        if ($password !== '' && !$zip->setEncryptionName($name, \ZipArchive::EM_AES_256, $password)) {
            throw new \Exception("Could not encrypt file in zip: {$name}");
        }

        return 1;
    }

    /**
     * Re-open the written archive and check its structure and entry count
     */
    private function verifyArchive(string $filepath, int $expectedEntries, string $password = ''): void
    {
        $zip = new \ZipArchive();
        $status = $zip->open($filepath, \ZipArchive::CHECKCONS | \ZipArchive::RDONLY);
        if ($status !== true) {
            throw new \Exception("The written archive is damaged (zip error {$status}): {$filepath}");
        }

        $entries = $zip->numFiles;

        // Make sure the archive can actually be decrypted again
        if ($password !== '') {
            $zip->setPassword($password);
            for ($i = 0; $i < $entries; $i++) {
                if (!str_ends_with((string)$zip->getNameIndex($i), '/')) {
                    $readable = $zip->getFromIndex($i) !== false;
                    break;
                }
            }
            if (!($readable ?? true)) {
                $zip->close();
                throw new \Exception("The written archive cannot be decrypted with archivePassword: {$filepath}");
            }
        }
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

        try {
            $ftpClient->upload($localFile, $this->remotePath($settings['ftpDirectory'], $remoteFilename), $onProgress);
        } finally {
            // Even a failed upload may have left a partial file
            $this->invalidateServerListing();
        }

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

            $fileCount += $this->addFileToZip($zip, $filePath, $relativePath);
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

            if ($toDelete) {
                $this->invalidateServerListing();
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
     * Where backups go, for display (no credentials)
     */
    private function connectionInfo(array $settings): array
    {
        $protocol = (string)$settings['ftpProtocol'];
        return [
            'host' => $settings['ftpHost'],
            'port' => (int)($settings['ftpPort'] ?? ($protocol === 'sftp' ? 22 : 21)),
            'path' => $settings['ftpDirectory'],
            'protocol' => strtoupper($protocol)
        ];
    }

    /**
     * Check the FTP setup step by step without creating a backup:
     * settings, connection, writing (and removing) a test file, listing backups
     */
    public function testConnection(): array
    {
        $settings = $this->getSettings();
        $directory = $settings['ftpDirectory'];
        $steps = [];
        $ftpClient = null;
        $tmp = null;

        $result = function (bool $ok) use (&$steps, $settings): array {
            return ['status' => $ok ? 'success' : 'error', 'steps' => $steps, 'connection' => $this->connectionInfo($settings)];
        };
        $step = function (string $name, bool $ok, string $message, array $data = []) use (&$steps, $directory): void {
            $steps[] = ['step' => $name, 'ok' => $ok, 'message' => $message, 'directory' => $directory] + $data;
        };

        $error = $settings['ftpEnabled'] ? $this->ftpConfigurationError($settings) : 'FTP uploads are disabled (ftpEnabled)';
        if ($error !== '') {
            $step('settings', false, $error);
            return $result(false);
        }
        $step('settings', true, 'Settings are complete');

        try {
            try {
                $ftpClient = $this->createFtpClient($settings);
                $step('connect', true, 'Connected and logged in');
            } catch (\Throwable $e) {
                $step('connect', false, $e->getMessage());
                return $result(false);
            }

            try {
                $tmp = (string)tempnam(sys_get_temp_dir(), 'ftpbk');
                file_put_contents($tmp, 'kirby-ftp-backup connection test');
                $remote = $this->remotePath($directory, '.ftp-backup-test-' . bin2hex(random_bytes(4)) . '.txt');
                $ftpClient->upload($tmp, $remote);
                $ftpClient->delete($remote);
                $step('write', true, 'Wrote and removed a test file in ' . $directory);
            } catch (\Throwable $e) {
                $step('write', false, $e->getMessage());
                return $result(false);
            }

            try {
                $count = count($this->ownBackups($ftpClient->listDirectory($directory)));
                $step('list', true, "Found {$count} backups in {$directory}", ['count' => $count]);
            } catch (\Throwable $e) {
                $step('list', false, $e->getMessage());
                return $result(false);
            }
        } finally {
            $ftpClient?->disconnect();
            if ($tmp) {
                @unlink($tmp);
            }
        }

        return $result(true);
    }

    // -------------------------------------------------------------------------
    // Server listing (cached, so a Panel visit does not always connect)
    // -------------------------------------------------------------------------

    /**
     * Minutes a server listing stays valid; 0 turns the cache off
     */
    private function serverCacheMinutes(): int
    {
        return max(0, (int)$this->option('serverCacheMinutes', 15));
    }

    /**
     * One entry per target, so a changed configuration never shows another server's files
     */
    private function serverCacheKey(array $settings): string
    {
        return 'server-listing-' . md5(json_encode([
            $settings['ftpProtocol'], $settings['ftpHost'], $settings['ftpPort'],
            $settings['ftpUsername'], $settings['ftpDirectory'],
        ]));
    }

    private function serverCache(): ?\Kirby\Cache\Cache
    {
        return $this->serverCacheMinutes() > 0 ? kirby()->cache('tearoom1.kirby-ftp-backup') : null;
    }

    /**
     * The cached listing (files, checkedAt, expired) or null
     */
    private function cachedServerListing(array $settings): ?array
    {
        $entry = $this->serverCache()?->get($this->serverCacheKey($settings));
        return is_array($entry) && isset($entry['files'], $entry['checkedAt']) ? $entry : null;
    }

    private function isFreshListing(?array $entry): bool
    {
        return $entry !== null
            && $entry['checkedAt'] > $this->serverChangedAt()
            && $entry['checkedAt'] >= time() - $this->serverCacheMinutes() * 60;
    }

    /**
     * When this site last changed the server (upload, cleanup, delete)
     */
    private function serverChangedAt(): int
    {
        $path = $this->backupDir . '/.server-changed';
        clearstatcache(true, $path);
        return is_file($path) ? (int)filemtime($path) : 0;
    }

    /**
     * Mark cached listings as outdated after the server changed. A marker file in
     * the backup directory reaches the Panel's cache also from cron runs, whose
     * Kirby instance may use another cache location. The cached entry stays as a
     * fallback for when the server cannot be reached.
     */
    public function invalidateServerListing(): void
    {
        @touch($this->backupDir . '/.server-changed');
    }

    /**
     * Read the backup files on the server: filename, size and creation time
     */
    private function fetchServerListing(array $settings): array
    {
        $ftpClient = null;
        $directory = $settings['ftpDirectory'];

        try {
            $ftpClient = $this->createFtpClient($settings);
            $files = [];
            foreach ($ftpClient->listDirectory($directory) as $file) {
                if (F::extension($file) !== 'zip') {
                    continue;
                }
                $path = $this->remotePath($directory, $file);
                try {
                    $files[] = [
                        'filename' => $file,
                        'size' => $ftpClient->getFileSize($path),
                        // Only ask the server for the file time when the name has none
                        'modified' => $this->backupTime($file, fn () => $ftpClient->getModifiedTime($path)),
                    ];
                } catch (\Throwable $e) {
                    // Still list files we can't get details for
                    $files[] = ['filename' => $file, 'size' => null, 'modified' => $this->parseBackupName($file)['timestamp'] ?? 0];
                }
            }
            return $files;
        } finally {
            $ftpClient?->disconnect();
        }
    }

    /**
     * Response data for a listing; protection and the next cleanup are evaluated
     * now, so a cached listing still shows changes made since it was read
     */
    private function serverStatsData(array $settings, array $entry, bool $cached): array
    {
        $files = $entry['files'];
        $names = array_column($files, 'filename');
        $deleteNext = $settings['deleteFromFtp'] ? $this->deletedByNextRun($names, $settings) : [];
        $protected = $this->protectedBackups();

        $backupFiles = [];
        $totalSize = 0;
        $latestModified = 0;
        foreach ($files as $file) {
            $known = $file['size'] !== null;
            $backupFiles[] = [
                'filename' => $file['filename'],
                'size' => (int)$file['size'],
                'formattedSize' => $known ? BackupController::formatSize((int)$file['size']) : 'Unknown',
                'modified' => (int)$file['modified'],
                'formattedDate' => $file['modified'] ? date('Y-m-d H:i:s', (int)$file['modified']) : 'Unknown',
                'protected' => in_array($file['filename'], $protected, true),
                'deleteNext' => in_array($file['filename'], $deleteNext, true),
            ];
            $totalSize += (int)$file['size'];
            $latestModified = max($latestModified, (int)$file['modified']);
        }
        usort($backupFiles, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return [
            'connection' => $this->connectionInfo($settings),
            'files' => $backupFiles,
            'count' => count($backupFiles),
            'totalSize' => $totalSize,
            'formattedTotalSize' => BackupController::formatSize($totalSize),
            'latestModified' => $latestModified > 0 ? date('Y-m-d H:i:s', $latestModified) : 'None',
            'checkedAt' => (int)$entry['checkedAt'],
            'cached' => $cached,
        ];
    }

    /**
     * The cached listing if it is still valid, without connecting; null otherwise
     */
    public function cachedServerStats(): ?array
    {
        $settings = $this->getSettings();
        if (!$settings['ftpEnabled'] || !$this->isFtpConfigured()) {
            return null;
        }
        $entry = $this->cachedServerListing($settings);
        return $this->isFreshListing($entry) ? $this->serverStatsData($settings, $entry, true) : null;
    }

    /**
     * Backups on the server. A valid cached listing is returned without connecting;
     * `$fresh` always reads the server. If the server cannot be reached, the last
     * cached listing is returned with `stale` and the error.
     */
    public function getFtpServerStats(bool $fresh = false): array
    {
        $settings = $this->getSettings();

        if (!$settings['ftpEnabled']) {
            return [
                'status' => 'error',
                'message' => 'FTP is disabled'
            ];
        }

        $entry = $this->cachedServerListing($settings);
        if (!$fresh && $this->isFreshListing($entry)) {
            return ['status' => 'success', 'data' => $this->serverStatsData($settings, $entry, true)];
        }

        try {
            $listing = ['files' => $this->fetchServerListing($settings), 'checkedAt' => time()];
            $this->serverCache()?->set($this->serverCacheKey($settings), $listing);
            return ['status' => 'success', 'data' => $this->serverStatsData($settings, $listing, false)];
        } catch (\Throwable $e) {
            error_log('[kirby-ftp-backup] Error retrieving FTP server stats: ' . $e->getMessage());
            $message = 'Error retrieving FTP server stats: ' . $e->getMessage();
            if ($entry !== null) {
                // A failed check is a valid result: show what the server had last time
                return ['status' => 'success', 'data' => [
                    ...$this->serverStatsData($settings, $entry, true),
                    'stale' => true,
                    'error' => $message,
                ]];
            }
            return [
                'status' => 'error',
                'message' => $message,
                'connection' => $this->connectionInfo($settings)
            ];
        }
    }

    /**
     * Only plain zip filenames are accepted, which blocks path traversal
     */
    private function isValidBackupName(string $filename): bool
    {
        return $filename === basename($filename)
            && !str_starts_with($filename, '.')
            && F::extension($filename) === 'zip';
    }

    /**
     * @return string[] Filenames that retention must keep
     */
    private function protectedBackups(): array
    {
        $path = $this->backupDir . '/.protected.json';
        $list = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /**
     * Protect a backup from retention (locally and on the server), or remove the protection
     */
    public function protectBackup(string $filename, bool $protected): array
    {
        if (!$this->isValidBackupName($filename)) {
            return ['status' => 'error', 'message' => 'Invalid filename'];
        }

        $list = array_diff($this->protectedBackups(), [$filename]);
        if ($protected) {
            $list[] = $filename;
        }
        file_put_contents($this->backupDir . '/.protected.json', json_encode(array_values($list)));

        return [
            'status' => 'success',
            'message' => $protected ? 'Backup is protected from automatic deletion' : 'Protection removed'
        ];
    }

    /**
     * Delete a backup locally and, if requested, on the FTP server
     */
    public function deleteBackup(string $filename, bool $remote = false): array
    {
        if (!$this->isValidBackupName($filename)) {
            return ['status' => 'error', 'message' => 'Invalid filename'];
        }
        if (in_array($filename, $this->protectedBackups(), true)) {
            return ['status' => 'error', 'message' => 'This backup is protected. Remove the protection before deleting it.'];
        }

        $localPath = $this->backupDir . '/' . $filename;
        $deletedLocally = is_file($localPath) && F::remove($localPath);

        if ($remote) {
            $settings = $this->getSettings();
            $ftpClient = null;
            try {
                $ftpClient = $this->createFtpClient($settings);
                $ftpClient->delete($this->remotePath($settings['ftpDirectory'], $filename));
            } catch (\Throwable $e) {
                return [
                    'status' => 'error',
                    'message' => ($deletedLocally ? 'Deleted locally, but not on the FTP server: ' : 'Could not delete on the FTP server: ') . $e->getMessage()
                ];
            } finally {
                $ftpClient?->disconnect();
                $this->invalidateServerListing();
            }
        } elseif (!$deletedLocally) {
            return ['status' => 'error', 'message' => 'Backup file not found'];
        }

        return ['status' => 'success', 'message' => 'Backup deleted'];
    }

    /**
     * Download a backup file
     */
    public function downloadBackup(string $filename): Response
    {
        if (!$this->isValidBackupName($filename)) {
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
