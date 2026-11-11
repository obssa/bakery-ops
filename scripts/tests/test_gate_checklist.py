"""The go-live checklist of step 5.6a (phase-5 plan 10.3): how gate_check reads it, the sign-off rule, claim-done's refusal,
check_invariants --signoffs and check_branch_protection, each on a fixture in a temporary folder.

    python3 -m unittest discover -s scripts/tests -p "test_gate_checklist.py" -v

Every verifier type has a fixture: ci_job with jobs (in CI and locally), ci_job with workflow (the Actions API mocked), script,
file_exists, file_exists with signoff (missing, signed in the web editor, committed locally, bad fields), an unknown type,
duplicate ids, and the reference time (now while the phase is open, done_at once it is claimed).
"""
from __future__ import annotations

import contextlib
import datetime as dt
import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest import mock

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPTS = os.path.dirname(HERE)
REPO = os.path.dirname(SCRIPTS)
sys.path.insert(0, SCRIPTS)
sys.path.insert(0, os.path.join(SCRIPTS, "ops"))

import check_branch_protection  # noqa: E402
import check_invariants  # noqa: E402
import gate_check  # noqa: E402
import phase_status  # noqa: E402

NOW = dt.datetime(2026, 11, 20, 12, 0, tzinfo=dt.timezone.utc)
WEB_EDITOR = {"GIT_COMMITTER_NAME": "GitHub", "GIT_COMMITTER_EMAIL": "noreply@github.com"}
LOCAL = {"GIT_COMMITTER_NAME": "Someone Local", "GIT_COMMITTER_EMAIL": "local@example.invalid"}

CI_YML = """name: ci
on: [pull_request]
jobs:
  status:
    runs-on: ubuntu-24.04
  mod-a:
    runs-on: ubuntu-24.04
  e2e-p5:
    needs: [status, mod-a]
    uses: ./.github/workflows/e2e.yml
  gate-p4:
    needs: [status, mod-a]
    runs-on: ubuntu-24.04
  gate-p5:
    needs: [status, gate-p4, e2e-p5]
    runs-on: ubuntu-24.04
    steps:
      - run: vendor/bin/pest --group=gate:p5
      - run: python3 scripts/gate_check.py --mode done --phase 5 --ci
"""

DEPLOY_YML = """name: deploy
on: [workflow_dispatch]
jobs:
  deploy:
    name: deploy the release
    runs-on: ubuntu-24.04
  live-e2e:
    name: Playwright @live against the site
    needs: deploy
    runs-on: ubuntu-24.04
"""


def manifest() -> dict:
    earlier = {str(k): {"name": f"phase {k}", "adrs": [], "contracts_required": [], "steps": [],
                        "gate": {"pest_group": f"gate:p{k}", "ci_job": f"gate-p{k}", "playwright_projects": []}} for k in range(4)}
    man = {
        "paths": {"openapi": "docs/openapi.json", "erd": "docs/erd.dbml"},
        "modules": {},
        "ci": {
            "workflow": ".github/workflows/ci.yml",
            "module_jobs": {"mod-a": []},
            "e2e_jobs": {"e2e-p5": ["mod-a"]},
            # ghost-job is a need in the manifest that ci.yml does not define
            "gate_jobs": {"gate-p4": ["mod-a"], "gate-p5": ["gate-p4", "e2e-p5", "ghost-job"]},
        },
        "phases": {"5": {"name": "Hardening", "adrs": [], "contracts_required": [], "steps": [],
                         "gate": {"pest_group": "gate:p5", "ci_job": "gate-p5", "playwright_projects": [],
                                  "checklist": "docs/go-live-checklist.json"}},
                   "4": {"name": "Analytics", "adrs": [], "contracts_required": [], "steps": [],
                         "gate": {"pest_group": "gate:p4", "ci_job": "gate-p4", "playwright_projects": []}}},
    }
    man["phases"].update(earlier)
    return man


def status(phase_status_value: str = "in_progress", done_at: str | None = None) -> dict:
    return {"current_phase": 5, "phases": {
        "4": {"status": "done", "done_commit": None, "done_at": None, "modules": {}},
        "5": {"status": phase_status_value, "done_commit": None, "done_at": done_at, "modules": {}},
    }}


def item(iid: str, verifier, required: bool = True, who: str = "CI") -> dict:
    return {"id": iid, "title": iid.replace("-", " "), "required": required, "who": who, "verifier": verifier}


def signoff_item(iid: str, required: bool = True) -> dict:
    return item(iid, {"type": "file_exists", "paths": [f"docs/go-live/signoffs/{iid}.md"], "signoff": True}, required, "Ramin")


def signoff_text(iid: str, date: str = "2026-11-10", statement: str = "Checked on the shop floor.") -> str:
    return f"---\nitem: {iid}\ndate: {date}\nsigned_by: Owner\nstatement: {statement}\n---\n"


