#!/usr/bin/env bash
#
# Bump X2Mail version across all files.
#
# Usage: ./scripts/bump-version.sh 0.4.0
#
set -euo pipefail

NEW_VERSION="${1:-}"
if [ -z "$NEW_VERSION" ]; then
    echo "Usage: $0 <new-version>"
    echo "  e.g. $0 0.4.0"
    exit 1
fi

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

OLD_VERSION=$(grep -oP '<version>\K[^<]+' appinfo/info.xml)
TODAY=$(date +%Y-%m-%d)

echo "Bumping X2Mail: ${OLD_VERSION} → ${NEW_VERSION}"
echo ""

# 1. appinfo/info.xml (source of truth)
sed -i "s|<version>${OLD_VERSION}</version>|<version>${NEW_VERSION}</version>|" appinfo/info.xml
echo "✓ appinfo/info.xml"

# 2. CHANGELOG.md — move [Unreleased] to new version
if grep -q "## \[Unreleased\]" CHANGELOG.md; then
    sed -i "s|## \[Unreleased\]|## [Unreleased]\n\n## [${NEW_VERSION}] — ${TODAY}|" CHANGELOG.md
    echo "✓ CHANGELOG.md — added [${NEW_VERSION}] section"
else
    echo "⚠ CHANGELOG.md — no [Unreleased] section found, edit manually"
fi

echo ""
echo "Version bumped to ${NEW_VERSION}."
echo ""
echo "Next steps:"
echo "  1. Edit CHANGELOG.md — fill in changes under [${NEW_VERSION}]"
echo "  2. git add -A && git commit -m 'release: v${NEW_VERSION}'"
echo "  3. ./scripts/release.sh"
