"""ci_affected.py: which CI jobs a pull request's change runs, read against the repository's own ci.yml and manifest, and the
guards ci.yml carries for it (every job's `if:` and the gates' upstream check). Runs in the `changes` job of ci.yml.

    python3 -m unittest discover -s scripts/tests -p "test_ci_affected.py" -v
"""
from __future__ import annotations

import contextlib
import io
import json
import os
import re
import sys
import unittest
from unittest import mock

HERE = os.path.dirname(os.path.abspath(__file__))
SCRIPTS = os.path.dirname(HERE)
REPO = os.path.dirname(SCRIPTS)
sys.path.insert(0, SCRIPTS)

import ci_affected  # noqa: E402

with open(os.path.join(REPO, ci_affected.MANIFEST), encoding="utf-8") as _f:
    MANIFEST = json.load(_f)
JOBS, PHASES = ci_affected.read_workflow(REPO)
MOD_JOBS = sorted(j for j in JOBS if j.startswith("mod-"))
E2E_JOBS = sorted(j for j in JOBS if j.startswith("e2e-"))

# PR #125 at 3b87c98 (the new item page): web, e2e and docs only
PR_125 = [
    "apps/web/e2e/p0/admin-smoke.spec.ts", "apps/web/e2e/p4/pay-refund.spec.ts", "apps/web/e2e/p6/a11y-admin.spec.ts",
    "apps/web/e2e/p6/a11y.spec.ts", "apps/web/e2e/p6/screens.spec.ts", "apps/web/messages/am.json", "apps/web/messages/en.json",
    "apps/web/src/app/[locale]/admin/catalog/CatalogScreen.tsx", "apps/web/src/app/[locale]/admin/catalog/new/page.tsx",
    "apps/web/src/components/catalog/Breadcrumb.tsx", "apps/web/src/components/catalog/ItemPage.tsx",
    "apps/web/src/components/catalog/NewItemPage.tsx", "apps/web/src/lib/api/catalog.ts", "apps/web/src/lib/createdItem.ts",
    "docs/design/fresh-counter.md", "docs/i18n/am-review.csv",
]


def run(files: list[str], labels: list[str] = ()) -> dict:
    return ci_affected.affected(files, MANIFEST, JOBS, list(labels), PHASES, 6)


