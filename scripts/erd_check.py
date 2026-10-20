#!/usr/bin/env python3
"""Every table (Schema::create) and every view (CREATE [OR REPLACE] VIEW) created by a migration must appear in
docs/erd.dbml as `Table <name>` (DBML has no views; a view is documented as a Table with a note). No PHP needed."""
import glob, os, re, sys
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
table_rx = re.compile(r"Schema::create\(\s*['\"](\w+)['\"]")
view_rx = re.compile(r"CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+[`\"]?(\w+)", re.I)
tables, views = set(), set()
for p in glob.glob(os.path.join(root, "apps", "api", "database", "migrations", "*.php")):
    src = open(p, encoding="utf-8", errors="ignore").read()
    tables |= set(table_rx.findall(src))
    views |= set(view_rx.findall(src))
erd_path = os.path.join(root, "docs", "erd.dbml")
erd = open(erd_path, encoding="utf-8").read() if os.path.exists(erd_path) else ""
documented = set(re.findall(r"^\s*Table\s+(\w+)", erd, re.M))
created = tables | views
missing = sorted(created - documented)
extra = sorted(documented - created)
for t in missing: print(f"erd: {'view' if t in views else 'table'} {t} is created by a migration but missing from docs/erd.dbml")
for t in extra: print(f"erd: note: {t} documented but no migration creates it yet (fine while planned)")
print(f"erd_check: {len(tables)} tables and {len(views)} view(s) in migrations, {len(documented)} documented, {len(missing)} missing")
sys.exit(1 if missing else 0)
