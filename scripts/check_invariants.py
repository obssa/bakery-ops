#!/usr/bin/env python3
"""Repo-level invariant checks for bakery-ops that need no PHP: run on the host, in CI, in a pre-commit.

  check_invariants.py --all [--routes apps/api/tmp/routes.json] [--strict-i18n]
  check_invariants.py --numeric | --branch-id | --bilingual | --append-only | --idempotency | --i18n
  check_invariants.py --selftest        # plants violations in a temp dir and asserts each is caught

What each check does (details in references/invariants.md):
  numeric      migrations: no float/double/real columns; money columns decimal(12,2); qty columns decimal(14,4);
               no (float) casts in app/Modules or app/Shared
  branch-id    every Schema::create table has branch_id unless allowlisted or a later Schema::table adds it
  bilingual    in every Schema::create, *_en columns have an *_am sibling and vice versa
  append-only  stock_movements, audit_log, order_events have a forbid_update_delete trigger in some migration
  idempotency  (needs `artisan route:list --json` output) every write route under api/v1 has idempotency +
               auth:sanctum + a permission/can middleware unless in the public allowlist; a read-like write (a write
               method that writes nothing, READ_LIKE_WRITES) needs no idempotency but keeps auth + permission
  i18n         apps/web/messages/en.json and am.json have identical key sets (am may hold __TODO__ unless --strict-i18n)
Exit 0 clean, 1 violations, 2 usage.
"""
from __future__ import annotations

import argparse
import glob
import json
import os
import re
import sys
import tempfile

BRANCH_ALLOWLIST = {
    "migrations", "jobs", "job_batches", "failed_jobs", "cache", "cache_locks", "sessions",
    "password_reset_tokens", "personal_access_tokens", "permissions", "roles", "model_has_permissions",
    "model_has_roles", "role_has_permissions", "branches", "units", "idempotency_keys", "telescope_entries",
    "pulse_values", "pulse_entries", "pulse_aggregates", "activity_log", "notifications_dedupe",
}
APPEND_ONLY_TABLES = ["stock_movements", "audit_log", "order_events", "item_cost_history"]
PUBLIC_ROUTES = {"api/v1/login", "api/v1/health", "api/v1/auth/login", "api/v1/short-code/verify"}
# write-method routes that write nothing (mirrors apps/api/tests/Support/read_like_writes.php)
READ_LIKE_WRITES = {"POST api/v1/broadcasting/auth"}
MONEY_RX = re.compile(r"(price|amount|total|subtotal|discount|tax|deposit|float)", re.I)
# invariants version 2: a column with the word `cost` in its name (unit_cost, moving_avg_cost, cost_after) is a rate per
# base unit, decimal(14,4); a total of money is named *_total and stays decimal(12,2)
COST_RATE_RX = re.compile(r"(^|_)cost(_|$)", re.I)
QTY_RX = re.compile(r"(^qty$|_qty$|quantity)", re.I)
FORBIDDEN_COL_RX = re.compile(r"->\s*(float|double|unsignedFloat|unsignedDouble)\s*\(\s*['\"](\w+)['\"]")
RAW_FORBIDDEN_RX = re.compile(r"\b(real|double precision|float4|float8)\b", re.I)
DECIMAL_RX = re.compile(r"->\s*decimal\s*\(\s*['\"](\w+)['\"]\s*,\s*(\d+)\s*,\s*(\d+)\s*\)")
COL_RX = re.compile(r"->\s*(?:string|text|json|jsonb|integer|bigInteger|decimal|boolean|date|dateTime|timestamp|uuid|foreignId|foreignUuid|unsignedBigInteger|char|enum)\s*\(\s*['\"](\w+)['\"]")
# closures may carry a `use (...)` clause and a `: void` return type before the brace
CREATE_RX = re.compile(r"Schema::create\(\s*['\"](\w+)['\"]\s*,\s*function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?(?::\s*\w+\s*)?\{", re.S)
TABLE_RX = re.compile(r"Schema::table\(\s*['\"](\w+)['\"]\s*,\s*function\s*\([^)]*\)\s*(?:use\s*\([^)]*\)\s*)?(?::\s*\w+\s*)?\{", re.S)


class Findings:
    def __init__(self) -> None:
        self.items: list[tuple[str, str]] = []

    def add(self, check: str, msg: str) -> None:
        self.items.append((check, msg))

    def report(self, checks_run: list[str]) -> int:
        for check, msg in self.items:
            print(f"[{check}] {msg}")
        print(f"check_invariants: {len(self.items)} violation(s) across {', '.join(checks_run)}")
        return 1 if self.items else 0


