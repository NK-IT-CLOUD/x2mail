# Changelog

All notable changes to X2Mail will be documented in this file.

Format: [Semantic Versioning](https://semver.org/) — MAJOR.MINOR.PATCH

## [Unreleased]

## [0.4.0] — 2026-03-19

### Added
- **Nextcloud-native Contacts integration**: read, create, edit, and delete contacts directly in Nextcloud Contacts — no CardDAV sync, no separate database
- Autocomplete suggestions in To/Cc/Bcc fields now pull from Nextcloud Contacts
- `occ x2mail:setup` now enables contacts automatically

### Changed
- Contacts provider replaced: PdoAddressBook/SQLite → NextcloudAddressBook via NC IManager API
- Separate suggestions driver removed (unified into AddressBook provider)

### Fixed
- Dovecot OAuth2 docs link updated to 2.4+ documentation
- Added Dovecot 2.4+ version requirement to README

## [0.3.1] — 2026-03-18

### Fixed
- MailSo: SMTP CRLF injection prevention in MailFrom/Rcpt
- MailSo: IMAP EscapeString strips CR/LF/NUL from quoted strings
- MailSo: MIME parser recursion depth limit (max 50 levels)
- MailSo: SSLContext property whitelist in fromArray()
- MailSo: Sieve script name CRLF stripping
- MailSo: fix undefined variable in IdnToUtf8/IdnToAscii
- MailSo: Xxtea return type and parameter type for PHP 8.4
- NC Plugin: replace all `\OC::$server` with `\OCP\Server::get()`

### Changed
- Static version path `app/snappymail/v/current/` — no renames on version bumps
- `APP_VERSION` read from info.xml at runtime (single source of truth)
- Update check against own GitHub releases instead of snappymail.eu
- Auto-update disabled (managed via scripts/release.sh)
- About page: X2Mail branding with NK-IT Dev + GitHub link
- `scripts/bump-version.sh` for automated version bumps

## [0.3.0] — 2026-03-18

### Security
- Fix S/MIME signature verification bypass (PKCS7_NOSIGS removed)
- Fix unsafe `unserialize()` in upgrade.php — restrict to scalars (prevent RCE)
- Fix TAR path traversal in plugin/update extraction
- Fix XSS via crafted RTF content (htmlspecialchars on output)
- Fix JWT broken encoding (wrong variable name)
- Add image decompression bomb protection (25MP limit)
- Fix SSO hash Time=0 bypass — require valid timestamp
- S/MIME cert path: basename() to prevent directory traversal
- Temp file: basename() to prevent path traversal
- TAR/ZIP: restrict Content-Type header chars to printable ASCII
- RTF: add recursion depth limit (max 100 levels)
- HTTP socket: instance-level Authorization storage (prevent cross-request leak)
- EXIF: validate MIME type before data:// URI construction
- Strict === comparison for session UID check

### Fixed
- PHP 8.4: OAuth2 MAC nonce — `uniqid()` replaced with `random_bytes()`
- PHP 8.4: JWT `openssl_pkey_free()` removed (deprecated since PHP 8.0)
- PHP 8.4: JWT `is_resource()` check updated for OpenSSLAsymmetricKey objects
- PHP 8.4: Imagick `setImageMatte()` replaced with `setImageAlphaChannel()`
- PHP 8.4: RTF `mb_convert_encoding` HTML-ENTITIES replaced with `html_entity_decode`
- PHP 8.4: OAuth2 SSL verification enabled by default (was disabled — MITM risk)
- PHP 8.4: HTTP socket `\split()` replaced with `\explode()` (removed since PHP 7)
- PHP 8.4: HTTP socket `\random_int()` fixed with required arguments
- PHP 8.4: CRAM SASL property declaration added
- PHP 8.4: `auto_detect_line_endings` removed (deprecated since PHP 8.1)
- PHP 8.4: lessphp class property declarations (15 dynamic properties)
- IMAP: OAUTHBEARER removed from wrong PLAIN/SCRAM branch (dead code fix)
- HTTP: `verify_peer` default changed to `true`, `CURLOPT_SSL_VERIFYHOST` enabled
- AdditionalAccount: fix `$aData` → `$aAccountHash` variable name bug
- Folders: fix undefined `$iErrorCode` variable
- TNEFDecoder: missing break in switch, null coalescing for buffer reads, typed property defaults
- TAR stream: fix undefined variable in addFromString
- S/MIME encrypt(): fix dead code return, remove duplicate fopen in sign()
- TNEFAttachment: buffer length sanity check

### Changed
- **Fork migration: SM Core v2.38.2 now tracked in git** (was gitignored + sed patches)
- Full SM Core audit completed: 6 CRITICAL, 15 HIGH, 16 MEDIUM findings fixed
- `scripts/release.sh` — automated release flow (build, sign, GitHub, NC App Store)

## [0.2.0] — 2026-03-18

### Added
- Setup Wizard web UI in admin settings with preflight checks
- `make sign` / `make validate` / `make release` build targets for NC App Store
- `scripts/deploy.sh` — build + deploy to NC Docker (FPM restart included)
- `scripts/apply-sm-patches.sh` — reproducible SM Core patch script
- `SM_VERSION` file for version pinning (v2.38.2)
- App Store metadata: author, repository, bugs, screenshots in info.xml

### Security
- Fix XSS via unescaped iframe src in templates/index.php
- Fix arbitrary file require via custom_config_file in InstallStep (realpath validation)
- Replace all `$_POST`/`$_GET`/`$_SERVER` direct access with `$this->request->getParam()`
- Add `hash_equals()` for admin panel key comparison (timing-safe)
- Add port range validation to `saveSetup()`
- Validate `app_path` to prevent protocol injection

### Fixed
- PHP 8.4: Remove deprecated `E_STRICT` constant from SM Logger
- PHP 8.4: Fix undefined array key "secure" in SM ConnectSettings
- PHP 8.4: Fix 32 implicit nullable parameters in SM PGP/GPG and Sabre VObject
- Chrome 117+: Fix invalid RegExp v-flag in folder create pattern
- NC 33: Replace 28 deprecated `\OC::$server` calls with `\OCP\Server::get()` or constructor DI
- NC 33: Replace 2 deprecated `\OC_User::isAdminUser()` with `IGroupManager::isAdmin()`
- NC 33: Replace 3 deprecated `getSystemConfig()` with `IConfig::getSystemValue()`
- NC 33: PHP 8 attributes replace deprecated PHPDoc annotations
- NC 33: DI autowiring replaces manual `$c->query()` controller registration
- Setup Wizard: API error feedback shown in UI instead of silent console.error
- Preflight check box and Delete Domain button contrast for light and dark themes

### Changed
- SM Core pinned at v2.38.2 with PHP 8.4 + browser compat patch set
- Auth type selection unified: OAUTHBEARER and XOAUTH2 merged into single SSO option
- SSO mode: hide Logout, Add Account, and redundant folder settings icon
- SSO mode: hide toggleLeftPanel button in settings view
- InstallStep removes SM default domains (gmail.com, hotmail.com, etc.) on install
- Licence updated to AGPL-3.0-or-later (SPDX format)
- GitHub URLs corrected to NK-IT-CLOUD/x2mail

## [0.1.0] — 2026-03-18

### Added
- First working version
- SnappyMail v2.38.2 core engine
- OAUTHBEARER/XOAUTH2 IMAP authentication
- Automatic OIDC token refresh
- `occ x2mail:setup` command
- `occ x2mail:status` command
- Nextcloud 28-35 support
- PHP 8.1+ required
