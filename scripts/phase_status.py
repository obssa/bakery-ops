#!/usr/bin/env python3
"""Read and update docs/phases/STATUS.json for the bakery-ops phase-gated build.

Stdlib only. Run from anywhere inside the repo.

  phase_status.py show
  phase_status.py init                      # create STATUS.json from the manifest (phase 0 in_progress)
  phase_status.py set-phase-status N STATUS # todo|planning|plan_approved|in_progress|gate_pending|done
  phase_status.py module NAME STATUS        # todo|in_progress|done   (within the module's phase)
  phase_status.py module-stable NAME        # top-level module status -> stable (after gate_check --mode module)
  phase_status.py claim-done N [--commit SHA] [--run-url URL]
  phase_status.py set-run-url N URL
  phase_status.py validate                  # against assets/status.schema.json (structural subset)

`plan_approved` is intentionally NOT settable here: Ramin flips it in his own commit.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import subprocess
import sys

PHASE_STATUSES = ["todo", "planning", "plan_approved", "in_progress", "gate_pending", "done"]
MODULE_PHASE_STATUSES = ["todo", "in_progress", "done"]
MODULE_STATUSES = ["todo", "in_progress", "stable"]


def find_repo_root(start: str | None = None) -> str:
    cur = os.path.abspath(start or os.getcwd())
    while True:
        if os.path.isdir(os.path.join(cur, ".git")) or os.path.isfile(os.path.join(cur, "CLAUDE.md")):
            return cur
        parent = os.path.dirname(cur)
        if parent == cur:
            sys.exit("phase_status: could not find the repo root (no .git / CLAUDE.md upwards)")
        cur = parent


def paths(root: str) -> dict:
    skill = os.path.join(root, ".claude", "skills", "bakery-ops-builder")
    return {
        "root": root,
        "status": os.path.join(root, "docs", "phases", "STATUS.json"),
        "manifest": os.path.join(skill, "assets", "dependency-manifest.json"),
        "schema": os.path.join(skill, "assets", "status.schema.json"),
    }


def load_json(path: str) -> dict:
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def save_json(path: str, data: dict) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8", newline="\n") as fh:
        json.dump(data, fh, indent=2, ensure_ascii=False)
        fh.write("\n")


def now_iso() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def git(root: str, *args: str) -> str:
    return subprocess.check_output(["git", *args], cwd=root, text=True).strip()


def build_initial_status(manifest: dict) -> dict:
    phases = {}
    for n, ph in manifest["phases"].items():
        step_modules = {s["module"]: "todo" for s in ph["steps"]}
        phases[n] = {
            "status": "in_progress" if n == "0" else "todo",
            "plan": f"docs/phases/phase-{n}-plan.md",
            "plan_approved": False,
            "plan_approved_by": None,
            "report": None,
            "gate_job": ph["gate"]["ci_job"],
            "gate_run_url": None,
            "done_commit": None,
            "done_at": None,
            "adrs": [],
            "adr_waiver": None,
            "contracts_required": ph["contracts_required"],
            "modules": step_modules,
            "playwright_projects": ph["gate"]["playwright_projects"],
        }
    modules = {name: {"phase": m["phase"], "status": "todo", "stable_since": None}
               for name, m in manifest["modules"].items()}
    return {"schema_version": 1, "current_phase": 0, "invariants_version": 1,
            "phases": phases, "modules": modules}


def cmd_show(p: dict, status: dict, manifest: dict) -> None:
    cur = str(status["current_phase"])
    ph = status["phases"][cur]
    mph = manifest["phases"][cur]
    print(f"bakery-ops  current phase {cur}: {mph['name']}  [{ph['status']}]  plan_approved={ph['plan_approved']}")
    print(f"  gate job {ph['gate_job']}  run {ph['gate_run_url'] or '-'}  done_commit {ph['done_commit'] or '-'}")
    print("  phase steps:")
    for step in mph["steps"]:
        st = ph["modules"].get(step["module"], "todo")
        mark = {"done": "x", "in_progress": "~", "todo": " "}[st]
        print(f"   [{mark}] {step['id']:>4} {step['module']:<24} {step['builds'][:80]}")
    nxt = next((s for s in mph["steps"] if ph["modules"].get(s["module"], "todo") != "done"), None)
    if nxt:
        deps = manifest["modules"].get(nxt["module"], {}).get("depends_on", [])
        unstable = [d for d in deps if status["modules"].get(d, {}).get("status") != "stable"]
        print(f"  next: {nxt['id']} {nxt['module']}"
              + (f"  (waiting on unstable deps: {', '.join(unstable)})" if unstable else "  (all deps stable)"))
    else:
        print("  next: all steps done -> run gate_check.py --mode done --phase", cur)
    print("  modules:", ", ".join(f"{k}={v['status']}" for k, v in status["modules"].items()))
    print("  contracts required this phase:", ", ".join(ph["contracts_required"]) or "-")
    for n in sorted(status["phases"], key=int):
        if n != cur:
            print(f"  phase {n}: {status['phases'][n]['status']}")


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--root", help="repo root (default: auto-detect)")
    sub = ap.add_subparsers(dest="cmd", required=True)
    sub.add_parser("show")
    sub.add_parser("init")
    sub.add_parser("validate")
    s = sub.add_parser("set-phase-status"); s.add_argument("phase"); s.add_argument("status", choices=PHASE_STATUSES)
    s = sub.add_parser("module"); s.add_argument("name"); s.add_argument("status", choices=MODULE_PHASE_STATUSES)
    s = sub.add_parser("module-stable"); s.add_argument("name")
    s = sub.add_parser("claim-done"); s.add_argument("phase"); s.add_argument("--commit"); s.add_argument("--run-url")
    s = sub.add_parser("set-run-url"); s.add_argument("phase"); s.add_argument("url")
    a = ap.parse_args(argv)

    p = paths(find_repo_root(a.root))
    manifest = load_json(p["manifest"])

    if a.cmd == "init":
        if os.path.exists(p["status"]):
            sys.exit(f"refusing to overwrite existing {p['status']}")
        save_json(p["status"], build_initial_status(manifest))
        print("created", p["status"])
        return 0

    if not os.path.exists(p["status"]):
        sys.exit(f"missing {p['status']} (run: phase_status.py init)")
    status = load_json(p["status"])

    if a.cmd == "show":
        cmd_show(p, status, manifest)
        return 0

    if a.cmd == "validate":
        errs = validate(status, manifest)
        for e in errs:
            print("INVALID:", e)
        print("STATUS.json valid" if not errs else f"{len(errs)} problem(s)")
        return 1 if errs else 0

    if a.cmd == "set-phase-status":
        ph = status["phases"][a.phase]
        if a.status == "plan_approved" or a.status == "done":
            sys.exit("plan_approved is set by Ramin's commit; done is set by claim-done")
        ph["status"] = a.status
        if a.status == "in_progress":
            status["current_phase"] = int(a.phase)
        save_json(p["status"], status)
        print(f"phase {a.phase} -> {a.status}")
        return 0

    if a.cmd == "module":
        phase_no = str(manifest["modules"].get(a.name, {}).get("phase", status["current_phase"]))
        ph = status["phases"][phase_no]
        ph["modules"][a.name] = a.status
        if a.name in status["modules"] and a.status == "in_progress":
            status["modules"][a.name]["status"] = "in_progress"
        save_json(p["status"], status)
        print(f"phase {phase_no} module {a.name} -> {a.status}")
        return 0

    if a.cmd == "module-stable":
        if a.name not in status["modules"]:
            sys.exit(f"unknown module {a.name}; manifest modules: {', '.join(manifest['modules'])}")
        status["modules"][a.name]["status"] = "stable"
        status["modules"][a.name]["stable_since"] = now_iso()
        phase_no = str(manifest["modules"][a.name]["phase"])
        status["phases"][phase_no]["modules"][a.name] = "done"
        save_json(p["status"], status)
        print(f"module {a.name} -> stable (phase {phase_no} step marked done)")
        return 0

    if a.cmd == "claim-done":
        ph = status["phases"][a.phase]
        commit = a.commit or git(p["root"], "rev-parse", "HEAD")
        ph["status"] = "done"
        ph["done_commit"] = commit
        ph["done_at"] = now_iso()
        ph["report"] = f"docs/phases/phase-{a.phase}-report.md"
        ph["adrs"] = manifest["phases"][a.phase]["adrs"]
        if a.run_url:
            ph["gate_run_url"] = a.run_url
        nxt = str(int(a.phase) + 1)
        if nxt in status["phases"]:
            status["current_phase"] = int(nxt)
            if status["phases"][nxt]["status"] == "todo":
                status["phases"][nxt]["status"] = "planning"
        save_json(p["status"], status)
        print(f"phase {a.phase} claimed done at {commit[:12]}; current phase now {status['current_phase']}")
        print("next: commit this STATUS change (docs only), push, wait for the gate job, then set-run-url")
        return 0

    if a.cmd == "set-run-url":
        status["phases"][a.phase]["gate_run_url"] = a.url
        save_json(p["status"], status)
        print("recorded")
        return 0
    return 2


def validate(status: dict, manifest: dict) -> list[str]:
    errs = []
    if status.get("schema_version") != 1:
        errs.append("schema_version must be 1")
    cur = status.get("current_phase")
    if not isinstance(cur, int) or not 0 <= cur <= 5:
        errs.append("current_phase must be 0..5")
    for n, ph in status.get("phases", {}).items():
        if n not in manifest["phases"]:
            errs.append(f"phase {n} not in manifest")
        if ph.get("status") not in PHASE_STATUSES:
            errs.append(f"phase {n}: bad status {ph.get('status')}")
        if ph.get("status") == "done" and not ph.get("done_commit"):
            errs.append(f"phase {n}: done without done_commit")
        for c in ph.get("contracts_required", []):
            if c not in manifest["contracts"]:
                errs.append(f"phase {n}: unknown contract {c}")
        for m, st in ph.get("modules", {}).items():
            if st not in MODULE_PHASE_STATUSES:
                errs.append(f"phase {n}: module {m} bad status {st}")
    for m, info in status.get("modules", {}).items():
        if m not in manifest["modules"]:
            errs.append(f"module {m} not in manifest")
        if info.get("status") not in MODULE_STATUSES:
            errs.append(f"module {m}: bad status {info.get('status')}")
    for m in manifest["modules"]:
        if m not in status.get("modules", {}):
            errs.append(f"manifest module {m} missing from STATUS.modules")
    return errs


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
