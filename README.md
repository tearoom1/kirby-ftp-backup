# Kirby FTP Backup

A Kirby CMS plugin that creates backups of your site content and uploads them to an FTP, FTPS or SFTP server.

[![Screenshot](screenshot.jpg)](https://github.com/tearoom1/kirby-ftp-backup)

***

## Features

- Create ZIP backups of your site content, optionally with extra paths (e.g. accounts) and AES-256 encryption
- Upload to an FTP, FTPS or SFTP server (password or RSA/ECDSA/Ed25519 key), with byte progress and size check
- Simple or tiered retention, locally and on the server; protected backups are never deleted
- Notifications by email or webhook when a run fails, and a Panel warning when the newest backup is too old
- Panel interface (English and German): one list of local and server backups, download, protect, delete, connection test
- Scheduled backups via cron job, Kirby CLI or URL

## Installation

### Manual Installation

1. Download or clone this repository
2. Place the folder `kirby-ftp-backup` in your site's `/site/plugins` directory

### Composer Installation

```bash
composer require tearoom1/kirby-ftp-backup
```

## Quick Start

1. Add the connection to `site/config/config.php`:

   ```php
   'tearoom1.kirby-ftp-backup' => [
       'ftpProtocol' => 'sftp',              // 'ftp', 'ftps' or 'sftp'
       'ftpHost' => 'backup.example.com',
       'ftpUsername' => 'backup',
       'ftpPassword' => 'secret',            // SFTP: or 'ftpPrivateKey' => '/path/to/key'
       'ftpDirectory' => 'backups',
       'backupRetention' => 10,              // keep the 10 newest backups
   ],
   ```

2. Open **FTP Backup** in the Panel, click **Test connection**, then **Create backup**.

3. Schedule a daily backup, e.g. at 2 am:

   ```bash
   0 2 * * * php /path/to/site/plugins/kirby-ftp-backup/run.php
   ```

Backups are stored in `content/.backups`, uploaded to the server and rotated according to the retention settings. To get an email when a run fails, add `'notifyEmail' => 'you@example.com'`.

## Documentation

- [Configuration](docs/configuration.md): all options, access control, retention, protected backups, file filtering, extra paths, encryption, notifications and connection tuning
- [Automation](docs/automation.md): cron with `run.php`, Kirby CLI and URL-based execution
- [Operations](docs/operations.md): restoring a backup, security, troubleshooting, development and tests

## Panel Interface

The plugin adds a "FTP Backup" area to your Kirby Panel (in English or German, following the user's Panel language):

- One list of all backups, local and on the FTP server, with date, size, where each copy is (`Local` / `Server`), whether it is protected and whether the next run will delete it
- Per backup: download, protect from deletion, delete the local copy, delete on the server
- Create a backup manually with live progress (compression, upload in bytes for FTP and SFTP, cleanup)
- Cancel a running backup (only one backup runs at a time; a second start is rejected)
- The result of the last run (also of cron and URL runs) and, with `maxBackupAge`, a warning when the newest backup is too old
- Test connection: checks the settings, login, write access and the backup directory without creating a backup
- Backup statistics and the FTP server listing with endpoint, file count and size

## Requirements

- Kirby 4 or 5
- PHP 8.1+ (Kirby 5 needs 8.2+)
- PHP ZIP extension
- PHP FTP extension

## License

This plugin is licensed under the [MIT License](LICENSE)

## Credits

- Developed by Mathis Koblin
- Assisted by AI Claude Sonnet 4.6

[!["Buy Me A Coffee"](https://www.buymeacoffee.com/assets/img/custom_images/orange_img.png)](https://coff.ee/tearoom1)

## Screenshots

Screenshots use sample content in a 1040 × 598 window at double pixel density (2080 × 1196 pixels). Panel views are cropped to the content area. The main image uses the light theme.

| View | Light | Dark |
| --- | --- | --- |
| Overview | [Light](screenshot.jpg) | [Dark](screenshot-dark.jpg) |
