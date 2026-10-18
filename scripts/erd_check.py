#!/usr/bin/env python3
"""Every table created by a migration must appear in docs/erd.dbml (as `Table <name>`). No PHP needed."""
import glob, os, re, sys
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
rx = re.compile(r"Schema::create\(\s*['\"](\w+)['\"]")
tables = set()
for p in glob.glob(os.path.join(root, "apps", "api", "database", "migrations", "*.php")):
    tables |= set(rx.findall(open(p, encoding="utf-8", errors="ignore").read()))
erd_path = os.path.join(root, "docs", "erd.dbml")
erd = open(erd_path, encoding="utf-8").read() if os.path.exists(erd_path) else ""
documented = set(re.findall(r"^\s*Table\s+(\w+)", erd, re.M))
missing = sorted(tables - documented)
extra = sorted(documented - tables)
for t in missing: print(f"erd: table {t} is created by a migration but missing from docs/erd.dbml")
for t in extra: print(f"erd: note: {t} documented but no migration creates it yet (fine while planned)")
print(f"erd_check: {len(tables)} tables in migrations, {len(documented)} documented, {len(missing)} missing")
sys.exit(1 if missing else 0)