class Fixture(unittest.TestCase):
    """A throwaway repository: a CI workflow, a deploy workflow, and whatever checklist a test writes."""

    def setUp(self) -> None:
        self.root = tempfile.mkdtemp(prefix="gate-checklist-")
        self.addCleanup(shutil.rmtree, self.root, ignore_errors=True)
        self.write("CLAUDE.md", "fixture\n")
        self.write(".github/workflows/ci.yml", CI_YML)
        self.write(".github/workflows/deploy.yml", DEPLOY_YML)
        self.man = manifest()
        self.st = status()
        now = mock.patch.object(gate_check, "utcnow", return_value=NOW)
        now.start()
        self.addCleanup(now.stop)
        slug = mock.patch.dict(os.environ, {"GITHUB_REPOSITORY": "owner/repo"})
        slug.start()
        self.addCleanup(slug.stop)

    def write(self, rel: str, text: str) -> None:
        path = os.path.join(self.root, rel)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w", encoding="utf-8", newline="\n") as fh:
            fh.write(text)

    def checklist(self, *items: dict) -> None:
        self.write("docs/go-live-checklist.json", json.dumps({"schema": 1, "phase": 5, "items": list(items)}))

    def rows(self, ci: bool = False) -> dict[str, tuple[str, str]]:
        rep = gate_check.Report()
        gate_check.check_checklist(self.root, self.man, self.st, 5, SimpleNamespace(ci=ci), rep)
        return {c: (s, d) for s, c, d in rep.rows}

    def git(self, *args: str, env: dict | None = None) -> None:
        full = dict(os.environ, GIT_AUTHOR_NAME="Owner", GIT_AUTHOR_EMAIL="owner@example.invalid", **(env or LOCAL))
        subprocess.run(["git", *args], cwd=self.root, env=full, check=True, capture_output=True, text=True)

    def init_git(self) -> None:
        self.git("init", "-q", "-b", "main")
        self.git("add", "-A")
        self.git("commit", "-q", "-m", "fixture")


class CiJobWithJobs(Fixture):
    def test_in_ci_a_direct_or_transitive_need_of_the_gate_job_passes(self) -> None:
        self.checklist(item("direct", {"type": "ci_job", "jobs": ["gate-p4", "e2e-p5"]}),
                       item("through", {"type": "ci_job", "jobs": ["mod-a"]}))
        rows = self.rows(ci=True)
        self.assertEqual(rows["checklist direct"][0], "PASS", rows["checklist direct"])
        self.assertEqual(rows["checklist through"][0], "PASS", rows["checklist through"])

    def test_in_ci_a_lower_gate_counts_when_the_gate_needs_every_job_it_needs(self) -> None:
        """Since 2026-11-12 the gates run side by side: gate-p5 needs gate-p4's jobs, not gate-p4."""
        self.man["ci"]["gate_jobs"]["gate-p5"] = ["mod-a", "e2e-p5"]
        self.checklist(item("gates-green", {"type": "ci_job", "jobs": ["gate-p4"]}))
        state, detail = self.rows(ci=True)["checklist gates-green"]
        self.assertEqual(state, "PASS", detail)
        self.assertIn("gate-p4 (every job it needs)", detail)
        self.man["ci"]["gate_jobs"]["gate-p5"] = ["e2e-p5"]
        self.man["ci"]["e2e_jobs"]["e2e-p5"] = []
        state, detail = self.rows(ci=True)["checklist gates-green"]
        self.assertEqual(state, "FAIL")
        self.assertIn("gate-p5 does not need every job gate-p4 needs (mod-a)", detail)

    def test_in_ci_a_job_the_gate_does_not_need_fails(self) -> None:
        self.checklist(item("load", {"type": "ci_job", "jobs": ["perf-load"]}))
        state, detail = self.rows(ci=True)["checklist load"]
        self.assertEqual(state, "FAIL")
        self.assertIn("perf-load: not a need of gate-p5", detail)

    def test_in_ci_a_manifest_need_missing_from_the_workflow_fails(self) -> None:
        self.checklist(item("ghost", {"type": "ci_job", "jobs": ["ghost-job"]}))
        state, detail = self.rows(ci=True)["checklist ghost"]
        self.assertEqual(state, "FAIL")
        self.assertIn("no such job in .github/workflows/ci.yml", detail)

    def test_self_is_the_gate_jobs_own_pest_step(self) -> None:
        self.checklist(item("own", {"type": "ci_job", "jobs": ["self"]}))
        self.assertEqual(self.rows(ci=True)["checklist own"][0], "PASS")
        self.write(".github/workflows/ci.yml", CI_YML.replace("      - run: vendor/bin/pest --group=gate:p5\n", ""))
        state, detail = self.rows(ci=True)["checklist own"]
        self.assertEqual(state, "FAIL")
        self.assertIn("neither gate-p5 nor a pest-gate-p5 job it needs runs a --group=gate:p5 step", detail)

    def test_self_may_be_the_pest_job_beside_the_gate_that_the_gate_needs(self) -> None:
        self.checklist(item("own", {"type": "ci_job", "jobs": ["self"]}))
        moved = CI_YML.replace("      - run: vendor/bin/pest --group=gate:p5\n", "").replace(
            "  gate-p5:\n    needs: [status, gate-p4, e2e-p5]\n",
            "  pest-gate-p5:\n    needs: [status]\n    runs-on: ubuntu-24.04\n    steps:\n      - run: vendor/bin/pest --group=gate:p5\n"
            "  gate-p5:\n    needs: [status, gate-p4, e2e-p5, pest-gate-p5]\n")
        self.write(".github/workflows/ci.yml", moved)
        self.assertEqual(self.rows(ci=True)["checklist own"][0], "PASS")
        # a pest-gate-p5 job the gate does not wait for proves nothing to the gate
        self.write(".github/workflows/ci.yml", moved.replace("[status, gate-p4, e2e-p5, pest-gate-p5]", "[status, gate-p4, e2e-p5]"))
        self.assertEqual(self.rows(ci=True)["checklist own"][0], "FAIL")

    def test_locally_it_is_trusted_to_ci(self) -> None:
        self.checklist(item("local", {"type": "ci_job", "jobs": ["perf-load"]}))
        self.assertEqual(self.rows(ci=False)["checklist local"], ("SKIP", "trusted to CI: perf-load"))


