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

if [ "$WEB_MODE" = "static" ]; then
    code="$(fetch /app/)"
    [ "$code" = "200" ] || { echo "FAIL: /app/ (web shell) returned $code"; exit 1; }
    grep -q 'Bakery Ops' /tmp/smoke.body || { echo "FAIL: web shell did not render"; exit 1; }
    echo "PASS: web shell served at /app/"
fi
echo "smoke OK: $BASE"
