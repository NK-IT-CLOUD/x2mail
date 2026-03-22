#!/usr/bin/env bash
#
# Download SnappyMail release, extract to app/, remove app/data/,
# and patch the nextcloud plugin namespace to OCA\X2Mail.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
SM_DIR="$APP_DIR/app"

# Get latest version if not specified
VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    echo "Fetching latest SnappyMail release version..."
    VERSION=$(curl -sL "https://api.github.com/repos/the-djmaze/snappymail/releases/latest" | grep -oP '"tag_name":\s*"v?\K[^"]+')
    if [ -z "$VERSION" ]; then
        echo "ERROR: Could not determine latest version"
        exit 1
    fi
fi

echo "SnappyMail version: $VERSION"

# Download
TARBALL_URL="https://github.com/the-djmaze/snappymail/releases/download/v${VERSION}/snappymail-${VERSION}.tar.gz"
TMPDIR=$(mktemp -d)
TARBALL="$TMPDIR/snappymail-${VERSION}.tar.gz"

echo "Downloading $TARBALL_URL ..."
curl -sL "$TARBALL_URL" -o "$TARBALL"

# Extract — SM tarball contains snappymail/ directory
echo "Extracting to $SM_DIR ..."
rm -rf "$SM_DIR"
mkdir -p "$SM_DIR"
tar -xzf "$TARBALL" -C "$SM_DIR" --strip-components=0

# If extracted into a subdirectory, move contents up
if [ -d "$SM_DIR/snappymail" ] && [ ! -f "$SM_DIR/index.php" ]; then
    # The tarball extracts with a top-level directory
    mv "$SM_DIR/snappymail"/* "$SM_DIR/" 2>/dev/null || true
    mv "$SM_DIR/snappymail"/.[!.]* "$SM_DIR/" 2>/dev/null || true
    rmdir "$SM_DIR/snappymail" 2>/dev/null || true
fi

# Remove app/data/ — SM would use it instead of NC's appdata
echo "Removing app/data/ ..."
rm -rf "$SM_DIR/data"

# Patch nextcloud plugin namespace: OCA\SnappyMail -> OCA\X2Mail
echo "Patching nextcloud plugin namespace..."
PLUGIN_DIR=$(find "$SM_DIR" -path "*/plugins/nextcloud/index.php" 2>/dev/null | head -1)
if [ -n "$PLUGIN_DIR" ]; then
    sed -i 's/OCA\\SnappyMail/OCA\\X2Mail/g' "$PLUGIN_DIR"
    echo "  Patched: $PLUGIN_DIR"
else
    echo "  WARNING: nextcloud plugin not found (will be installed on first run)"
fi

# Also patch any other PHP files in the nextcloud plugin directory
PLUGIN_BASE=$(dirname "$PLUGIN_DIR" 2>/dev/null || echo "")
if [ -n "$PLUGIN_BASE" ] && [ -d "$PLUGIN_BASE" ]; then
    find "$PLUGIN_BASE" -name "*.php" -exec sed -i 's/OCA\\SnappyMail/OCA\\X2Mail/g' {} +
    echo "  Patched all PHP files in nextcloud plugin"
fi

# Cleanup
rm -rf "$TMPDIR"

echo ""
echo "Done! SnappyMail $VERSION installed to $SM_DIR"
echo "  - app/data/ removed"
echo "  - Nextcloud plugin namespace patched to OCA\\X2Mail"
