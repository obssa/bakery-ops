#!/usr/bin/env bash
# crontab-sync: keeps a crontab that several sites share equal to the per-site fragment files in ~/crontab.d, safely.
#
# Why it exists (docs/runbook/server.md, "The shared crontab"): on a CloudLinux/CageFS host, /usr/bin/crontab is a wrapper
# that, on EVERY call (a plain `crontab -l` too), copies the real crontab into the account's mirror file, runs the command on
# the mirror, then saves the mirror back as the real crontab. When two calls overlap, the copy step can fail with
# "BlockingIOError: Resource temporarily unavailable"; the wrapper hides the error, the mirror is left empty and the empty
# file is saved as the crontab. One site's deploy running `crontab -l ... | crontab -` back to back can therefore wipe every
# site's lines. This tool:
#   - checks by reading the mirror file directly (no crontab call at all) and stops there when it already matches;
#   - otherwise takes one lock shared by everyone, reads the crontab twice with a gap and trusts only two equal reads,
#     writes once, waits, reads twice again and retries until what is installed is what was meant;
#   - changes only the lines whose trailing tag (`# <tag>`) belongs to a fragment; every other line is kept, in order;
#   - restores the kept lines from the last good copy when it finds the crontab emptied.
#
# Fragments: ~/crontab.d/<name>.cron, one cron line per line, each ending in ` # <tag>` where <tag> is <name> or
# <name>-<something>; a tag may appear in one fragment only. Lines starting with # and blank lines are ignored.
#
# Usage: crontab-sync [--check] [--full] [--quiet]
#   (default)  repair if needed
#   --check    report only: exit 0 in sync, 3 when a repair is needed; never writes
#   --full     skip the mirror shortcut and read through crontab (catches edits made outside the mirror, e.g. cPanel's page)
#   --quiet    print nothing unless something changed or failed (cron)
# Exit: 0 ok, 1 failed (nothing or the previous state left), 2 bad usage or bad fragment, 3 (--check) repair needed.
#
# Environment (tests and odd hosts): CRONTAB_BIN, CRONTAB_MIRROR, CRONTAB_D, CRONTAB_SYNC_HOME, CRONTAB_GAP (seconds between
# crontab calls, default 3), CRONTAB_TRIES (default 5), CRONTAB_SYNC_LOCK_WAIT (default 120).
set -uo pipefail

check=0 full=0 quiet=0
for arg in "$@"; do
  case "$arg" in
    --check) check=1 ;;
    --full) full=1 ;;
    --quiet) quiet=1 ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "crontab-sync: unknown option $arg" >&2; exit 2 ;;
  esac
done

state="${CRONTAB_SYNC_HOME:-$HOME}"
dir="${CRONTAB_D:-$state/crontab.d}"
cron_bin="${CRONTAB_BIN:-crontab}"
mirror="${CRONTAB_MIRROR:-/var/spool/cron/$(id -un)}"
gap="${CRONTAB_GAP:-3}"
tries="${CRONTAB_TRIES:-5}"
lock_wait="${CRONTAB_SYNC_LOCK_WAIT:-120}"
log_file="$state/logs/crontab-sync.log"
last_good="$dir/.last-good"

say() { [ "$quiet" = 1 ] || echo "$*"; }
log() {
  mkdir -p "$(dirname "$log_file")" 2>/dev/null || return 0
  echo "$(date -u '+%F %T')Z $*" >>"$log_file" 2>/dev/null || true
  if [ "$(wc -c <"$log_file" 2>/dev/null || echo 0)" -gt 1048576 ]; then
    tail -n 2000 "$log_file" >"$log_file.tmp" 2>/dev/null && mv "$log_file.tmp" "$log_file"
  fi
}
die() { echo "crontab-sync: $*" >&2; log "FAILED: $*"; exit "${2:-1}"; }

tmp="$(mktemp -d)" || die "cannot make a temp folder"
trap 'rm -rf "$tmp"' EXIT

