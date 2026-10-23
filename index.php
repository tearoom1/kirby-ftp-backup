<?php

// Exit early if plugin is disabled
if (!option('tearoom1.kirby-ftp-backup.enabled', true)) {
    return;
}

@include_once __DIR__ . '/vendor/autoload.php';

// Register autoloader
load([
    'TearoomOne\\FtpBackup\\BackupCancelledException' => 'src/BackupCancelledException.php',
    'TearoomOne\\FtpBackup\\BackupController' => 'src/BackupController.php',
    'TearoomOne\\FtpBackup\\BackupManager' => 'src/BackupManager.php',
    'TearoomOne\\FtpBackup\\FileDownloadResponse' => 'src/FileDownloadResponse.php',
    'TearoomOne\\FtpBackup\\FtpClient' => 'src/FtpClient.php',
    'TearoomOne\\FtpBackup\\FtpClientInterface' => 'src/FtpClientInterface.php',
    'TearoomOne\\FtpBackup\\SftpClient' => 'src/SftpClient.php',
], __DIR__);

use Kirby\Http\Response;
use TearoomOne\FtpBackup\BackupController;
use TearoomOne\FtpBackup\BackupManager;
use TearoomOne\FtpBackup\FileDownloadResponse;

/**
 * Wrap a route action with the access check and error handling shared by all plugin routes
 */