def create_blocks(src: str, rx=CREATE_RX):
    """Yield (table, body) for each Schema::create block (brace-matched); pass TABLE_RX for Schema::table blocks."""
    for m in rx.finditer(src):
        depth, i = 1, m.end()
        while i < len(src) and depth:
            if src[i] == "{":
                depth += 1
            elif src[i] == "}":
                depth -= 1
            i += 1
        yield m.group(1), src[m.end():i - 1]


def migration_files(root: str) -> list[str]:
    return sorted(glob.glob(os.path.join(root, "apps", "api", "database", "migrations", "*.php")))


def read(path: str) -> str:
    with open(path, encoding="utf-8", errors="ignore") as fh:
        return fh.read()


def check_numeric(root: str, f: Findings) -> None:
    for path in migration_files(root):
        rel = os.path.relpath(path, root)
        src = read(path)
        for m in FORBIDDEN_COL_RX.finditer(src):
            f.add("numeric", f"{rel}: column {m.group(2)} uses {m.group(1)}(); use decimal(12,2) for money or decimal(14,4) for quantities and cost rates")
        for m in RAW_FORBIDDEN_RX.finditer(src):
            if "DB::statement" in src or "DB::unprepared" in src:
                f.add("numeric", f"{rel}: raw SQL uses {m.group(1)}")
        for m in DECIMAL_RX.finditer(src):
            col, prec, scale = m.group(1), int(m.group(2)), int(m.group(3))
            if QTY_RX.search(col) and (prec, scale) != (14, 4):
                f.add("numeric", f"{rel}: quantity column {col} is decimal({prec},{scale}); quantities are decimal(14,4)")
            elif COST_RATE_RX.search(col) and not col.endswith("_total"):
                if (prec, scale) != (14, 4):
                    f.add("numeric", f"{rel}: cost rate {col} is decimal({prec},{scale}); a cost rate (per base unit) is decimal(14,4); a total of money is named *_total")
            elif MONEY_RX.search(col) and not QTY_RX.search(col) and (prec, scale) != (12, 2) and not col.endswith("_pct"):
                f.add("numeric", f"{rel}: money column {col} is decimal({prec},{scale}); money is decimal(12,2)")
    for base in ("apps/api/app/Modules", "apps/api/app/Shared"):
        for path in glob.glob(os.path.join(root, base, "**", "*.php"), recursive=True):
            src = read(path)
            for i, line in enumerate(src.splitlines(), 1):
                if re.search(r"\(float\)|floatval\(|'float'|\"float\"|=> *'double'", line):
                    f.add("numeric", f"{os.path.relpath(path, root)}:{i}: float cast/conversion in domain code; use Quantity/Money (brick/math)")


def read_allow_file(root: str) -> set[str]:
    """apps/api/.invariants-allow: one table per line, `table  # reason`. Each line is a visible, reviewable
    exception; put a TODO(p<N>) marker in the reason so the phase gate refuses to close until it is removed."""
    path = os.path.join(root, "apps", "api", ".invariants-allow")
    allowed = set()
    if os.path.exists(path):
        for line in read(path).splitlines():
            line = line.split("#", 1)[0].strip()
            if line:
                allowed.add(line)
    if allowed:
        print(f"[branch-id] note: allowed without branch_id by apps/api/.invariants-allow: {', '.join(sorted(allowed))}")
    return allowed


def check_branch_id(root: str, f: Findings, allow: set[str]) -> None:
    allow = allow | read_allow_file(root)
    # a table created before its module (the users scaffold) may receive branch_id from a later Schema::table block
    extended = set()
    for path in migration_files(root):
        for table, body in create_blocks(read(path), TABLE_RX):
            if re.search(r"['\"]branch_id['\"]", body):
                extended.add(table)
    for path in migration_files(root):
        rel = os.path.relpath(path, root)
        for table, body in create_blocks(read(path)):
            if table in allow or table in extended or table.startswith("pulse_") or table.startswith("telescope_"):
                continue
            if not re.search(r"['\"]branch_id['\"]", body):
                f.add("branch-id", f"{rel}: table {table} has no branch_id (multi-tenant rule 8); allowlist it only if it is truly global")


def check_bilingual(root: str, f: Findings) -> None:
    for path in migration_files(root):
        rel = os.path.relpath(path, root)
        for table, body in create_blocks(read(path)):
            cols = set(COL_RX.findall(body))
            for c in cols:
                if c.endswith("_en") and c[:-3] + "_am" not in cols:
                    f.add("bilingual", f"{rel}: {table}.{c} has no {c[:-3]}_am sibling")
                if c.endswith("_am") and c[:-3] + "_en" not in cols:
                    f.add("bilingual", f"{rel}: {table}.{c} has no {c[:-3]}_en sibling")