class CiJobWithWorkflow(Fixture):
    LIVE = {"type": "ci_job", "workflow": "deploy.yml", "job": "live-e2e", "branch": "main", "max_age_days": 7}

    def fake_api(self, runs: list[dict], jobs: dict[int, list[dict]]):
        calls: list[str] = []

        def api(path: str) -> dict:
            calls.append(path)
            if "/actions/workflows/" in path:
                return {"workflow_runs": runs}
            run_id = int(path.split("/actions/runs/")[1].split("/")[0])
            return {"jobs": jobs.get(run_id, [])}
        return api, calls

    @staticmethod
    def job(name: str, conclusion: str, completed: str | None) -> dict:
        return {"name": name, "conclusion": conclusion, "completed_at": completed}

    def test_the_newest_successful_job_within_max_age_passes_matched_by_display_name(self) -> None:
        api, calls = self.fake_api([{"id": 2, "updated_at": "2026-11-18T10:00:00Z"}],
                                   {2: [self.job("deploy the release", "success", "2026-11-18T09:00:00Z"),
                                        self.job("Playwright @live against the site", "success", "2026-11-18T10:00:00Z")]})
        self.checklist(item("live-ops", self.LIVE))
        with mock.patch.object(gate_check, "api_get", side_effect=api):
            state, detail = self.rows(ci=True)["checklist live-ops"]
        self.assertEqual(state, "PASS", detail)
        self.assertIn("newest success 2026-11-18 10:00Z", detail)
        self.assertIn("repos/owner/repo/actions/workflows/deploy.yml/runs?", calls[0])
        self.assertIn("branch=main", calls[0])
        self.assertIn("status=success", calls[0])

    def test_a_run_whose_job_was_skipped_is_passed_over_for_an_older_one(self) -> None:
        api, _ = self.fake_api([{"id": 3, "updated_at": "2026-11-19T10:00:00Z"}, {"id": 2, "updated_at": "2026-11-17T10:00:00Z"}],
                               {3: [self.job("live-e2e", "skipped", "2026-11-19T10:00:00Z")],
                                2: [self.job("live-e2e", "success", "2026-11-17T10:00:00Z")]})
        self.checklist(item("live-ops", self.LIVE))
        with mock.patch.object(gate_check, "api_get", side_effect=api):
            state, detail = self.rows(ci=True)["checklist live-ops"]
        self.assertEqual(state, "PASS", detail)
        self.assertIn("2026-11-17 10:00Z", detail)

    def test_a_success_older_than_max_age_fails(self) -> None:
        api, _ = self.fake_api([{"id": 1, "updated_at": "2026-11-01T10:00:00Z"}],
                               {1: [self.job("live-e2e", "success", "2026-11-01T10:00:00Z")]})
        self.checklist(item("live-ops", self.LIVE))
        with mock.patch.object(gate_check, "api_get", side_effect=api):
            state, detail = self.rows(ci=True)["checklist live-ops"]
        self.assertEqual(state, "FAIL")
        self.assertIn("19.1 days before", detail)

    def test_no_successful_run_fails(self) -> None:
        api, _ = self.fake_api([], {})
        self.checklist(item("live-ops", self.LIVE))
        with mock.patch.object(gate_check, "api_get", side_effect=api):
            state, detail = self.rows(ci=True)["checklist live-ops"]
        self.assertEqual(state, "FAIL")
        self.assertIn("no successful run of deploy.yml job live-e2e on main", detail)

    def test_an_api_error_fails_with_its_message(self) -> None:
        self.checklist(item("live-ops", self.LIVE))
        with mock.patch.object(gate_check, "api_get", side_effect=gate_check.ApiError("HTTP 403")):
            self.assertEqual(self.rows(ci=True)["checklist live-ops"], ("FAIL", "Actions API: HTTP 403"))

    def test_without_a_job_the_runs_own_time_counts(self) -> None:
        api, calls = self.fake_api([{"id": 9, "updated_at": "2026-11-20T03:00:00Z"}], {})
        self.checklist(item("uptime", {"type": "ci_job", "workflow": "uptime.yml", "max_age_days": 1}))
        with mock.patch.object(gate_check, "api_get", side_effect=api):
            state, detail = self.rows(ci=True)["checklist uptime"]
        self.assertEqual(state, "PASS", detail)
        self.assertEqual(len(calls), 1, "no jobs request without a job name")

    def test_the_reference_time_is_now_while_the_phase_is_open(self) -> None:
        api, calls = self.fake_api([{"id": 1, "updated_at": "2026-11-10T10:00:00Z"}],
                                   {1: [self.job("live-e2e", "success", "2026-11-10T10:00:00Z")]})
        self.checklist(item("live-ops", self.LIVE))
        with mock.patch.object(gate_check, "api_get", side_effect=api):
            rows = self.rows(ci=True)
        self.assertEqual(rows["checklist live-ops"][0], "FAIL", "10 days before now is older than 7")
        self.assertIn("reference time 2026-11-20T12:00Z (now)", rows["checklist schema"][1])
        self.assertIn("created=%3C%3D2026-11-20T12%3A00%3A00Z", calls[0])

    def test_the_reference_time_is_done_at_once_claimed(self) -> None:
        self.st = status("done", done_at="2026-11-12T08:00:00Z")
        # a run after done_at is ignored: the gate on main proves the state at the claim, not today's
        api, calls = self.fake_api([{"id": 5, "updated_at": "2026-11-19T10:00:00Z"}, {"id": 1, "updated_at": "2026-11-10T10:00:00Z"}],
                                   {5: [self.job("live-e2e", "success", "2026-11-19T10:00:00Z")],
                                    1: [self.job("live-e2e", "success", "2026-11-10T10:00:00Z")]})
        self.checklist(item("live-ops", self.LIVE))
        with mock.patch.object(gate_check, "api_get", side_effect=api):
            rows = self.rows(ci=True)
        state, detail = rows["checklist live-ops"]
        self.assertEqual(state, "PASS", detail)
        self.assertIn("2026-11-10 10:00Z, 1.9 days before 2026-11-12T08:00:00Z", detail)
        self.assertIn("reference time 2026-11-12T08:00Z (done_at)", rows["checklist schema"][1])
        self.assertIn("created=%3C%3D2026-11-12T08%3A00%3A00Z", calls[0])


