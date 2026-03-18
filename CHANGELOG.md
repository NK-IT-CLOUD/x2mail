# Changelog

All notable changes to X2Mail will be documented in this file.

Format: [Semantic Versioning](https://semver.org/) — MAJOR.MINOR.PATCH

## [Unreleased]

### Added
- Initial project scaffold
- `occ x2mail:setup` — IMAP/SMTP/OIDC domain configuration
- `occ x2mail:status` — show configured domains and OIDC state
- `DomainConfigService` — programmatic SM domain config management
- Native `user_oidc` support (TokenBridgeListener + LoginBridgeListener)
- Native `oidc_login` support (AccessTokenUpdatedListener)
- `TokenRefreshMiddleware` — auto-refresh via user_oidc TokenService
- NC Unified Search integration
- SM Core v2.38.2 (light fork)
- Setup Wizard web UI in admin settings
- `scripts/deploy.sh` — build + deploy to NC Docker (FPM restart included)

### Fixed
- NC 33 / PHP 8.4 compatibility: PHP 8 attributes replace deprecated annotations
- Deprecated `\OC::$server->getSystemConfig()` replaced with `IConfig::getSystemValue()`
- DI autowiring replaces manual `$c->query()` controller registration
- Setup Wizard: API error feedback shown in UI instead of silent console.error
- Preflight check box contrast improved for light and dark themes
- Delete Domain button visible in both themes

### Changed
- Auth type selection unified: `oauthbearer` and `xoauth2` merged into single SSO option
- InstallStep removes SM default domains (gmail.com, hotmail.com, etc.) on install

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
