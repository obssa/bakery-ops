#!/usr/bin/env python3
"""Writes the Amharic review sheet (step 5.4a, plan 10.2 Q5): one row per message of both dictionaries.

  i18n_export.py [--root DIR] [--out docs/i18n/am-review.csv]

Columns: key (web:<path> for apps/web/messages, api:<file>.<path> for apps/api/lang), screen (where the text shows), english,
amharic, correction (the reviewer's better Amharic; empty keeps the current one) and comment. The file is UTF-8 with a byte-order mark
so a spreadsheet program opens the Ethiopic script as it is. Written again over a sheet that already holds a reviewer's work, it keeps
each row's correction and comment (a correction that is now the current Amharic, because it was imported, is dropped).
scripts/i18n_import.py applies a filled sheet back.
"""
from __future__ import annotations

import argparse
import csv
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from i18n_common import ROOT, load_entries  # noqa: E402

DEFAULT_OUT = os.path.join("docs", "i18n", "am-review.csv")
HEADER = ["key", "screen", "english", "amharic", "correction", "comment"]

# Where each web namespace shows (src/app/[locale]/...). A namespace whose second part names a folder under its screen's route
# (inventory.ledger, adminShifts.history, analytics.sales) shows on that sub-page.
SCREENS = {
    "app": "every screen",
    "nav": "every screen (menu)",
    "common": "every screen",
    "errors": "every screen (errors)",
    "locale": "every screen (language)",
    "dates": "every screen (dates)",
    "outbox": "every screen (changes waiting to send)",
    "bell": "every screen (notifications)",
    "not_found": "/not-found",
    "home": "/",
    "login": "/login",
    "pad": "/pad",
    "till": "/till",
    "kds": "/kds/kitchen, /kds/bar",
    "stations": "/till, /kds (stock strip)",
    "production": "/production",
    "transfer": "/admin/inventory/transfers",
    "admin": "/admin",
    "adminOrders": "/admin/orders",
    "orderRecord": "/admin/orders/record",
    "refund": "/admin/orders/record (refund)",
    "catalog": "/admin/catalog",
    "recipe": "/admin/catalog (item page)",
    "people": "/admin/people",
    "devices": "/admin/people/devices",
    "deviceGate": "/pad, /till, /kds, /production (a computer not enrolled)",
    "planner": "/admin/planner",
    "inventory": "/admin/inventory",
    "shifts": "/shifts",
    "counts": "/shifts/count",
    "adminShifts": "/admin/shifts",
    "adminPayments": "/admin/payments",
    "analytics": "/admin/analytics",
    "audit": "/admin/audit",
}


def screen(root: str, key: str) -> str:
    kind, path = key.split(":", 1)
    parts = path.split(".")
    if kind == "api":
        return f"server message ({parts[0]})"
    base = SCREENS.get(parts[0], "shared")
    if base.startswith("/") and "," not in base and " " not in base and len(parts) > 2:
        sub = os.path.join(root, "apps", "web", "src", "app", "[locale]", *base.strip("/").split("/"), parts[1])
        if os.path.isdir(sub):
            return f"{base}/{parts[1]}"
    return base


def previous(path: str) -> dict[str, dict[str, str]]:
    if not os.path.exists(path):
        return {}
    with open(path, encoding="utf-8-sig", newline="") as fh:
        return {row["key"]: row for row in csv.DictReader(fh) if row.get("key")}


def export_sheet(root: str, out: str) -> int:
    old = previous(out)
    rows = []
    for e in load_entries(root):
        kept = old.get(e.key, {})
        correction = (kept.get("correction") or "").strip()
        if correction == e.am:
            correction = ""
        rows.append({
            "key": e.key,
            "screen": screen(root, e.key),
            "english": e.en,
            "amharic": e.am,
            "correction": correction,
            "comment": kept.get("comment") or "",
        })
    os.makedirs(os.path.dirname(out), exist_ok=True)
    with open(out, "w", encoding="utf-8-sig", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=HEADER, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)
    return len(rows)


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--root", default=ROOT)
    ap.add_argument("--out", default=None, help=f"default: <root>/{DEFAULT_OUT}")
    a = ap.parse_args(argv)
    out = a.out or os.path.join(a.root, DEFAULT_OUT)
    count = export_sheet(a.root, out)
    print(f"wrote {count} rows to {os.path.relpath(out, a.root)}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
