#!/usr/bin/env python3
"""Which CI jobs a change runs (the `changes` job of .github/workflows/ci.yml).

  ci_affected.py --event pull_request                                  # a pull request: always the full graph
  ci_affected.py --event push --sha <sha> --before <sha> [--repo o/r]  # a push to main: reuse, docs only, or full
  ci_affected.py --files apps/web/src/a.tsx docs/x.md                  # what a list of paths would select (no git)

Every pull request runs everything (2026-11-11, Ramin: "no more long waiting time"). A pull request is merged by moving main to its
head, so main's commit is the commit the pull request's full run tested; a push to main therefore reuses that run instead of running
the graph a second time:

  reuse      a push whose commit has a successful `ci` run of event pull_request from this repository that ran in full (its
             `changes` job uploaded the artifact ci-full-run) selects no job: every test job is skipped, the gates run gate_check
             only, the run concludes success and deploy.yml starts. The tested tree is main's tree: the pull request's merge ref is
             its head merged into a base that is an ancestor of main (main is never force-pushed), and that merge is the head.
  docs only  a push that changes nothing outside docs/ (Ramin's sign-offs in the web editor, the status and report commits) runs
             invariants plus the check of each generated file it touches (openapi-check, erd-check, i18n-check); the gates still
             run gate_check, which reads STATUS.json, the reports, the ADRs and the go-live checklist.
  full       anything else, a manual run, or any doubt (no token, the API unreachable, the diff unreadable).

`affected()` (the per-path selection, below) is kept for --files and its tests; it decided what a pull request ran between
2026-11-10 and 2026-11-11:

  apps/api/app/Modules/<M>, tests/Modules/<M>, routes/modules/<m>.php
                      mod-<m> and every module job downstream of it (its consumers in the manifest's modules.*.depends_on
                      and in ci.yml's needs between mod-* jobs, transitively, plus one hop over event_only and
                      interface_only), the static API jobs, and the jobs that run the whole app (below)
  any other apps/api  every API job (Shared, migrations, seeders, config, routes/api.php, tests/Support, tests/Gate, ...)
  apps/web            web-lint, i18n-check, web-build, web-errors
  any apps/ change    docker-build and every e2e-p* project
  tools/print-bridge  print-bridge
  docs/openapi.json   openapi-check; docs/erd.dbml erd-check; docs/i18n/ i18n-check; any doc: invariants
  full run            .github/, scripts/, docker/, compose files, Makefile, .claude/, docs/phases/, docs/adr/, docs/go-live*,
                      an unknown path, or the label full-ci

The gate jobs gate-p0 .. gate-p6 are the required checks: they always run, and a gate whose Pest step was not selected
runs only gate_check. Outputs (to $GITHUB_OUTPUT when set): full=true|false, jobs=<JSON list of job ids> and reuse=<run URL or
empty>.
Exit 0, or 2 on a usage error.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MANIFEST = ".claude/skills/bakery-ops-builder/assets/dependency-manifest.json"
WORKFLOW = ".github/workflows/ci.yml"
FULL_LABEL = "full-ci"
FULL_MARKER = "ci-full-run"  # the artifact a pull request's full run uploads (ci.yml, changes job)

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


def docs_only_jobs(files: list[str]) -> list[str] | None:
    """The jobs a push to main that changes only docs/ runs, or None when a file lies outside docs/ (or nothing changed)."""
    if not files or not all(f.startswith("docs/") for f in files):
        return None
    jobs = {"invariants"}
    for f in files:
        if f == "docs/openapi.json":
            jobs.add("openapi-check")
        elif f == "docs/erd.dbml":
            jobs.add("erd-check")
        elif f.startswith("docs/i18n/"):
            jobs.add("i18n-check")
    return sorted(jobs)


def pick_reusable(runs: list[dict], sha: str, repo: str, ran_in_full) -> dict | None:
    """The newest run in `runs` (the Actions API's workflow_runs) that main's push to `sha` may reuse: a successful run of event
    pull_request on that very commit, from this repository, that ran in full (`ran_in_full(run)`: it carries the marker)."""
    for run in sorted(runs, key=lambda r: r.get("created_at") or "", reverse=True):
        if (run.get("head_sha") == sha and run.get("event") == "pull_request" and run.get("status") == "completed"
                and run.get("conclusion") == "success"
                and (run.get("head_repository") or {}).get("full_name") == repo
                and ran_in_full(run)):
            return run
    return None


def api_get(path: str, token: str) -> dict:
    req = urllib.request.Request(f"https://api.github.com/{path}", headers={
        "Authorization": f"Bearer {token}", "Accept": "application/vnd.github+json", "X-GitHub-Api-Version": "2022-11-28"})
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.load(resp)


def reusable_run(repo: str, sha: str, token: str) -> dict | None:
    """Ask the Actions API for a pull request run main may reuse (None when there is none)."""
    q = urllib.parse.urlencode({"head_sha": sha, "event": "pull_request", "status": "success", "per_page": 20})
    runs = api_get(f"repos/{repo}/actions/workflows/ci.yml/runs?{q}", token).get("workflow_runs", [])

    def ran_in_full(run: dict) -> bool:
        arts = api_get(f"repos/{repo}/actions/runs/{run['id']}/artifacts?name={FULL_MARKER}", token)
        return any(a.get("name") == FULL_MARKER and not a.get("expired") for a in arts.get("artifacts", []))

    return pick_reusable(runs, sha, repo, ran_in_full)


def push_result(root: str, sha: str | None, before: str | None, repo: str | None, token: str | None) -> dict:
    """What a push to main runs: reuse a pull request's full run of the same commit, else docs only, else everything."""
    if sha and repo and token:
        try:
            run = reusable_run(repo, sha, token)
        except (urllib.error.URLError, OSError, ValueError, KeyError) as e:
            print(f"::warning::cannot ask the Actions API for a run to reuse ({e}): running everything")
            run = None
        if run:
            url = run.get("html_url") or f"https://github.com/{repo}/actions/runs/{run['id']}"
            return {"full": False, "jobs": [], "reuse": url,
                    "reasons": [(f"pull request run {run['id']}", f"reused: it ran everything on {sha[:12]} and passed")]}
    if sha and before and not re.fullmatch(r"0+", before):
        files = changed_files(root, before, sha)
        jobs = docs_only_jobs(files or [])
        if jobs is not None:
            return {"full": False, "jobs": jobs, "reuse": "", "reasons": [(f, "docs only") for f in files]}
    return {"full": True, "jobs": [], "reuse": "", "reasons": [("push to main", "full run (no pull request run to reuse)")]}


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
    ap.add_argument("--sha", help="a push: the commit pushed")
    ap.add_argument("--before", help="a push: the commit main pointed at before it")
    ap.add_argument("--repo", default=os.environ.get("GITHUB_REPOSITORY"))
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
    if a.files is not None:
        result = affected(a.files, manifest, jobs, labels, phases, phase)
    elif a.event == "pull_request":
        result = {"full": True, "jobs": [], "reasons": [("pull request", "full run: every pull request runs everything, so main can reuse it")]}
    elif a.event == "push":
        result = push_result(a.root, a.sha, a.before, a.repo, os.environ.get("GITHUB_TOKEN"))
    else:
        result = {"full": True, "jobs": [], "reasons": [(f"event {a.event}", "full run")]}
    full = "true" if result["full"] else "false"
    print(f"full={full}")
    print(f"jobs={json.dumps(result['jobs'])}")
    for path, what in result["reasons"]:
        print(f"  {path}: {what}")
    if os.environ.get("GITHUB_OUTPUT"):
        with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as f:
            f.write(f"full={full}\njobs={json.dumps(result['jobs'], separators=(',', ':'))}\nreuse={result.get('reuse', '')}\n")
    if os.environ.get("GITHUB_STEP_SUMMARY"):
        with open(os.environ["GITHUB_STEP_SUMMARY"], "a", encoding="utf-8") as f:
            f.write("### What this change runs\n\n")
            if result.get("reuse"):
                f.write(f"Nothing runs again: this commit's pull request run passed in full, {result['reuse']}\n\n")
            f.write("Everything (the full graph).\n\n" if result["full"] else f"{len(result['jobs'])} jobs: {', '.join(result['jobs'])}\n\n")
            f.write("| Path | Runs |\n|---|---|\n")
            for path, what in result["reasons"][:200]:
                f.write(f"| `{path}` | {what} |\n")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