class Script(Fixture):
    def test_exit_zero_passes_with_the_last_lines_as_the_detail(self) -> None:
        self.checklist(item("ok", {"type": "script", "run": ["python3", "-c", "print('one'); print('two'); print('three'); print('four')"]}))
        self.assertEqual(self.rows()["checklist ok"], ("PASS", "two | three | four"))

    def test_a_non_zero_exit_fails(self) -> None:
        self.checklist(item("bad", {"type": "script", "run": ["python3", "-c", "import sys; print('main does not require gate-p5'); sys.exit(1)"]}))
        self.assertEqual(self.rows()["checklist bad"], ("FAIL", "exit 1: main does not require gate-p5"))

    def test_it_runs_from_the_repository_root(self) -> None:
        self.checklist(item("cwd", {"type": "script", "run": ["python3", "-c", "import os; print(os.path.exists('CLAUDE.md'))"]}))
        self.assertEqual(self.rows()["checklist cwd"], ("PASS", "True"))

    def test_a_timeout_fails(self) -> None:
        self.checklist(item("slow", {"type": "script", "run": ["python3", "-c", "import time; time.sleep(5)"], "timeout": 1}))
        self.assertEqual(self.rows()["checklist slow"], ("FAIL", "timed out after 1 s"))


class FileExists(Fixture):
    def test_every_path_present_and_not_empty_passes(self) -> None:
        self.write("docs/handbook/README.md", "# Handbook\n")
        self.write("docs/handbook/training.md", "# Training\n")
        self.checklist(item("handbook", {"type": "file_exists", "paths": ["docs/handbook/README.md", "docs/handbook/training.md"]}))
        self.assertEqual(self.rows()["checklist handbook"], ("PASS", "2 files"))

    def test_a_missing_or_empty_path_fails(self) -> None:
        self.write("docs/handbook/README.md", "")
        self.checklist(item("handbook", {"type": "file_exists", "paths": ["docs/handbook/README.md", "docs/handbook/training.md"]}))
        self.assertEqual(self.rows()["checklist handbook"],
                         ("FAIL", "missing or empty: docs/handbook/README.md, docs/handbook/training.md"))

    def test_every_verifier_of_an_item_must_pass(self) -> None:
        self.write("docs/a.md", "a\n")
        self.checklist(item("both", [{"type": "file_exists", "paths": ["docs/a.md"]}, {"type": "file_exists", "paths": ["docs/b.md"]}]))
        state, detail = self.rows()["checklist both"]
        self.assertEqual(state, "FAIL")
        self.assertEqual(detail, "file_exists PASS: docs/a.md; file_exists FAIL: missing or empty: docs/b.md")


