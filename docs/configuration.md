# Configuration

[← Back to the README](../README.md)

All configuration is handled through Kirby's option system. Add the following to your `site/config/config.php` file:

```php
'tearoom1.kirby-ftp-backup' => [
    // Plugin Control
    'enabled' => true,                           // Enable/disable the entire plugin
    'ftpEnabled' => true,                        // Enable/disable FTP uploads (backups still created locally)

    // FTP Connection Settings
    'ftpProtocol' => 'ftps',
    'ftpHost' => 'your-ftp-host.com',
    'ftpPort' => 21,                             // Defaults to 21 (FTP/FTPS) or 22 (SFTP)
    'ftpUsername' => 'your-username',
    'ftpPassword' => 'your-password',
    'ftpDirectory' => 'backups',
    'ftpPassive' => true,
    'ftpPrivateKey' => '/path/to/private/key',   // SFTP only: RSA, ECDSA or Ed25519 key file
    'ftpPassphrase' => 'your-passphrase',
    'ftpTimeout' => 30,
    'ftpMaxRetries' => 3,
    'ftpRetryDelay' => 5,
    'ftpKeepAlive' => 0,
    'cliOutputOnSuccess' => false,

    // Backup Settings
    'backupDirectory' => 'content/.backups',  // Local directory to store backups
    'backupRetention' => 10,                 // Number of backups to keep when using simple retention strategy
    'deleteFromFtp' => true,                  // Whether to delete old backups from FTP
    'filePrefix' => 'backup-',               // Prefix for backup filenames
    'retentionStrategy' => 'simple',         // Backup retention strategy: 'simple' or 'tiered'
    'tieredRetention' => [
        'daily' => 10,    // Keep all backups for the first 10 days
        'weekly' => 4,    // Then keep 1 per week for 4 weeks
        'monthly' => 6    // Then keep 1 per month for 6 months
    ],

    // File Filtering (regex patterns without delimiters, case-insensitive)
    'includePatterns' => [],                 // If not empty, only files matching these patterns are included
    'excludePatterns' => [],                 // Files matching these patterns are always excluded
    'excludePaths' => [],                    // Relative paths or fnmatch patterns to exclude
    'excludeContentWatch' => false,          // Exclude .content-watch.json history files
    'excludeDrafts' => false,                // Exclude Kirby _drafts folders
]
```

## Configuration Options

