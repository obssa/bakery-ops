#!/usr/bin/env python3
"""Does `main` require the gate checks? The go-live checklist item `branch-protection` (phase-5 plan 10.3).

  check_branch_protection.py --require gate-p0..gate-p5 [--require other-check] [--branch main] [--repo owner/name]

Reads GET /repos/{owner}/{repo}/branches/{branch} and its `.protection.required_status_checks.contexts`. `main` uses classic
branch protection (not rulesets), and this endpoint is readable with the default GITHUB_TOKEN on a public repository; the
`/protection` endpoint needs an admin token, so it is not used. The token is GH_TOKEN or GITHUB_TOKEN over HTTPS; without
one the gh CLI is used. `--require` takes names, comma lists and numbered ranges (`gate-p0..gate-p5`). Stdlib only.

Exit 0 when every required check is required on the branch, 1 when one is missing or the API cannot be read, 2 usage.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import urllib.error
import urllib.request

RANGE_RX = re.compile(r"^(.*?)(\d+)\.\.(.*?)(\d+)$")


class ApiError(Exception):
    pass


def expand(spec: str) -> list[str]:
    """`gate-p0..gate-p5` -> gate-p0, gate-p1, ... gate-p5; `a,b` -> a, b."""
    out: list[str] = []
    for part in (p.strip() for p in spec.split(",")):
        if not part:
            continue
        m = RANGE_RX.match(part)
        if m:
            prefix, lo, prefix2, hi = m.group(1), int(m.group(2)), m.group(3), int(m.group(4))
            if prefix2 not in ("", prefix) or lo > hi:
                raise ValueError(f"bad range {part!r}: write it as name0..name5")
            out += [f"{prefix}{k}" for k in range(lo, hi + 1)]
        elif ".." in part:
            raise ValueError(f"bad range {part!r}: write it as name0..name5")
        else:
            out.append(part)
    return out


def api_get(path: str) -> dict:
    token = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
    if token:
        base = os.environ.get("GITHUB_API_URL", "https://api.github.com").rstrip("/")
        req = urllib.request.Request(f"{base}/{path}", headers={
            "Authorization": f"Bearer {token}", "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28", "User-Agent": "bakery-ops-branch-protection"})
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except (urllib.error.URLError, OSError, ValueError) as e:
            raise ApiError(str(e)) from e
    gh = shutil.which("gh")
    if not gh:
        raise ApiError("no GH_TOKEN or GITHUB_TOKEN and no gh CLI")
    try:
        proc = subprocess.run([gh, "api", path], text=True, encoding="utf-8", errors="replace", capture_output=True, timeout=60)
    except subprocess.TimeoutExpired as e:
        raise ApiError("gh api timed out") from e
    if proc.returncode != 0:
        raise ApiError(((proc.stderr or proc.stdout or "").strip().splitlines() or ["gh api failed"])[-1])
    try:
        return json.loads(proc.stdout)
    except ValueError as e:
        raise ApiError(f"gh api returned no JSON: {e}") from e


def repo_slug() -> str | None:
    if os.environ.get("GITHUB_REPOSITORY"):
        return os.environ["GITHUB_REPOSITORY"]
    try:
        proc = subprocess.run(["git", "remote", "get-url", "origin"], text=True, capture_output=True)
    except OSError:
        return None
    m = re.search(r"github\.com[:/]([^/\s]+/[^/\s]+?)(?:\.git)?/?$", proc.stdout.strip()) if proc.returncode == 0 else None
    return m.group(1) if m else None


def required_contexts(branch_json: dict) -> list[str] | None:
    """The branch's required status checks, or None when it has no classic protection."""
    prot = branch_json.get("protection") or {}
    if not prot.get("enabled"):
        return None
    checks = prot.get("required_status_checks") or {}
    contexts = list(checks.get("contexts") or [])
    contexts += [c["context"] for c in checks.get("checks") or [] if c.get("context") and c["context"] not in contexts]
    return contexts


def check(repo: str, branch: str, required: list[str]) -> tuple[bool, str]:
    try:
        data = api_get(f"repos/{repo}/branches/{branch}")
    except ApiError as e:
        return False, f"cannot read {branch}: {e}"
    contexts = required_contexts(data)
    if contexts is None:
        return False, f"{branch} is not protected (no classic branch protection)"
    missing = [c for c in required if c not in contexts]
    if missing:
        return False, f"{branch} does not require {', '.join(missing)} (it requires: {', '.join(contexts) or 'nothing'})"
    return True, f"{branch} requires {', '.join(required)} ({len(contexts)} required check(s) in all)"


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--require", action="append", required=True, help="a check name, a comma list or a range like gate-p0..gate-p5")
    ap.add_argument("--branch", default="main")
    ap.add_argument("--repo", help="owner/name (default: GITHUB_REPOSITORY, else the origin remote)")
    a = ap.parse_args(argv)
    try:
        required = [c for spec in a.require for c in expand(spec)]
    except ValueError as e:
        ap.error(str(e))
    repo = a.repo or repo_slug()
    if not repo:
        print("check_branch_protection: cannot tell the repository; pass --repo owner/name")
        return 1
    ok, detail = check(repo, a.branch, required)
    print(detail)
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
