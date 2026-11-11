#!/usr/bin/env bash
# Make Chromium ready for Playwright without depending on Ubuntu's apt mirror when it is not needed.
#
# `playwright install --with-deps` / `install-deps` always runs `apt-get update` + `apt-get install`, which
# takes a minute or two per job and fails the job outright when the runner's mirror (azure.archive.ubuntu.com)
# cannot be resolved (2026-11-11: every e2e job of PR #136 failed three times in that step, not in a test).
# GitHub's ubuntu-24.04 image already carries the libraries Chromium needs (it ships Google Chrome), so:
#   1. download the browser from Playwright's CDN when it is not in the cache (no apt);
#   2. launch it once; if it starts, the libraries are there and apt is skipped;
#   3. only if it does not start, install the libraries through apt, retrying, with the main Ubuntu
#      archive as a fallback mirror.
# Run from apps/web.
set -euo pipefail

probe() {
  node -e "require('@playwright/test').chromium.launch().then(b => b.close()).then(() => process.exit(0), e => { console.error(e.message.split('\n')[0]); process.exit(1); })"
}

pnpm exec playwright install chromium

if probe; then
  echo "Chromium starts with the runner's own libraries; apt skipped."
  exit 0
fi

echo "Chromium does not start yet; installing its libraries through apt."
for attempt in 1 2 3; do
  if pnpm exec playwright install-deps chromium; then
    probe && exit 0
  fi
  if [ "$attempt" -eq 1 ]; then
    echo "::warning::apt failed on the runner's mirror; switching to archive.ubuntu.com"
    sudo sed -i 's#http://azure.archive.ubuntu.com/ubuntu#http://archive.ubuntu.com/ubuntu#g' \
      /etc/apt/sources.list /etc/apt/sources.list.d/*.list /etc/apt/sources.list.d/*.sources 2>/dev/null || true
  fi
  sleep $((attempt * 20))
done
echo "::error::Chromium's libraries could not be installed (apt mirror unreachable?)"
exit 1