| Option | Type | Default | Description                                                      |
|--------|------|---------|------------------------------------------------------------------|
| `enabled` | boolean | `true` | Enable/disable the entire plugin                                 |
| `allowedRoles` | array | `[]` | Additional Kirby roles allowed to use the plugin. Admins always have access (see [Access Control](#access-control)) |
| `ftpEnabled` | boolean | `true` | Enable/disable FTP uploads (backups still created locally)       |
| `ftpProtocol` | string | `'ftp'` | FTP protocol: 'ftp', 'ftps' or 'sftp'                            |
| `ftpHost` | string | `''` | FTP server hostname                                              |
| `ftpPort` | integer | `21` / `22` | Server port. Defaults to `21` for FTP/FTPS and `22` for SFTP |
| `ftpUsername` | string | `''` | FTP username                                                     |
| `ftpPassword` | string | `''` | FTP password                                                     |
| `ftpDirectory` | string | `'/'` | Remote directory to store backups. A relative path (e.g. `'backups'`) is resolved from the login directory |
| `ftpPassive` | boolean | `true` | Use passive mode                                                 |
| `ftpUsePasvAddress` | boolean | `true` | FTP/FTPS: connect the data channel to the IP announced in the PASV reply. Set to `false` when the server announces a wrong or internal IP (e.g. after a server move); the data channel then uses `ftpHost` |
| `ftpPrivateKey` | string | `null` | SFTP: path to a private key file (RSA, ECDSA or Ed25519, OpenSSH/PEM/PuTTY format). `ftpUsername` is still required |
| `ftpPassphrase` | string | `null` | Passphrase for the private key, if it is encrypted |
| `ftpTimeout` | integer | `30` | Socket response timeout in seconds (see [Advanced Options](#advanced-options)) |
| `ftpMaxRetries` | integer | `3` | Number of reconnect-and-resume attempts after an FTP/FTPS upload failure |
| `ftpRetryDelay` | integer | `5` | Seconds to wait before reconnecting for an FTP/FTPS upload retry |
| `ftpKeepAlive` | integer | `0` | SFTP only. SSH keepalive interval in seconds, `0` = disabled (see [Advanced Options](#advanced-options)) |
| `cliOutputOnSuccess` | boolean | `false` | Print successful `run.php` results. Errors are always written to STDERR |
| `archivePassword` | string | `null` | Encrypt archives with AES-256. macOS Archive Utility and `unzip` cannot open them; use 7-Zip (`7z x`), Keka or WinZip |
| `additionalPaths` | array | `[]` | Extra files or folders to back up, relative to the project root (or absolute), e.g. `['site/accounts', 'site/blueprints']`. Stored under `_additional/` in the archive |
| `notifyEmail` | string\|array | `null` | Send an email when a run fails or its upload fails (uses Kirby's [email setup](https://getkirby.com/docs/guide/emails)) |
| `notifyFrom` | string | `null` | Sender of notification emails (default: the first recipient) |
| `notifyWebhook` | string | `null` | URL that receives each notification as JSON `POST` (e.g. for Slack, ntfy or a monitoring service) |
| `notifyOnSuccess` | boolean | `false` | Also notify about successful runs |
| `maxBackupAge` | integer | `0` | Show a warning in the Panel when the newest backup is older than this many hours (`0` = off) |
| `serverCacheMinutes` | integer | `15` | Panel: reuse the list of files on the FTP server for this many minutes instead of connecting on every visit (`0` = always connect). Uploads, cleanup and deletions mark the list as outdated; the refresh button always reads the server |
| `backupDirectory` | string | `'content/.backups'` | Either absolute or relative (to Kirby base) path for local backups. It is never included in a backup, even when it lies inside `content` |
| `backupRetention` | integer | `10` | Number of backups to keep when using simple retention strategy   |
| `deleteFromFtp` | boolean | `true` | Whether to delete old backups from FTP server                    |
| `filePrefix` | string | `'backup-'` | Prefix for backup filenames. Retention only ever deletes files with this prefix (see [Retention Strategies](#retention-strategies)) |
| `retentionStrategy` | string | `'simple'` | Backup retention strategy: 'simple' or 'tiered'                  |
| `tieredRetention` | array | see below | Settings for tiered retention strategy                           |
| `includePatterns` | array | `[]` | Regex patterns (no delimiters, case-insensitive) - if not empty, only matching files are included |
| `excludePatterns` | array | `[]` | Regex patterns (no delimiters, case-insensitive) - matching files are always excluded |
| `excludePaths` | array | `[]` | Relative paths or fnmatch patterns to exclude before regex filtering |
| `excludeContentWatch` | boolean | `false` | Exclude `.content-watch.json` files from backups |
| `excludeDrafts` | boolean | `false` | Exclude all Kirby `_drafts` directories from backups |
| `urlExecutionEnabled` | boolean | `false` | Enable URL-based backup execution                                |
| `urlExecutionToken` | string | `''` | Security token required for URL-based backup execution          |

## Plugin Control Options

### Disable Entire Plugin

To completely disable the plugin (no backups will be created):

```php
'tearoom1.kirby-ftp-backup' => [
    'enabled' => false,
]
```

When disabled:
- Panel UI will not be accessible
- API routes will return error responses
- CLI commands will not execute
- URL-based execution will be blocked

### Access Control

By default, **only users with the `admin` role** can access the plugin — that includes the panel area, the menu entry, the stats view, listing/creating/cancelling backups, protecting and deleting backups, the connection test, and downloading backup files. Non-admins will not see the menu item at all, and any direct API call returns `403 Forbidden`.

If you want to grant access to additional Kirby roles (for example, an `editor` or a dedicated `backup-operator` role), list them in `allowedRoles`:

```php
'tearoom1.kirby-ftp-backup' => [
    'allowedRoles' => ['editor', 'backup-operator'],
]
```

Notes:
- Admins are **always** allowed; you do not need to include `'admin'` in the list.
- Users with any of the listed roles get **full** access — there is no read-only mode. They can create, download (archives contain your entire site), protect and delete backups, also on the FTP server, and cancel running jobs. Only grant this to roles you fully trust.
- The `allowedRoles` option does **not** apply to the URL-based `/ftp-backup/execute` endpoint — that endpoint is protected by its own token and is intended for unauthenticated cron-style use (see [URL-Based Backup Execution](automation.md#url-based-backup-execution)).

### Disable FTP Only

To create backups locally but skip FTP uploads:

```php
'tearoom1.kirby-ftp-backup' => [
    'ftpEnabled' => false,
]
```

When FTP is disabled:
- Backups are still created and stored locally
- No FTP connection or upload attempts
- Local retention policies still apply
- Panel UI remains functional for viewing/downloading local backups

This is useful when:
- You want local backups only
- FTP server is temporarily unavailable
- Testing backup creation without uploading
- Migrating between FTP servers

## Protecting and Deleting Backups

In the Panel, a backup can be protected (e.g. the one made right before a big update). Protected backups are never removed by retention — neither locally nor on the FTP server — and do not count towards `backupRetention`. Backups can also be deleted individually, optionally including the copy on the FTP server.

## Encryption

With `archivePassword`, every file in the archive is encrypted with AES-256 before the archive leaves the server. Keep the password somewhere other than the site: without it the backups cannot be restored.

```php
'archivePassword' => 'a long random passphrase',
```

## Notifications

A backup that silently stops working is only noticed when it is needed. The plugin can report failed runs (and runs where the backup was created but the upload failed) from all triggers: Panel, cron, CLI and URL.

```php
'tearoom1.kirby-ftp-backup' => [
    'notifyEmail' => 'you@example.com',
    'notifyWebhook' => 'https://ntfy.sh/my-site-backups',
    'maxBackupAge' => 36, // hours
]
```

The webhook receives a JSON body like:

```json
{"site": "https://example.com", "title": "My Site", "time": 1792538403, "status": "warning",
 "message": "FTP upload failed: …", "filename": "backup-2026-10-20-020000.zip", "uploaded": false}
```

`status` is `success`, `warning` (backup created, upload failed) or `error`. The Panel shows the result of the last run and, with `maxBackupAge`, warns when the newest backup is too old.

## Retention Strategies

The plugin supports two retention strategies for managing old backups. Both apply to the local backup directory and, with `deleteFromFtp`, to the FTP directory after a successful upload.

Retention only touches files the plugin created itself, recognised by their name: `filePrefix` followed by the creation time, e.g. `backup-2026-10-20-020000.zip`. Other files in the same directory, such as manual exports or backups of another site with a different prefix, are never deleted. If you change `filePrefix`, older backups with the previous prefix are kept and have to be removed by hand.

### Simple Retention

Keeps a fixed number of most recent backups, discarding older ones. This is controlled by the `backupRetention` option.

```php
'backupRetention' => 10, // Keep 10 most recent backups
```

### Tiered Retention

A more sophisticated approach that keeps backups with decreasing frequency as they age:

```php
'retentionStrategy' => 'tiered',
'tieredRetention' => [
    'daily' => 10,    // Keep all backups for the first 10 days
    'weekly' => 4,    // Then keep 1 per week for 4 weeks
    'monthly' => 6    // Then keep 1 per month for 6 months
]
```

This strategy:
1. Keeps all backups from the last X days
2. Then keeps one backup per week for Y weeks
3. Then keeps one backup per month for Z months
4. Deletes anything older

This provides a good balance between recent recovery points and long-term archiving.

## File Filtering

You can control which files are included in backups using regex patterns. This is useful for excluding large media files, temporary files, or other content you don't need to back up.

### How it works

1. **Default behavior**: All files are included
2. **Include patterns**: If specified, only files matching these patterns are included
3. **Dedicated excludes**: `excludeContentWatch`, `excludeDrafts` and `excludePaths` are applied before regex patterns
4. **Exclude patterns**: Files matching these patterns are always excluded
5. **Case-insensitive**: Regex patterns are automatically case-insensitive

### Examples

**Exclude specific file types:**
```php
'excludePatterns' => [
    '\.mp4$',           // Exclude video files
    '\.zip$',           // Exclude zip files
    '/cache/',          // Exclude cache directory
    '\.tmp$',           // Exclude temporary files
]
```

**Exclude Content Watch history and drafts:**
```php
'excludeContentWatch' => true,
'excludeDrafts' => true,
```

**Exclude specific relative paths:**
```php
'excludePaths' => [
    'private',
    '*/_versions/*',
]
```

The backup directory itself is always excluded, there is no need to list it.

**Include only specific file types:**
```php
'includePatterns' => [
    '\.txt$',           // Only text files
    '\.md$',            // And markdown files
]
```

**Exclude large media files but keep images:**
```php
'includePatterns' => [
    '\.(jpg|jpeg|png|gif|svg)$',  // Only image files
    '\.txt$',                      // And text files
    '\.md$',                       // And markdown files
],
'excludePatterns' => [
    '/originals/',                 // Exclude originals folder
]
```

**Complex filtering:**
```php
'includePatterns' => [
    '\.(txt|md|json|yml)$',       // Include text-based files
],
'excludePatterns' => [
    '/\._',                       // Exclude macOS resource forks
    '/\.DS_Store$',               // Exclude .DS_Store files
    '/thumbs\.db$',               // Exclude Windows thumbnails
]
```

**Note**: Patterns are standard regex patterns without delimiters. The plugin automatically adds `#` delimiters and makes all patterns case-insensitive.

## Advanced Options

### `ftpTimeout`

How long to wait for the server to respond to each individual packet, in seconds. This is **not** a total transfer timeout — large files will transfer for as long as needed as long as the connection stays active. The default of `30` seconds is appropriate for most servers. Increase it only if uploads fail on very slow or high-latency connections.

### `ftpKeepAlive` (SFTP only)

Sends an SSH keepalive packet every N seconds to prevent firewalls or load balancers from dropping idle connections during large uploads. Disabled by default (`0`).

> **Note:** Some OpenSSH servers respond to keepalives with a `hostkeys-00@openssh.com` global request that phpseclib cannot handle mid-transfer, which can interrupt the upload. Only enable this if you have confirmed that idle-timeout drops are the cause of your upload failures.
