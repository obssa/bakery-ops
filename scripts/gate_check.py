#!/usr/bin/env python3
"""Gate checks for the bakery-ops phase-gated build. Stdlib only.

  gate_check.py --mode entry  --phase N            may phase N start?   (dependencies proven, plan approved)
  gate_check.py --mode module --module NAME        is NAME eligible for 'stable'?
  gate_check.py --mode done   --phase N            may phase N be declared done?

Options
  --ci              trust the CI `needs` chain for test results instead of running Pest/Playwright here;
                    for an in-progress phase in --mode done this is informational (exit 0) unless STATUS
                    already claims the phase done, in which case every artifact check must pass.
  --no-tests        skip running Pest (local dry run); --no-e2e skips Playwright
  --allow-dirty     tolerate an unclean working tree (entry mode)
  --runner CMD      how to run pest, default auto: scripts/pest if present, else apps/api/vendor/bin/pest
  --status PATH     alternative STATUS.json (used by the self-tests and the skill evals)
  --manifest PATH   alternative manifest

A phase whose manifest gate names a `checklist` (phase 5: docs/go-live-checklist.json, phase-5 plan 10.3) gets one row per
item in --mode done, `checklist <id>`: PASS, FAIL, SKIP (trusted to CI when run locally) or WAIT (a sign-off not made yet).
WAIT fails the gate like FAIL, except in an informational --ci run, where it stays a WAIT row and the job stays green.

Exit 0 = all checks passed, 1 = at least one failed or waiting, 2 = usage.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import re
import shutil
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

TODO_MARK = "TODO(p{n})"


class Report:
    def __init__(self) -> None:
        self.rows: list[tuple[str, str, str]] = []  # (PASS|FAIL|WARN|SKIP|WAIT, check, detail)
        self.wait_blocks = True  # an informational run keeps its WAIT rows but does not fail on them

    def add(self, ok: bool | None, check: str, detail: str = "", warn: bool = False) -> None:
        state = "SKIP" if ok is None else ("PASS" if ok else ("WARN" if warn else "FAIL"))
        self.rows.append((state, check, detail))

    def row(self, state: str, check: str, detail: str = "") -> None:
        self.rows.append((state, check, detail))

    @property
    def failed(self) -> bool:
        return any(s == "FAIL" or (s == "WAIT" and self.wait_blocks) for s, _, _ in self.rows)

    def print(self, title: str) -> None:
        print(f"== gate_check: {title}")
        width = max((len(c) for _, c, _ in self.rows), default=10)
        for state, check, detail in self.rows:
            print(f"  [{state}] {check.ljust(width)}  {detail}")
        n_fail = sum(1 for s, _, _ in self.rows if s == "FAIL")
        n_wait = sum(1 for s, _, _ in self.rows if s == "WAIT")
        n_warn = sum(1 for s, _, _ in self.rows if s == "WARN")
        waiting = f"{n_wait} waiting, " if n_wait else ""
        print(f"== result: {'FAIL' if self.failed else 'PASS'} ({n_fail} failed, {waiting}{n_warn} warnings, {len(self.rows)} checks)")


# ----------------------------------------------------------------------------- helpers

def find_repo_root(start: str | None = None) -> str:
    cur = os.path.abspath(start or os.getcwd())
    while True:
        if os.path.isdir(os.path.join(cur, ".git")) or os.path.isfile(os.path.join(cur, "CLAUDE.md")):
            return cur
        parent = os.path.dirname(cur)
        if parent == cur:
            sys.exit("gate_check: could not find the repo root")
        cur = parent


def load(path: str) -> dict:
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def git(root: str, *args: str) -> tuple[int, str]:
    """Never raises: an unknown commit (exit 128) is a failed check, not a crash."""
    proc = subprocess.run(["git", *args], cwd=root, text=True, capture_output=True)
    out = (proc.stdout or "").strip() or (proc.stderr or "").strip()
    return proc.returncode, out


def is_git_repo(root: str) -> bool:
    # a directory in a clone, a file in a linked worktree
    return os.path.exists(os.path.join(root, ".git"))


def grep_files(root: str, rel_dirs: list[str], pattern: str, exts=(".php", ".ts", ".tsx", ".js", ".mjs", ".py", ".yml", ".yaml", ".md")) -> list[str]:
    hits = []
    rx = re.compile(re.escape(pattern))
    for rel in rel_dirs:
        base = os.path.join(root, rel)
        if not os.path.isdir(base):
            continue
        for dirpath, dirnames, filenames in os.walk(base):
            dirnames[:] = [d for d in dirnames if d not in ("vendor", "node_modules", ".next", "storage")]
            for fn in filenames:
                if fn.endswith(exts):
                    fp = os.path.join(dirpath, fn)
                    try:
                        with open(fp, encoding="utf-8", errors="ignore") as fh:
                            for i, line in enumerate(fh, 1):
                                if rx.search(line):
                                    hits.append(f"{os.path.relpath(fp, root)}:{i}")
                    except OSError:
                        pass
    return hits


class PestRunner:
    def __init__(self, root: str, runner: str | None, enabled: bool) -> None:
        self.root = root
        self.enabled = enabled
        # only a run that runs tests looks for a runner: with --no-tests or --ci nothing asks Docker (step 6.5: on a PC whose
        # Docker engine is stopped the CLI's probe never returned, and the gate hung before printing a row)
        self.cmd = self._resolve(runner) if enabled else None

    def _resolve(self, runner: str | None) -> list[str] | None:
        if runner:
            return runner.split()
        # Prefer the compose service directly: on Windows `bash` may resolve to WSL's stub, so never
        # go through the scripts/pest wrapper here. Host PHP (8.0) is never used.
        if shutil.which("docker") and os.path.exists(os.path.join(self.root, "compose.yml")):
            try:
                probe = subprocess.run(["docker", "compose", "-f", "compose.yml", "-f", "compose.dev.yml", "ps", "-q", "app"],
                                       cwd=self.root, text=True, capture_output=True, timeout=60)
            except subprocess.TimeoutExpired:
                probe = None
            if probe is not None and probe.returncode == 0 and probe.stdout.strip():
                return ["docker", "compose", "-f", "compose.yml", "-f", "compose.dev.yml", "exec", "-T", "app", "vendor/bin/pest"]
        direct = os.path.join(self.root, "apps", "api", "vendor", "bin", "pest")
        if os.path.exists(direct) and os.name != "nt":
            return [direct]
        return None

    def group(self, groups: str) -> tuple[bool | None, str]:
        """Returns (ok, detail). ok=None when tests were not run."""
        if not self.enabled:
            return None, f"pest --group={groups} not run (--no-tests/--ci)"
        if not self.cmd:
            return None, "no pest runner: start the stack (python scripts/task.py up) so tests can run in the app container"
        # Pest and Playwright print UTF-8 (Amharic names, check marks); the Windows default code page cannot decode it
        proc = subprocess.run([*self.cmd, *[f"--group={g}" for g in groups.split(",")], "--colors=never"], cwd=self.root,
                              text=True, encoding="utf-8", errors="replace", capture_output=True)
        out = (proc.stdout or "") + (proc.stderr or "")
        if re.search(r"No tests (found|executed)", out):
            return False, f"group {groups}: no tests found"
        if proc.returncode == 0:
            m = re.search(r"Tests:\s+(.*)", out)
            return True, f"group {groups}: {m.group(1).strip() if m else 'green'}"
        tail = "\n".join(out.strip().splitlines()[-8:])
        return False, f"group {groups} failed:\n{tail}"


# ----------------------------------------------------------------------------- common checks

def check_contract(root: str, man: dict, pair: str, rep: Report, pest: PestRunner, done_commit: str | None, require_unmodified_consumer: bool) -> None:
    c = man["contracts"].get(pair)
    if not c:
        rep.add(False, f"contract {pair}", "not in manifest")
        return
    f = os.path.join(root, c["file"])
    if not os.path.exists(f):
        rep.add(False, f"contract {pair} file", f"missing {c['file']}")
        return
    with open(f, encoding="utf-8", errors="ignore") as fh:
        body = fh.read()
    if "->todo()" in body:
        rep.add(False, f"contract {pair} todo", "contains ->todo(): write the cases before the consumer")
    else:
        rep.add(True, f"contract {pair} file", c["file"])
    if require_unmodified_consumer:
        consumer = pair.split("->")[0]
        cdir = f"apps/api/app/Modules/{consumer.capitalize()}"
        if consumer == "shared":
            cdir = "apps/api/app/Shared"
        if is_git_repo(root) and done_commit:
            code, _ = git(root, "diff", "--quiet", done_commit, "--", cdir)
            _, untracked = git(root, "ls-files", "--others", "--exclude-standard", "--", cdir)
            modified = code == 1 or bool(untracked)
            rep.add(not modified, f"contract {pair} first", "consumer unchanged since previous gate" if not modified
                    else f"{cdir} already modified: contract tests must be green before consumer code")
        else:
            exists = os.path.isdir(os.path.join(root, cdir)) and any(os.scandir(os.path.join(root, cdir)))
            rep.add(not exists, f"contract {pair} first", "consumer dir empty" if not exists else f"{cdir} already has code")
    ok, detail = pest.group(f"contract:{pair}")
    rep.add(ok, f"contract {pair} green", detail)


# ----------------------------------------------------------------------------- go-live checklist (phase-5 plan 10.3)

CHECKLIST_SCHEMA = 1
VERIFIER_TYPES = ("ci_job", "script", "file_exists")
VERIFIER_KEYS = {
    "ci_job": {"type", "jobs", "workflow", "job", "branch", "max_age_days"},
    "script": {"type", "run", "timeout"},
    "file_exists": {"type", "paths", "signoff"},
}
ITEM_KEYS = {"id", "title", "required", "who", "verifier", "note"}
WHO = ("CI", "Ramin")
SIGNOFF_DIR = "docs/go-live/signoffs"
SIGNOFF_FIELDS = ("item", "date", "signed_by", "statement")
SIGNOFF_FIELD_RX = re.compile(r"^(item|date|signed_by|statement)[ \t]*:[ \t]*(.*?)[ \t]*$", re.M)
# GitHub's web editor commits as this committer; an agent on the developer PC commits under Ramin's own identity
WEB_EDITOR_COMMITTER = "GitHub\tnoreply@github.com"
ID_RX = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
ADDIS = dt.timezone(dt.timedelta(hours=3))
STATE_ORDER = {"PASS": 0, "SKIP": 1, "WAIT": 2, "FAIL": 3}


class ApiError(Exception):
    pass


def utcnow() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc)


def parse_time(value: str) -> dt.datetime:
    t = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    return t if t.tzinfo else t.replace(tzinfo=dt.timezone.utc)


def api_get(path: str) -> dict:
    """GET a GitHub REST path: with GH_TOKEN/GITHUB_TOKEN over HTTPS (CI), else through the gh CLI (locally)."""
    token = os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
    if token:
        base = os.environ.get("GITHUB_API_URL", "https://api.github.com").rstrip("/")
        req = urllib.request.Request(f"{base}/{path}", headers={
            "Authorization": f"Bearer {token}", "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28", "User-Agent": "bakery-ops-gate-check"})
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except (urllib.error.URLError, OSError, ValueError) as e:
            raise ApiError(str(e)) from e
    gh = shutil.which("gh")
    if not gh:
        raise ApiError("no GH_TOKEN and no gh CLI to read the Actions API")
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


def repo_slug(root: str) -> str | None:
    if os.environ.get("GITHUB_REPOSITORY"):
        return os.environ["GITHUB_REPOSITORY"]
    code, url = git(root, "remote", "get-url", "origin")
    m = re.search(r"github\.com[:/]([^/\s]+/[^/\s]+?)(?:\.git)?/?$", url) if code == 0 else None
    return m.group(1) if m else None


def workflow_jobs(root: str, rel: str) -> dict[str, str]:
    """Job id -> the text of its block, from a workflow file whose job keys sit two spaces in under `jobs:`."""
    path = os.path.join(root, rel)
    if not os.path.exists(path):
        return {}
    blocks: dict[str, str] = {}
    cur, in_jobs = None, False
    with open(path, encoding="utf-8", errors="ignore") as fh:
        for line in fh:
            if re.match(r"^jobs:\s*$", line):
                in_jobs = True
                continue
            if in_jobs and re.match(r"^[^\s#]", line):
                in_jobs, cur = False, None
                continue
            m = re.match(r"^  ([A-Za-z0-9_-]+):\s*(#.*)?$", line) if in_jobs else None
            if m:
                cur = m.group(1)
                blocks[cur] = ""
            elif cur:
                blocks[cur] += line
    return blocks


def gate_needs(man: dict, gate_job: str) -> set[str]:
    """Every job the gate job waits for, directly or through the jobs it needs (manifest ci.*_jobs)."""
    graph: dict[str, list[str]] = {}
    for key in ("module_jobs", "e2e_jobs", "gate_jobs"):
        graph.update(man.get("ci", {}).get(key, {}))
    seen: set[str] = set()
    stack = list(graph.get(gate_job, []))
    while stack:
        j = stack.pop()
        if j not in seen:
            seen.add(j)
            stack += graph.get(j, [])
    return seen


def _strings(value: object) -> bool:
    return isinstance(value, list) and bool(value) and all(isinstance(x, str) and x.strip() for x in value)


def verifier_errors(v: object, item_id: str) -> list[str]:
    if not isinstance(v, dict):
        return ["a verifier is not an object"]
    t = v.get("type")
    if t not in VERIFIER_TYPES:
        return [f"unknown verifier type {t!r} (known: {', '.join(VERIFIER_TYPES)})"]
    errs = []
    extra = sorted(set(v) - VERIFIER_KEYS[t])
    if extra:
        errs.append(f"{t}: unknown key(s) {', '.join(extra)}")
    if t == "ci_job":
        if ("jobs" in v) == ("workflow" in v):
            errs.append("ci_job takes exactly one of jobs or workflow")
        elif "jobs" in v:
            if not _strings(v["jobs"]):
                errs.append("ci_job jobs must be a non-empty list of job ids")
            stray = sorted({"job", "branch", "max_age_days"} & set(v))
            if stray:
                errs.append(f"ci_job with jobs takes no {', '.join(stray)}")
        else:
            wf = v["workflow"]
            if not (isinstance(wf, str) and re.match(r"^[A-Za-z0-9_.-]+\.ya?ml$", wf)):
                errs.append("ci_job workflow must be a file name in .github/workflows, such as deploy.yml")
            for k in ("job", "branch"):
                if k in v and not (isinstance(v[k], str) and v[k].strip()):
                    errs.append(f"ci_job {k} must be a non-empty string")
            age = v.get("max_age_days")
            if isinstance(age, bool) or not isinstance(age, (int, float)) or age <= 0:
                errs.append("ci_job with workflow needs max_age_days, a positive number")
    elif t == "script":
        if not _strings(v.get("run")):
            errs.append("script run must be a non-empty argument list")
        tmo = v.get("timeout", 120)
        if isinstance(tmo, bool) or not isinstance(tmo, int) or not 1 <= tmo <= 600:
            errs.append("script timeout must be 1 to 600 seconds")
    else:
        paths = v.get("paths")
        if not _strings(paths):
            errs.append("file_exists paths must be a non-empty list")
        elif any(p.startswith(("/", "\\")) or ".." in p.replace("\\", "/").split("/") for p in paths):
            errs.append("file_exists paths are relative to the repository root, without ..")
        if "signoff" in v and not isinstance(v["signoff"], bool):
            errs.append("file_exists signoff must be true or false")
        if v.get("signoff") and paths != [f"{SIGNOFF_DIR}/{item_id}.md"]:
            errs.append(f"a sign-off has exactly one path, {SIGNOFF_DIR}/{item_id}.md")
    return errs


def checklist_errors(data: object, phase: int) -> list[str]:
    """Every way the file breaks docs/go-live-checklist.schema.json; empty when it is valid."""
    if not isinstance(data, dict):
        return ["the checklist is not a JSON object"]
    errs = []
    extra = sorted(set(data) - {"$schema", "schema", "phase", "items"})
    if extra:
        errs.append(f"unknown top-level key(s) {', '.join(extra)}")
    if data.get("schema") != CHECKLIST_SCHEMA:
        errs.append(f"schema must be {CHECKLIST_SCHEMA}")
    if data.get("phase") != phase:
        errs.append(f"phase must be {phase}")
    items = data.get("items")
    if not isinstance(items, list) or not items:
        return errs + ["items must be a non-empty list"]
    seen: set[str] = set()
    for i, item in enumerate(items):
        if not isinstance(item, dict):
            errs.append(f"items[{i}] is not an object")
            continue
        iid = item.get("id")
        if not isinstance(iid, str) or not ID_RX.match(iid):
            errs.append(f"items[{i}]: id must be lower-case words joined by dashes")
            continue
        if iid in seen:
            errs.append(f"{iid}: duplicate id")
        seen.add(iid)
        extra = sorted(set(item) - ITEM_KEYS)
        if extra:
            errs.append(f"{iid}: unknown key(s) {', '.join(extra)}")
        if not (isinstance(item.get("title"), str) and item["title"].strip()):
            errs.append(f"{iid}: title missing")
        if not isinstance(item.get("required"), bool):
            errs.append(f"{iid}: required must be true or false")
        if item.get("who") not in WHO:
            errs.append(f"{iid}: who must be one of {', '.join(WHO)}")
        vs = item.get("verifier")
        vs = vs if isinstance(vs, list) else [vs]
        if not vs:
            errs.append(f"{iid}: the verifier list is empty")
        errs += [f"{iid}: {e}" for v in vs for e in verifier_errors(v, iid)]
    return errs


class Checklist:
    """Evaluates the items of a valid checklist for one phase."""

    def __init__(self, root: str, man: dict, gate: dict, ci: bool, ref: dt.datetime) -> None:
        self.root, self.man, self.gate, self.ci, self.ref = root, man, gate, ci, ref

    def item(self, item: dict) -> tuple[str, str]:
        vs = item["verifier"] if isinstance(item["verifier"], list) else [item["verifier"]]
        results = [self.verify(v, item) for v in vs]
        state = max((s for s, _ in results), key=lambda s: STATE_ORDER[s])
        if len(results) == 1:
            return state, results[0][1]
        return state, "; ".join(f"{v['type']} {s}: {d}" for v, (s, d) in zip(vs, results))

    def verify(self, v: dict, item: dict) -> tuple[str, str]:
        if v["type"] == "ci_job":
            return self.ci_jobs(v) if "jobs" in v else self.ci_workflow(v)
        if v["type"] == "script":
            return self.script(v)
        return self.files(v, item)

    def ci_jobs(self, v: dict) -> tuple[str, str]:
        if not self.ci:
            return "SKIP", "trusted to CI: " + ", ".join(v["jobs"])
        gate_job, workflow = self.gate["ci_job"], self.man["ci"]["workflow"]
        needs, defined = gate_needs(self.man, gate_job), workflow_jobs(self.root, workflow)
        ok, bad = [], []
        for job in v["jobs"]:
            if job == "self":
                # the gate job's own Pest step runs before gate_check, so a gate_check that runs at all saw it pass
                if f"--group={self.gate['pest_group']}" in defined.get(gate_job, ""):
                    ok.append("self")
                else:
                    bad.append(f"self: the {gate_job} job runs no --group={self.gate['pest_group']} step")
            elif job not in needs:
                bad.append(f"{job}: not a need of {gate_job}")
            elif job not in defined:
                bad.append(f"{job}: a need in the manifest but no such job in {workflow}")
            else:
                ok.append(job)
        if bad:
            return "FAIL", "; ".join(bad)
        return "PASS", f"needs of {gate_job}: {', '.join(ok)}"

    def ci_workflow(self, v: dict) -> tuple[str, str]:
        wf, job, branch = v["workflow"], v.get("job"), v.get("branch", "main")
        what = wf + (f" job {job}" if job else "") + f" on {branch}"
        repo = repo_slug(self.root)
        if not repo:
            return "FAIL", "cannot tell the repository (GITHUB_REPOSITORY or a github.com origin remote)"
        names = {job} if job else set()
        if job:
            m = re.search(r"^    name:[ \t]*(.+?)[ \t]*$", workflow_jobs(self.root, f".github/workflows/{wf}").get(job, ""), re.M)
            if m:
                names.add(m.group(1).strip("'\""))
        ref_iso = self.ref.astimezone(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
        query = urllib.parse.urlencode({"branch": branch, "status": "success", "per_page": 20, "created": f"<={ref_iso}"})
        finished = None
        try:
            for run in api_get(f"repos/{repo}/actions/workflows/{wf}/runs?{query}").get("workflow_runs", [])[:20]:
                if job:
                    jobs = api_get(f"repos/{repo}/actions/runs/{run['id']}/jobs?per_page=100").get("jobs", [])
                    done = [j["completed_at"] for j in jobs if j.get("name") in names and j.get("conclusion") == "success" and j.get("completed_at")]
                    if not done:
                        continue
                    when = parse_time(max(done))
                else:
                    when = parse_time(run.get("updated_at") or run["created_at"])
                if when <= self.ref:
                    finished = when
                    break
        except ApiError as e:
            return "FAIL", f"Actions API: {e}"
        if finished is None:
            return "FAIL", f"no successful run of {what} before {ref_iso}"
        age = self.ref - finished
        detail = f"{what}: newest success {finished:%Y-%m-%d %H:%M}Z, {age.total_seconds() / 86400:.1f} days before {ref_iso} (max {v['max_age_days']})"
        return ("PASS" if age <= dt.timedelta(days=v["max_age_days"]) else "FAIL"), detail

    def script(self, v: dict) -> tuple[str, str]:
        cmd = list(v["run"])
        if cmd[0] in ("python3", "python"):
            cmd[0] = sys.executable  # the interpreter running the gate: `python3` is missing or a store stub on Windows
        timeout = v.get("timeout", 120)
        try:
            proc = subprocess.run(cmd, cwd=self.root, text=True, encoding="utf-8", errors="replace", capture_output=True, timeout=timeout)
        except subprocess.TimeoutExpired:
            return "FAIL", f"timed out after {timeout} s"
        except OSError as e:
            return "FAIL", f"could not run {cmd[0]}: {e}"
        lines = [ln.strip() for ln in ((proc.stdout or "") + (proc.stderr or "")).strip().splitlines() if ln.strip()]
        tail = " | ".join(lines[-3:]) or "no output"
        return ("PASS", tail) if proc.returncode == 0 else ("FAIL", f"exit {proc.returncode}: {tail}")

    def files(self, v: dict, item: dict) -> tuple[str, str]:
        paths = v["paths"]
        missing = [p for p in paths if not (os.path.isfile(os.path.join(self.root, p)) and os.path.getsize(os.path.join(self.root, p)) > 0)]
        if not v.get("signoff"):
            if missing:
                return "FAIL", "missing or empty: " + ", ".join(missing)
            return "PASS", paths[0] if len(paths) == 1 else f"{len(paths)} files"
        path = paths[0]
        if missing:
            return "WAIT", f"{item.get('who', 'Ramin')}: {path} not signed yet (how to sign: docs/go-live/README.md)"
        problems = signoff_problems(self.root, path, utcnow())
        return ("FAIL", "; ".join(problems)) if problems else ("PASS", f"{path} signed in GitHub's web editor")


def signoff_committers(root: str, rel: str) -> list[str] | None:
    """`name<TAB>email` of the committer of every commit that touched rel; None when there is no history to read."""
    try:
        inside = subprocess.run(["git", "rev-parse", "--is-inside-work-tree"], cwd=root, text=True, capture_output=True)
        if inside.returncode != 0 or inside.stdout.strip() != "true":
            return None
        log = subprocess.run(["git", "log", "--format=%cn%x09%ce", "--", rel], cwd=root, text=True, encoding="utf-8",
                             errors="replace", capture_output=True)
    except OSError:
        return None
    if log.returncode != 0:
        return None
    return [ln for ln in log.stdout.splitlines() if ln.strip()]


def signoff_problems(root: str, rel: str, now: dt.datetime) -> list[str]:
    with open(os.path.join(root, rel), encoding="utf-8", errors="replace") as fh:
        text = fh.read()
    fields: dict[str, str] = {}
    for key, value in SIGNOFF_FIELD_RX.findall(text):
        fields.setdefault(key, value)
    problems = [f"field {k} missing" for k in SIGNOFF_FIELDS if not fields.get(k) or fields[k].startswith("<")]
    stem = os.path.splitext(os.path.basename(rel))[0]
    if fields.get("item") and not fields["item"].startswith("<") and fields["item"] != stem:
        problems.append(f"item is {fields['item']!r}, not {stem!r}")
    date = fields.get("date", "")
    if date and not date.startswith("<"):
        try:
            if not re.match(r"^\d{4}-\d{2}-\d{2}$", date):
                raise ValueError(date)
            if dt.date.fromisoformat(date) > now.astimezone(ADDIS).date():
                problems.append(f"date {date} is in the future")
        except ValueError:
            problems.append(f"date {date!r} is not YYYY-MM-DD")
    commits = signoff_committers(root, rel)
    if commits is None:
        problems.append("no git history to show it was signed in GitHub's web editor")
    elif not commits:
        problems.append("not committed: sign it in GitHub's web editor")
    else:
        local = [c for c in commits if c != WEB_EDITOR_COMMITTER]
        if local:
            problems.append(f"{len(local)} of {len(commits)} commit(s) not made in GitHub's web editor (committer {local[0].split(chr(9))[0]})")
    return problems


def check_checklist(root: str, man: dict, st: dict, n: int, a, rep: Report) -> None:
    gate = man["phases"][str(n)]["gate"]
    rel = gate.get("checklist")
    if not rel:
        return
    path = os.path.join(root, rel)
    if not os.path.exists(path):
        rep.add(False, "checklist", f"missing {rel}")
        return
    try:
        data = load(path)
    except ValueError as e:
        rep.add(False, "checklist", f"{rel} is not JSON: {e}")
        return
    errs = checklist_errors(data, n)
    for e in errs:
        rep.add(False, "checklist schema", e)
    if errs:
        return
    ph = st["phases"].get(str(n), {})
    claimed = ph.get("status") == "done" and bool(ph.get("done_at"))
    # now while the phase is open; done_at once claimed, so the gate on main does not turn red a week after go-live
    ref = parse_time(ph["done_at"]) if claimed else utcnow()
    rep.add(True, "checklist schema", f"{rel}: {len(data['items'])} items, reference time {ref:%Y-%m-%dT%H:%M}Z ({'done_at' if claimed else 'now'})")
    cl = Checklist(root, man, gate, bool(a.ci), ref)
    for item in data["items"]:
        state, detail = cl.item(item)
        if not item["required"] and state in ("FAIL", "WAIT"):
            state, detail = "WARN", f"optional, {state}: {detail}"
        rep.row(state, f"checklist {item['id']}", detail)


# ----------------------------------------------------------------------------- modes

def mode_entry(root: str, man: dict, st: dict, n: int, a, rep: Report, pest: PestRunner) -> None:
    key = str(n)
    if key not in man["phases"]:
        rep.add(False, "phase", f"phase {n} not in manifest"); return
    ph = st["phases"].get(key)
    if not ph:
        rep.add(False, "phase", f"phase {n} missing from STATUS.json"); return
    prev_commit = None
    if n > 0:
        prev = st["phases"].get(str(n - 1), {})
        prev_done = prev.get("status") == "done" and bool(prev.get("done_commit"))
        rep.add(prev_done, f"phase {n-1} done", f"status={prev.get('status')} done_commit={prev.get('done_commit')}")
        prev_commit = prev.get("done_commit")
        if prev_done and is_git_repo(root):
            code, _ = git(root, "merge-base", "--is-ancestor", prev_commit, "HEAD")
            rep.add(code == 0, f"phase {n-1} commit in history", f"{prev_commit[:12]} is {'an' if code == 0 else 'NOT an'} ancestor of HEAD")
        gate_prev = man["phases"][str(n - 1)]["gate"]["pest_group"]
        ok, detail = pest.group(gate_prev)
        rep.add(ok, f"{gate_prev} green", detail)
    else:
        rep.add(True, "phase 0 entry", "no previous phase")

    if is_git_repo(root):
        _, dirty = git(root, "status", "--porcelain")
        clean = not dirty.strip()
        if clean:
            rep.add(True, "clean working tree", "clean")
        else:
            rep.add(False, "clean working tree", f"{len(dirty.splitlines())} changed file(s)" + (" (allowed by --allow-dirty)" if a.allow_dirty else ""), warn=a.allow_dirty)

    plan_rel = ph.get("plan") or f"docs/phases/phase-{n}-plan.md"
    plan_exists = os.path.exists(os.path.join(root, plan_rel))
    rep.add(plan_exists, "phase plan exists", plan_rel)
    rep.add(bool(ph.get("plan_approved")), "plan approved by Ramin", f"plan_approved={ph.get('plan_approved')}"
            + (f" by {ph.get('plan_approved_by')}" if ph.get("plan_approved_by") else ""))
    if ph.get("plan_approved") and is_git_repo(root):
        _, authors = git(root, "log", "--format=%ae", "-S", '"plan_approved": true', "--", st.get("_path", "docs/phases/STATUS.json"))
        last = authors.splitlines()[0] if authors else ""
        approvers = man.get("human_approvers", [])
        if approvers:
            rep.add(last in approvers, "approval author", f"{last or 'uncommitted'}", warn=True)

    for pair in man["phases"][key]["contracts_required"]:
        consumer, provider = pair.split("->")
        provider_phase = man["modules"].get(provider, {}).get("phase")
        if provider_phase is not None and provider_phase >= n:
            # The provider itself is built in this phase (e.g. payments->orders in Phase 1): the contract
            # cannot be green at phase entry. It is required before the consumer's own step instead
            # (gate_check --mode module --module <provider> runs it once the consumer has written it).
            rep.add(None, f"contract {pair}", f"intra-phase: provider {provider} is built in phase {n}; write it before step '{consumer}', checked by --mode module --module {provider}")
            continue
        check_contract(root, man, pair, rep, pest, prev_commit, require_unmodified_consumer=(n > 0))

    phase_modules = [m for m, info in man["modules"].items() if info["phase"] == n]
    for m in phase_modules:
        for dep in man["modules"][m]["depends_on"]:
            dep_phase = man["modules"][dep]["phase"]
            if dep_phase < n:
                stable = st["modules"].get(dep, {}).get("status") == "stable"
                rep.add(stable, f"dep {m}->{dep} stable", st["modules"].get(dep, {}).get("status", "missing"))

    seeder = os.path.join(root, "apps", "api", "database", "seeders", f"Phase{n}Seeder.php")
    rep.add(os.path.exists(seeder) or None, f"Phase{n}Seeder", "exists" if os.path.exists(seeder) else "not yet (created during the phase)")


def mode_module(root: str, man: dict, st: dict, name: str, a, rep: Report, pest: PestRunner) -> None:
    if name not in man["modules"]:
        rep.add(False, "module", f"{name} not in manifest ({', '.join(man['modules'])})"); return
    info = man["modules"][name]
    for dep in info["depends_on"]:
        stable = st["modules"].get(dep, {}).get("status") == "stable"
        rep.add(stable, f"dep {dep} stable", st["modules"].get(dep, {}).get("status", "missing"))
    ok, detail = pest.group(f"module:{name}")
    rep.add(ok, f"module:{name} green", detail)
    consumers = [pair for pair in man["contracts"] if pair.endswith(f"->{name}")]
    present = [pair for pair in consumers if os.path.exists(os.path.join(root, man["contracts"][pair]["file"]))]
    for pair in present:
        ok, detail = pest.group(f"contract:{pair}")
        rep.add(ok, f"contract {pair} green", detail)
    if not present:
        rep.add(True, "consumer contracts", "none written yet (fine for a leaf or an early phase)")
    ok, detail = pest.group("arch")
    rep.add(ok, "arch boundaries green", detail)
    arch_file = os.path.join(root, "apps", "api", "tests", "Arch", "ModuleBoundariesTest.php")
    rep.add(os.path.exists(arch_file), "ModuleBoundariesTest exists", "apps/api/tests/Arch/ModuleBoundariesTest.php")
    mod_dir = os.path.join(root, "apps", "api", "app", "Shared" if name == "shared" else f"Modules/{name.capitalize()}")
    todo_hits = grep_files(root, [os.path.relpath(mod_dir, root)], "TODO(") if os.path.isdir(mod_dir) else []
    rep.add(not todo_hits, "no TODO markers in module", f"{len(todo_hits)} found" + (": " + ", ".join(todo_hits[:3]) if todo_hits else ""), warn=True)


def mode_done(root: str, man: dict, st: dict, n: int, a, rep: Report, pest: PestRunner) -> None:
    key = str(n)
    ph = st["phases"].get(key)
    mph = man["phases"].get(key)
    if not ph or not mph:
        rep.add(False, "phase", f"phase {n} missing"); return
    claimed = ph.get("status") == "done"
    informational = a.ci and not claimed
    gate = mph["gate"]

    # tests
    ok, detail = pest.group(gate["pest_group"])
    rep.add(ok, f"{gate['pest_group']} green", detail)
    for k in range(n):
        okk, d = pest.group(man["phases"][str(k)]["gate"]["pest_group"])
        rep.add(okk, f"gate:p{k} still green", d)
    ok, detail = pest.group("arch,invariants")
    rep.add(ok, "arch,invariants green", detail)

    # playwright
    projects = gate["playwright_projects"]
    web = os.path.join(root, "apps", "web")
    if a.ci or a.no_e2e or not pest.enabled:
        rep.add(None, "playwright projects", f"{', '.join(projects)} (trusted to CI / skipped)")
    elif os.path.isdir(web) and shutil.which("pnpm"):
        # the resolved path: on Windows it is pnpm.cmd, which CreateProcess does not find under the bare name "pnpm"
        args = [shutil.which("pnpm"), "exec", "playwright", "test"] + [x for p in projects for x in ("--project", p)]
        proc = subprocess.run(args, cwd=web, text=True, encoding="utf-8", errors="replace", capture_output=True)
        rep.add(proc.returncode == 0, "playwright projects", ", ".join(projects) + ("" if proc.returncode == 0 else "\n" + "\n".join((proc.stdout or "").splitlines()[-8:])))
    else:
        rep.add(False, "playwright projects", "apps/web or pnpm missing")

    # artifacts
    for adr in mph["adrs"]:
        path = os.path.join(root, "docs", "adr", f"{adr}.md")
        rep.add(os.path.exists(path), f"ADR {adr}", "present" if os.path.exists(path) else "missing")
    if not mph["adrs"]:
        rep.add(bool(ph.get("adr_waiver")) or True, "ADRs", "none required by manifest")
    report = os.path.join(root, "docs", "phases", f"phase-{n}-report.md")
    rep.add(os.path.exists(report), "gate report", f"docs/phases/phase-{n}-report.md")
    openapi = os.path.join(root, man["paths"]["openapi"])
    rep.add(os.path.exists(openapi) and os.path.getsize(openapi) > 2, "openapi.json present", man["paths"]["openapi"])
    erd = os.path.join(root, man["paths"]["erd"])
    if os.path.exists(erd):
        with open(erd, encoding="utf-8", errors="ignore") as fh:
            erd_text = fh.read()
        missing = []
        for m, info in man["modules"].items():
            if info["phase"] <= n:
                for t in info.get("tables", []):
                    tname = t.split(" ")[0]
                    if not re.search(rf"\bTable\s+{re.escape(tname)}\b", erd_text, re.I):
                        missing.append(tname)
        rep.add(not missing, "ERD covers tables", "all listed" if not missing else f"missing: {', '.join(missing[:8])}{' …' if len(missing) > 8 else ''}")
    else:
        rep.add(False, "ERD present", man["paths"]["erd"])

    # hygiene
    todo = grep_files(root, ["apps", "docs", ".github"], TODO_MARK.format(n=n))
    rep.add(not todo, f"no {TODO_MARK.format(n=n)} markers", f"{len(todo)} found" + (": " + ", ".join(todo[:3]) if todo else ""))
    phase_mods = [m for m, info in man["modules"].items() if info["phase"] == n]
    skips = []
    for m in phase_mods:
        tdir = os.path.join("apps", "api", "tests", "Modules", m.capitalize())
        skips += grep_files(root, [tdir], "->skip(")
    rep.add(not skips, "no ->skip( in phase module tests", f"{len(skips)} found" + (": " + ", ".join(skips[:3]) if skips else ""))

    # the go-live checklist, for a phase whose gate names one (phase 5)
    check_checklist(root, man, st, n, a, rep)

    # status bookkeeping
    if claimed:
        dc = ph.get("done_commit")
        rep.add(bool(dc), "done_commit recorded", dc or "missing")
        if dc and is_git_repo(root):
            code, _ = git(root, "merge-base", "--is-ancestor", dc, "HEAD")
            rep.add(code == 0, "done_commit in history", f"{dc[:12]}")
            # The claim is "this commit is the phase as built": until the next phase starts, only docs and STATUS may
            # follow it. Once phase N+1 is in_progress (its entry gate passed) app code moves on with that phase;
            # this gate then keeps proving phase N through its tests and Playwright projects, not by freezing apps/.
            nxt = st["phases"].get(str(n + 1), {})
            if nxt.get("status", "todo") not in ("todo", "planning", "plan_approved"):
                rep.add(None, "no app code after done_commit", f"phase {n + 1} is {nxt.get('status')}: app code moves on with it")
            else:
                code2, diff = git(root, "diff", "--name-only", dc, "HEAD", "--", "apps")
                rep.add(not diff.strip(), "no app code after done_commit", "only docs changed since" if not diff.strip() else f"{len(diff.splitlines())} app file(s) changed after done_commit")
        rep.add(all(v == "done" for v in ph["modules"].values()), "all phase steps done", ", ".join(f"{k}={v}" for k, v in ph["modules"].items() if v != "done") or "all done")
        for m in phase_mods:
            rep.add(st["modules"].get(m, {}).get("status") == "stable", f"module {m} stable", st["modules"].get(m, {}).get("status", "missing"))
    else:
        rep.add(None, "phase claimed done", f"status={ph.get('status')} (informational run)" if informational else f"status={ph.get('status')}: run phase_status.py claim-done {n} after this passes")

    if informational:
        # downgrade failures to warnings: the gate job is a required check while the phase is in progress;
        # a checklist FAIL keeps its word in the detail, and a WAIT row stays WAIT without failing the job
        rep.rows = [("WARN", c, f"FAIL: {d}" if c.startswith("checklist ") else d) if s == "FAIL" else (s, c, d) for s, c, d in rep.rows]
        rep.wait_blocks = False


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--mode", required=True, choices=["entry", "module", "done"])
    ap.add_argument("--phase", type=int)
    ap.add_argument("--module")
    ap.add_argument("--ci", action="store_true")
    ap.add_argument("--no-tests", action="store_true")
    ap.add_argument("--no-e2e", action="store_true")
    ap.add_argument("--allow-dirty", action="store_true")
    ap.add_argument("--runner")
    ap.add_argument("--root")
    ap.add_argument("--status")
    ap.add_argument("--manifest")
    a = ap.parse_args(argv)

    root = find_repo_root(a.root)
    manifest_path = a.manifest or os.path.join(root, ".claude", "skills", "bakery-ops-builder", "assets", "dependency-manifest.json")
    status_path = a.status or os.path.join(root, "docs", "phases", "STATUS.json")
    if not os.path.exists(manifest_path):
        sys.exit(f"gate_check: manifest not found at {manifest_path}")
    if not os.path.exists(status_path):
        sys.exit(f"gate_check: STATUS.json not found at {status_path} (phase_status.py init)")
    man = load(manifest_path)
    st = load(status_path)
    st["_path"] = os.path.relpath(status_path, root)

    pest = PestRunner(root, a.runner, enabled=not (a.no_tests or a.ci))
    rep = Report()
    if a.mode == "entry":
        if a.phase is None:
            ap.error("--phase required")
        mode_entry(root, man, st, a.phase, a, rep, pest)
        rep.print(f"entry phase {a.phase} ({man['phases'][str(a.phase)]['name'] if str(a.phase) in man['phases'] else '?'})")
    elif a.mode == "module":
        if not a.module:
            ap.error("--module required")
        mode_module(root, man, st, a.module, a, rep, pest)
        rep.print(f"module {a.module}")
    else:
        if a.phase is None:
            ap.error("--phase required")
        mode_done(root, man, st, a.phase, a, rep, pest)
        rep.print(f"done phase {a.phase}")
    return 1 if rep.failed else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
