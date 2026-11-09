#!/usr/bin/env python3
"""Which CI jobs a pull request's change can affect (the `changes` job of .github/workflows/ci.yml).

  ci_affected.py --event pull_request --base <sha> --head <sha> [--labels-json '["full-ci"]']
  ci_affected.py --files apps/web/src/a.tsx docs/x.md      # what a list of paths would run (no git)

A push to main and a manual run always run everything: main's full run is the gate before the deploy. A pull request
runs the jobs its changed files can affect, and anything it cannot place runs everything (the safe default):

  apps/api/app/Modules/<M>, tests/Modules/<M>, routes/modules/<m>.php
                      mod-<m> and every module job downstream of it (its consumers in the manifest's modules.*.depends_on
                      and in ci.yml's needs between mod-* jobs, transitively, plus one hop over event_only and
                      interface_only), the static API jobs, and the jobs that run the whole app (below)
  any other apps/api  every API job (Shared, migrations, seeders, config, routes/api.php, tests/Support, tests/Gate, ...)
  apps/web            web-lint, i18n-check, web-build, web-errors
  any apps/ change    docker-build and every e2e-p* project: the screens go through the API, and the e2e suite proves the
                      flows end to end. An API change also runs every job with no rule of its own (rules-off-gates,
                      files-s3, perf-*, backup-drill: they run the API as a whole) and the Pest steps of gate-p4 .. gate-p6
  tools/print-bridge  print-bridge
  docs/openapi.json   openapi-check; docs/erd.dbml erd-check; docs/i18n/ i18n-check; any doc: invariants
  full run            .github/, scripts/, docker/, compose files, Makefile, .claude/ (the manifest and the skill's scripts),
                      docs/phases/ (STATUS.json and the reports gate_check reads), docs/adr/, docs/go-live*, an unknown path,
                      or the label full-ci

The gate jobs gate-p0 .. gate-p6 are the required checks: they always run, and a gate whose Pest step was not selected
runs only gate_check. Outputs (to $GITHUB_OUTPUT when set): full=true|false and jobs=<JSON list of job ids>.
Exit 0, or 2 on a usage error.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MANIFEST = ".claude/skills/bakery-ops-builder/assets/dependency-manifest.json"
WORKFLOW = ".github/workflows/ci.yml"
FULL_LABEL = "full-ci"

STATIC_API = ["api-lint", "api-arch", "invariants", "openapi-check", "erd-check"]
WEB = ["web-lint", "i18n-check", "web-build", "web-errors"]
ALWAYS = ("status", "changes")
OWN_RULE = set(STATIC_API) | set(WEB) | {"docker-build", "print-bridge", *ALWAYS}


def whole_api(jobs: dict[str, list[str]]) -> list[str]:
    """The jobs that run the API as a whole, which any API change runs: every job with no rule of its own (in this repository
    rules-off-gates, files-s3, perf-dashboards, perf-load, backup-drill) and the gates, whose Pest step runs when selected."""
    return sorted(j for j in jobs if j not in OWN_RULE and not j.startswith(("mod-", "e2e-")))

FULL_PREFIXES = (".github/", "scripts/", "docker/", ".claude/", ".githooks/", "docs/phases/", "docs/adr/", "docs/go-live")
FULL_FILES = ("Makefile",)


def read_workflow(root: str) -> tuple[dict[str, list[str]], dict[str, int]]:
    """(job id -> its needs, job id -> the phase it starts at), read from ci.yml: the jobs: block, two-space job ids, the
    job's `needs:` line, and `fromJSON(needs.status.outputs.phase) >= N` in its `if:` (the header above runs-on, uses or steps)."""
    jobs: dict[str, list[str]] = {}
    phases: dict[str, int] = {}
    cur, in_jobs, header = None, False, False
    with open(os.path.join(root, WORKFLOW), encoding="utf-8") as f:
        for line in f:
            if re.match(r"^jobs:\s*$", line):
                in_jobs = True
                continue
            if in_jobs and re.match(r"^[^\s#]", line):
                break
            m = re.match(r"^  ([A-Za-z0-9_-]+):\s*(#.*)?$", line) if in_jobs else None
            if m:
                cur, header = m.group(1), True
                jobs[cur] = []
                continue
            if not cur or not header:
                continue
            if re.match(r"^    (runs-on|uses|steps):", line):
                header = False
                continue
            n = re.match(r"^    needs:\s*(.+?)\s*$", line)
            if n:
                jobs[cur] = [x.strip() for x in n.group(1).strip("[]").split(",") if x.strip()]
            p = re.search(r"fromJSON\(needs\.status\.outputs\.phase\) >= (\d+)", line)
            if p and cur not in phases:
                phases[cur] = int(p.group(1))
    return jobs, phases


def ci_jobs(root: str) -> dict[str, list[str]]:
    return read_workflow(root)[0]