def check_append_only(root: str, f: Findings) -> None:
    files = migration_files(root)
    if not files:
        return
    created = set()
    protected = set()
    for path in files:
        src = read(path)
        for table, _ in create_blocks(src):
            created.add(table)
        for t in APPEND_ONLY_TABLES:
            if re.search(rf"forbid_update_delete[\s\S]{{0,400}}\b{t}\b|\b{t}\b[\s\S]{{0,400}}forbid_update_delete", src):
                protected.add(t)
    for t in APPEND_ONLY_TABLES:
        if t in created and t not in protected:
            f.add("append-only", f"table {t} is created but no migration attaches the forbid_update_delete trigger to it")


# `artisan route:list --json` resolves middleware aliases to class names; map them back so the checks read aliases
MW_CLASS_ALIASES = {
    "authenticate": "auth", "authorize": "can", "permissionmiddleware": "permission",
    "rolemiddleware": "role", "roleorpermissionmiddleware": "role_or_permission",
}


def normalise_middleware(mw: list[str]) -> list[str]:
    out = []
    for m in mw:
        name, _, args = m.partition(":")
        if "\\" in name:
            base = name.rsplit("\\", 1)[-1].lower()
            name = MW_CLASS_ALIASES.get(base, base)
        out.append(name + (":" + args if args else ""))
    return out


def check_idempotency(root: str, f: Findings, routes_path: str | None) -> bool:
    candidates = [routes_path] if routes_path else [os.path.join(root, "apps", "api", "tmp", "routes.json")]
    path = next((c for c in candidates if c and os.path.exists(c)), None)
    if not path:
        print("[idempotency] skipped: no routes JSON (produce with: scripts/artisan route:list --json > apps/api/tmp/routes.json)")
        return False
    routes = json.loads(read(path))
    for r in routes:
        uri = r.get("uri", "")
        if not uri.startswith("api/v1"):
            continue
        methods = set((r.get("method") or "").split("|"))
        mw = r.get("middleware") or []
        if isinstance(mw, str):
            mw = [mw]
        mw_s = " ".join(normalise_middleware(mw))
        public = uri in PUBLIC_ROUTES
        writes = {m for m in methods & {"POST", "PUT", "PATCH", "DELETE"} if f"{m} {uri}" not in READ_LIKE_WRITES}
        if writes and not public and "idempotency" not in mw_s:
            f.add("idempotency", f"{r.get('method')} {uri}: write route without the idempotency middleware (rule 7)")
        if not public and "auth:sanctum" not in mw_s:
            f.add("permission", f"{r.get('method')} {uri}: no auth:sanctum middleware (rule 6)")
        if not public and not re.search(r"\b(permission|can|role_or_permission):", mw_s):
            f.add("permission", f"{r.get('method')} {uri}: no permission:/can: middleware; enforce server-side (rule 6)")
    return True


def check_i18n(root: str, f: Findings, strict: bool) -> None:
    en = os.path.join(root, "apps", "web", "messages", "en.json")
    am = os.path.join(root, "apps", "web", "messages", "am.json")
    if not (os.path.exists(en) and os.path.exists(am)):
        print("[i18n] skipped: apps/web/messages/{en,am}.json not found")
        return

    def flat(d, prefix=""):
        out = {}
        for k, v in d.items():
            key = f"{prefix}{k}"
            if isinstance(v, dict):
                out.update(flat(v, key + "."))
            else:
                out[key] = v
        return out

    e, a = flat(json.loads(read(en))), flat(json.loads(read(am)))
    for k in sorted(set(e) - set(a)):
        f.add("i18n", f"key {k} missing from am.json")
    for k in sorted(set(a) - set(e)):
        f.add("i18n", f"key {k} missing from en.json")
    if strict:
        for k, v in a.items():
            if isinstance(v, str) and "__TODO__" in v:
                f.add("i18n", f"am.json {k} still __TODO__ (strict mode, Phase 5)")


