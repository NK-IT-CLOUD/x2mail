# Release Process

## Versioning

Semantic Versioning: `MAJOR.MINOR.PATCH`

- **MAJOR** — Breaking changes (NC version drops, config format changes)
- **MINOR** — New features (new occ commands, new auth methods)
- **PATCH** — Bug fixes, security patches, SM core updates

## Version Locations

Update ALL of these for every release:

1. `appinfo/info.xml` → `<version>X.Y.Z</version>`
2. `CHANGELOG.md` → Move [Unreleased] items to new version section
3. Git tag: `git tag -a vX.Y.Z -m "vX.Y.Z: summary"`

## Release Checklist

```bash
# 1. Update version
vim appinfo/info.xml          # bump version
vim CHANGELOG.md              # move unreleased → new version

# 2. Commit
git add appinfo/info.xml CHANGELOG.md
git commit -m "release: vX.Y.Z"

# 3. Tag
git tag -a vX.Y.Z -m "vX.Y.Z: one-line summary"

# 4. Build
make clean && make build
# → build/x2mail.tar.gz

# 5. Test on staging NC
# Deploy, enable, test login flow

# 6. Push
git push origin main --tags

# 7. GitHub Release
# Create release from tag, attach x2mail.tar.gz

# 8. NC App Store (future)
# Sign with NC certificate, submit via app store API
```

## SM Core Updates

SM Core is a light fork. When updating:

```bash
# 1. Update SM_VERSION if changing the bundled core
echo "2.39.0" > SM_VERSION

# 2. Rebuild core
make update-core

# 3. Verify
# - Check plugins/nextcloud/index.php for namespace patch
# - Check PHP compatibility
# - Test IMAP/OIDC login

# 4. Document in CHANGELOG
# Under [Unreleased]:
# ### Changed
# - SM Core updated to v2.39.0 (security fixes, PHP 8.4 compat)

# 5. Release as PATCH version bump
```

## NC Version Compatibility

When Nextcloud releases a new major version:

1. Test X2Mail on the new NC version
2. Update `appinfo/info.xml` → `max-version`
3. Fix any deprecated API usage
4. Release as MINOR version bump
5. Document in CHANGELOG under "### Changed"
