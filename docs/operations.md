# Operations

[← Back to the README](../README.md)

## Restoring a Backup

Each archive contains the `content` folder as it was at backup time (files at the archive root, e.g. `home/home.txt`). Every archive is re-opened and checked after it is written, and FTP/SFTP uploads are compared by size.

To restore:

1. Get the archive: download it in the Panel or copy it from the FTP server.
2. Test it before touching the site: `unzip -t backup-….zip` (for encrypted archives use `7z t backup-….zip`, see [Encryption](configuration.md#encryption)).
3. Move the current content folder aside, e.g. `mv content content-before-restore`. Keep the backup directory (default `content/.backups`) if you want to keep your local backups.
4. Unpack the archive into a new, empty `content` folder: `mkdir content && unzip backup-….zip -d content`.
   If you use `additionalPaths`, move the `content/_additional/…` folders back to their places afterwards (e.g. `content/_additional/site/accounts` → `site/accounts`) and remove `content/_additional`.
5. Clear Kirby's caches and generated thumbnails (`media` folder) and check the site.
6. Remove `content-before-restore` once everything looks right.

## Security Considerations

- Store your FTP credentials securely in your `config.php` file
- Make sure your `config.php` file is not accessible from the web
- Consider using SFTP or FTP with SSL for secure transfers
- Consider `archivePassword` when backups are stored on a server you do not control
- Regularly verify that your backups are being created and can be restored

## Troubleshooting

If you encounter issues:

1. Use **Test connection** in the Panel: it checks the settings, login, write access and the backup directory step by step and shows the exact error
2. Check that your FTP credentials and server settings are correct
3. Verify that the FTP directory exists and has write permissions
4. Check your server's PHP error logs for any PHP errors
5. Make sure the local backup directory is writable by PHP
6. Check if you have the required PHP extensions (zip, ftp)
7. If uploads fail on slow or high-latency connections, increase `ftpTimeout` — see [Advanced Options](configuration.md#advanced-options)
8. If you get a 504 Gateway Timeout when creating backups from the Panel, increase your web server's proxy/fastcgi read timeout to match or exceed `max_execution_time` in your `php.ini`

## Development and Tests

The tests can be run with:

```bash
composer update
composer test
composer analyze
```

`tests/ServerIntegrationTest.php` additionally runs against real servers when these variables are set (the GitHub workflow starts an SFTP container and a pyftpdlib FTP server for it):

```bash
FTP_BACKUP_TEST_FTP=ftp://user:pass@host:21/absolute/writable/dir \
FTP_BACKUP_TEST_SFTP=sftp://user:pass@host:22/absolute/writable/dir \
FTP_BACKUP_TEST_SFTP_RELATIVE=upload \
composer test
```

Make sure to cleanup the vendor after running tests, to remove dev dependencies.

```bash
composer dist
```