class Selection(unittest.TestCase):
    def test_the_new_item_page_runs_the_web_and_every_e2e_project_and_no_module_job(self):
        r = run(PR_125)
        self.assertFalse(r["full"])
        self.assertEqual([j for j in r["jobs"] if j.startswith("mod-")], [])
        for job in E2E_JOBS + ["web-lint", "web-build", "web-errors", "i18n-check", "docker-build", "invariants"]:
            self.assertIn(job, r["jobs"])
        for job in ["api-lint", "api-arch", "perf-dashboards", "perf-load", "pest-gate-p4", "backup-drill", "files-s3"]:
            self.assertNotIn(job, r["jobs"])

    def test_an_orders_change_runs_orders_and_everything_downstream_of_it_not_what_it_stands_on(self):
        r = run(["apps/api/app/Modules/Orders/Services/Totals.php"])
        self.assertFalse(r["full"])
        mods = {j for j in r["jobs"] if j.startswith("mod-")}
        self.assertTrue({"mod-orders", "mod-payments", "mod-shifts", "mod-analytics"} <= mods, mods)
        self.assertFalse({"mod-identity", "mod-catalog", "mod-inventory"} & mods, mods)
        for job in ["api-lint", "api-arch", "invariants", "openapi-check", "erd-check", "docker-build", "web-build", "pest-gate-p4"] + E2E_JOBS:
            self.assertIn(job, r["jobs"])
        self.assertNotIn("web-lint", r["jobs"])

    def test_a_module_test_or_route_file_counts_as_its_module(self):
        for path in ["apps/api/tests/Modules/Analytics/Feature/X.php", "apps/api/routes/modules/analytics.php"]:
            mods = {j for j in run([path])["jobs"] if j.startswith("mod-")}
            self.assertIn("mod-analytics", mods)
            self.assertNotIn("mod-orders", mods)

    def test_every_module_change_runs_analytics_which_reads_them_all(self):
        for m in ["Identity", "Catalog", "Inventory", "Orders", "Payments", "Production", "Notifications", "Shifts", "Analytics"]:
            self.assertIn("mod-analytics", run([f"apps/api/app/Modules/{m}/X.php"])["jobs"], m)

    def test_a_shared_change_runs_every_api_job(self):
        r = run(["apps/api/app/Shared/Money.php"])
        self.assertFalse(r["full"])
        for job in MOD_JOBS + ci_affected.STATIC_API + ci_affected.whole_api(JOBS) + E2E_JOBS + ["docker-build"]:
            self.assertIn(job, r["jobs"])

    def test_migrations_seeders_and_test_support_run_every_api_job(self):
        for path in ["apps/api/database/migrations/2026_11_14_100000_x.php", "apps/api/database/seeders/Phase1Seeder.php",
                     "apps/api/tests/Support/route_permissions.php", "apps/api/tests/Contract/Orders/IdentityContractTest.php",
                     "apps/api/composer.lock", "apps/api/app/Modules/Audit/AuditedAction.php"]:
            jobs = run([path])["jobs"]
            for job in MOD_JOBS:
                self.assertIn(job, jobs, path)

    def test_ci_scripts_docker_and_phase_files_run_everything(self):
        for path in [".github/workflows/ci.yml", "scripts/gate_check.py", "docker/php.Dockerfile", "compose.ci.yml", "Makefile",
                     "docs/phases/STATUS.json", "docs/phases/phase-6-report.md", "docs/adr/0023-x.md", "docs/go-live-checklist.json",
                     ci_affected.MANIFEST]:
            self.assertTrue(run([path])["full"], path)

    def test_docs_only_run_no_api_web_or_e2e_job(self):
        r = run(["docs/design/fresh-counter.md", "docs/runbook/server.md", "README.md"])
        self.assertFalse(r["full"])
        self.assertEqual(r["jobs"], ["invariants"])
        self.assertEqual(run(["docs/openapi.json"])["jobs"], ["invariants", "openapi-check"])
        self.assertEqual(run(["docs/erd.dbml"])["jobs"], ["erd-check", "invariants"])
        self.assertEqual(run(["docs/i18n/am-review.csv"])["jobs"], ["i18n-check", "invariants"])

    def test_the_print_bridge_runs_alone(self):
        self.assertEqual(run(["tools/print-bridge/bridge.js"])["jobs"], ["print-bridge"])

    def test_an_unknown_path_runs_everything(self):
        self.assertTrue(run(["somewhere/new.txt"])["full"])
        self.assertTrue(run(["apps/mobile/App.tsx"])["full"])

    def test_one_full_path_among_many_runs_everything(self):
        self.assertTrue(run(PR_125 + ["scripts/i18n_export.py"])["full"])

    def test_the_label_full_ci_runs_everything(self):
        self.assertTrue(run(["docs/design/fresh-counter.md"], ["full-ci"])["full"])
        self.assertFalse(run(["docs/design/fresh-counter.md"], ["design"])["full"])

    def test_no_changed_file_runs_everything(self):
        self.assertTrue(run([])["full"])

    def test_a_job_of_a_phase_not_reached_is_left_out(self):
        jobs = ci_affected.affected(["apps/web/src/a.tsx"], MANIFEST, JOBS, [], PHASES, 3)["jobs"]
        self.assertIn("e2e-p3", jobs)
        self.assertNotIn("e2e-p4", jobs)

    def test_a_push_runs_everything(self):
        out = io.StringIO()
        # the CI step running these tests has its own GITHUB_OUTPUT and summary: this run must not write in them
        with mock.patch.dict(os.environ, {"GITHUB_OUTPUT": "", "GITHUB_STEP_SUMMARY": ""}), contextlib.redirect_stdout(out):
            self.assertEqual(ci_affected.main(["--event", "push"]), 0)
        self.assertIn("full=true", out.getvalue())


class Needs(unittest.TestCase):
    def needs(self, **results: str) -> dict:
        return {job.replace("_", "-"): {"result": r, "outputs": {}} for job, r in results.items()}

    def test_a_full_run_needs_every_need_to_pass(self):
        self.assertEqual(ci_affected.check_needs(self.needs(changes="success", mod_orders="success"), "true", []), [])
        self.assertTrue(ci_affected.check_needs(self.needs(changes="success", mod_orders="skipped"), "true", []))

    def test_a_selective_run_lets_a_job_the_change_did_not_select_skip(self):
        n = self.needs(changes="success", gate_p0="success", mod_orders="skipped", e2e_p1="success")
        self.assertEqual(ci_affected.check_needs(n, "false", ["e2e-p1"]), [])

    def test_a_selected_job_that_skipped_means_something_before_it_failed(self):
        n = self.needs(changes="success", mod_catalog="skipped")
        self.assertTrue(ci_affected.check_needs(n, "false", ["mod-catalog"]))

    def test_a_failed_need_fails_the_gate_selected_or_not(self):
        self.assertTrue(ci_affected.check_needs(self.needs(changes="success", mod_orders="failure"), "false", []))
        self.assertTrue(ci_affected.check_needs(self.needs(changes="success", mod_orders="cancelled"), "false", ["mod-orders"]))

    def test_a_gate_or_the_changes_job_always_has_to_pass(self):
        self.assertTrue(ci_affected.check_needs(self.needs(changes="success", gate_p3="skipped"), "false", []))
        self.assertTrue(ci_affected.check_needs(self.needs(changes="failure"), "", []))