def current_phase(root: str) -> int | None:
    try:
        with open(os.path.join(root, "docs", "phases", "STATUS.json"), encoding="utf-8") as f:
            return int(json.load(f)["current_phase"])
    except (OSError, ValueError, KeyError, TypeError):
        return None


def module_dependents(manifest: dict, jobs: dict[str, list[str]]) -> dict[str, set[str]]:
    """Module -> every module that can be affected by a change in it.

    The consumers of a module, transitively (modules.*.depends_on, and ci.yml's needs between mod-* jobs), then one hop
    over the event and interface couplings (event_only, interface_only) of the module and of each of those consumers, both
    ways: a listener's tests run the code of the module whose events it reads, an interface's the module that implements
    it. The couplings do not chain: a module that only shares an event with another changes nothing for that one's consumers.
    """
    mods = manifest.get("modules", {})
    consumers: dict[str, set[str]] = {m: set() for m in mods}
    partners: dict[str, set[str]] = {m: set() for m in mods}
    for m, spec in mods.items():
        for dep in spec.get("depends_on", []):
            consumers.setdefault(dep, set()).add(m)
        for other in spec.get("event_only", []) + spec.get("interface_only", []):
            partners.setdefault(other, set()).add(m)
            partners.setdefault(m, set()).add(other)
    for job, needs in jobs.items():
        if job.startswith("mod-"):
            for need in needs:
                if need.startswith("mod-"):
                    consumers.setdefault(need[4:], set()).add(job[4:])
    out: dict[str, set[str]] = {}
    for m in set(consumers) | set(partners):
        seen: set[str] = set()
        stack = list(consumers.get(m, ()))
        while stack:
            d = stack.pop()
            if d not in seen and d != m:
                seen.add(d)
                stack += consumers.get(d, ())
        for x in {m} | seen:
            seen |= partners.get(x, set())
        out[m] = seen - {m}
    return out


def classify(path: str, modules: set[str]) -> tuple[str, str | None]:
    """(kind, module): kind is full, module, api, web, bridge, openapi, erd, i18n or doc."""
    if path.startswith(FULL_PREFIXES) or path in FULL_FILES or re.match(r"^compose[\w.-]*\.ya?ml$", path):
        return "full", None
    m = re.match(r"^apps/api/(?:app|tests)/Modules/([A-Za-z]+)/", path) or re.match(r"^apps/api/routes/modules/([a-z_]+)\.php$", path)
    if m and m.group(1).lower() in modules:
        return "module", m.group(1).lower()
    if path.startswith("apps/api/"):
        return "api", None
    if path.startswith("apps/web/"):
        return "web", None
    if path.startswith("tools/print-bridge/"):
        return "bridge", None
    if path == "docs/openapi.json":
        return "openapi", None
    if path == "docs/erd.dbml":
        return "erd", None
    if path.startswith("docs/i18n/"):
        return "i18n", None
    if path.startswith("docs/") or re.match(r"^[^/]+\.md$", path):
        return "doc", None
    return "full", None


def affected(files: list[str], manifest: dict, jobs: dict[str, list[str]], labels: list[str] = (),
             phases: dict[str, int] | None = None, phase: int | None = None) -> dict:
    """{'full': bool, 'jobs': sorted job ids, 'reasons': [(path, what)]}. A job of a phase not reached yet is left out: it
    skips on its phase condition, and a selected job that skipped would read, to the gates, as a need that never ran."""
    all_jobs = set(jobs)
    if FULL_LABEL in labels:
        return {"full": True, "jobs": [], "reasons": [(f"label {FULL_LABEL}", "full run")]}
    if not files:
        return {"full": True, "jobs": [], "reasons": [("no changed files read", "full run")]}
    modules = set(manifest.get("modules", {}))
    dependents = module_dependents(manifest, jobs)
    mod_jobs = sorted(j for j in all_jobs if j.startswith("mod-"))
    picked: set[str] = set()
    reasons: list[tuple[str, str]] = []
    apps = api = False

    def mods_for(m: str) -> list[str]:
        return [f"mod-{x}" for x in sorted({m} | dependents.get(m, set())) if f"mod-{x}" in all_jobs]

    for path in files:
        kind, mod = classify(path, modules)
        if kind == "full":
            return {"full": True, "jobs": [], "reasons": [(path, "full run")]}
        if kind == "module":
            got = mods_for(mod)
            picked.update(got + STATIC_API)
            apps = api = True
            reasons.append((path, f"module {mod}: {', '.join(got) or 'no module job of its own'} and the static API jobs"))
        elif kind == "api":
            picked.update(mod_jobs + STATIC_API)
            apps = api = True
            reasons.append((path, "every API job"))
        elif kind == "web":
            picked.update(WEB)
            apps = True
            reasons.append((path, "the web jobs"))
        elif kind == "bridge":
            picked.add("print-bridge")
            reasons.append((path, "print-bridge"))
        else:
            got = {"openapi": ["openapi-check"], "erd": ["erd-check"], "i18n": ["i18n-check"]}.get(kind, [])
            picked.update(got + ["invariants"])
            reasons.append((path, ", ".join(got + ["invariants"])))
    if apps:
        picked.add("docker-build")
        picked.update(j for j in all_jobs if j.startswith("e2e-"))
    if api:
        picked.update(whole_api(jobs))
    # the jobs that download web-build's artifact (the Next build) bring it; docker-build (the API image) comes with any app change
    if any(j.startswith("e2e-") or j == "web-errors" for j in picked):
        picked.add("web-build")
    started = (lambda j: True) if phase is None else (lambda j: (phases or {}).get(j, 0) <= phase)
    return {"full": False, "jobs": sorted(j for j in picked if j in all_jobs and started(j)), "reasons": reasons}


