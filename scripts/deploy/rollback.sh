#!/usr/bin/env bash
# Runs ON THE SERVER when the smoke test fails (piped over SSH by deploy.yml).
# Restores the code snapshot taken by pre-release.sh. Migrations are NOT reversed; they must be
# backwards compatible (expand, then contract).
# Env: APP_DIR, PHP_BIN, SNAP_DIR
set -euo pipefail
: "${APP_DIR:?APP_DIR is required}"
PHP_BIN="${PHP_BIN:-php}"
SNAP_DIR="${SNAP_DIR:-$HOME/releases/bakery-ops}"
SNAP="$SNAP_DIR/prev.tar.gz"

if [ ! -f "$SNAP" ]; then
    echo "no snapshot at $SNAP; nothing to roll back to"
    exit 1
fi
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
tar -xzf "$SNAP" -C "$work"
excludes="$work/scripts/deploy/rsync-excludes.txt"
[ -f "$excludes" ] || excludes="$APP_DIR/scripts/deploy/rsync-excludes.txt"
[ -f "$excludes" ] || { echo "no rsync exclude list found; refusing to sync with --delete"; exit 1; }
rsync -a --delete --exclude-from="$excludes" "$work/" "$APP_DIR/"
cd "$APP_DIR"
# rsync never touches public/.htaccess (cPanel keeps its PHP handler there), yet a release may have changed Laravel's
# rules in it (the /app web shell rules did): rebuild it from the restored release's copy, exactly as release.sh does.
if [ -f scripts/deploy/public.htaccess ]; then
    blocks="$(awk '/BEGIN cPanel-generated/{k=1} k{print} /END cPanel-generated/{k=0}' public/.htaccess 2>/dev/null || true)"
    {
        if [ -n "$blocks" ]; then printf '%s\n\n' "$blocks"; fi
        cat scripts/deploy/public.htaccess
    } > public/.htaccess.new
    mv public/.htaccess.new public/.htaccess
    echo "public/.htaccess restored from the previous release"
fi
rm -f bootstrap/cache/*.php
"$PHP_BIN" artisan optimize
"$PHP_BIN" artisan up || true
chmod 711 "$APP_DIR"; chmod 755 "$APP_DIR/public"
echo "rolled back to $(cat REVISION 2>/dev/null || echo 'unknown revision')"
