#!/usr/bin/env bash
# Runs ON THE SERVER after new code is copied (piped over SSH by .github/workflows/deploy.yml).
# Env: APP_DIR (site folder = the Laravel app, docroot APP_DIR/public), PHP_BIN (php 8.3 CLI)
set -euo pipefail
: "${APP_DIR:?APP_DIR is required}"
PHP_BIN="${PHP_BIN:-php}"
cd "$APP_DIR"

if [ ! -f .env ]; then
    echo "ERROR: $APP_DIR/.env is missing. Provision the server first (docs/runbook/server.md)."
    exit 1
fi
if [ ! -f vendor/autoload.php ]; then
    echo "ERROR: vendor/ is missing; the release was not copied."
    exit 1
fi

# Laravel prefers .env.{APP_ENV} over .env when APP_ENV is in the process environment;
# a stray copy would silently shadow the real configuration.
for f in .env.production .env.staging .env.local .env.testing .env.ci; do
    if [ -e "$f" ]; then mv "$f" "$f.unused.$(date +%s)"; echo "moved aside $f"; fi
done

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/public storage/app/private bootstrap/cache
# Stale compiled caches can reference dev-only providers the --no-dev vendor lacks.
rm -f bootstrap/cache/*.php

# public/.htaccess is never copied by rsync: cPanel keeps its PHP version handler there.
# Rebuild it as cPanel's own blocks followed by Laravel's rules.
if [ -f scripts/deploy/public.htaccess ]; then
    blocks="$(awk '/BEGIN cPanel-generated/{k=1} k{print} /END cPanel-generated/{k=0}' public/.htaccess 2>/dev/null || true)"
    {
        if [ -n "$blocks" ]; then printf '%s\n\n' "$blocks"; fi
        cat scripts/deploy/public.htaccess
    } > public/.htaccess.new
    mv public/.htaccess.new public/.htaccess
    echo "public/.htaccess rebuilt ($(printf '%s' "$blocks" | grep -c 'BEGIN cPanel-generated') cPanel block(s) kept)"
fi

"$PHP_BIN" artisan down --render="errors::503" --retry=15 || true
trap '"$PHP_BIN" artisan up >/dev/null 2>&1 || true' EXIT

"$PHP_BIN" artisan migrate --force --no-interaction
"$PHP_BIN" artisan optimize
[ -e public/storage ] || "$PHP_BIN" artisan storage:link
"$PHP_BIN" artisan queue:restart || true
# step 4.3e: register the receipts' Amharic font in storage/fonts now rather than on the first receipt; never fatal (a receipt does it itself)
"$PHP_BIN" artisan receipts:fonts || echo "receipts:fonts failed: the first receipt registers the font itself (docs/runbook/server.md, Receipts)"

# rsync mirrors the runner's modes; LiteSpeed needs a traversable site folder.
chmod 711 "$APP_DIR"
chmod 755 "$APP_DIR/public"

echo "release OK: $(cat REVISION 2>/dev/null || echo 'unknown revision')"
