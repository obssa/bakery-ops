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

Exit 0 = all checks passed, 1 = at least one failed, 2 = usage.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import sys

TODO_MARK = "TODO(p{n})"


class Report:
    def __init__(self) -> None:
        self.rows: list[tuple[str, str, str]] = []  # (PASS|FAIL|WARN|SKIP, check, detail)

    def add(self, ok: bool | None, check: str, detail: str = "", warn: bool = False) -> None:
        state = "SKIP" if ok is None else ("PASS" if ok else ("WARN" if warn else "FAIL"))
        self.rows.append((state, check, detail))

    @property
    def failed(self) -> bool:
        return any(s == "FAIL" for s, _, _ in self.rows)

    def print(self, title: str) -> None:
        print(f"== gate_check: {title}")
        width = max((len(c) for _, c, _ in self.rows), default=10)
        for state, check, detail in self.rows:
            print(f"  [{state}] {check.ljust(width)}  {detail}")
        n_fail = sum(1 for s, _, _ in self.rows if s == "FAIL")
        n_warn = sum(1 for s, _, _ in self.rows if s == "WARN")
        print(f"== result: {'FAIL' if n_fail else 'PASS'} ({n_fail} failed, {n_warn} warnings, {len(self.rows)} checks)")


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
    return os.path.isdir(os.path.join(root, ".git"))


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
        self.cmd = self._resolve(runner)

    def _resolve(self, runner: str | None) -> list[str] | None:
        if runner:
            return runner.split()
        # Prefer the compose service directly: on Windows `bash` may resolve to WSL's stub, so never
        # go through the scripts/pest wrapper here. Host PHP (8.0) is never used.
        if shutil.which("docker") and os.path.exists(os.path.join(self.root, "compose.yml")):
            probe = subprocess.run(["docker", "compose", "-f", "compose.yml", "-f", "compose.dev.yml", "ps", "-q", "app"],
                                   cwd=self.root, text=True, capture_output=True)
            if probe.returncode == 0 and probe.stdout.strip():
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
        proc = subprocess.run([*self.cmd, *[f"--group={g}" for g in groups.split(",")], "--colors=never"], cwd=self.root,
                              text=True, capture_output=True)
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
        if provider_phase is not None and provider_phase >= n and n > 0:
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
        args = ["pnpm", "exec", "playwright", "test"] + [x for p in projects for x in ("--project", p)]
        proc = subprocess.run(args, cwd=web, text=True, capture_output=True)
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

    # status bookkeeping
    if claimed:
        dc = ph.get("done_commit")
        rep.add(bool(dc), "done_commit recorded", dc or "missing")
        if dc and is_git_repo(root):
            code, _ = git(root, "merge-base", "--is-ancestor", dc, "HEAD")
            rep.add(code == 0, "done_commit in history", f"{dc[:12]}")
            code2, diff = git(root, "diff", "--name-only", dc, "HEAD", "--", "apps")
            rep.add(not diff.strip(), "no app code after done_commit", "only docs changed since" if not diff.strip() else f"{len(diff.splitlines())} app file(s) changed after done_commit")
        rep.add(all(v == "done" for v in ph["modules"].values()), "all phase steps done", ", ".join(f"{k}={v}" for k, v in ph["modules"].items() if v != "done") or "all done")
        for m in phase_mods:
            rep.add(st["modules"].get(m, {}).get("status") == "stable", f"module {m} stable", st["modules"].get(m, {}).get("status", "missing"))
    else:
        rep.add(None, "phase claimed done", f"status={ph.get('status')} (informational run)" if informational else f"status={ph.get('status')}: run phase_status.py claim-done {n} after this passes")

    if informational:
        # downgrade failures to warnings: the gate job is a required check while the phase is in progress
        rep.rows = [("WARN" if s == "FAIL" else s, c, d) for s, c, d in rep.rows]


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
