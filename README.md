# Kirby FTP Backup

A Kirby CMS plugin that creates backups of your site content and uploads them to an FTP server.

## Features

- Create ZIP backups of your site content
- Automatic upload to FTP server
- Configurable backup retention
- Panel interface for manual backups and downloads
- Scheduled backups via cron job

## Installation

### Manual Installation

1. Download or clone this repository
2. Place the folder `kirby-ftp-backup` in your site's `/site/plugins` directory

### Composer Installation

```bash
composer require tearoom1/kirby-ftp-backup
```

## Configuration

All configuration is handled through Kirby's option system. Add the following to your `site/config/config.php` file:

```php
'tearoom1.ftp-backup' => [
    // FTP Connection Settings
    'ftpHost' => 'your-ftp-host.com',
    'ftpPort' => 21,
    'ftpUsername' => 'your-username',
    'ftpPassword' => 'your-password',
    'ftpDirectory' => 'backups', 
    'ftpSsl' => false,
    'ftpPassive' => true,
    
    // Backup Settings
    'backupDirectory' => 'content/.backups',  // Local directory to store backups
    'backupRetention' => 10,                 // Number of backups to keep
    'deleteFromFtp' => true                  // Whether to delete old backups from FTP
]
```

### Configuration Options

| Option | Type | Default | Description                                                        |
|--------|------|---------|--------------------------------------------------------------------|
| `ftpHost` | string | `''` | FTP server hostname                                                |
| `ftpPort` | integer | `21` | FTP server port                                                    |
| `ftpUsername` | string | `''` | FTP username                                                       |
| `ftpPassword` | string | `''` | FTP password                                                       |
| `ftpDirectory` | string | `'/'` | Remote directory to store backups                                  |
| `ftpSsl` | boolean | `false` | Use SSL/TLS connection                                             |
| `ftpPassive` | boolean | `true` | Use passive mode                                                   |
| `backupDirectory` | string | `'content/.backups'` | Either absolute or relative (to Kirby base) path for local backups |
| `backupRetention` | integer | `10` | Number of backups to keep locally and on FTP                       |
| `deleteFromFtp` | boolean | `true` | Whether to delete old backups from FTP server                      |

## Panel Interface

The plugin adds a "FTP Backup" area to your Kirby Panel:

- View all available backups
- Create new backups manually
- Download existing backups
- View backup statistics (count, total size, latest backup)

## Automatic Backups with Cron

To set up automatic backups, add a cron job to your server. The cron job should run the included `run.php` script:

```bash
php /path/to/site/plugins/kirby-ftp-backup/run.php
```

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
- Outputs logs to the console

## Security Considerations

- Store your FTP credentials securely in your `config.php` file
- Make sure your `config.php` file is not accessible from the web
- Consider using SFTP or FTP with SSL for secure transfers
- Regularly verify that your backups are being created and can be restored

## Troubleshooting

If you encounter issues:

1. Check that your FTP credentials and server settings are correct
2. Verify that the FTP directory exists and has write permissions
3. Check your server's PHP error logs for any PHP errors
4. Make sure the local backup directory is writable by PHP
5. Check if you have the required PHP extensions (zip, ftp)

## Requirements

- Kirby 3.5+
- PHP 7.4+
- PHP ZIP extension
- PHP FTP extension

## License

MIT License

## Credits

Developed by [Your Name/Company]
