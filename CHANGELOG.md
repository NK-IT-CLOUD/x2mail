# Changelog

All notable changes to X2Mail will be documented in this file.

Format: [Semantic Versioning](https://semver.org/) — MAJOR.MINOR.PATCH

## [Unreleased]

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
