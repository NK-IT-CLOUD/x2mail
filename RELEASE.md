# Release Process

## Versioning

X2Mail follows [Semantic Versioning](https://semver.org/): `MAJOR.MINOR.PATCH`

- **MAJOR** — Breaking changes
- **MINOR** — New features
- **PATCH** — Bug fixes, security patches

## Installation

Download the latest release from [GitHub Releases](https://github.com/NK-IT-CLOUD/x2mail/releases) or install via the [Nextcloud App Store](https://apps.nextcloud.com/apps/x2mail).

## Upgrade

Nextcloud handles upgrades automatically when a new version is published to the App Store. For manual upgrades, replace the app directory and run:

```bash
occ upgrade
occ x2mail:status
```