# ---- the fragments: what each site wants -------------------------------------------------------------------------------
[ -d "$dir" ] || die "no fragment folder $dir" 2
shopt -s nullglob
fragments=("$dir"/*.cron)
shopt -u nullglob
[ "${#fragments[@]}" -gt 0 ] || die "no fragments in $dir" 2

declare -A owner=()
: >"$tmp/ours"
for f in $(printf '%s\n' "${fragments[@]}" | LC_ALL=C sort); do
  name="$(basename "$f" .cron)"
  [[ "$name" =~ ^[a-z0-9][a-z0-9._-]*$ ]] || die "bad fragment name: $f" 2
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    [[ -z "${line//[[:space:]]/}" || "$line" =~ ^[[:space:]]*# ]] && continue
    [[ "$line" =~ \ \#\ ([a-z0-9][a-z0-9._-]*)$ ]] || die "$f: a line without a trailing ' # <tag>': $line" 2
    tag="${BASH_REMATCH[1]}"
    [[ "$tag" == "$name" || "$tag" == "$name"-* ]] || die "$f: tag '$tag' does not start with '$name'" 2
    if [ -n "${owner[$tag]:-}" ] && [ "${owner[$tag]}" != "$name" ]; then die "tag '$tag' is used by ${owner[$tag]} and $name" 2; fi
    owner[$tag]="$name"
    [[ "$line" == MAILTO=* ]] && die "$f: MAILTO belongs to no fragment" 2
    printf '%s\n' "$line" >>"$tmp/ours"
  done <"$f"
done
[ -s "$tmp/ours" ] || die "the fragments hold no lines" 2

is_ours() { # is_ours LINE: the line's trailing tag belongs to a fragment
  [[ "$1" =~ \ \#\ ([a-z0-9][a-z0-9._-]*)$ ]] && [ -n "${owner[${BASH_REMATCH[1]}]:-}" ]
}

# desired SRC OUT: MAILTO first (the source's own, else MAILTO=""), the source's other lines in order, then the fragments' lines
desired() {
  local src="$1" out="$2" line mailto=""
  : >"$out.rest"
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}"
    [ -z "${line//[[:space:]]/}" ] && continue
    if [[ "$line" == MAILTO=* ]]; then [ -z "$mailto" ] && mailto="$line"; continue; fi
    is_ours "$line" && continue
    printf '%s\n' "$line" >>"$out.rest"
  done <"$src"
  { echo "${mailto:-MAILTO=\"\"}"; cat "$out.rest" "$tmp/ours"; } >"$out"
  rm -f "$out.rest"
}

# normal SRC: the file without blank lines (what cron reads). No <(...) anywhere in this file: the host has no /dev/fd.
normal() { grep -v '^[[:space:]]*$' "$1" 2>/dev/null || true; }
same() { normal "$1" >"$tmp/n1"; normal "$2" >"$tmp/n2"; cmp -s "$tmp/n1" "$tmp/n2"; }
changed_lines() { normal "$1" >"$tmp/n1"; normal "$2" >"$tmp/n2"; diff "$tmp/n1" "$tmp/n2" | grep '^[<>]' || true; }

# ---- the cheap path: the mirror, read without any crontab call --------------------------------------------------------
if [ "$full" = 0 ] && [ -r "$mirror" ] && [ -s "$mirror" ]; then
  desired "$mirror" "$tmp/want"
  if same "$mirror" "$tmp/want"; then
    say "in sync ($(normal "$mirror" | wc -l | tr -d ' ') lines, read from the mirror)"
    cp "$mirror" "$tmp/lg" && mv "$tmp/lg" "$last_good" 2>/dev/null || true
    exit 0
  fi
fi

# ---- the careful path --------------------------------------------------------------------------------------------------
command -v flock >/dev/null || die "flock is not available"
exec 9>"$state/.crontab-sync.lock" || die "cannot open $state/.crontab-sync.lock"
flock -w "$lock_wait" 9 || die "another crontab-sync holds the lock"

read_once() { # read_once OUT: one `crontab -l`; "no crontab for" counts as empty
  if "$cron_bin" -l >"$1" 2>"$tmp/err"; then return 0; fi
  if grep -qi 'no crontab for' "$tmp/err"; then : >"$1"; return 0; fi
  return 1
}
stable_read() { # stable_read OUT: two reads $gap apart that agree
  local i
  for i in $(seq 1 "$tries"); do
    if read_once "$tmp/a" && sleep "$gap" && read_once "$tmp/b" && same "$tmp/a" "$tmp/b"; then
      cp "$tmp/a" "$1"; return 0
    fi
    sleep "$((gap * i))"
  done
  return 1
}

stable_read "$tmp/cur" || die "could not get two equal reads of the crontab in $tries tries; nothing written"

src="$tmp/cur"
if [ ! -s "$tmp/cur" ] || ! normal "$tmp/cur" | grep -qv '^MAILTO='; then
  if [ -s "$last_good" ]; then
    say "the crontab is empty: the other sites' lines come back from the last good copy"
    log "crontab found empty; kept lines restored from $last_good"
    src="$last_good"
  else
    say "the crontab is empty and there is no last good copy: only the fragments' lines will be installed"
    log "crontab found empty; no last good copy"
  fi
fi
desired "$src" "$tmp/want"

if same "$tmp/cur" "$tmp/want"; then
  say "in sync ($(normal "$tmp/cur" | wc -l | tr -d ' ') lines)"
  cp "$tmp/cur" "$last_good.tmp" && mv "$last_good.tmp" "$last_good"
  exit 0
fi

if [ "$check" = 1 ]; then
  echo "repair needed (- installed, + wanted):"
  changed_lines "$tmp/cur" "$tmp/want" | sed 's/^< /- /; s/^> /+ /'
  exit 3
fi

mkdir -p "$state/backups" && chmod 700 "$state/backups" || die "cannot create $state/backups"
backup="$state/backups/crontab.$(date -u +%Y%m%dT%H%M%SZ).sync.bak"
cp "$tmp/cur" "$backup" || die "cannot write $backup"
# keep the newest 50 of this tool's own copies; never anything else in backups/
find "$state/backups" -maxdepth 1 -name 'crontab.*.sync.bak' -printf '%T@ %p\n' 2>/dev/null | sort -rn | tail -n +51 | cut -d' ' -f2- |
  while IFS= read -r old; do rm -f -- "$old"; done

changes="$(changed_lines "$tmp/cur" "$tmp/want" | grep -c . || true)"
for i in $(seq 1 "$tries"); do
  sleep "$gap"
  if ! "$cron_bin" - <"$tmp/want" 2>"$tmp/err"; then
    log "write $i/$tries refused: $(tr '\n' ' ' <"$tmp/err")"
    sleep "$((gap * i))"; continue
  fi
  sleep "$gap"
  if stable_read "$tmp/after" && same "$tmp/after" "$tmp/want"; then
    cp "$tmp/want" "$last_good.tmp" && mv "$last_good.tmp" "$last_good"
    log "repaired: $changes line(s) changed (copy of the previous crontab: $backup)"
    echo "repaired: $changes line(s) changed; previous crontab saved as $backup"
    exit 0
  fi
  log "write $i/$tries did not hold; trying again"
  sleep "$((gap * i))"
done
die "the crontab could not be set in $tries tries; the previous one is saved as $backup"
