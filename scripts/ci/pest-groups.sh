#!/usr/bin/env bash
# Run Pest for a comma-separated list of groups. A group with no tests yet is a failure by default
# (a module job that runs nothing proves nothing); pass --allow-empty for consumer-contract groups,
# which legitimately do not exist before their consumer's phase.
set -uo pipefail
groups="$1"; allow_empty="${2:-}"
cd "$(dirname "$0")/../../apps/api"
args=""; IFS="," ; for g in $groups; do args="$args --group=$g"; done; unset IFS
out=$(vendor/bin/pest $args --colors=never 2>&1); code=$?
echo "$out" | tail -40
if echo "$out" | grep -Eq "No tests (found|executed)"; then
  if [ "$allow_empty" = "--allow-empty" ]; then echo "(no tests for groups '$groups' yet: allowed)"; exit 0; fi
  echo "::error::no tests tagged '$groups'. A module job that runs nothing proves nothing."; exit 1
fi
exit $code