class SignOff(Fixture):
    def test_a_missing_sign_off_waits_for_ramin(self) -> None:
        self.checklist(signoff_item("training-done"))
        state, detail = self.rows()["checklist training-done"]
        self.assertEqual(state, "WAIT")
        self.assertIn("Ramin: docs/go-live/signoffs/training-done.md not signed yet", detail)

    def test_an_optional_sign_off_never_blocks(self) -> None:
        self.checklist(signoff_item("push-telegram", required=False))
        state, detail = self.rows()["checklist push-telegram"]
        self.assertEqual(state, "WARN")
        self.assertTrue(detail.startswith("optional, WAIT: "), detail)

    def test_a_sign_off_committed_in_the_web_editor_passes(self) -> None:
        self.checklist(signoff_item("training-done"))
        self.init_git()
        self.write("docs/go-live/signoffs/training-done.md", signoff_text("training-done"))
        self.git("add", "-A")
        self.git("commit", "-q", "-m", "Create training-done.md", env=WEB_EDITOR)
        self.assertEqual(self.rows()["checklist training-done"],
                         ("PASS", "docs/go-live/signoffs/training-done.md signed in GitHub's web editor"))

    def test_a_sign_off_committed_locally_fails(self) -> None:
        self.checklist(signoff_item("training-done"))
        self.init_git()
        self.write("docs/go-live/signoffs/training-done.md", signoff_text("training-done"))
        self.git("add", "-A")
        self.git("commit", "-q", "-m", "sign", env=WEB_EDITOR)
        self.write("docs/go-live/signoffs/training-done.md", signoff_text("training-done", statement="Edited on a PC."))
        self.git("commit", "-q", "-am", "edit", env=LOCAL)
        state, detail = self.rows()["checklist training-done"]
        self.assertEqual(state, "FAIL")
        self.assertIn("1 of 2 commit(s) not made in GitHub's web editor (committer Someone Local)", detail)

    def test_an_uncommitted_sign_off_fails(self) -> None:
        self.checklist(signoff_item("training-done"))
        self.init_git()
        self.write("docs/go-live/signoffs/training-done.md", signoff_text("training-done"))
        state, detail = self.rows()["checklist training-done"]
        self.assertEqual(state, "FAIL")
        self.assertIn("not committed", detail)

    def test_the_four_fields_are_checked(self) -> None:
        self.checklist(signoff_item("am-reviewed"))
        self.init_git()
        self.write("docs/go-live/signoffs/am-reviewed.md",
                   "item: training-done\ndate: 2026-12-25\nsigned_by: <your name or role>\n")
        self.git("add", "-A")
        self.git("commit", "-q", "-m", "sign", env=WEB_EDITOR)
        state, detail = self.rows()["checklist am-reviewed"]
        self.assertEqual(state, "FAIL")
        for words in ("field signed_by missing", "field statement missing", "item is 'training-done', not 'am-reviewed'",
                      "date 2026-12-25 is in the future"):
            self.assertIn(words, detail)

    def test_a_date_that_is_not_iso_fails(self) -> None:
        self.checklist(signoff_item("am-reviewed"))
        self.init_git()
        self.write("docs/go-live/signoffs/am-reviewed.md", signoff_text("am-reviewed", date="10/11/2026"))
        self.git("add", "-A")
        self.git("commit", "-q", "-m", "sign", env=WEB_EDITOR)
        self.assertIn("date '10/11/2026' is not YYYY-MM-DD", self.rows()["checklist am-reviewed"][1])


class Schema(Fixture):
    def _all_rows(self) -> list[tuple[str, str, str]]:
        rep = gate_check.Report()
        gate_check.check_checklist(self.root, self.man, self.st, 5, SimpleNamespace(ci=False), rep)
        return rep.rows

    def test_an_unknown_verifier_type_fails_and_no_item_is_read(self) -> None:
        self.checklist(item("odd", {"type": "manual_check", "paths": ["x"]}), signoff_item("training-done"))
        rows = self._all_rows()
        self.assertEqual([(s, c) for s, c, _ in rows], [("FAIL", "checklist schema")])
        self.assertIn("odd: unknown verifier type 'manual_check'", rows[0][2])

    def test_duplicate_ids_fail(self) -> None:
        self.checklist(signoff_item("training-done"), signoff_item("training-done"))
        details = [d for s, c, d in self._all_rows() if s == "FAIL"]
        self.assertIn("training-done: duplicate id", details)

    def test_other_shapes_fail(self) -> None:
        self.checklist(
            item("both-kinds", {"type": "ci_job", "jobs": ["a"], "workflow": "deploy.yml", "max_age_days": 1}),
            item("no-age", {"type": "ci_job", "workflow": "deploy.yml"}),
            item("wrong-path", {"type": "file_exists", "paths": ["docs/go-live/README.md"], "signoff": True}),
            item("shell", {"type": "script", "run": "python3 x.py"}),
            {"id": "Bad Id", "title": "x", "required": True, "who": "CI", "verifier": {"type": "script", "run": ["x"]}},
            {"id": "no-who", "title": "x", "required": "yes", "verifier": {"type": "script", "run": ["x"]}},
        )
        details = "\n".join(d for s, c, d in self._all_rows() if s == "FAIL")
        for words in ("both-kinds: ci_job takes exactly one of jobs or workflow",
                      "no-age: ci_job with workflow needs max_age_days",
                      "wrong-path: a sign-off has exactly one path, docs/go-live/signoffs/wrong-path.md",
                      "shell: script run must be a non-empty argument list",
                      "items[4]: id must be lower-case words joined by dashes",
                      "no-who: required must be true or false", "no-who: who must be one of CI, Ramin"):
            self.assertIn(words, details)

    def test_a_missing_checklist_fails(self) -> None:
        self.assertEqual(self._all_rows(), [("FAIL", "checklist", "missing docs/go-live-checklist.json")])

    def test_a_gate_without_a_checklist_adds_no_rows(self) -> None:
        rep = gate_check.Report()
        gate_check.check_checklist(self.root, self.man, self.st, 4, SimpleNamespace(ci=True), rep)
        self.assertEqual(rep.rows, [])


