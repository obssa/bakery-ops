#!/usr/bin/env python3
"""An independent check of the count hash chain (ADR 0012; phase-3 plan section 10.3).

This file shares no code with the PHP implementation (app/Modules/Shifts/Hash/CanonicalJson.php): it reads the grammar of section 10.3
and implements it again with the Python standard library only, so a change in PHP, in a PHP library or in the encoder that moves one
byte of what is hashed fails a job that has nothing to do with PHP.

Two things it checks:

  verify_chain.py [--vectors FILE]
      The pinned vectors (apps/api/tests/Fixtures/chain/vectors.json, written by CanonicalJsonTest): for each vector it encodes the
      payload with its own encoder and requires the bytes the PHP encoder produced, checks the shape of the version 1 payload, and
      recomputes sha256(previous_hash . canonical_payload) and the links between the vectors.

  verify_chain.py --export FILE [FILE ...]
      One or more chain exports (HashChain::export, "bakery-chain-export"): the numbering is 1, 2, 3 without gaps, every previous_hash
      is the hash of the link before it (sixty-four zeros for the first), every payload_hash is the sha256 of previous_hash followed by
      the stored bytes, and every stored payload is in canonical form (parsed and encoded again it gives the same bytes) and names
      its own chain_seq. It cannot know what the live rows say today; the PHP verify does that.

Exit status 0 when everything holds, 1 when anything does not (each problem is printed), 2 for a file it cannot read.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

GENESIS = "0" * 64
DEFAULT_VECTORS = Path(__file__).resolve().parent.parent / "apps" / "api" / "tests" / "Fixtures" / "chain" / "vectors.json"

PAYLOAD_V1_KEYS = {
    "branch_id", "location_id", "shift_instance_id", "shift_date", "station_id", "type", "seq", "supersedes_count_id", "snapshot_at",
    "ledger_movement_id", "counted_by_user_id", "cosigned_by_user_id", "decision", "decided_by_user_id", "decided_at", "decision_note",
    "submitted_at", "lines", "cash", "chain_seq", "locked_at", "locked_by_user_id", "payload_version",
}
LINE_KEYS = {
    "item_id", "expected_qty", "carried_qty", "counted_qty", "variance_qty", "reason_code", "reason_note", "adjustment_movement_id",
    "adjustment_qty",
}
CASH_KEYS = {
    "denominations", "counted_total", "expected_total", "variance_total", "carried_float_total", "cash_sales_total", "float_left_total",
    "channels", "reason_code", "reason_note", "currency",
}
QTY = re.compile(r"^-?\d+\.\d{4}$")
MONEY = re.compile(r"^-?\d+\.\d{2}$")
INSTANT = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$")
DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
HASH = re.compile(r"^[0-9a-f]{64}$")


class Grammar(Exception):
    """A value outside the closed grammar of section 10.3."""


def encode_string(value: str) -> str:
    out = ['"']
    for ch in value:
        code = ord(ch)
        if ch == '"':
            out.append('\\"')
        elif ch == "\\":
            out.append("\\\\")
        elif code < 0x20:
            out.append("\\u%04x" % code)
        else:
            out.append(ch)
    out.append('"')
    return "".join(out)


def encode(value: object) -> str:
    """Canonical JSON: UTF-8, no whitespace outside strings, map keys in byte order, only null/bool/int/str/list/map."""
    if value is None:
        return "null"
    if value is True:
        return "true"
    if value is False:
        return "false"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, str):
        return encode_string(value)
    if isinstance(value, list):
        return "[" + ",".join(encode(item) for item in value) + "]"
    if isinstance(value, dict):
        items = sorted(((str(k), v) for k, v in value.items()), key=lambda kv: kv[0].encode("utf-8"))
        return "{" + ",".join(encode_string(k) + ":" + encode(v) for k, v in items) + "}"
    raise Grammar("a %s is not in the grammar" % type(value).__name__)


def sha(previous_hash: str, canonical: str) -> str:
    return hashlib.sha256(previous_hash.encode("ascii") + canonical.encode("utf-8")).hexdigest()


def load_no_floats(text: str) -> object:
    def refuse(token: str) -> object:
        raise Grammar("the number %s is not an integer" % token)

    return json.loads(text, parse_float=refuse, parse_constant=refuse)


def decimal_problems(path: str, value: object, problems: list[str]) -> None:
    """Every *_qty is a four-place decimal string, every *_total two places; zero is never negative."""
    if isinstance(value, dict):
        for key, item in value.items():
            where = "%s.%s" % (path, key)
            if item is not None and key.endswith("_qty"):
                if not isinstance(item, str) or not QTY.match(item) or item.startswith("-0.0000"):
                    problems.append("%s: %r is not a four-place decimal" % (where, item))
            elif item is not None and key.endswith("_total"):
                if not isinstance(item, str) or not MONEY.match(item) or item.startswith("-0.00"):
                    problems.append("%s: %r is not a two-place decimal" % (where, item))
            elif key.endswith("_at") and item is not None:
                if not isinstance(item, str) or not INSTANT.match(item):
                    problems.append("%s: %r is not an instant with six fraction digits" % (where, item))
            decimal_problems(where, item, problems)
    elif isinstance(value, list):
        for index, item in enumerate(value):
            decimal_problems("%s[%d]" % (path, index), item, problems)


def payload_problems(payload: dict) -> list[str]:
    problems: list[str] = []
    if set(payload) != PAYLOAD_V1_KEYS:
        problems.append("payload keys differ: missing %s, extra %s" % (sorted(PAYLOAD_V1_KEYS - set(payload)), sorted(set(payload) - PAYLOAD_V1_KEYS)))
        return problems
    if payload["payload_version"] != 1:
        problems.append("payload_version is %r, not 1" % payload["payload_version"])
    if not isinstance(payload["shift_date"], str) or not DATE.match(payload["shift_date"]):
        problems.append("shift_date %r is not YYYY-MM-DD" % payload["shift_date"])
    ids = [line["item_id"] for line in payload["lines"] if isinstance(line, dict) and "item_id" in line]
    if ids != sorted(ids):
        problems.append("lines are not ordered by item_id: %s" % ids)
    for line in payload["lines"]:
        if set(line) != LINE_KEYS:
            problems.append("line keys differ for item %r" % line.get("item_id"))
    cash = payload["cash"]
    if cash is not None:
        if set(cash) != CASH_KEYS:
            problems.append("cash keys differ")
        codes = [row["code"] for row in cash["channels"]]
        if codes != sorted(codes, key=lambda c: c.encode("utf-8")):
            problems.append("cash channels are not ordered by code: %s" % codes)
    decimal_problems("payload", payload, problems)
    return problems


def check_vectors(path: Path) -> list[str]:
    document = load_no_floats(path.read_text(encoding="utf-8"))
    problems: list[str] = []
    if not isinstance(document, dict) or document.get("format") != "bakery-chain-vectors" or not document.get("vectors"):
        return ["%s is not a bakery-chain-vectors document" % path]
    previous = GENESIS
    for vector in document["vectors"]:
        name = vector.get("name", "?")
        mine = encode(vector["payload"])
        if mine != vector["canonical"]:
            problems.append("%s: the bytes differ from the PHP encoder's\n    python: %s\n    php:    %s" % (name, mine, vector["canonical"]))
        problems += ["%s: %s" % (name, p) for p in payload_problems(vector["payload"])]
        if vector["previous_hash"] != previous:
            problems.append("%s: previous_hash is not the hash of the vector before it" % name)
        expected = sha(vector["previous_hash"], mine)
        if expected != vector["payload_hash"]:
            problems.append("%s: payload_hash %s is not sha256(previous_hash . payload) = %s" % (name, vector["payload_hash"], expected))
        previous = vector["payload_hash"]
    return problems


def check_export(path: Path) -> list[str]:
    document = load_no_floats(path.read_text(encoding="utf-8"))
    problems: list[str] = []
    if not isinstance(document, dict) or document.get("format") != "bakery-chain-export" or document.get("version") != 1:
        return ["%s is not a bakery-chain-export version 1 document" % path]
    expected_seq = 1
    previous = GENESIS
    for link in document["links"]:
        seq = link["chain_seq"]
        where = "link %s (count %s)" % (seq, link.get("count_id"))
        if seq != expected_seq:
            problems.append("%s: the numbering skips from %d" % (where, expected_seq))
        if link["previous_hash"] != previous:
            problems.append("%s: previous_hash is not the hash of the link before it" % where)
        stored = link["canonical_payload"]
        if sha(link["previous_hash"], stored) != link["payload_hash"]:
            problems.append("%s: payload_hash is not sha256(previous_hash . canonical_payload)" % where)
        try:
            parsed = load_no_floats(stored)
            if encode(parsed) != stored:
                problems.append("%s: the stored bytes are not in canonical form" % where)
            if isinstance(parsed, dict):
                if parsed.get("chain_seq") != seq:
                    problems.append("%s: the payload names chain_seq %r" % (where, parsed.get("chain_seq")))
                if parsed.get("payload_version") != link["payload_version"]:
                    problems.append("%s: payload_version differs from the payload's" % where)
                if link["payload_version"] == 1:
                    problems += ["%s: %s" % (where, p) for p in payload_problems(parsed)]
        except (Grammar, ValueError) as error:
            problems.append("%s: the stored payload is not valid canonical JSON (%s)" % (where, error))
        if not HASH.match(link["payload_hash"]):
            problems.append("%s: payload_hash is not 64 lowercase hex characters" % where)
        expected_seq = seq + 1
        previous = link["payload_hash"]
    if document["links"] and document.get("head_hash") != previous:
        problems.append("head_hash is not the hash of the last link")
    return problems


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--vectors", type=Path, default=None, help="the pinned vectors file (default: the repository's)")
    parser.add_argument("--export", type=Path, nargs="+", default=None, help="chain export files to check instead of the vectors")
    args = parser.parse_args()

    jobs = [(path, check_export) for path in args.export] if args.export else [(args.vectors or DEFAULT_VECTORS, check_vectors)]
    failed = False
    for path, check in jobs:
        try:
            problems = check(path)
        except (OSError, ValueError, Grammar, KeyError, TypeError) as error:
            print("%s: cannot be read: %s" % (path, error), file=sys.stderr)
            return 2
        if problems:
            failed = True
            print("%s: %d problem(s)" % (path, len(problems)))
            for problem in problems:
                print("  - " + problem)
        else:
            print("%s: ok" % path)
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
