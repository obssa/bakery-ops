#!/usr/bin/env python3
"""Cross-platform task runner for bakery-ops (the host is Windows without `make`; CI is Linux).

  python scripts/task.py up            start db, redis, app (dev overlay), install vendor if missing, migrate
  python scripts/task.py down          stop the stack
  python scripts/task.py doctor        environment sanity (docker, PHP 8.3 in container, node 24, LF endings, gh)
  python scripts/task.py lint          pint --test + phpstan (in container)
  python scripts/task.py arch          pest arch,invariants + migration lint
  python scripts/task.py test [groups] pest, optionally --group=<groups>
  python scripts/task.py modules       pest module groups in dependency order (stops at the first red module)
  python scripts/task.py invariants    check_invariants.py --all (+ route list from artisan)
  python scripts/task.py web-lint      eslint + tsc
  python scripts/task.py web-build     next build
  python scripts/task.py e2e [projects] playwright projects for phases <= current (needs the stack up)
  python scripts/task.py ci            everything GitHub Actions runs, in the same order
  python scripts/task.py gate N        gate_check.py --mode done --phase N (local, runs tests)

PHP never runs on the host: every PHP step is `docker compose exec app ...`.
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
API = os.path.join(ROOT, "apps", "api")
WEB = os.path.join(ROOT, "apps", "web")
COMPOSE = ["docker", "compose", "-f", "compose.yml", "-f", "compose.dev.yml"]
MODULE_ORDER = ["identity", "catalog", "inventory", "orders", "payments", "production", "notifications", "shifts", "analytics"]
MODULE_EXTRA = {"inventory": ",property,concurrency", "shifts": ",seeds"}  # same groups as ci.yml mod-inventory and mod-shifts


def sh(cmd: list[str], cwd: str = ROOT, check: bool = True, env: dict | None = None, capture: bool = False) -> subprocess.CompletedProcess:
    print("$", " ".join(cmd), flush=True)
    e = dict(os.environ)
    if env:
        e.update(env)
    return subprocess.run(cmd, cwd=cwd, check=check, env=e, text=True, capture_output=capture)


def app(*args: str, check: bool = True, capture: bool = False) -> subprocess.CompletedProcess:
    return sh([*COMPOSE, "exec", "-T", "app", *args], check=check, capture=capture)


def pnpm() -> str:
    return "pnpm.cmd" if os.name == "nt" and shutil.which("pnpm.cmd") else "pnpm"


def current_phase() -> int:
    with open(os.path.join(ROOT, "docs", "phases", "STATUS.json"), encoding="utf-8") as fh:
        return int(json.load(fh)["current_phase"])


def ensure_env() -> None:
    env = os.path.join(API, ".env")
    if not os.path.exists(env):
        shutil.copy(os.path.join(API, ".env.example"), env)
        print("created apps/api/.env from .env.example")


def task_up() -> None:
    ensure_env()
    sh([*COMPOSE, "up", "-d", "--build", "--wait", "db", "redis"])
    sh([*COMPOSE, "up", "-d", "--build", "app"])
    r = app("test", "-f", "vendor/autoload.php", check=False)
    if r.returncode != 0:
        app("composer", "install", "--no-interaction", "--no-progress")
    r = app("php", "-r", "echo file_get_contents('.env');", check=False, capture=True)
    if "APP_KEY=base64:" not in (r.stdout or ""):
        app("php", "artisan", "key:generate", "--force", "--no-interaction")
    sh([*COMPOSE, "up", "-d", "--wait", "app"])
    app("php", "artisan", "migrate", "--force", "--no-interaction")
    print("stack up: API http://localhost:8000  (web: cd apps/web && pnpm dev)")


def task_down() -> None:
    sh([*COMPOSE, "down"], check=False)


def task_doctor() -> int:
    ok = True

    def check(label: str, cond: bool, detail: str = "") -> None:
        nonlocal ok
        ok &= cond
        print(f"  [{'ok' if cond else 'XX'}] {label} {detail}")

    check("docker on PATH", shutil.which("docker") is not None)
    r = subprocess.run(["docker", "info", "--format", "{{.ServerVersion}}"], text=True, capture_output=True)
    check("docker daemon", r.returncode == 0, (r.stdout or r.stderr).strip())
    r = subprocess.run(["node", "-v"], text=True, capture_output=True)
    check("node 24", r.stdout.startswith("v24"), r.stdout.strip())
    check("pnpm", shutil.which("pnpm") is not None or shutil.which("pnpm.cmd") is not None)
    r = subprocess.run(["git", "config", "core.autocrlf"], cwd=ROOT, text=True, capture_output=True)
    check("git core.autocrlf is false/unset", r.stdout.strip() in ("", "false", "input"), r.stdout.strip() or "unset")
    r = app("php", "-r", "echo PHP_VERSION;", check=False, capture=True)
    check("PHP 8.3 in container", (r.stdout or "").startswith("8.3"), (r.stdout or "container not running: run `task.py up`").strip())
    check("gh CLI", shutil.which("gh") is not None)
    check("apps/api/.env", os.path.exists(os.path.join(API, ".env")), "(task.py up creates it)")
    for f in ("scripts/gate_check.py", "scripts/phase_status.py", "scripts/check_invariants.py"):
        check(f, os.path.exists(os.path.join(ROOT, f)))
    print("doctor:", "all good" if ok else "problems found")
    return 0 if ok else 1


def task_lint() -> None:
    app("vendor/bin/pint", "--test")
    app("vendor/bin/phpstan", "analyse", "--memory-limit=1G", "--no-progress")


def task_arch() -> None:
    sh([sys.executable, "scripts/check_invariants.py", "--numeric", "--branch-id", "--bilingual", "--append-only"])
    app("vendor/bin/pest", "--group=arch", "--group=invariants", "--colors=never")


def task_test(groups: str | None) -> None:
    args = ["vendor/bin/pest", "--colors=never"]
    for g in (groups.split(",") if groups else []):
        args.append(f"--group={g}")
    app(*args)


def task_modules() -> None:
    """Same rule as .github/workflows/module-tests.yml: a module still `todo` in STATUS.json may have no
    tests; once it is in_progress or stable, an empty group is a failure (a job that runs nothing proves nothing)."""
    phase = current_phase()
    with open(os.path.join(ROOT, "docs", "phases", "STATUS.json"), encoding="utf-8") as fh:
        module_status = {k: v.get("status", "todo") for k, v in json.load(fh)["modules"].items()}
    for m in MODULE_ORDER:
        status = module_status.get(m, "todo")
        groups = f"module:{m}{MODULE_EXTRA.get(m, '')}"
        print(f"-- module {m} [{status}] ({groups})")
        r = app("vendor/bin/pest", *[f"--group={g}" for g in groups.split(",")], "--colors=never", check=False, capture=True)
        out = (r.stdout or "") + (r.stderr or "")
        print(out[-3000:])
        if "No tests found" in out:
            if status == "todo":
                print(f"   (no tests tagged {groups} yet: allowed while the module is todo)")
                continue
            sys.exit(f"module {m} is {status} but has no tests tagged {groups}: a module that runs nothing proves nothing")
        if r.returncode != 0:
            sys.exit(f"module {m} is red; everything after it in the dependency order was not run (phase {phase})")


def task_invariants() -> None:
    os.makedirs(os.path.join(API, "tmp"), exist_ok=True)
    r = app("php", "artisan", "route:list", "--json", check=False, capture=True)
    routes = os.path.join(API, "tmp", "routes.json")
    if r.returncode == 0:
        with open(routes, "w", encoding="utf-8") as fh:
            fh.write(r.stdout)
    sh([sys.executable, "scripts/check_invariants.py", "--all", "--routes", routes])


def task_web_lint() -> None:
    sh([pnpm(), "install", "--frozen-lockfile"], cwd=WEB)
    sh([pnpm(), "lint"], cwd=WEB)
    sh([pnpm(), "exec", "tsc", "--noEmit"], cwd=WEB)


def task_web_build() -> None:
    sh([pnpm(), "build"], cwd=WEB, env={"NEXT_PUBLIC_API_URL": "http://localhost:8000"})


NOT_STARTED = ("todo", "planning", "plan_approved")


def task_e2e(projects: list[str]) -> None:
    """Same rule as .github/workflows/e2e.yml: a project p<N>-<flow> of a phase that has not started (todo, planning,
    plan_approved) may have no specs yet; once phase N is in_progress or later, each of its projects must list a test."""
    if not projects:
        with open(os.path.join(ROOT, ".claude", "skills", "bakery-ops-builder", "assets", "dependency-manifest.json"), encoding="utf-8") as fh:
            man = json.load(fh)
        projects = [p for n, ph in man["phases"].items() if int(n) <= current_phase() for p in ph["gate"]["playwright_projects"]]
    with open(os.path.join(ROOT, "docs", "phases", "STATUS.json"), encoding="utf-8") as fh:
        phases = json.load(fh)["phases"]
    started = False
    for p in projects:
        m = re.match(r"p(\d+)-", p)
        if not m:
            sys.exit(f"Playwright project {p!r} is not named p<phase>-<flow>")
        status = phases.get(m.group(1), {}).get("status", "todo")
        if status in NOT_STARTED:
            print(f"   {p}: phase {m.group(1)} is {status}, no specs required yet")
            continue
        started = True
        r = sh([pnpm(), "exec", "playwright", "test", "--list", f"--project={p}"], cwd=WEB, check=False, capture=True)
        if r.returncode != 0:
            sys.exit(f"phase {m.group(1)} is {status} but Playwright project {p} has no tests")
    args = [pnpm(), "exec", "playwright", "test"] + [f"--project={p}" for p in projects] + ([] if started else ["--pass-with-no-tests"])
    sh(args, cwd=WEB, env={"NEXT_PUBLIC_API_URL": "http://localhost:8000", "PLAYWRIGHT_WEB_SERVER": "next-start"})


def task_ci() -> None:
    task_lint()
    task_arch()
    task_modules()
    task_invariants()
    task_web_lint()
    task_web_build()
    task_e2e([])
    print("\nlocal ci: green")


def task_gate(n: str) -> None:
    sh([sys.executable, "scripts/gate_check.py", "--mode", "done", "--phase", n])


def main(argv: list[str]) -> int:
    if not argv or argv[0] in ("-h", "--help", "help"):
        print(__doc__)
        return 0
    cmd, rest = argv[0], argv[1:]
    try:
        if cmd == "up": task_up()
        elif cmd == "down": task_down()
        elif cmd == "doctor": return task_doctor()
        elif cmd == "lint": task_lint()
        elif cmd == "arch": task_arch()
        elif cmd == "test": task_test(rest[0] if rest else None)
        elif cmd == "modules": task_modules()
        elif cmd == "invariants": task_invariants()
        elif cmd == "web-lint": task_web_lint()
        elif cmd == "web-build": task_web_build()
        elif cmd == "e2e": task_e2e(rest)
        elif cmd == "ci": task_ci()
        elif cmd == "gate": task_gate(rest[0] if rest else str(current_phase()))
        else:
            print(__doc__); return 2
    except subprocess.CalledProcessError as e:
        print(f"task {cmd} failed (exit {e.returncode})")
        return e.returncode or 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