class TheRepositoryChecklist(unittest.TestCase):
    """The real docs/go-live-checklist.json: valid, every 10.3 item present, every verifier type used, the schema file in step."""

    def setUp(self) -> None:
        with open(os.path.join(REPO, "docs", "go-live-checklist.json"), encoding="utf-8") as fh:
            self.data = json.load(fh)
        with open(os.path.join(REPO, "docs", "go-live-checklist.schema.json"), encoding="utf-8") as fh:
            self.schema = json.load(fh)
        with open(os.path.join(REPO, ".claude", "skills", "bakery-ops-builder", "assets", "dependency-manifest.json"), encoding="utf-8") as fh:
            self.man = json.load(fh)

    def test_it_is_valid(self) -> None:
        self.assertEqual(gate_check.checklist_errors(self.data, 5), [])

    def test_the_manifest_points_phase_5_at_it(self) -> None:
        self.assertEqual(self.man["phases"]["5"]["gate"].get("checklist"), "docs/go-live-checklist.json")

    def test_every_item_of_plan_10_3_is_there(self) -> None:
        ids = {i["id"] for i in self.data["items"]}
        self.assertEqual(ids, {
            "gates-green", "branch-protection", "device-enforcement", "offline-replay", "print-emulator", "i18n-strict",
            "backup-drill-ci", "load-test", "live-ops", "uptime-monitor", "handbook", "on-call-plan", "restore-runbook",
            "print-runbook", "adrs", "devices-enrolled", "print-bridge-shop", "am-reviewed", "training-done",
            "on-call-agreed", "offsite-backup", "error-tracking", "receipt-wording", "push-telegram"})
        optional = {i["id"] for i in self.data["items"] if not i["required"]}
        self.assertEqual(optional, {"push-telegram"})
        signed = {i["id"] for i in self.data["items"] if i["who"] == "Ramin"}
        self.assertEqual(signed, {"devices-enrolled", "print-bridge-shop", "am-reviewed", "training-done", "on-call-agreed",
                                  "offsite-backup", "error-tracking", "receipt-wording", "push-telegram"})

    def test_every_verifier_type_is_used_and_the_schema_lists_the_same_types(self) -> None:
        used = set()
        for i in self.data["items"]:
            for v in (i["verifier"] if isinstance(i["verifier"], list) else [i["verifier"]]):
                used.add(v["type"] + ("+workflow" if "workflow" in v else "+jobs" if "jobs" in v else "")
                         + ("+signoff" if v.get("signoff") else ""))
        self.assertEqual(used, {"ci_job+jobs", "ci_job+workflow", "script", "file_exists", "file_exists+signoff"})
        types = {d["properties"]["type"]["const"] for k, d in self.schema["$defs"].items() if k not in ("item", "verifier")}
        self.assertEqual(types, set(gate_check.VERIFIER_TYPES))

    def test_the_two_copies_of_the_scripts_are_identical(self) -> None:
        skill = os.path.join(REPO, ".claude", "skills", "bakery-ops-builder", "scripts")
        for name in ("gate_check.py", "phase_status.py"):
            with open(os.path.join(SCRIPTS, name), "rb") as a, open(os.path.join(skill, name), "rb") as b:
                self.assertEqual(a.read(), b.read(), f"scripts/{name} and the skill's copy differ")


