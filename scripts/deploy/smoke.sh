#!/usr/bin/env bash
# Runs on the CI runner against the live URL after a release.
# Usage: smoke.sh https://bakery-ops.orcamind.dev [web-mode: skip|static]
set -euo pipefail
BASE="${1:?base URL required}"
WEB_MODE="${2:-skip}"
fetch() { curl -sS -o /tmp/smoke.body -D /tmp/smoke.headers -w '%{http_code}' -H 'Cache-Control: no-cache' "$BASE$1?smoke=$(date +%s%N)"; }

ok=0
for attempt in $(seq 1 10); do
    code="$(fetch /api/v1/health || true)"
    if [ "$code" = "200" ]; then ok=1; break; fi
    echo "attempt $attempt: /api/v1/health returned $code; retrying"; sleep 6
done
[ "$ok" = 1 ] || { echo "FAIL: /api/v1/health never returned 200"; head -c 1500 /tmp/smoke.body; exit 1; }
grep -q '"status":"ok"' /tmp/smoke.body && grep -q '"database":"ok"' /tmp/smoke.body \
    || { echo "FAIL: health body is not status ok / database ok"; cat /tmp/smoke.body; exit 1; }
echo "PASS: /api/v1/health 200, database ok"

code="$(fetch /up)"
[ "$code" = "200" ] || { echo "FAIL: /up returned $code"; exit 1; }
echo "PASS: /up 200"

code="$(fetch /api/v1/this-route-does-not-exist)"
[ "$code" = "404" ] || { echo "FAIL: unknown API route returned $code instead of 404"; exit 1; }
grep -qi '^content-type: application/json' /tmp/smoke.headers || { echo "FAIL: API 404 is not JSON"; exit 1; }
echo "PASS: unknown API routes answer JSON 404"

code="$(fetch /)"
if [ "$code" = "302" ] && grep -qi '^location: */app/' /tmp/smoke.headers; then
    echo "PASS: / redirects to /app/"
else
    echo "WARN: / answered $code without a redirect to /app/"
fi

if [ "$WEB_MODE" = "static" ]; then
    # The web shell: a static export LiteSpeed serves from public/app/ (ADR 0006, public/.htaccess).
    code="$(fetch /app/)"
    [ "$code" = "200" ] || { echo "FAIL: /app/ (start page) returned $code"; exit 1; }
    grep -q 'Bakery Ops' /tmp/smoke.body || { echo "FAIL: /app/ start page did not render"; exit 1; }
    echo "PASS: /app/ start page"
    for locale in en am; do
        code="$(fetch "/app/$locale/")"
        [ "$code" = "200" ] || { echo "FAIL: /app/$locale/ returned $code"; exit 1; }
        grep -q "<html lang=\"$locale\"" /tmp/smoke.body || { echo "FAIL: /app/$locale/ is not <html lang=\"$locale\">"; head -c 600 /tmp/smoke.body; exit 1; }
        echo "PASS: /app/$locale/ 200 with <html lang=\"$locale\">"
    done
    code="$(fetch /app/en/login/)"
    [ "$code" = "200" ] || { echo "FAIL: /app/en/login/ returned $code"; exit 1; }
    echo "PASS: /app/en/login/ 200"

    # Non-fatal: the fallbacks and cache headers in public/.htaccess (a miss here is not worth a rollback)
    code="$(fetch /app/am/no-such-page/)"
    if [ "$code" = "200" ] && grep -q '<html lang="am"' /tmp/smoke.body; then echo "PASS: unknown /app/am/ page -> Amharic not-found page"
    else echo "WARN: unknown /app/am/ page answered $code"; fi
    asset="$(curl -sS "$BASE/app/en/" | grep -o '/app/_next/static/[^"]*\.css' | head -n 1 || true)"
    if [ -n "$asset" ]; then
        code="$(fetch "$asset")"
        if [ "$code" = "200" ] && grep -qi '^cache-control:.*immutable' /tmp/smoke.headers; then echo "PASS: $asset is immutable"
        else echo "WARN: $asset answered $code without an immutable Cache-Control"; fi
    fi
fi
echo "smoke OK: $BASE"
