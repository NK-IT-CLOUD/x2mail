#!/usr/bin/env bash
#
# X2Mail Release Script (Gitea-first)
#
# Tags and pushes to Gitea origin. CI release.yaml handles:
#   - Signing, GitHub orphan push, GitHub Release, NC App Store
#
# Usage:
#   ./scripts/release.sh                # Tag + push to Gitea (triggers CI)
#   ./scripts/release.sh --github-only  # Manual GitHub orphan + release (fallback)
#   ./scripts/release.sh --dry-run      # Show what would happen, no actions
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
cd "$APP_DIR"

VERSION=$(grep -oP '<version>\K[^<]+' appinfo/info.xml)
APP_NAME="x2mail"
TAG="v${VERSION}"
GH_REPO="NK-IT-CLOUD/x2mail"

# Files for GitHub orphan push (--github-only fallback)
GH_FILES=".gitignore README.md LICENSE CHANGELOG.md appinfo/ css/ img/ js/ l10n/ lib/ templates/ app/"

DRY_RUN=false
GITHUB_ONLY=false

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=true ;;
        --github-only) GITHUB_ONLY=true ;;
        --help|-h)
            echo "Usage: $0 [--dry-run] [--github-only]"
            echo ""
            echo "  --dry-run       Show plan without executing"
            echo "  --github-only   Manual GitHub orphan + release (fallback, skips Gitea)"
            exit 0 ;;
        *) echo "Unknown arg: $arg"; exit 1 ;;
    esac
done

echo "=== X2Mail Release ${VERSION} ==="
echo ""

# ── Pre-flight ────────────────────────────────────────────────

echo "--- Pre-flight ---"

if [ -n "$(git status --porcelain)" ]; then
    echo "ERROR: Working tree not clean. Commit or stash first."
    git status --short
    exit 1
fi

BRANCH=$(git branch --show-current)
if [ "$BRANCH" != "main" ]; then
    echo "ERROR: Must be on 'main' (currently '${BRANCH}')"
    exit 1
fi

if git tag -l "${TAG}" | grep -q "${TAG}"; then
    echo "ERROR: Tag ${TAG} already exists"
    exit 1
fi

if ! grep -q "## \[${VERSION}\]" CHANGELOG.md; then
    echo "ERROR: CHANGELOG.md has no entry for [${VERSION}]"
    exit 1
fi

echo "OK: Pre-flight passed"
echo ""

# ── Dry run ───────────────────────────────────────────────────

if [ "$DRY_RUN" = true ]; then
    echo "--- Dry Run ---"
    if [ "$GITHUB_ONLY" = true ]; then
        echo "  1. make build + validate"
        echo "  2. git orphan push to github/main"
        echo "  3. gh release create ${TAG}"
    else
        echo "  1. make build + validate (local sanity)"
        echo "  2. git tag ${TAG}"
        echo "  3. git push origin main --tags → Gitea"
        echo "  4. CI handles: sign, GitHub orphan, GH release, NC App Store"
    fi
    echo ""
    echo "Dry run complete."
    exit 0
fi

# ── GitHub-only fallback ──────────────────────────────────────

if [ "$GITHUB_ONLY" = true ]; then
    echo "--- GitHub-only (manual fallback) ---"

    TARBALL="build/${APP_NAME}-${VERSION}.tar.gz"
    if [ ! -f "$TARBALL" ]; then
        echo "Building tarball..."
        make clean && make build
    fi
    make validate

    echo "Incremental push to GitHub..."
    git fetch github main || true
    git checkout -B gh-release-tmp github/main 2>/dev/null || git checkout --orphan gh-release-tmp
    git rm -rf .
    git checkout main -- ${GH_FILES}
    git add -A
    git commit -m "${APP_NAME} ${TAG}" --allow-empty
    git push github gh-release-tmp:main --force
    git tag -f "${TAG}"
    git push github "${TAG}" --force
    git checkout -f main
    git branch -D gh-release-tmp

    echo "Creating GitHub release..."
    CHANGELOG_SECTION=$(sed -n "/## \[${VERSION}\]/,/## \[/p" CHANGELOG.md | head -n -1 | tail -n +2)
    gh release create "${TAG}" "${TARBALL}" \
        --repo "${GH_REPO}" \
        --title "${TAG}" \
        --notes "${CHANGELOG_SECTION}"

    echo ""
    echo "Done: https://github.com/${GH_REPO}/releases/tag/${TAG}"
    exit 0
fi

# ── Normal flow: Gitea-first ──────────────────────────────────

echo "--- Build (local sanity check) ---"
make clean && make build
make validate
echo ""

echo "--- Tag ---"
CHANGELOG_SECTION=$(sed -n "/## \[${VERSION}\]/,/## \[/p" CHANGELOG.md | head -n -1)
git tag -a "${TAG}" -m "${TAG}

${CHANGELOG_SECTION}"
echo "Created tag ${TAG}"
echo ""

echo "--- Push to Gitea ---"
git push origin main
git push origin "${TAG}"
echo ""

echo "=== Release ${VERSION} pushed ==="
echo "CI will now: sign → GitHub orphan → GH release → NC App Store"
echo "Monitor at: https://git.server.nk-it.cloud/nk-dev/x2mail/actions"