def changed_files(root: str, base: str, head: str) -> list[str] | None:
    """The paths the pull request changes since it left its base; None when git cannot tell (the caller then runs everything)."""
    # --no-renames: a move lists both the old path and the new one, so moving a file out of a module still runs that module
    proc = subprocess.run(["git", "diff", "--name-only", "--no-renames", f"{base}...{head}"], cwd=root, text=True,
                          capture_output=True)
    if proc.returncode != 0:
        print(f"ci_affected: git diff {base[:12]}...{head[:12]} failed: {proc.stderr.strip()}", file=sys.stderr)
        return None
    return [ln.strip() for ln in proc.stdout.splitlines() if ln.strip()]


def check_needs(needs: dict, full: str, jobs: list[str]) -> list[str]:
    """What is wrong with a gate's needs (toJSON(needs) of the gate job): every need passed, except, on a selective run, a
    job the change did not select, which is skipped. A gate, `status` and `changes` always run, so they must have passed."""
    if full not in ("true", "false"):
        return [f"the changes job gave no answer (full={full!r}): it failed or did not run"]
    bad = []
    for job, n in sorted(needs.items()):
        result = (n or {}).get("result")
        if result == "success":
            continue
        always = job in ("status", "changes") or job.startswith("gate-p")
        if full == "false" and result == "skipped" and not always and job not in jobs:
            continue
        why = "selected for this change" if full == "false" and job in jobs else ("always runs" if always else "a full run")
        bad.append(f"{job}: {result} ({why})")
    return bad


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--event", default="pull_request")
    ap.add_argument("--base")
    ap.add_argument("--head")
    ap.add_argument("--labels-json", default="[]")
    ap.add_argument("--files", nargs="*")
    ap.add_argument("--check-needs", action="store_true",
                    help="in a gate job: fail unless every need passed or was not selected (reads NEEDS, FULL and JOBS)")
    ap.add_argument("--root", default=ROOT)
    a = ap.parse_args(argv)
    if a.check_needs:
        try:
            needs = json.loads(os.environ.get("NEEDS") or "{}")
            selected = json.loads(os.environ.get("JOBS") or "[]")
        except ValueError as e:
            print(f"::error::cannot read NEEDS or JOBS: {e}")
            return 1
        bad = check_needs(needs, os.environ.get("FULL", ""), selected)
        for line in bad:
            print(f"::error::{line}")
        print(f"{len(needs) - len(bad)} of {len(needs)} needs passed or were not needed" + (": the gate fails" if bad else ""))
        return 1 if bad else 0
    with open(os.path.join(a.root, MANIFEST), encoding="utf-8") as f:
        manifest = json.load(f)
    jobs, phases = read_workflow(a.root)
    phase = current_phase(a.root)
    labels = json.loads(a.labels_json or "[]") or []
    if a.event != "pull_request":
        result = {"full": True, "jobs": [], "reasons": [(f"event {a.event}", "full run")]}
    elif a.files is not None:
        result = affected(a.files, manifest, jobs, labels, phases, phase)
    elif a.base and a.head:
        files = changed_files(a.root, a.base, a.head)
        if files is None:
            result = {"full": True, "jobs": [], "reasons": [("git diff failed", "full run")]}
        else:
            result = affected(files, manifest, jobs, labels, phases, phase)
    else:
        print("ci_affected: a pull request needs --base and --head (or --files)", file=sys.stderr)
        return 2
    full = "true" if result["full"] else "false"
    print(f"full={full}")
    print(f"jobs={json.dumps(result['jobs'])}")
    for path, what in result["reasons"]:
        print(f"  {path}: {what}")
    if os.environ.get("GITHUB_OUTPUT"):
        with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as f:
            f.write(f"full={full}\njobs={json.dumps(result['jobs'], separators=(',', ':'))}\n")
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as f:
            f.write("### What this change runs\n\n")
            f.write("Everything (the full graph).\n\n" if result["full"] else f"{len(result['jobs'])} jobs: {', '.join(result['jobs'])}\n\n")
            f.write("| Path | Runs |\n|---|---|\n")
            for path, what in result["reasons"][:200]:
                f.write(f"| `{path}` | {what} |\n")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
