#!/usr/bin/env bash
#
# X2Mail Release Script
#
# Full release flow: build → sign → validate → GitHub Release → NC App Store
#
# Usage:
#   ./scripts/release.sh                # Full release (build + GH + NC Store)
#   ./scripts/release.sh --github-only  # Skip NC App Store upload
#   ./scripts/release.sh --dry-run      # Show what would happen, no actions
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
cd "$APP_DIR"

VERSION=$(grep -oP '<version>\K[^<]+' appinfo/info.xml)
APP_NAME="x2mail"
TARBALL="build/${APP_NAME}-${VERSION}.tar.gz"
SIG_FILE="build/${APP_NAME}-${VERSION}.tar.gz.sig"
CERT_DIR="${HOME}/.nextcloud/certificates"
GH_REPO="NK-IT-CLOUD/x2mail"

# NC App Store
NC_STORE_API="https://apps.nextcloud.com/api/v1"
NC_STORE_TOKEN="${NC_STORE_TOKEN:-ed5007916d7340a694d650f7c44cca30392264e5}"

DRY_RUN=false
SKIP_NC_STORE=false

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=true ;;
        --github-only) SKIP_NC_STORE=true ;;
        --help|-h)
            echo "Usage: $0 [--github-only|--dry-run]"
            echo ""
            echo "  --github-only   Skip NC App Store upload"
            echo "  --dry-run       Show plan without executing"
            exit 0 ;;
    esac
done

echo "=== X2Mail Release v${VERSION} ==="
echo ""

# ── Pre-flight checks ────────────────────────────────────────────

echo "--- Pre-flight ---"

# Git clean?
if [ -n "$(git status --porcelain)" ]; then
    echo "ERROR: Working tree not clean. Commit or stash changes first."
    git status --short
    exit 1
fi

# On main?
BRANCH=$(git branch --show-current)
if [ "$BRANCH" != "main" ]; then
    echo "ERROR: Must be on 'main' branch (currently on '${BRANCH}')"
    exit 1
fi

# Tag exists?
if git tag -l "v${VERSION}" | grep -q "v${VERSION}"; then
    echo "WARNING: Tag v${VERSION} already exists"
    TAG_EXISTS=true
else
    TAG_EXISTS=false
fi

# Signing key?
if [ ! -f "${CERT_DIR}/${APP_NAME}.key" ]; then
    echo "ERROR: Signing key not found: ${CERT_DIR}/${APP_NAME}.key"
    exit 1
fi

# gh CLI?
if ! command -v gh &>/dev/null; then
    echo "ERROR: gh CLI not found. Install: https://cli.github.com/"
    exit 1
fi

# NC Store cert? (warning only)
if [ ! -f "${CERT_DIR}/${APP_NAME}.crt" ]; then
    echo "NOTE: NC App Store certificate not yet available (${CERT_DIR}/${APP_NAME}.crt)"
    echo "      GitHub Release will work, NC Store upload will be skipped."
    SKIP_NC_STORE=true
fi

# CHANGELOG check
if ! grep -q "## \[${VERSION}\]" CHANGELOG.md; then
    echo "ERROR: CHANGELOG.md has no entry for [${VERSION}]"
    exit 1
fi

echo "OK: All pre-flight checks passed"
echo ""

if [ "$DRY_RUN" = true ]; then
    echo "--- Dry Run Plan ---"
    echo "  1. make release (build + sign + validate)"
    echo "  2. git tag v${VERSION} ($([ "$TAG_EXISTS" = true ] && echo "already exists" || echo "new"))"
    echo "  3. git push origin main --tags"
    echo "  4. gh release create v${VERSION} with ${APP_NAME}-${VERSION}.tar.gz"
    [ "$SKIP_NC_STORE" = false ] && echo "  5. POST ${NC_STORE_API}/apps/releases (NC App Store)"
    echo ""
    echo "Dry run complete. Run without --dry-run to execute."
    exit 0
fi

# ── Build + Sign + Validate ──────────────────────────────────────

echo "--- Build ---"
make release

echo ""

# ── Git Tag ──────────────────────────────────────────────────────

echo "--- Tag ---"
if [ "$TAG_EXISTS" = false ]; then
    CHANGELOG_SECTION=$(sed -n "/## \[${VERSION}\]/,/## \[/p" CHANGELOG.md | head -n -1)
    git tag -a "v${VERSION}" -m "v${VERSION}

${CHANGELOG_SECTION}"
    echo "Created tag v${VERSION}"
else
    echo "Tag v${VERSION} already exists, skipping"
fi

# ── Push ─────────────────────────────────────────────────────────

echo "--- Push ---"
git push origin main --tags

echo ""

# ── GitHub Release ───────────────────────────────────────────────

echo "--- GitHub Release ---"
if gh release view "v${VERSION}" --repo "${GH_REPO}" &>/dev/null; then
    echo "Release v${VERSION} already exists, uploading asset..."
    gh release upload "v${VERSION}" "${TARBALL}" --repo "${GH_REPO}" --clobber
else
    CHANGELOG_SECTION=$(sed -n "/## \[${VERSION}\]/,/## \[/p" CHANGELOG.md | head -n -1 | tail -n +2)
    gh release create "v${VERSION}" "${TARBALL}" \
        --repo "${GH_REPO}" \
        --title "v${VERSION}" \
        --notes "${CHANGELOG_SECTION}"
fi
echo "OK: https://github.com/${GH_REPO}/releases/tag/v${VERSION}"

DOWNLOAD_URL="https://github.com/${GH_REPO}/releases/download/v${VERSION}/${APP_NAME}-${VERSION}.tar.gz"

echo ""

# ── NC App Store ─────────────────────────────────────────────────

if [ "$SKIP_NC_STORE" = true ]; then
    echo "--- NC App Store: SKIPPED ---"
    echo ""
    echo "To publish manually later:"
    echo "  Download URL: ${DOWNLOAD_URL}"
    echo "  Signature:    $(cat "${SIG_FILE}")"
else
    echo "--- NC App Store ---"
    SIGNATURE=$(cat "${SIG_FILE}")

    RESPONSE=$(curl -s -w "\n%{http_code}" -X POST \
        "${NC_STORE_API}/apps/releases" \
        -H "Authorization: Token ${NC_STORE_TOKEN}" \
        -H "Content-Type: application/json" \
        -d "{\"download\":\"${DOWNLOAD_URL}\",\"signature\":\"${SIGNATURE}\",\"nightly\":false}")

    HTTP_CODE=$(echo "$RESPONSE" | tail -1)
    BODY=$(echo "$RESPONSE" | head -n -1)

    if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "201" ]; then
        echo "OK: Published to NC App Store (HTTP ${HTTP_CODE})"
    else
        echo "ERROR: NC App Store returned HTTP ${HTTP_CODE}"
        echo "$BODY"
        echo ""
        echo "Manual upload:"
        echo "  URL: https://apps.nextcloud.com/developer/apps/releases/new"
        echo "  Download: ${DOWNLOAD_URL}"
        echo "  Signature: ${SIGNATURE}"
        exit 1
    fi
fi

echo ""
echo "=== Release v${VERSION} complete ==="