# the repository's workflow and the skill's canonical copy of it (assets/ci/ci.yml, what a fresh clone installs)
WORKFLOWS = [ci_affected.WORKFLOW, ".claude/skills/bakery-ops-builder/assets/ci/ci.yml"]


def header(block: str) -> str:
    """The job's lines above runs-on or uses, on one line."""
    return " ".join(re.split(r"\n    (?:runs-on|uses):", block)[0].split())


def needs_of(block: str) -> list[str]:
    m = re.search(r"^    needs:\s*(.+?)\s*$", block, re.M)
    return [x.strip() for x in m.group(1).strip("[]").split(",") if x.strip()] if m else []


def job_blocks(workflow: str) -> dict[str, str]:
    blocks: dict[str, str] = {}
    cur, in_jobs = None, False
    with open(os.path.join(REPO, workflow), encoding="utf-8") as f:
        for line in f:
            if re.match(r"^jobs:\s*$", line):
                in_jobs = True
                continue
            m = re.match(r"^  ([A-Za-z0-9_-]+):\s*$", line) if in_jobs else None
            if m:
                cur = m.group(1)
                blocks[cur] = ""
            elif cur:
                blocks[cur] += line
    return blocks


class Workflow(unittest.TestCase):
    """ci.yml and its canonical copy carry the guards the selection relies on, for every job (a job added without them would
    run on every PR, or run after a failed need)."""

    def test_every_job_the_selection_names_exists(self):
        for job in ci_affected.STATIC_API + ci_affected.WEB + ["docker-build", "print-bridge"]:
            self.assertIn(job, JOBS)

    def test_every_job_but_status_and_changes_waits_for_changes(self):
        for wf in WORKFLOWS:
            blocks = job_blocks(wf)
            self.assertIn("changes", blocks, wf)
            for job, block in blocks.items():
                if job not in ("status", "changes"):
                    self.assertIn("changes", needs_of(block), f"{wf}: {job}")

    def test_every_job_has_both_branches_and_a_clause_per_need(self):
        sel = "fromJSON(needs.changes.outputs.jobs || '[]')"
        for wf in WORKFLOWS:
            for job, block in job_blocks(wf).items():
                if job in ("status", "changes") or job.startswith("gate-p"):
                    continue
                cond, where = header(block), f"{wf}: {job}"
                self.assertIn("needs.changes.outputs.full == 'true' && success()", cond, where)
                self.assertIn("needs.changes.outputs.full == 'false' && !cancelled() && needs.changes.result == 'success'", cond, where)
                self.assertIn(f"contains({sel}, '{job}')", cond, where)
                for need in needs_of(block):
                    if need not in ("status", "changes"):
                        self.assertIn(f"(needs.{need}.result == 'success' || !contains({sel}, '{need}'))", cond, f"{where} -> {need}")
                p = re.search(r"fromJSON\(needs\.status\.outputs\.phase\) >= (\d+)", cond)
                if p:
                    self.assertIn(f"fromJSON(needs.status.outputs.phase) >= {p.group(1)} && (", cond, where)

    def test_every_gate_always_runs_and_checks_what_it_waits_for(self):
        for wf in WORKFLOWS:
            for job, block in job_blocks(wf).items():
                if job.startswith("gate-p"):
                    self.assertRegex(block, r"    if: \$\{\{ !cancelled\(\)", f"{wf}: {job}")
                    self.assertIn("run: python3 scripts/ci_affected.py --check-needs", block, f"{wf}: {job}")

    def test_a_gates_pest_group_runs_in_its_own_job_beside_the_others_and_the_gate_needs_it(self):
        for wf in WORKFLOWS:
            blocks = job_blocks(wf)
            pest_jobs = [j for j, b in blocks.items() if re.search(r"- name: Pest group gate:p", b)]
            self.assertTrue(pest_jobs, wf)
            for job in pest_jobs:
                self.assertRegex(job, r"^pest-gate-p\d+$", f"{wf}: a gate's Pest group belongs in pest-gate-pN, not in {job}")
                self.assertIn(job, needs_of(blocks[job[len("pest-"):]]), f"{wf}: {job}")
                self.assertNotIn("GATE_PEST", blocks[job], f"{wf}: {job}")

    def test_a_gate_only_checks_and_runs_gate_check(self):
        for wf in WORKFLOWS:
            for job, block in job_blocks(wf).items():
                if not re.fullmatch(r"gate-p\d+", job):
                    continue
                where = f"{wf}: {job}"
                self.assertNotIn("GATE_PEST", block, where)
                self.assertNotIn("docker compose", block, where)
                steps = [st for st in block.split("\n      - ") if "scripts/gate_check.py --mode done" in st]
                self.assertEqual(len(steps), 1, where)
                self.assertNotIn("if:", steps[0], where)

    def test_the_gates_run_side_by_side_and_each_covers_the_one_below(self):
        """No gate waits for another gate; instead gate-pN needs every job gate-p(N-1) needs (the closure of the phases below)."""
        for wf in WORKFLOWS:
            blocks = job_blocks(wf)
            gates = sorted((int(j[6:]), j) for j in blocks if re.fullmatch(r"gate-p\d+", j))
            for _, g in gates:
                self.assertFalse([n for n in needs_of(blocks[g]) if re.fullmatch(r"gate-p\d+", n)], f"{wf}: {g} waits for a gate")
            for (_, lower), (_, upper) in zip(gates, gates[1:]):
                missing = set(needs_of(blocks[lower])) - set(needs_of(blocks[upper])) - {"status"}
                self.assertFalse(missing, f"{wf}: {upper} does not cover {lower}: {sorted(missing)}")

    def test_no_test_job_waits_for_a_module_job(self):
        """The module, e2e and stack jobs start together; only the gates hold the dependency rule."""
        for wf in WORKFLOWS:
            for job, block in job_blocks(wf).items():
                if re.fullmatch(r"gate-p\d+", job):
                    continue
                waits = [n for n in needs_of(block) if n.startswith(("mod-", "e2e-")) or n in ("rules-off-gates", "enforced-gates")]
                self.assertFalse(waits, f"{wf}: {job} waits for {waits}")

    def test_the_manifest_names_the_same_needs_as_ci_yml(self):
        """dependency-manifest.json ci.*_jobs is what gate_check reads (gate_needs, the checklist's `jobs`): it must be ci.yml's graph."""
        for key in ("module_jobs", "e2e_jobs", "gate_jobs"):
            for job, needs in MANIFEST["ci"][key].items():
                self.assertIn(job, JOBS, f"{key}: {job}")
                self.assertEqual(sorted(needs), sorted(n for n in JOBS[job] if n not in ("status", "changes")), f"{key}: {job}")

    def test_mod_inventory_runs_in_three_shards(self):
        block = job_blocks(".github/workflows/ci.yml")["mod-inventory"]
        self.assertIn('matrix: { shard: ["1/3", "2/3", "3/3"] }', block)
        self.assertIn('shard: "${{ matrix.shard }}"', block)