class InformationalAndStrict(Fixture):
    """mode_done on a phase with a checklist: informational while open in CI, strict once claimed or run locally."""

    def done(self, ci: bool) -> gate_check.Report:
        self.write("docs/phases/phase-5-report.md", "# report\n")
        self.write("docs/openapi.json", "{\"openapi\": \"3.1.0\"}\n")
        self.write("docs/erd.dbml", "Table branches {}\n")
        self.checklist(signoff_item("training-done"), item("missing", {"type": "file_exists", "paths": ["docs/nothing.md"]}),
                       item("present", {"type": "file_exists", "paths": ["docs/erd.dbml"]}))
        rep = gate_check.Report()
        pest = gate_check.PestRunner(self.root, None, enabled=False)
        a = SimpleNamespace(ci=ci, no_e2e=True, no_tests=True)
        gate_check.mode_done(self.root, self.man, self.st, 5, a, rep, pest)
        return rep

    def states(self, rep: gate_check.Report) -> dict[str, tuple[str, str]]:
        return {c: (s, d) for s, c, d in rep.rows if c.startswith("checklist ")}

    def test_open_phase_in_ci_reports_fail_and_wait_without_failing(self) -> None:
        rep = self.done(ci=True)
        rows = self.states(rep)
        self.assertEqual(rows["checklist training-done"][0], "WAIT")
        self.assertEqual(rows["checklist missing"], ("WARN", "FAIL: missing or empty: docs/nothing.md"))
        self.assertEqual(rows["checklist present"][0], "PASS")
        self.assertFalse(rep.failed)
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            rep.print("done phase 5")
        self.assertIn("== result: PASS (0 failed, 1 waiting,", out.getvalue())

    def test_locally_a_wait_fails_the_gate(self) -> None:
        self.write("docs/nothing.md", "now it exists\n")
        rep = self.done(ci=False)
        self.assertEqual(self.states(rep)["checklist missing"][0], "PASS")
        self.assertTrue(rep.failed, "a required sign-off still waiting fails a local run")

    def test_claimed_phase_in_ci_is_strict(self) -> None:
        self.st = status("done", done_at="2026-11-12T08:00:00Z")
        self.st["phases"]["5"]["done_commit"] = "0" * 40
        rep = self.done(ci=True)
        self.assertEqual(self.states(rep)["checklist training-done"][0], "WAIT")
        self.assertEqual(self.states(rep)["checklist missing"][0], "FAIL")
        self.assertTrue(rep.failed)


class ClaimDone(Fixture):
    """phase_status.py claim-done N runs the gate first for a phase whose gate names a checklist, and refuses with the list."""

    def prepare(self) -> None:
        skill = os.path.join(self.root, ".claude", "skills", "bakery-ops-builder", "assets")
        os.makedirs(skill, exist_ok=True)
        with open(os.path.join(skill, "dependency-manifest.json"), "w", encoding="utf-8") as fh:
            json.dump(self.man, fh)
        self.write("docs/phases/STATUS.json", json.dumps(self.st))
        self.write("docs/phases/phase-5-report.md", "# report\n")
        self.write("docs/openapi.json", "{\"openapi\": \"3.1.0\"}\n")
        self.write("docs/erd.dbml", "Table branches {}\n")

    def claim(self, phase: str) -> tuple[int, str, dict]:
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            code = phase_status.main(["--root", self.root, "claim-done", phase, "--commit", "a" * 40])
        with open(os.path.join(self.root, "docs", "phases", "STATUS.json"), encoding="utf-8") as fh:
            return code, out.getvalue(), json.load(fh)

    def test_refused_with_the_waiting_items(self) -> None:
        self.checklist(signoff_item("training-done"), signoff_item("am-reviewed"),
                       item("present", {"type": "file_exists", "paths": ["docs/erd.dbml"]}))
        self.prepare()
        code, out, st = self.claim("5")
        self.assertEqual(code, 1)
        self.assertIn("claim-done 5 refused", out)
        self.assertIn("[WAIT] checklist training-done", out)
        self.assertIn("[WAIT] checklist am-reviewed", out)
        self.assertNotIn("checklist present", out)
        self.assertEqual(st["phases"]["5"]["status"], "in_progress", "STATUS is not touched")

    def test_claimed_once_every_required_item_passes(self) -> None:
        self.checklist(item("present", {"type": "file_exists", "paths": ["docs/erd.dbml"]}), signoff_item("push-telegram", required=False))
        self.prepare()
        code, out, st = self.claim("5")
        self.assertEqual(code, 0, out)
        self.assertEqual(st["phases"]["5"]["status"], "done")
        self.assertEqual(st["phases"]["5"]["done_commit"], "a" * 40)

    def test_a_phase_without_a_checklist_is_claimed_as_before(self) -> None:
        self.st["phases"]["4"]["status"] = "in_progress"
        self.prepare()
        code, _, st = self.claim("4")
        self.assertEqual(code, 0)
        self.assertEqual(st["phases"]["4"]["status"], "done")


class SignoffsInvariant(Fixture):
    """check_invariants.py --signoffs (the invariants job): a local commit touching docs/go-live/signoffs/ fails the PR."""

    def findings(self) -> list[str]:
        f = check_invariants.Findings()
        check_invariants.check_signoffs(self.root, f)
        return [m for _, m in f.items]

    def test_no_folder_is_clean(self) -> None:
        self.init_git()
        self.assertEqual(self.findings(), [])

    def test_a_web_editor_commit_is_clean(self) -> None:
        self.init_git()
        self.write("docs/go-live/signoffs/training-done.md", signoff_text("training-done"))
        self.git("add", "-A")
        self.git("commit", "-q", "-m", "Create training-done.md", env=WEB_EDITOR)
        self.assertEqual(self.findings(), [])

    def test_a_local_commit_fails(self) -> None:
        self.init_git()
        self.write("docs/go-live/signoffs/training-done.md", signoff_text("training-done"))
        self.git("add", "-A")
        self.git("commit", "-q", "-m", "an agent signs", env=LOCAL)
        found = self.findings()
        self.assertEqual(len(found), 1)
        self.assertIn("(committer Someone Local) touches docs/go-live/signoffs/", found[0])

    def test_an_uncommitted_file_fails(self) -> None:
        self.init_git()
        self.write("docs/go-live/signoffs/training-done.md", signoff_text("training-done"))
        self.assertEqual(self.findings(), ["uncommitted change in docs/go-live/signoffs/training-done.md: sign-offs are made only in GitHub's web editor"])

    def test_a_folder_that_is_not_a_repository_fails(self) -> None:
        self.assertEqual(self.findings(), ["not a git work tree: the check reads the history"])


