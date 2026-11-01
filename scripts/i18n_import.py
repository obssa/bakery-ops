#!/usr/bin/env python3
"""Applies a filled Amharic review sheet (step 5.4a; 5.4b imports the reviewer's sheet with it).

  i18n_import.py [--root DIR] [--dry-run] [docs/i18n/am-review.csv]

Every row with a correction replaces that key's Amharic: web:<path> in apps/web/messages/am.json (rewritten in the repository's
format), api:<file>.<path> in apps/api/lang/am/<file>.php (only that string literal changes; comments and layout stay). A row with an
empty correction changes nothing. The sheet is checked first and applied only when every row is sound: the key exists, and the
correction names the same placeholders as the English ({name} on the web, :name in the API). Otherwise nothing is written, each
problem is printed and the exit code is 1. Then run i18n_export.py again for a fresh sheet, and the LangParityTest and i18n-check.
"""
from __future__ import annotations

import argparse
import csv
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from i18n_common import (  # noqa: E402
    API_LANG,
    ROOT,
    WEB_MESSAGES,
    load_entries,
    placeholders,
    read_json,
    rewrite_php_lang,
    set_dotted,
    write_json,
)

DEFAULT_SHEET = os.path.join("docs", "i18n", "am-review.csv")


def describe(key: str, names: set[str]) -> str:
    if not names:
        return "none"
    return " ".join(sorted(names) if key.startswith("web:") else (f":{n}" for n in sorted(names)))


def read_corrections(sheet: str) -> dict[str, str]:
    with open(sheet, encoding="utf-8-sig", newline="") as fh:
        rows = list(csv.DictReader(fh))
    return {row["key"].strip(): row["correction"].strip() for row in rows if (row.get("correction") or "").strip()}


def apply_sheet(root: str, sheet: str, dry_run: bool) -> list[str]:
    """Checks every correction, then writes them all, or nothing and the list of problems."""
    english = {e.key: e for e in load_entries(root)}
    corrections = read_corrections(sheet)
    problems = []
    for key, text in corrections.items():
        entry = english.get(key)
        if entry is None:
            problems.append(f"{key}: no such key")
            continue
        want, got = placeholders(key, entry.en), placeholders(key, text)
        if want != got:
            problems.append(
                f"{key}: placeholders differ from the English (en {describe(key, want)}, correction {describe(key, got)})")
        if "__TODO__" in text:
            problems.append(f"{key}: the correction is still __TODO__")
    if problems or dry_run:
        return problems

    changes = {k: v for k, v in corrections.items() if v != english[k].am}
    web = {k[len("web:"):]: v for k, v in changes.items() if k.startswith("web:")}
    if web:
        path = os.path.join(root, WEB_MESSAGES, "am.json")
        tree = read_json(path)
        for key, text in web.items():
            set_dotted(tree, key, text)
        write_json(path, tree)
    by_file: dict[str, dict[str, str]] = {}
    for key, text in changes.items():
        if key.startswith("api:"):
            stem, path = key[len("api:"):].split(".", 1)
            by_file.setdefault(stem, {})[path] = text
    for stem, file_changes in by_file.items():
        path = os.path.join(root, API_LANG, "am", f"{stem}.php")
        with open(path, encoding="utf-8") as fh:
            src, _ = rewrite_php_lang(fh.read(), file_changes)
        with open(path, "w", encoding="utf-8", newline="\n") as fh:
            fh.write(src)
    return []


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--root", default=ROOT)
    ap.add_argument("--dry-run", action="store_true", help="check the sheet and write nothing")
    ap.add_argument("sheet", nargs="?", default=None, help=f"default: <root>/{DEFAULT_SHEET}")
    a = ap.parse_args(argv)
    sheet = a.sheet or os.path.join(a.root, DEFAULT_SHEET)
    problems = apply_sheet(a.root, sheet, a.dry_run)
    if problems:
        print(f"{len(problems)} problem(s); nothing was written:")
        for p in problems:
            print(f"  {p}")
        return 1
    count = len(read_corrections(sheet))
    print(f"{count} correction(s) {'checked' if a.dry_run else 'applied'}")
    return 0


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    sys.exit(main(sys.argv[1:]))
