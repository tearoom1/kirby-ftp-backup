## [1.11.0](https://github.com/tearoom1/kirby-ftp-backup/compare/v1.10.2...v1.11.0) (2026-10-23)

### What's new in 1.11

#### A new Panel view
- The area now uses Kirby's own view header: large title, "Create backup" as the main action, "Support this plugin", and a "…" menu with "Test connection" and "Setup" (Setup only for admins).
- Three tiles give the overview at a glance:
  - **Last backup:** how long ago, with date and time. It turns orange when the newest backup is older than `maxBackupAge` and tells you to check the cron job.
  - **On this web server:** number and total size of the local backups.
  - **On the FTP server:** number and total size of the server copies, and when the server was last checked.
- The backups table shows where each backup lives ("Web server", "FTP server"), with unambiguous dates ("Mar 12, 2026") and aligned times.
- Notes in the table say which copy the retention rule will remove: "Local copy deleted by the next run", "Server copy deleted by the next run", or "Deleted by the next run".

#### Server listing is cached
- The Panel no longer connects to the FTP server on every visit. The server listing is cached for 15 minutes and refreshed after a backup, after cleanup on the server, after deleting a server copy, and when you click refresh.
- New option `serverCacheMinutes` (default `15`, `0` disables the cache).
- If the server can't be reached, the last known listing is shown and marked as such.
- The separate "Server" dialog was removed. Its information is now in the tile and the table.

#### Backups that only exist on the FTP server
- They can now be downloaded from the Panel.
- "Copy to web server" fetches them into the local backup folder.

#### Fixes
- Backup dates come from the timestamp in the filename instead of the file time. Copied or restored files no longer show the wrong date or end up in the wrong place in the list.

#### Good to know when updating
- `FtpClientInterface` has a new `download()` method. This only matters if you wrote your own client implementation.
- A backup copied back to the web server falls under the local retention rule again. Protect it if you want to keep it.

#### Commits


### Features

* **panel:** align backup times in the Created column ([cd8fb49](https://github.com/tearoom1/kirby-ftp-backup/commit/cd8fb491bae01d364283dcc46081fc9e162cf977))
* **panel:** cache the FTP server listing instead of connecting on every visit ([47d3a9d](https://github.com/tearoom1/kirby-ftp-backup/commit/47d3a9d0f16c4962d252c00a329fdb0d0604d59d))
* **panel:** download or copy backups that only exist on the FTP server ([8f9b5a9](https://github.com/tearoom1/kirby-ftp-backup/commit/8f9b5a9cc84dfa043cd6ee0bbc07cb61838238e0))
* **panel:** native Kirby view with header buttons, overview tiles and a backups section ([a825fcb](https://github.com/tearoom1/kirby-ftp-backup/commit/a825fcb7180246e2f74a2b6c4f8937697ac0327f))
* **panel:** tiles for last backup, web server and FTP server ([2ede745](https://github.com/tearoom1/kirby-ftp-backup/commit/2ede745dc68407e55383dbc03b199da036d7ca74))
* **panel:** unambiguous table dates and retention notes per copy ([6fdbf82](https://github.com/tearoom1/kirby-ftp-backup/commit/6fdbf82fe7b5eccac49db361d5eeef709f56ff88))


### Bug Fixes

* **panel:** show the latest backup in the Last backup tile again ([fb93d68](https://github.com/tearoom1/kirby-ftp-backup/commit/fb93d6883504e0ca3ab5c7e0275a44ac58561a8d))
* take the backup date from the filename instead of the file time ([dd89791](https://github.com/tearoom1/kirby-ftp-backup/commit/dd89791e89f4c370b7ff0ca5f2efa9aaa5c07110))

## [1.10.2](https://github.com/tearoom1/kirby-ftp-backup/compare/v1.10.1...v1.10.2) (2026-10-23)


### Bug Fixes

* update phpseclib to 3.0.57 for security advisories ([a48a5c3](https://github.com/tearoom1/kirby-ftp-backup/commit/a48a5c3728ebecf0fa0627eceb50285d23fdb001))

## [1.10.1](https://github.com/tearoom1/kirby-ftp-backup/compare/v1.10.0...v1.10.1) (2026-10-23)


### Bug Fixes

* keep additional paths relative when the Kirby root is behind a symlink ([a682230](https://github.com/tearoom1/kirby-ftp-backup/commit/a6822305a47f77392dd2a9a943987c47fe35bc71))

## [1.10.0](https://github.com/tearoom1/kirby-ftp-backup/compare/v1.9.0...v1.10.0) (2026-10-23)


### Features

* Panel support link opens the central support page ([43079b8](https://github.com/tearoom1/kirby-ftp-backup/commit/43079b8cc0f234b40a0a8a0dadb65fefd7ece2f9))

## [1.9.0](https://github.com/tearoom1/kirby-ftp-backup/compare/v1.8.0...v1.9.0) (2026-10-20)


### Features

* **panel:** backup list as a table with date, size, local and server columns ([abe2713](https://github.com/tearoom1/kirby-ftp-backup/commit/abe2713eaa7793a4b9fdbe3397f374a91762aa2b))

