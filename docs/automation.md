# Automation

[← Back to the README](../README.md)

Backups can be started from cron with the included `run.php`, with the Kirby CLI or by calling a URL. All of them create, upload and rotate backups like the Panel does, and report failures through the [notifications](configuration.md#notifications).

## Automatic Backups with Cron

To set up automatic backups, add a cron job to your server. The cron job should run the included `run.php` script:

```bash
php /path/to/site/plugins/kirby-ftp-backup/run.php
```

Optionally the path to the kirby root directory can be passed as the first argument:

```bash
php /path/to/site/plugins/kirby-ftp-backup/run.php /path/to/root
```

The root directory is the one with the `kirby` folder inside.

Successful runs are silent by default. This can be configured globally:

```php
'tearoom1.kirby-ftp-backup' => [
    'cliOutputOnSuccess' => true,
]
```

The cron command can override the configuration for a single invocation:

```bash
# Never print successful runs
php /path/to/site/plugins/kirby-ftp-backup/run.php --silent

# Print successful runs
php /path/to/site/plugins/kirby-ftp-backup/run.php --verbose

# Flags and an explicit Kirby root can be combined in either order
php /path/to/site/plugins/kirby-ftp-backup/run.php --silent /path/to/root
```

Errors are always written to STDERR and return a non-zero exit code.


### Example Crontab Entry

To run a backup every day at 2 AM:

```
0 2 * * * php /path/to/site/plugins/kirby-ftp-backup/run.php
```

Replace `/path/to/site` with the actual path to your Kirby installation.

### Using the Run Script

The `run.php` script handles:
- Creating a new backup
- Uploading the backup to the configured FTP server
- Cleaning up old backups based on the retention setting
- Optionally printing successful runs, controlled by configuration or `--silent`/`--verbose`
- Writing a message to STDERR and returning exit code `1` on failure

### Kirby CLI

If the [Kirby CLI](https://github.com/getkirby/cli) is installed, a backup can also be started with:

```bash
kirby ftp-backup:create
```

## URL-Based Backup Execution

As an alternative to cron jobs, you can trigger backups via HTTP requests. This is useful for:
- External monitoring services
- Webhook-based automation
- Manual triggering from remote systems
- URL based cronjob execution

### Configuration

First, enable URL execution and set a secure token in your `config.php`:

```php
'tearoom1.kirby-ftp-backup' => [
    // ... other settings ...
    'urlExecutionEnabled' => true,
    'urlExecutionToken' => 'your-secure-random-token-here',
]
```

> **⚠️ Security: the token is the only thing protecting this endpoint.**
> The `/ftp-backup/execute` URL is publicly reachable whenever `urlExecutionEnabled` is `true` — it is not behind the panel login, only behind the token. The plugin does **not** rate-limit failed attempts, so a weak token can be brute-forced.
>
> **You must:**
> - Generate a long, high-entropy token (at least 32 random characters), e.g. `openssl rand -hex 32` or `php -r "echo bin2hex(random_bytes(32));"`. Never use a guessable string.
> - Treat the token like a password: do not commit it to public repos, do not share it in chats, rotate it if exposed.
> - Prefer HTTPS so the token is not sent in the clear.
> - For defense in depth, add rate limiting / IP allow-listing at the web server level (e.g. `fail2ban`, nginx `limit_req`, Cloudflare rules). The plugin itself does not throttle requests.
> - If you don't need URL execution, leave `urlExecutionEnabled` set to `false`.

### Usage

Once configured, you can trigger a backup by making a GET request to:

```
https://yoursite.com/ftp-backup/execute?token=your-secure-random-token-here
```

### Security Features

- **Token Authentication**: Requires a matching token to execute
- **Enable/Disable Toggle**: Can be completely disabled via configuration
- **Timing-Safe Comparison**: Uses `hash_equals()` to prevent timing attacks
- **Plain Text Response**: Returns simple status messages for easy monitoring

**Not provided by the plugin:** rate limiting on the `execute` endpoint. Token strength and any throttling are entirely your responsibility — see the warning above.

### Example Response

On success:
```
Backup created successfully: backup-2025-09-26-140236.zip
File uploaded to FTP server
```

When the backup was created but the upload failed (HTTP status 500, run.php exit code 1):
```
Backup created locally but FTP upload failed: FTP upload failed: Failed to connect to FTP server [ftps://user@example.com:21]: …
```

On error (HTTP status 500, run.php exit code 1):
```
Error creating backup: Backup failed: …
```
