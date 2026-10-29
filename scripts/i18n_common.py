"""Shared helpers of the i18n scripts (step 5.4a): read and rewrite the two dictionaries without PHP on the host.

The web dictionary is apps/web/messages/{en,am}.json (next-intl, ICU placeholders `{name}`); the API dictionary is
apps/api/lang/{en,am}/*.php (Laravel, placeholders `:name`). The PHP files are plain `return [...]` arrays of single-quoted strings, so a
small tokenizer reads them and records where each value sits; a correction rewrites only that string literal and keeps every comment and
line break as it was.
"""
from __future__ import annotations

import json
import os
import re
from dataclasses import dataclass

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
WEB_MESSAGES = os.path.join("apps", "web", "messages")
API_LANG = os.path.join("apps", "api", "lang")

WEB_PLACEHOLDER = re.compile(r"\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*[,}]")
API_PLACEHOLDER = re.compile(r":([A-Za-z_][A-Za-z0-9_]*)")


# --- the web dictionary ------------------------------------------------------------------------------------------------------------

def read_json(path: str) -> dict:
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def write_json(path: str, data: dict) -> None:
    """The format the repository keeps: two spaces, UTF-8 as is, a final newline (json.dumps round-trips both files byte for byte)."""
    with open(path, "w", encoding="utf-8", newline="\n") as fh:
        fh.write(json.dumps(data, ensure_ascii=False, indent=2) + "\n")


def flatten(tree: dict, prefix: str = "") -> dict[str, object]:
    out: dict[str, object] = {}
    for key, value in tree.items():
        dotted = f"{prefix}{key}"
        if isinstance(value, dict):
            out.update(flatten(value, dotted + "."))
        else:
            out[dotted] = value
    return out


def set_dotted(tree: dict, dotted: str, value: str) -> bool:
    """Sets an existing leaf; returns False (and changes nothing) when the key is not in the tree."""
    node = tree
    parts = dotted.split(".")
    for part in parts[:-1]:
        node = node.get(part) if isinstance(node, dict) else None
        if not isinstance(node, dict):
            return False
    if not isinstance(node, dict) or parts[-1] not in node or isinstance(node[parts[-1]], dict):
        return False
    node[parts[-1]] = value
    return True


def web_placeholders(text: str) -> set[str]:
    return set(WEB_PLACEHOLDER.findall(text))


# --- the API dictionary ------------------------------------------------------------------------------------------------------------

@dataclass
class PhpValue:
    value: str
    start: int  # offset of the opening quote
    end: int  # offset just past the closing quote


class PhpLangError(ValueError):
    pass


_TOKEN = re.compile(
    r"""
    (?P<ws>\s+)
  | (?P<line_comment>(?://|\#)[^\n]*)
  | (?P<block_comment>/\*.*?\*/)
  | (?P<string>'(?:[^'\\]|\\.)*')
  | (?P<arrow>=>)
  | (?P<punct>[\[\],;])
  | (?P<word>[A-Za-z_][A-Za-z0-9_]*)
  | (?P<other>.)
    """,
    re.VERBOSE | re.DOTALL,
)


def _unquote(literal: str) -> str:
    return re.sub(r"\\([\\'])", r"\1", literal[1:-1])


def quote_php(text: str) -> str:
    return "'" + text.replace("\\", "\\\\").replace("'", "\\'") + "'"


def _tokens(src: str):
    body = src.split("return", 1)
    if len(body) != 2:
        raise PhpLangError("no `return` statement")
    offset = len(body[0]) + len("return")
    for m in _TOKEN.finditer(src, offset):
        kind = m.lastgroup
        if kind in ("ws", "line_comment", "block_comment"):
            continue
        yield kind, m.group(), m.start(), m.end()


def parse_php_lang(src: str) -> dict:
    """Reads `return [ 'key' => 'value' | [ ... ], ... ];` into nested dicts whose leaves are PhpValue."""
    toks = list(_tokens(src))
    pos = 0

    def expect(kind: str, text: str | None = None):
        nonlocal pos
        if pos >= len(toks):
            raise PhpLangError(f"unexpected end, wanted {text or kind}")
        k, t, s, e = toks[pos]
        if k != kind or (text is not None and t != text):
            raise PhpLangError(f"at offset {s}: wanted {text or kind}, found {t!r}")
        pos += 1
        return t, s, e

    def array() -> dict:
        nonlocal pos
        expect("punct", "[")
        out: dict = {}
        while True:
            if toks[pos][1] == "]":
                pos += 1
                return out
            key, _, _ = expect("string")
            expect("arrow")
            if toks[pos][1] == "[":
                value = array()
            else:
                literal, s, e = expect("string")
                value = PhpValue(_unquote(literal), s, e)
            name = _unquote(key)
            if name in out:
                raise PhpLangError(f"duplicate key {name!r}")
            out[name] = value
            if toks[pos][1] == ",":
                pos += 1

    tree = array()
    expect("punct", ";")
    return tree


def php_values(tree: dict, prefix: str = "") -> dict[str, PhpValue]:
    out: dict[str, PhpValue] = {}
    for key, value in tree.items():
        dotted = f"{prefix}{key}"
        if isinstance(value, dict):
            out.update(php_values(value, dotted + "."))
        else:
            out[dotted] = value
    return out


def read_php_lang(path: str) -> dict[str, str]:
    with open(path, encoding="utf-8") as fh:
        return {k: v.value for k, v in php_values(parse_php_lang(fh.read())).items()}


def rewrite_php_lang(src: str, changes: dict[str, str]) -> tuple[str, list[str]]:
    """Replaces the string literal of each dotted key in `changes`; returns the new source and the keys it could not find."""
    values = php_values(parse_php_lang(src))
    missing = [k for k in changes if k not in values]
    for key in sorted((k for k in changes if k in values), key=lambda k: values[k].start, reverse=True):
        v = values[key]
        src = src[: v.start] + quote_php(changes[key]) + src[v.end:]
    return src, missing


def api_placeholders(text: str) -> set[str]:
    return set(API_PLACEHOLDER.findall(text))


# --- both, as one list of rows -----------------------------------------------------------------------------------------------------

@dataclass
class Entry:
    key: str  # web:<dotted> or api:<file>.<dotted>
    en: str
    am: str


def load_entries(root: str = ROOT) -> list[Entry]:
    """Every message of both dictionaries, the web's in file order, then the API's file by file."""
    out: list[Entry] = []
    en = flatten(read_json(os.path.join(root, WEB_MESSAGES, "en.json")))
    am = flatten(read_json(os.path.join(root, WEB_MESSAGES, "am.json")))
    for key, value in en.items():
        out.append(Entry(f"web:{key}", str(value), str(am.get(key, ""))))
    en_dir = os.path.join(root, API_LANG, "en")
    for name in sorted(os.listdir(en_dir)):
        if not name.endswith(".php"):
            continue
        stem = name[:-4]
        en_values = read_php_lang(os.path.join(en_dir, name))
        am_path = os.path.join(root, API_LANG, "am", name)
        am_values = read_php_lang(am_path) if os.path.exists(am_path) else {}
        for key, value in en_values.items():
            out.append(Entry(f"api:{stem}.{key}", value, am_values.get(key, "")))
    return out


def placeholders(key: str, text: str) -> set[str]:
    return web_placeholders(text) if key.startswith("web:") else api_placeholders(text)
