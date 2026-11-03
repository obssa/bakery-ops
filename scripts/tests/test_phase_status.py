"""phase_status.py for a phase the manifest gains after `init` (step 6.0, phase-6 plan section 1): add-phase, claim-done's next phase,
and validate's range read from the manifest; plus the repository's own STATUS.json against its manifest and schema.

    python3 -m unittest discover -s scripts/tests -p "test_phase_status.py" -v
"""
from __future__ import annotations

import contextlib
import io
import json
import os
import re
import shutil
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPTS = os.path.dirname(HERE)
REPO = os.path.dirname(SCRIPTS)
sys.path.insert(0, SCRIPTS)

import phase_status  # noqa: E402


def manifest_phase(n: int, steps: list[str]) -> dict:
    return {"name": f"Phase {n}", "contracts_required": [], "adrs": [],
            "steps": [{"id": f"{n}.{i + 1}", "module": m, "builds": m} for i, m in enumerate(steps)],
            "gate": {"pest_group": f"gate:p{n}", "ci_job": f"gate-p{n}", "playwright_projects": [f"p{n}-flow"]}}


def manifest() -> dict:
    return {"modules": {"shared": {"phase": 0, "depends_on": []}},
            "contracts": {},
            "phases": {"5": manifest_phase(5, ["ops", "docs"]), "6": manifest_phase(6, ["catalog.images", "web.design-system", "docs"])}}


def status(phase_5: str = "done") -> dict:
    entry = phase_status.phase_entry(manifest(), "5", phase_5)
    if phase_5 == "done":
        entry.update(done_commit="b" * 40, done_at="2026-11-02T21:00:21Z")
    return {"schema_version": 1, "current_phase": 5, "invariants_version": 2, "phases": {"5": entry},
            "modules": {"shared": {"phase": 0, "status": "stable", "stable_since": None}}}


class Fixture(unittest.TestCase):
    def setUp(self) -> None:
        self.root = tempfile.mkdtemp(prefix="phase-status-")
        self.addCleanup(shutil.rmtree, self.root, True)
        open(os.path.join(self.root, "CLAUDE.md"), "w", encoding="utf-8").close()

    def write(self, man: dict, st: dict) -> None:
        assets = os.path.join(self.root, ".claude", "skills", "bakery-ops-builder", "assets")
        os.makedirs(assets, exist_ok=True)
        with open(os.path.join(assets, "dependency-manifest.json"), "w", encoding="utf-8") as fh:
            json.dump(man, fh)
        os.makedirs(os.path.join(self.root, "docs", "phases"), exist_ok=True)
        with open(os.path.join(self.root, "docs", "phases", "STATUS.json"), "w", encoding="utf-8") as fh:
            json.dump(st, fh)

    def run_cmd(self, *args: str) -> tuple[int, str, dict]:
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            code = phase_status.main(["--root", self.root, *args])
        with open(os.path.join(self.root, "docs", "phases", "STATUS.json"), encoding="utf-8") as fh:
            return code, out.getvalue(), json.load(fh)


class AddPhase(Fixture):
    def test_adds_the_next_phase_as_planning_and_hands_over_the_current_phase(self) -> None:
        self.write(manifest(), status("done"))
        code, out, st = self.run_cmd("add-phase", "6")
        self.assertEqual(code, 0, out)
        ph = st["phases"]["6"]
        self.assertEqual(ph["status"], "planning")
        self.assertEqual(st["current_phase"], 6)
        self.assertFalse(ph["plan_approved"], "approval is never set by the script")
        self.assertEqual(ph["gate_job"], "gate-p6")
        self.assertEqual(ph["plan"], "docs/phases/phase-6-plan.md")
        self.assertEqual(ph["modules"], {"catalog.images": "todo", "web.design-system": "todo", "docs": "todo"})
        self.assertEqual(ph["playwright_projects"], ["p6-flow"])
        self.assertEqual(st["phases"]["5"]["status"], "done", "the closed phase is not touched")

    def test_a_phase_whose_predecessor_is_open_stays_todo(self) -> None:
        self.write(manifest(), status("in_progress"))
        code, _, st = self.run_cmd("add-phase", "6")
        self.assertEqual(code, 0)
        self.assertEqual(st["phases"]["6"]["status"], "todo")
        self.assertEqual(st["current_phase"], 5)

    def test_refuses_an_unknown_an_existing_or_an_out_of_order_phase(self) -> None:
        man = manifest()
        man["phases"]["8"] = manifest_phase(8, ["docs"])
        self.write(man, status("done"))
        for phase in ("7", "8"):
            with self.assertRaises(SystemExit):
                self.run_cmd("add-phase", phase)
        self.run_cmd("add-phase", "6")
        with self.assertRaises(SystemExit):
            self.run_cmd("add-phase", "6")

    def test_the_result_validates(self) -> None:
        self.write(manifest(), status("done"))
        self.run_cmd("add-phase", "6")
        code, out, _ = self.run_cmd("validate")
        self.assertEqual(code, 0, out)