def selftest() -> int:
    tmp = tempfile.mkdtemp(prefix="bakery-invariants-")
    mig = os.path.join(tmp, "apps", "api", "database", "migrations")
    os.makedirs(mig)
    os.makedirs(os.path.join(tmp, "apps", "api", "app", "Modules", "Orders"))
    os.makedirs(os.path.join(tmp, "apps", "api", "tmp"))
    os.makedirs(os.path.join(tmp, "apps", "web", "messages"))
    with open(os.path.join(mig, "2026_01_01_000000_bad.php"), "w", encoding="utf-8") as fh:
        fh.write("""<?php
Schema::create('items', function (Blueprint $t) {
    $t->id();
    $t->string('name_en');
    $t->float('weight');
    $t->decimal('price', 10, 2);
    $t->decimal('qty', 14, 4);
    $t->decimal('unit_cost', 12, 2);
    $t->decimal('moving_avg_cost', 14, 4);
    $t->decimal('cost_before', 14, 4)->nullable();
    $t->decimal('line_total', 12, 2);
    $t->decimal('cost_total', 12, 2);
});
Schema::create('stock_movements', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id'); $t->decimal('qty', 14, 4);
});
""")
    with open(os.path.join(tmp, "apps", "api", "app", "Modules", "Orders", "Total.php"), "w", encoding="utf-8") as fh:
        fh.write("<?php\n$x = (float) $row->total;\n")
    with open(os.path.join(tmp, "apps", "api", "tmp", "routes.json"), "w", encoding="utf-8") as fh:
        json.dump([
            {"method": "POST", "uri": "api/v1/orders", "middleware": ["api", "auth:sanctum", "permission:orders.create"]},
            {"method": "GET", "uri": "api/v1/orders", "middleware": ["api"]},
            {"method": "POST", "uri": "api/v1/login", "middleware": ["api"]},
            {"method": "POST", "uri": "api/v1/broadcasting/auth", "middleware": ["api", "auth:sanctum", "can:identity.authenticated"]},
        ], fh)
    with open(os.path.join(tmp, "apps", "web", "messages", "en.json"), "w", encoding="utf-8") as fh:
        json.dump({"pad": {"send": "Send", "table": "Table"}}, fh)
    with open(os.path.join(tmp, "apps", "web", "messages", "am.json"), "w", encoding="utf-8") as fh:
        json.dump({"pad": {"send": "__TODO__"}}, fh)

    f = Findings()
    check_numeric(tmp, f); check_branch_id(tmp, f, BRANCH_ALLOWLIST); check_bilingual(tmp, f)
    check_append_only(tmp, f); check_idempotency(tmp, f, None); check_i18n(tmp, f, strict=True)
    msgs = [m for _, m in f.items]
    expected = [
        ("float column", lambda m: "weight uses float" in m),
        ("money scale", lambda m: "price is decimal(10,2)" in m),
        ("cost rate scale", lambda m: "cost rate unit_cost is decimal(12,2)" in m),
        ("float cast", lambda m: "float cast" in m),
        ("branch_id", lambda m: "table items has no branch_id" in m),
        ("bilingual", lambda m: "items.name_en has no name_am" in m),
        ("append-only", lambda m: "stock_movements is created but no migration" in m),
        ("idempotency", lambda m: "POST api/v1/orders: write route without the idempotency" in m),
        ("permission", lambda m: "GET api/v1/orders: no auth:sanctum" in m),
        ("i18n missing", lambda m: "key pad.table missing from am.json" in m),
        ("i18n todo", lambda m: "pad.send still __TODO__" in m),
    ]
    bad = [name for name, pred in expected if not any(pred(m) for m in msgs)]
    false_pos = [m for m in msgs if "qty" in m and "decimal(14,4)" in m and "is decimal" in m]
    false_pos += [m for m in msgs if "broadcasting/auth" in m]
    false_pos += [m for m in msgs if any(c in m for c in ("moving_avg_cost", "cost_before", "line_total", "cost_total"))]
    for m in msgs:
        print("  caught:", m)
    if bad or false_pos:
        print("SELFTEST FAIL: not caught:", bad, "false positives:", false_pos)
        return 1
    print(f"SELFTEST PASS: {len(expected)} planted violations caught, no false positives")
    return 0


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    for flag in ("all", "numeric", "branch-id", "bilingual", "append-only", "idempotency", "i18n", "selftest"):
        ap.add_argument(f"--{flag}", action="store_true")
    ap.add_argument("--routes")
    ap.add_argument("--strict-i18n", action="store_true")
    ap.add_argument("--root")
    ap.add_argument("--allow-table", action="append", default=[], help="extra table allowed without branch_id")
    a = ap.parse_args(argv)
    if a.selftest:
        return selftest()
    root = os.path.abspath(a.root or os.getcwd())
    while not os.path.isdir(os.path.join(root, "apps")) and os.path.dirname(root) != root:
        root = os.path.dirname(root)
    f = Findings()
    run = []
    allow = BRANCH_ALLOWLIST | set(a.allow_table)
    if a.all or a.numeric: check_numeric(root, f); run.append("numeric")
    if a.all or a.branch_id: check_branch_id(root, f, allow); run.append("branch-id")
    if a.all or a.bilingual: check_bilingual(root, f); run.append("bilingual")
    if a.all or a.append_only: check_append_only(root, f); run.append("append-only")
    if a.all or a.idempotency:
        if check_idempotency(root, f, a.routes): run.append("idempotency")
    if a.all or a.i18n: check_i18n(root, f, a.strict_i18n); run.append("i18n")
    if not run:
        ap.error("choose a check or --all")
    return f.report(run)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
