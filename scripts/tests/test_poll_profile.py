"""The poll profile of step 5.5d (apps/api/tests/Fixtures/perf/poll-profile.json) against the web app it describes.

    python3 -m unittest discover -s scripts/tests -p "test_*.py"

PollBudgetTest (PHP) and scripts/perf/load.k6.js both read the profile; this test keeps every feed's interval equal to the web constant it
names (a `PollOptions` constant's `intervalMs`, or a literal such as a React Query `refetchInterval`), and every path to one the web asks
for, so a change of cadence in apps/web fails here until the profile, the budget and the runbook follow it.
"""
from __future__ import annotations

import json
import os
import re
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PROFILE = os.path.join(ROOT, "apps", "api", "tests", "Fixtures", "perf", "poll-profile.json")


def read(relative: str) -> str:
    with open(os.path.join(ROOT, relative), encoding="utf-8") as handle:
        return handle.read()


def interval_of(source: str, constant: str) -> int | None:
    """The intervalMs of `const NAME ... = { intervalMs: N, ... }` (N may carry `_` separators)."""
    match = re.search(r"\bconst\s+" + re.escape(constant) + r"\b[^=]*=\s*\{[^}]*?\bintervalMs:\s*([0-9_]+)", source)
    return int(match.group(1).replace("_", "")) if match else None


class PollProfile(unittest.TestCase):
    def setUp(self) -> None:
        with open(PROFILE, encoding="utf-8") as handle:
            self.profile = json.load(handle)

    def test_every_interval_is_the_web_constant_it_names(self) -> None:
        for kind, spec in self.profile["kinds"].items():
            for feed in spec["feeds"]:
                with self.subTest(kind=kind, feed=feed["name"]):
                    web = feed["web"]
                    source = read(web["file"])
                    if "constant" in web:
                        self.assertEqual(interval_of(source, web["constant"]), feed["interval_ms"], f"{web['file']} {web['constant']}")
                    else:
                        self.assertIn(web["literal"], source)
                        number = int(re.sub(r"[^0-9]", "", web["literal"]))
                        self.assertEqual(number, feed["interval_ms"], web["literal"])

    def test_every_path_is_one_the_web_polls(self) -> None:
        live = "\n".join(read(os.path.join("apps", "web", "src", "lib", "live", name)) for name in sorted(os.listdir(os.path.join(ROOT, "apps", "web", "src", "lib", "live"))))
        live += read(os.path.join("apps", "web", "src", "lib", "api", "counts.ts"))
        for kind, spec in self.profile["kinds"].items():
            for feed in spec["feeds"]:
                with self.subTest(kind=kind, feed=feed["name"]):
                    # the web writes a station or a shift id as ${stationId} / ${shiftId}; compare the fixed part before the first one
                    head = feed["path"].split("?")[0]
                    fixed = re.split(r"\{[a-z]+\}", head)
                    pattern = r"[^\"`/]*".join(re.escape(part) for part in fixed)
                    self.assertRegex(live, pattern, f"{feed['path']} is not a path apps/web/src/lib polls")

    def test_the_two_mixes(self) -> None:
        mixes = self.profile["mixes"]
        self.assertEqual(sum(mixes["ci"].values()), 60)
        self.assertEqual(sum(mixes["day_one"].values()), 12)
        self.assertEqual(set(mixes["ci"]), set(self.profile["kinds"]))
        self.assertEqual(set(mixes["day_one"]), set(self.profile["kinds"]))

    def test_the_parser_reads_both_spellings(self) -> None:
        self.assertEqual(interval_of("export const A: PollOptions = { intervalMs: 15_000, hiddenMs: 60_000 };", "A"), 15000)
        self.assertEqual(interval_of("const MENU_POLL = { intervalMs: 5000, hiddenMs: 20_000 };", "MENU_POLL"), 5000)
        self.assertIsNone(interval_of("const B = { hiddenMs: 1 };", "A"))


if __name__ == "__main__":
    unittest.main()