class ClaimDoneAddsTheNextPhase(Fixture):
    def test_claiming_phase_5_lists_phase_6_from_the_manifest(self) -> None:
        self.write(manifest(), status("in_progress"))
        code, out, st = self.run_cmd("claim-done", "5", "--commit", "c" * 40)
        self.assertEqual(code, 0, out)
        self.assertEqual(st["phases"]["5"]["status"], "done")
        self.assertEqual(st["phases"]["6"]["status"], "planning")
        self.assertEqual(st["current_phase"], 6)


class Validate(Fixture):
    def test_the_range_comes_from_the_manifest(self) -> None:
        st = status("done")
        st["current_phase"] = 6
        self.write(manifest(), st)
        code, out, _ = self.run_cmd("validate")
        self.assertEqual(code, 1)
        self.assertIn("current_phase 6 has no entry in phases", out)
        st["current_phase"] = 7
        self.write(manifest(), st)
        code, out, _ = self.run_cmd("validate")
        self.assertEqual(code, 1)
        self.assertIn("current_phase must be 0..6", out)

    def test_a_gate_job_that_is_not_the_manifests_is_reported(self) -> None:
        st = status("done")
        st["phases"]["5"]["gate_job"] = "gate-p4"
        self.write(manifest(), st)
        code, out, _ = self.run_cmd("validate")
        self.assertEqual(code, 1)
        self.assertIn("gate_job gate-p4", out)


class TheRepository(unittest.TestCase):
    """docs/phases/STATUS.json against the manifest, and the schema's caps against the manifest's phases."""

    def setUp(self) -> None:
        skill = os.path.join(REPO, ".claude", "skills", "bakery-ops-builder", "assets")
        with open(os.path.join(skill, "dependency-manifest.json"), encoding="utf-8") as fh:
            self.man = json.load(fh)
        with open(os.path.join(skill, "status.schema.json"), encoding="utf-8") as fh:
            self.schema = json.load(fh)
        with open(os.path.join(REPO, "docs", "phases", "STATUS.json"), encoding="utf-8") as fh:
            self.st = json.load(fh)

    def test_status_is_valid(self) -> None:
        self.assertEqual(phase_status.validate(self.st, self.man), [])

    def test_every_manifest_phase_has_a_status_entry_up_to_the_current_one(self) -> None:
        for n in range(self.st["current_phase"] + 1):
            self.assertIn(str(n), self.st["phases"])

    def test_the_schema_admits_every_phase_of_the_manifest(self) -> None:
        props = self.schema["properties"]
        last = max(int(n) for n in self.man["phases"])
        self.assertGreaterEqual(props["current_phase"]["maximum"], last)
        phase = props["phases"]
        entry = phase["additionalProperties"]["properties"]
        for n, ph in self.man["phases"].items():
            self.assertRegex(n, phase["propertyNames"]["pattern"])
            self.assertRegex(ph["gate"]["ci_job"], entry["gate_job"]["pattern"])
            for project in ph["gate"]["playwright_projects"]:
                self.assertRegex(project, entry["playwright_projects"]["items"]["pattern"])
        self.assertGreaterEqual(self.schema["properties"]["modules"]["additionalProperties"]["properties"]["phase"]["maximum"], last)

    def test_the_skill_copy_is_the_same_script(self) -> None:
        with open(os.path.join(SCRIPTS, "phase_status.py"), encoding="utf-8") as a, \
                open(os.path.join(REPO, ".claude", "skills", "bakery-ops-builder", "scripts", "phase_status.py"), encoding="utf-8") as b:
            self.assertEqual(a.read(), b.read())

    def test_every_playwright_project_of_the_manifest_is_in_the_config(self) -> None:
        with open(os.path.join(REPO, "apps", "web", "playwright.config.ts"), encoding="utf-8") as fh:
            config = fh.read()
        names = set(re.findall(r'name:\s*"(p\d+-[a-z0-9-]+)"', config))
        for ph in self.man["phases"].values():
            for project in ph["gate"]["playwright_projects"]:
                self.assertIn(project, names)


if __name__ == "__main__":
    unittest.main()
