# Release process

## Versioning

X2Mail follows [Semantic Versioning](https://semver.org/): `MAJOR.MINOR.PATCH`

- **MAJOR**: breaking changes
- **MINOR**: new features
- **PATCH**: bug fixes and security patches

## Installation

Download the latest release from [GitHub Releases](https://github.com/NK-IT-CLOUD/x2mail/releases) or install via the [Nextcloud App Store](https://apps.nextcloud.com/apps/x2mail).

## Upgrade

Nextcloud installs new versions automatically once they are published to the App Store. To upgrade by hand, replace the app directory and run:

```bash
occ upgrade
occ x2mail:status
```
