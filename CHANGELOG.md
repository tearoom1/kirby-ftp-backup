## [2.0.0](https://github.com/tearoom1/kirby-ftp-backup/compare/v1.11.0...v2.0.0) (2026-11-25)


### ⚠ BREAKING CHANGES

* requires Kirby 6 and PHP 8.3. Use the 1.x releases for
Kirby 4 and 5.

Co-Authored-By: Claude <noreply@anthropic.com>

### Features

* Kirby 6 support ([82800ce](https://github.com/tearoom1/kirby-ftp-backup/commit/82800ceda3497471408c90e51bafc8e97fc12ba3))
* styled tooltips instead of browser titles ([d6c8a04](https://github.com/tearoom1/kirby-ftp-backup/commit/d6c8a043231ac57d095781e5269b257209ecf930))


### Bug Fixes

* **cli:** read arguments from $_SERVER so run.php works without argv ([bb8f5d7](https://github.com/tearoom1/kirby-ftp-backup/commit/bb8f5d7e1a7d92b20823e69ba2484395224658a1))
* give the status icons an accessible name ([713f1eb](https://github.com/tearoom1/kirby-ftp-backup/commit/713f1eb981cc72acc19c944c5e7e268b9520134b))
* keep the footer padding in the setup and test dialogs ([58f35e9](https://github.com/tearoom1/kirby-ftp-backup/commit/58f35e9d34980b584968821ca37c0890fabf1b48))
* keep the tooltip while hovered or focused and stop its Escape ([704f25a](https://github.com/tearoom1/kirby-ftp-backup/commit/704f25a68db4b00b96bb54b1111ecfe2fae22b70))
* keep tooltips open on the bubble, for late text and empty triggers ([e7fbeb1](https://github.com/tearoom1/kirby-ftp-backup/commit/e7fbeb173efdb873a8ebaee290b7b4ec0e45905e))
* read run.php arguments from argv when $_SERVER is empty ([9b4263a](https://github.com/tearoom1/kirby-ftp-backup/commit/9b4263a622dedda5382bd5d1e7e6c7578fd36b32))
* show a connection test result only in the dialog it opened ([8fd4cf2](https://github.com/tearoom1/kirby-ftp-backup/commit/8fd4cf2fbddc29176d713c9c41d101cb156fe773))
* show tooltips above dialogs and drawers ([c5ae420](https://github.com/tearoom1/kirby-ftp-backup/commit/c5ae4205068415b1ae807f18b02d3ca6042752db))
* use Kirby 6 color and size tokens ([f13470e](https://github.com/tearoom1/kirby-ftp-backup/commit/f13470eba6dc79f3c13014c43a273c396a0ec6a0))

## [1.11.0](https://github.com/tearoom1/kirby-ftp-backup/compare/v1.10.2...v1.11.0) (2026-10-23)


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