SHA = "a" * 40
GH_REPO = "obssa/bakery-ops"


def pr_run(**over) -> dict:
    run = {"id": 7, "head_sha": SHA, "event": "pull_request", "status": "completed", "conclusion": "success",
           "head_repository": {"full_name": GH_REPO}, "created_at": "2026-11-11T20:00:00Z", "html_url": "https://x/runs/7"}
    run.update(over)
    return run


class MainReusesThePullRequestRun(unittest.TestCase):
    def main_out(self, argv: list[str], env: dict | None = None) -> str:
        out = io.StringIO()
        with mock.patch.dict(os.environ, {"GITHUB_OUTPUT": "", "GITHUB_STEP_SUMMARY": "", **(env or {})}), contextlib.redirect_stdout(out):
            self.assertEqual(ci_affected.main(argv), 0)
        return out.getvalue()

    def test_a_pull_request_always_runs_everything(self):
        self.assertIn("full=true", self.main_out(["--event", "pull_request"]))
        self.assertIn("full=true", self.main_out(["--event", "pull_request", "--base", "x", "--head", "y"]))

    def test_the_same_commit_full_and_green_is_reused(self):
        self.assertEqual(ci_affected.pick_reusable([pr_run()], SHA, GH_REPO, lambda r: True)["id"], 7)

    def test_a_run_that_is_not_full_green_same_commit_or_ours_is_not(self):
        always = lambda r: True  # noqa: E731
        self.assertIsNone(ci_affected.pick_reusable([pr_run()], SHA, GH_REPO, lambda r: False))
        self.assertIsNone(ci_affected.pick_reusable([pr_run(conclusion="failure")], SHA, GH_REPO, always))
        self.assertIsNone(ci_affected.pick_reusable([pr_run(status="in_progress", conclusion=None)], SHA, GH_REPO, always))
        self.assertIsNone(ci_affected.pick_reusable([pr_run(head_sha="b" * 40)], SHA, GH_REPO, always))
        self.assertIsNone(ci_affected.pick_reusable([pr_run(event="push")], SHA, GH_REPO, always))
        self.assertIsNone(ci_affected.pick_reusable([pr_run(head_repository={"full_name": "someone/fork"})], SHA, GH_REPO, always))
        self.assertIsNone(ci_affected.pick_reusable([pr_run(head_repository=None)], SHA, GH_REPO, always))

    def test_the_newest_reusable_run_wins(self):
        runs = [pr_run(id=1, created_at="2026-11-11T10:00:00Z"), pr_run(id=2, created_at="2026-11-11T12:00:00Z")]
        self.assertEqual(ci_affected.pick_reusable(runs, SHA, GH_REPO, lambda r: True)["id"], 2)

    def test_a_push_with_a_reusable_run_selects_nothing(self):
        with mock.patch.object(ci_affected, "reusable_run", return_value=pr_run()):
            out = self.main_out(["--event", "push", "--sha", SHA, "--before", "b" * 40, "--repo", GH_REPO], {"GITHUB_TOKEN": "t"})
        self.assertIn("full=false", out)
        self.assertIn("jobs=[]", out)

    def test_a_push_runs_everything_when_the_api_cannot_answer(self):
        boom = mock.Mock(side_effect=ci_affected.urllib.error.URLError("down"))
        with mock.patch.object(ci_affected, "reusable_run", boom), mock.patch.object(ci_affected, "changed_files", return_value=["apps/web/a.tsx"]):
            out = self.main_out(["--event", "push", "--sha", SHA, "--before", "b" * 40, "--repo", GH_REPO], {"GITHUB_TOKEN": "t"})
        self.assertIn("full=true", out)

    def test_a_push_without_a_token_never_reuses(self):
        with mock.patch.object(ci_affected, "reusable_run") as asked, mock.patch.object(ci_affected, "changed_files", return_value=["apps/web/a.tsx"]):
            out = self.main_out(["--event", "push", "--sha", SHA, "--before", "b" * 40, "--repo", GH_REPO], {"GITHUB_TOKEN": ""})
        asked.assert_not_called()
        self.assertIn("full=true", out)

    def test_a_docs_only_push_runs_the_doc_checks(self):
        self.assertEqual(ci_affected.docs_only_jobs(["docs/phases/STATUS.json", "docs/phases/phase-6-report.md"]), ["invariants"])
        self.assertEqual(ci_affected.docs_only_jobs(["docs/openapi.json", "docs/i18n/am-review.csv", "docs/erd.dbml"]),
                         ["erd-check", "i18n-check", "invariants", "openapi-check"])
        self.assertIsNone(ci_affected.docs_only_jobs(["docs/x.md", "apps/web/a.tsx"]))
        self.assertIsNone(ci_affected.docs_only_jobs(["README.md"]))
        self.assertIsNone(ci_affected.docs_only_jobs([]))
        with mock.patch.object(ci_affected, "reusable_run", return_value=None),                 mock.patch.object(ci_affected, "changed_files", return_value=["docs/go-live/signoffs/x.md"]):
            out = self.main_out(["--event", "push", "--sha", SHA, "--before", "b" * 40, "--repo", GH_REPO], {"GITHUB_TOKEN": "t"})
        self.assertIn("full=false", out)
        self.assertIn('jobs=["invariants"]', out)

    def test_a_new_branch_push_runs_everything(self):
        with mock.patch.object(ci_affected, "reusable_run", return_value=None):
            out = self.main_out(["--event", "push", "--sha", SHA, "--before", "0" * 40, "--repo", GH_REPO], {"GITHUB_TOKEN": "t"})
        self.assertIn("full=true", out)

    def test_a_manual_run_runs_everything(self):
        self.assertIn("full=true", self.main_out(["--event", "workflow_dispatch"]))


if __name__ == "__main__":
    unittest.main()
