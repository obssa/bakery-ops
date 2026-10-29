#!/usr/bin/env python3
"""The Amharic dictionary report (step 5.4a): what a reviewer or the next step should look at. A report, never a gate: it exits 0.

  i18n_report.py [--root DIR] [--json]

It lists, over apps/web/messages/{en,am}.json and apps/api/lang/{en,am}/*.php:
  same_as_english  Amharic texts that are still the English one, except texts that hold no word outside their placeholders
                   (`{qty} {unit}`) and texts whose only words are in ALLOWED (brand names, units, ETB, codes);
  placeholders     texts whose Amharic names other placeholders than the English ({name} on the web, :name in the API);
  unused           keys no code seems to read any more. A heuristic: a web key counts as read when its path or its last part appears
                   as a string in apps/web/src, or a template there starts with it (t(`status_${s}`)); an API key when it appears as a
                   string in apps/api/app, routes or resources, or a string there ending in a dot is joined to a variable
                   (trans('reports.'.$key)). Check a listed key by hand before deleting it.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from i18n_common import ROOT, Entry, load_entries, placeholders  # noqa: E402

# Words that may stay in Latin script in an Amharic text (plan 10.2 D19, docs/i18n/glossary.md): the currency, the file formats and
# codes the dictionary keeps, the same list as the screen sweep's ALLOWED_WORDS (apps/web/e2e/support/amharic.ts), and unit symbols.
# A word goes here with its kind; a word that has an Amharic name does not.
ALLOWED = {
    # the currency, file formats and codes (the sweep's list)
    "ETB", "PDF", "CSV", "Excel", "SKU", "TIN",
    # unit symbols, should a message carry one
    "kg", "g", "l", "ml", "pcs",
}

# Keys whose Amharic is the English text on purpose, each with its reason.
SAME_ON_PURPOSE = {
    "web:locale.en": "the language switch names each language in its own script (LanguageSwitcher, lang=\"en\")",
}

ICU_ARGUMENT =re.compile(r"\{\s*[A-Za-z_]\w*\s*\}")
ICU_HEAD = re.compile(r"\{\s*[A-Za-z_]\w*\s*,\s*(?:plural|select|selectordinal)\s*,")
ICU_BRANCH = re.compile(r"(?:=\d+|zero|one|two|few|many|other)\s*\{")
API_ARGUMENT = re.compile(r":[A-Za-z_]\w*")
WORD = re.compile(r"[A-Za-z]+")

QUOTED = re.compile(r"\"((?:[^\"\\\n]|\\.)*)\"|'((?:[^'\\\n]|\\.)*)'")
TEMPLATE = re.compile(r"`((?:[^`\\]|\\.)*)`")
COMMENT = re.compile(r"/\*.*?\*/|^\s*//[^\n]*", re.DOTALL | re.MULTILINE)


def words(entry: Entry) -> list[str]:
    text = entry.en
    if entry.key.startswith("web:"):
        text = ICU_BRANCH.sub(" ", ICU_HEAD.sub(" ", ICU_ARGUMENT.sub(" ", text)))
    else:
        text = API_ARGUMENT.sub(" ", text)
    return WORD.findall(text)


def same_as_english(entries: list[Entry]) -> list[str]:
    out = []
    for e in entries:
        if e.am != e.en or e.key in SAME_ON_PURPOSE:
            continue
        found = words(e)
        if found and not all(w in ALLOWED for w in found):
            out.append(e.key)
    return out


def placeholder_gaps(entries: list[Entry]) -> list[tuple[str, list[str], list[str]]]:
    out = []
    for e in entries:
        en, am = placeholders(e.key, e.en), placeholders(e.key, e.am)
        if en != am:
            out.append((e.key, sorted(en), sorted(am)))
    return out


def source_strings(dirs: list[str], suffixes: tuple[str, ...]) -> tuple[set[str], set[str]]:
    """Every string literal in the files under dirs, and the static start of every template or interpolated string."""
    literals: set[str] = set()
    prefixes: set[str] = set()
    for base in dirs:
        for folder, _, files in os.walk(base):
            for name in files:
                if not name.endswith(suffixes):
                    continue
                with open(os.path.join(folder, name), encoding="utf-8", errors="replace") as fh:
                    src = COMMENT.sub(" ", fh.read())
                # Quoted strings first, over the whole file (so the ones inside a template's ${...} count too), then the templates.
                found = [(m, next(g for g in m.groups() if g is not None)) for m in QUOTED.finditer(src)]
                found += [(m, m.group(1)) for m in TEMPLATE.finditer(src)]
                for m, text in found:
                    for marker in ("${", "{$"):
                        if marker in text:
                            text = text.split(marker, 1)[0]
                            if text:
                                prefixes.add(text)
                            break
                    else:
                        literals.add(text)
                        end = m.end()
                        if text.endswith(".") and re.match(r"\s*\.\s*\$", src[end:end + 8]):
                            prefixes.add(text)
    return literals, prefixes


def unused(entries: list[Entry], root: str) -> list[str]:
    web_literals, web_prefixes = source_strings([os.path.join(root, "apps", "web", "src")], (".ts", ".tsx"))
    api_dirs = [os.path.join(root, "apps", "api", d) for d in ("app", "routes", "resources")]
    api_literals, api_prefixes = source_strings(api_dirs, (".php",))
    out = []
    for e in entries:
        kind, key = e.key.split(":", 1)
        if kind == "web":
            parts = key.split(".")
            names = {key, ".".join(parts[1:]), parts[-1]}
            literals, prefixes = web_literals, web_prefixes
        else:
            names = {key}
            literals, prefixes = api_literals, api_prefixes
        if names & literals:
            continue
        if any(n.startswith(p) for n in names for p in prefixes if len(p) >= 2):
            continue
        out.append(e.key)
    return out


def build_report(root: str = ROOT) -> dict:
    entries = load_entries(root)
    return {
        "counts": {
            "web": sum(1 for e in entries if e.key.startswith("web:")),
            "api": sum(1 for e in entries if e.key.startswith("api:")),
        },
        "same_as_english": same_as_english(entries),
        "placeholders": placeholder_gaps(entries),
        "unused": unused(entries, root),
    }


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--root", default=ROOT)
    ap.add_argument("--json", action="store_true")
    a = ap.parse_args(argv)
    report = build_report(a.root)
    if a.json:
        print(json.dumps(report, ensure_ascii=False, indent=2))
        return 0
    c = report["counts"]
    print(f"== i18n report: {c['web']} web messages, {c['api']} API messages (a report, not a gate)")
    sections = (
        ("same_as_english", "Amharic still the English text", lambda k: k),
        ("placeholders", "placeholders differ", lambda g: f"{g[0]}: en {' '.join(g[1]) or 'none'}, am {' '.join(g[2]) or 'none'}"),
        ("unused", "keys no code seems to read (check by hand)", lambda k: k),
    )
    for name, title, fmt in sections:
        items = report[name]
        print(f"-- {title}: {len(items)}")
        for item in items:
            print(f"   {fmt(item)}")
    return 0


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    sys.exit(main(sys.argv[1:]))
