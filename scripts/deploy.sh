#!/usr/bin/env bash
#
# Build and deploy X2Mail to the Nextcloud Docker container.
#
# Usage:
#   ./scripts/deploy.sh              # Build + deploy
#   ./scripts/deploy.sh --build-only # Only create tarball
#   ./scripts/deploy.sh --skip-build # Deploy existing tarball
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
VERSION=$(grep -oP '<version>\K[^<]+' "$APP_DIR/appinfo/info.xml")
TARBALL="$APP_DIR/build/x2mail-${VERSION}.tar.gz"

NC_HOST="ct-nextcloud"
NC_CONTAINER="nextcloud"
NC_APP_PATH="/var/www/html/custom_apps"

BUILD=true
DEPLOY=true

for arg in "$@"; do
    case "$arg" in
        --build-only) DEPLOY=false ;;
        --skip-build) BUILD=false ;;
        --help|-h)
            echo "Usage: $0 [--build-only|--skip-build]"
            exit 0
            ;;
    esac
done

# --- Build ---
if [ "$BUILD" = true ]; then
    echo "==> Building x2mail-${VERSION}.tar.gz ..."
    cd "$APP_DIR"
    make build
fi

if [ "$DEPLOY" = false ]; then
    echo "Done. Tarball: $TARBALL"
    exit 0
fi

# --- Pre-flight ---
if [ ! -f "$TARBALL" ]; then
    echo "ERROR: $TARBALL not found. Run without --skip-build first."
    exit 1
fi

# --- Deploy ---
echo "==> Copying to $NC_HOST ..."
scp "$TARBALL" "${NC_HOST}:/tmp/"

echo "==> Installing into container '$NC_CONTAINER' ..."
ssh "$NC_HOST" "
    docker cp /tmp/x2mail-${VERSION}.tar.gz ${NC_CONTAINER}:/tmp/ && \
    docker exec ${NC_CONTAINER} sh -c '
        rm -rf ${NC_APP_PATH}/x2mail && \
        cd ${NC_APP_PATH} && \
        tar xzf /tmp/x2mail-${VERSION}.tar.gz && \
        chown -R www-data:www-data ${NC_APP_PATH}/x2mail && \
        rm /tmp/x2mail-${VERSION}.tar.gz
    ' && \
    rm /tmp/x2mail-${VERSION}.tar.gz
"

# --- Verify ---
echo "==> Verifying ..."
STATUS=$(ssh "$NC_HOST" "docker exec -u www-data ${NC_CONTAINER} php occ app:list 2>/dev/null" | grep -A0 'x2mail' || true)
if echo "$STATUS" | grep -q "$VERSION"; then
    echo "OK: x2mail $VERSION deployed and active."
else
    echo "WARNING: Could not verify. Check manually:"
    echo "  ssh $NC_HOST docker exec -u www-data $NC_CONTAINER php occ app:list | grep x2mail"
fi

echo ""
echo "==> OCC upgrade (safe, applies pending migrations) ..."
ssh "$NC_HOST" "docker exec -u www-data ${NC_CONTAINER} php occ upgrade" 2>&1 | tail -3

echo ""
echo "==> Restarting PHP-FPM (clear OPcache) ..."
ssh "$NC_HOST" "docker restart ${NC_CONTAINER}"
sleep 3

echo ""
echo "Done."
