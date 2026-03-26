# Changelog

All notable changes to X2Mail will be documented in this file.

Format: [Semantic Versioning](https://semver.org/) — MAJOR.MINOR.PATCH

## [0.5.9] — 2026-03-26

### Added
- Personal settings page with Identity & Signatures management link
- Own settings section with app icon in Nextcloud sidebar
- Dynamic page title from admin-configured branding

### Fixed
- PSR-12 code style compliance
- CSS isolation for Nextcloud header and user menu
- Admin panel branding
- German translations

## [0.5.8] — 2026-03-26

### Added
- ICS Event Card: calendar invitations displayed prominently above message body
- Event details: date/time, organizer, location, attendees with formatted display
- One-click "Save to Calendar" button with CalDAV integration
- Calendar picker filters read-only calendars (Deck-generated etc.)
- Toast notification on successful calendar save
- German and English translations for event card UI
- App Store screenshot for calendar integration

## [0.5.7] — 2026-03-26

### Fixed
- SideMenu app compatibility: SnappyMail's global CSS (ul/li margin resets) no longer leaks into Nextcloud UI
- CSS selector scoping: all embed.css rules prefixed with `#rl-app` to prevent style leakage
- Boot CSS: strip body/html rules from SnappyMail's inline boot stylesheet

## [0.5.6] — 2026-03-26

### Changed
- SSO defaults: disable contacts autosave
- Hide theme selector on fresh install (x2mail theme is default)

## [0.5.5] — 2026-03-26

### Fixed
- Default theme set to x2mail on fresh install (was falling back to "Default")

## [0.5.4] — 2026-03-26

### Added
- First release on Nextcloud App Store
- Signed with official Nextcloud Code Signing certificate

### Changed
- Updated screenshots for App Store listing

## [0.5.3] — 2026-03-25

### Added
- PHPUnit test infrastructure with 18 unit tests (DomainConfigService, TokenRefreshMiddleware)
- CI: PHPUnit step in Gitea pipeline

### Changed
- Event listeners moved from `boot()` closures to dedicated `IEventListener` classes (PasswordLogin, Logout, Impersonate)

### Fixed
- Domain validation: reject `.` and `..` as domain names (found by unit tests)

## [0.5.2] — 2026-03-25

### Added
- Dashboard widget for unread mail (`IAPIWidgetV2`, auto-reload every 120s)
- Complete German translations for all UI strings

### Changed
- Migrate 47 deprecated `IConfig` calls to `IAppConfig`/`IUserConfig` (NC33 public API)
- Replace private `OC\Core\Command\Base` with `Symfony\Component\Console\Command\Command`
- Template escaping: `p()` for values, `print_unescaped()` for engine content
- Replace "SnappyMail" with "X2Mail" in admin panel UI

### Fixed
- Null-guard for `$this->userId` in FetchController personal settings
- Add `declare(strict_types=1)` to Settings command
- Dashboard widget icon uses NC URL generator instead of internal SM path

## [0.5.1] — 2026-03-25

### Fixed
- SSO setup incorrectly disabled identity management (allow_additional_identities, popup_identity)

## [0.5.0] — 2026-03-25

### Added
- New `x2mail` theme for Nextcloud 33+ design system
  - 3-tier color mapping: pastel backgrounds, element colors for icons, text colors for readability
  - Alerts follow NC33 NoteCard pattern (pastel bg + colored left border)
  - Buttons follow NC33 NcButton pattern (focus-visible box-shadow, transitions)
  - Inputs with NC33 focus-visible inset box-shadow
  - NC33 info status color support
  - Light + dark mode with NC33 theme values
  - Updated border-radius, font stack, disabled states to NC33 defaults

### Fixed
- Identity popup close button navigated away instead of showing confirm dialog (href="#" in embedded mode)
- Error tooltips used aggressive red background instead of NC33 NoteCard pattern
- Priority-high indicators, attachment errors, virus warnings now use NC33 color system
- btn-danger/btn-warning hover states were overridden by generic hover rule

### Changed
- Default theme switched from `NextcloudV25+` to `x2mail` (InstallStep, AdminSettings, RainLoop)
- Remove 20 unused bundled SnappyMail themes (A, BlackWood, Blurred, etc.)
- Hide auto-logout setting in SSO/embedded mode (NC manages the session)

## [0.4.10] — 2026-03-25

### Fixed
- SSO: auto-disable "Add account" and "Manage identities" when OIDC is configured (Setup Wizard, CLI, and upgrade)
- SSO: SM plugin read autologin config from wrong app namespace (`snappymail` → `x2mail`), breaking fresh installs

## [0.4.8] — 2026-03-23

### Fixed
- Fix unreadable error messages in Compose view (dark red text on dark background in NC dark theme)
- Position compose error tooltip inline in toolbar row instead of overlapping fields

## [0.4.7] — 2026-03-23

### Fixed
- Fix double-slash in `app_path` when `overwritewebroot=/` (normalize `getAppWebPath()` output in InstallStep, Setup, AdminSettings, FetchController)

## [0.4.6] — 2026-03-22

### Security
- Fix ContactsSync password leaked to browser in AppData JSON response
- Fix path traversal via unvalidated domain in DomainConfigService
- Fix SM plugin file/folder paths without directory traversal check
- Fix Setup Wizard missing hostname validation and error message redaction
- Fix `app_path` missing `..` traversal check in admin settings
- Fix IMAP connection failure permanently wiping stored credentials
- Add email format validation to personal settings
- Restrict log file permissions to 0600 on creation

## [0.4.5] — 2026-03-22

### Added
- **PHPStan Level 7 static analysis** — catches type errors, undefined methods, wrong argument types at build time
- CI pipeline with automated lint, build, validate, and deploy

### Fixed
- Removed 3 unused injected properties (`FetchController::$appManager`, `Provider::$l10n`, `AdminSection::$l`)
- Removed redundant runtime checks (`is_callable`, `method_exists`) that always evaluate to true
- Fixed SnappyMail API calls: `bUseSortIfSupported` → `bUseSort`, `MailClient::IsLoggined()` → `ImapClient()->IsLoggined()`
- Added type guards for `file_get_contents()` return values
- Fixed private method access pattern in `SnappyMailHelper`
- Added missing return type declarations and PHPDoc type annotations across 20 files

## [0.4.4] — 2026-03-19

### Fixed
- Skip SM bootstrap for app-password/token logins (bots, DAV clients, API)
- Graceful degradation when app/index.php is temporarily unreadable
- Guard against APP_DATA_FOLDER_PATH redefinition on retry after partial bootstrap

## [0.4.3] — 2026-03-19

### Fixed
- Setup and InstallStep now set title and loading_description to "X2Mail"
- Restored original minified app.min.js — no more broken JS from unminified overwrites
- Regenerated compressed .gz/.br static files to match modified JS/CSS
- Reverted PageController mailto handling to upstream SM ServiceMailto flow

## [0.4.2] — 2026-03-19

### Fixed
- Contact detail view now shows name and email for read-only (system) contacts
- Contact CRUD uses CardDAV backend directly — proper vCard N property support
- Numeric contact IDs for SnappyMail JS compatibility
- Contact tab restructured to match business tab layout (label + span + input)
- German labels corrected: "Vorname:" / "Nachname:" (singular + colon)
- Read-only contact spans visible via CSS specificity fix
- Empty name fields (middle name, prefix, suffix) hidden for read-only contacts

## [0.4.1] — 2026-03-19

### Fixed
- Bundled nextcloud plugin now syncs to SM data directory on every app enable/upgrade
- Contacts from all address books (including system/users) are now visible, system contacts marked read-only
- Contacts without email address are hidden from the contacts list
- `IManager::delete()` type handling fixed for NC CardDAV backend compatibility
- Search queries capped at 10,000 results for safety in large address books
- Double-slash in `app_path` when `overwritewebroot = /` prevented

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