class BranchProtection(unittest.TestCase):
    """scripts/ops/check_branch_protection.py against a mocked GET /repos/{owner}/{repo}/branches/main."""

    PROTECTED = {"name": "main", "protected": True, "protection": {"enabled": True, "required_status_checks": {
        "enforcement_level": "non_admins",
        "contexts": ["gate-p0", "gate-p1", "gate-p2", "gate-p3", "gate-p4", "gate-p5"],
        "checks": [{"context": f"gate-p{k}", "app_id": 1} for k in range(6)]}}}

    def run_main(self, response, *args: str) -> tuple[int, str, list[str]]:
        calls: list[str] = []

        def api(path: str) -> dict:
            calls.append(path)
            if isinstance(response, Exception):
                raise response
            return response
        out = io.StringIO()
        with mock.patch.object(check_branch_protection, "api_get", side_effect=api), contextlib.redirect_stdout(out):
            code = check_branch_protection.main(["--repo", "owner/repo", *args])
        return code, out.getvalue().strip(), calls

    def test_ranges_and_lists_expand(self) -> None:
        self.assertEqual(check_branch_protection.expand("gate-p0..gate-p5"), [f"gate-p{k}" for k in range(6)])
        self.assertEqual(check_branch_protection.expand("gate-p2..gate-p3, invariants"), ["gate-p2", "gate-p3", "invariants"])
        for bad in ("gate-p5..gate-p0", "gate-p0..other-p5", "a..b"):
            with self.assertRaises(ValueError):
                check_branch_protection.expand(bad)

    def test_every_required_gate_present_passes(self) -> None:
        code, out, calls = self.run_main(self.PROTECTED, "--require", "gate-p0..gate-p5")
        self.assertEqual(code, 0, out)
        self.assertEqual(calls, ["repos/owner/repo/branches/main"])
        self.assertIn("main requires gate-p0, gate-p1, gate-p2, gate-p3, gate-p4, gate-p5", out)

    def test_a_missing_gate_fails(self) -> None:
        resp = json.loads(json.dumps(self.PROTECTED))
        resp["protection"]["required_status_checks"]["contexts"].remove("gate-p5")
        resp["protection"]["required_status_checks"]["checks"].pop()
        code, out, _ = self.run_main(resp, "--require", "gate-p0..gate-p5")
        self.assertEqual(code, 1)
        self.assertIn("main does not require gate-p5", out)

    def test_an_unprotected_branch_fails(self) -> None:
        code, out, _ = self.run_main({"name": "main", "protected": False, "protection": {"enabled": False}}, "--require", "gate-p0")
        self.assertEqual(code, 1)
        self.assertIn("not protected", out)

    def test_an_api_error_fails(self) -> None:
        code, out, _ = self.run_main(check_branch_protection.ApiError("HTTP 401"), "--require", "gate-p0")
        self.assertEqual((code, out), (1, "cannot read main: HTTP 401"))



class ApiGetRetries(unittest.TestCase):
    """A dropped Actions API answer is asked again (GitHub's incident of 2026-11-11 crashed gate-p5 with IncompleteRead)."""

    class Resp:
        def __init__(self, body: bytes) -> None:
            self.body = body

        def __enter__(self):
            return self

        def __exit__(self, *a) -> None:
            return None

        def read(self) -> bytes:
            return self.body

    def test_a_dropped_answer_is_asked_again(self) -> None:
        import http.client
        calls = [http.client.IncompleteRead(b"", 9424), self.Resp(b'{"ok": true}')]

        def urlopen(req, timeout=30):
            got = calls.pop(0)
            if isinstance(got, Exception):
                raise got
            return got

        with mock.patch.dict(os.environ, {"GH_TOKEN": "t"}), mock.patch.object(gate_check.urllib.request, "urlopen", urlopen),                 mock.patch.object(gate_check.time, "sleep"):
            self.assertEqual(gate_check.api_get("repos/o/r/actions/runs/1/jobs"), {"ok": True})

    def test_three_dropped_answers_are_the_apis_failure_not_a_traceback(self) -> None:
        import http.client

        def urlopen(req, timeout=30):
            raise http.client.IncompleteRead(b"", 10)

        with mock.patch.dict(os.environ, {"GH_TOKEN": "t"}), mock.patch.object(gate_check.urllib.request, "urlopen", urlopen),                 mock.patch.object(gate_check.time, "sleep"):
            with self.assertRaises(gate_check.ApiError):
                gate_check.api_get("repos/o/r/actions/runs/1/jobs")

if __name__ == "__main__":
    unittest.main()
