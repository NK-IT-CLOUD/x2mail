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
- NC33 compatibility (DI PageController, SensitiveString)
- NC Unified Search integration
- SM Core v2.38.2 (light fork)

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