$guarded = function (string $context, Closure $action): Closure {
    return function (...$args) use ($context, $action) {
        if (!BackupController::canAccess()) {
            return Response::json(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
        try {
            return $action(...$args);
        } catch (\Throwable $e) {
            error_log("[kirby-ftp-backup] Error {$context}: " . $e->getMessage());
            return Response::json([
                'status' => 'error',
                'message' => "Error {$context}: " . $e->getMessage()
            ], 500);
        }
    };
};

Kirby::plugin('tearoom1/kirby-ftp-backup', [
    // Plugin information
    'name' => 'FTP Backup',
    'description' => 'Plugin to create and manage site backups with FTP functionality',

    // Plugin options
    'options' => [
        // Plugin control
        'enabled' => true, // Enable/disable the entire plugin
        'allowedRoles' => [], // Additional Kirby roles allowed to use the plugin (admins always allowed). Example: ['editor', 'client']
        'ftpEnabled' => true, // Enable/disable FTP uploads (backups still created locally)
        // FTP setup
        'ftpProtocol' => 'ftp', // Connection type: 'ftp', 'ftps' or 'sftp'
        'ftpHost' => '', // FTP host
        'ftpPort' => null, // Server port (default: 21 for FTP/FTPS, 22 for SFTP)
        'ftpUsername' => '', // FTP username
        'ftpPassword' => '', // FTP password
        'ftpDirectory' => '/', // Remote directory (a relative path is resolved from the login directory)
        'ftpPassive' => true, // Use passive mode
        'ftpUsePasvAddress' => true, // FTP/FTPS: use the IP announced in the PASV reply. Set to false if the server announces a wrong/internal IP (data connection then goes to ftpHost)
        'ftpPrivateKey' => null, // SFTP: path to a private key file (RSA, ECDSA or Ed25519)
        'ftpPassphrase' => null, // SFTP: passphrase of the private key
        'ftpTimeout' => 30, // Socket response timeout in seconds (how long to wait for the server to respond to each packet, not the total transfer duration)
        'ftpMaxRetries' => 3, // Resume a failed FTP/FTPS upload up to this many times
        'ftpRetryDelay' => 5, // Seconds to wait before reconnecting for an upload retry
        'ftpKeepAlive' => 0, // Send SSH keepalive every N seconds during upload (0 = disabled). Useful when a firewall drops idle connections mid-transfer.
        'cliOutputOnSuccess' => false, // Print successful run.php results; errors are always printed
        // Notifications (sent for failed runs and runs whose upload failed)
        'notifyEmail' => null, // Address or list of addresses (uses Kirby's email setup)
        'notifyFrom' => null, // Sender address (default: the first recipient)
        'notifyWebhook' => null, // URL that receives a JSON POST per notification
        'notifyOnSuccess' => false, // Also notify about successful runs
        'maxBackupAge' => 0, // Warn in the Panel when the newest backup is older than this many hours (0 = off)
        'serverCacheMinutes' => 15, // Panel: reuse the list of files on the server for this many minutes (0 = always connect)
        'cache' => true, // Plugin cache that holds the server list
        // general settings
        'backupDirectory' => kirby()->root('content') . '/.backups',
        'backupRetention' => 10, // Number of backups to keep
        'deleteFromFtp' => true, // Delete backups from FTP server
        'filePrefix' => 'backup-', // Prefix for backup filenames
        'retentionStrategy' => 'simple', // 'simple' or 'tiered'
        'tieredRetention' => [
            'daily' => 10,    // Keep all backups for the first 10 days
            'weekly' => 4,    // Then keep 1 per week for 4 weeks
            'monthly' => 6    // Then keep 1 per month for 6 months
        ],
        'archivePassword' => null, // Encrypt archives with AES-256 (open them with 7-Zip or similar)
        'additionalPaths' => [], // Extra files/folders relative to the project root, stored under _additional/ in the archive, e.g. ['site/accounts']
        // File filtering (regex patterns without delimiters, case-insensitive)
        'includePatterns' => [], // Array of regex patterns - if not empty, only matching files are included
        'excludePatterns' => [], // Array of regex patterns - matching files are always excluded
        'excludePaths' => [], // Array of relative paths or fnmatch patterns, e.g. ['.backups', '_drafts/*']
        'excludeContentWatch' => false, // Exclude .content-watch.json history files
        'excludeDrafts' => false, // Exclude all Kirby _drafts directories
        // URL execution settings
        'urlExecutionToken' => '', // Token required for URL-based backup execution
        'urlExecutionEnabled' => false, // Enable/disable URL-based backup execution
    ],

    'translations' => require __DIR__ . '/src/translations.php',

    // Panel areas registration
    'areas' => [
        'ftp-backup' => require __DIR__ . '/src/areas/ftp-backup.php',
    ],

    // API routes
    'api' => [
        'routes' => [
            // List backups
            [
                'pattern' => 'ftp-backup/backups',
                'method' => 'GET',
                'action' => $guarded('listing backups', function () {
                    return (new BackupManager())->listBackups();
                })
            ],
            // Create backup manually
            [
                'pattern' => 'ftp-backup/create',
                'method' => 'POST',
                'action' => $guarded('creating backup', function () {
                    $jobId = kirby()->request()->get('jobId');
                    return (new BackupManager())->createBackup(true, $jobId ?: null);
                })
            ],
            // Cancel a running backup
            [
                'pattern' => 'ftp-backup/cancel',
                'method' => 'POST',
                'action' => $guarded('cancelling backup', function () {
                    $jobId = (string)kirby()->request()->get('jobId');
                    if ($jobId === '') {
                        return Response::json(['status' => 'error', 'message' => 'Missing jobId'], 400);
                    }
                    return (new BackupManager())->cancelBackup($jobId);
                })
            ],
            // Protect a backup from retention, or remove the protection
            [
                'pattern' => 'ftp-backup/protect',
                'method' => 'POST',
                'action' => $guarded('protecting backup', function () {
                    $request = kirby()->request();
                    return (new BackupManager())->protectBackup(
                        (string)$request->get('filename'),
                        (bool)$request->get('protected', true)
                    );
                })
            ],
            // Delete a backup locally and optionally on the FTP server
            [
                'pattern' => 'ftp-backup/delete',
                'method' => 'POST',
                'action' => $guarded('deleting backup', function () {
                    $request = kirby()->request();
                    return (new BackupManager())->deleteBackup(
                        (string)$request->get('filename'),
                        (bool)$request->get('remote', false)
                    );
                })
            ],
            // Fetch a backup that is only on the server; the browser then loads it from the returned URL
            [
                'pattern' => 'ftp-backup/server-download',
                'method' => 'POST',
                'action' => $guarded('downloading from the server', function () {
                    return (new BackupManager())->prepareServerDownload((string)kirby()->request()->get('filename'));
                })
            ],
            // Copy a backup from the server to this web server
            [
                'pattern' => 'ftp-backup/copy-from-server',
                'method' => 'POST',
                'action' => $guarded('copying from the server', function () {
                    return (new BackupManager())->copyFromServer((string)kirby()->request()->get('filename'));
                })
            ],
            // Poll progress of a running backup
            [
                'pattern' => 'ftp-backup/progress/(:any)',
                'method' => 'GET',
                'action' => $guarded('reading progress', function (string $jobId) {
                    return ['status' => 'success', 'data' => (new BackupManager())->getProgress($jobId)];
                })
            ],
            // Catch accidental GET requests to the create endpoint (caused by HTTP→HTTPS or www redirects
            // converting the POST method to GET — fix your canonical URL in config.php)
            [
                'pattern' => 'ftp-backup/create',
                'method' => 'GET',
                'action' => function () {
                    return Response::json([
                        'status' => 'error',
                        'message' => 'This endpoint only accepts POST requests. A GET request was received, which is most likely caused by an HTTP redirect (e.g. HTTP→HTTPS or www→non-www) converting your POST to GET. Set the correct canonical URL in your Kirby config.php: \'url\' => \'https://yourdomain.com\''
                    ], 405);
                }
            ],
            // Check FTP settings status
            [
                'pattern' => 'ftp-backup/settings-status',
                'method' => 'GET',
                'action' => $guarded('checking settings', function () {
                    $manager = new BackupManager();
                    return [
                        'status' => 'success',
                        'data' => [
                            'configured' => $manager->isFtpConfigured(),
                            'ftpEnabled' => $manager->getSettings()['ftpEnabled'],
                            'reason' => $manager->ftpConfigurationError(),
                        ]
                    ];
                })
            ],
            // Check the FTP setup without creating a backup
            [
                'pattern' => 'ftp-backup/test-connection',
                'method' => 'POST',
                'action' => $guarded('testing the connection', function () {
                    // A failed test is a valid result, not a failed request
                    return ['status' => 'success', 'data' => (new BackupManager())->testConnection()];
                })
            ],
            // Get FTP server stats and file list
            [
                'pattern' => 'ftp-backup/ftp-stats',
                'method' => 'GET',
                'action' => $guarded('retrieving FTP stats', function () {
                    // ?fresh=1 skips the cached list (refresh button in the Panel)
                    $fresh = filter_var(kirby()->request()->get('fresh', false), FILTER_VALIDATE_BOOLEAN);
                    return (new BackupManager())->getFtpServerStats($fresh);
                })
            ],
        ]
    ],

    'routes' => [
        // Download a backup (Panel session required)
        [
            'pattern' => 'ftp-backup/download/(:any)',
            'method' => 'GET',
            'action' => $guarded('downloading backup', function (string $filename) {
                $response = (new BackupManager())->downloadBackup(rawurldecode($filename));
                if ($response instanceof FileDownloadResponse) {
                    // Send it ourselves: Kirby 4 rebuilds returned responses
                    // from their body and would drop the stream
                    echo $response->send();
                    exit;
                }
                return $response;
            })
        ],
        // Send a backup fetched from the server once, then remove the temporary copy (Panel session required)
        [
            'pattern' => 'ftp-backup/server-download/(:any)',
            'method' => 'GET',
            'action' => $guarded('downloading from the server', function (string $token) {
                $response = (new BackupManager())->serverDownload($token);
                if ($response instanceof FileDownloadResponse) {
                    echo $response->send();
                    exit;
                }
                return $response;
            })
        ],
        // Execute backup via URL (with token authentication)
        [
            'pattern' => 'ftp-backup/execute',
            'method' => 'GET',
            'action' => function () {
                if (!option('tearoom1.kirby-ftp-backup.urlExecutionEnabled', false)) {
                    return new Response('URL execution is disabled', 'text/plain', 403);
                }

                $providedToken = (string)get('token');
                $configuredToken = (string)option('tearoom1.kirby-ftp-backup.urlExecutionToken', '');

                if ($configuredToken === '') {
                    return new Response('No token configured', 'text/plain', 403);
                }

                if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
                    return new Response('Invalid or missing token', 'text/plain', 403);
                }

                $result = (new BackupManager())->executeBackupWithFormatting(true);
                return new Response($result['message'], 'text/plain', $result['success'] ? 200 : 500);
            }
        ],
    ],

    // Kirby CLI commands (https://github.com/getkirby/cli)
    'commands' => [
        'ftp-backup:create' => [
            'description' => 'Create a new backup and upload it to the FTP server',
            'args' => [],
            'command' => static function ($cli): void {
                $result = (new BackupManager())->executeBackupWithFormatting(true);
                if (!$result['success']) {
                    $cli->error($result['message']);
                    exit($result['exitCode']);
                }
                $cli->success($result['message']);
            }
        ]
    ]
]);
