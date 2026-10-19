#!/usr/bin/env bash
# Runs ON THE SERVER before new code is copied (piped over SSH by deploy.yml).
# Puts the current release into maintenance mode and snapshots it for rollback.
# Env: APP_DIR (site folder), PHP_BIN (php 8.3 CLI), SNAP_DIR (snapshot folder)
set -euo pipefail
: "${APP_DIR:?APP_DIR is required}"
PHP_BIN="${PHP_BIN:-php}"
SNAP_DIR="${SNAP_DIR:-$HOME/releases/bakery-ops}"

if [ ! -f "$APP_DIR/.env" ]; then
    echo "ERROR: $APP_DIR/.env is missing. Provision the server first (docs/runbook/server.md)."
    exit 1
fi

mkdir -p "$SNAP_DIR"
if [ -f "$APP_DIR/artisan" ] && [ -f "$APP_DIR/vendor/autoload.php" ]; then
    (cd "$APP_DIR" && "$PHP_BIN" artisan down --render="errors::503" --retry=15) || true
    tar -czf "$SNAP_DIR/prev.tar.gz.tmp" -C "$APP_DIR" \
        --exclude=./storage --exclude=./.env --exclude='./.env.*' \
        --exclude=./public/storage --exclude=./bootstrap/cache .
    mv "$SNAP_DIR/prev.tar.gz.tmp" "$SNAP_DIR/prev.tar.gz"
    echo "snapshot: $SNAP_DIR/prev.tar.gz ($(cat "$APP_DIR/REVISION" 2>/dev/null || echo 'unknown revision'))"
else
    echo "first deploy: nothing to snapshot"
fi
