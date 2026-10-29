"""The i18n scripts of step 5.4a on a tiny copy of the two dictionaries (scripts/tests/fixtures/i18n).

    python3 -m unittest discover -s scripts/tests -p "test_*.py"

The fixture holds two web namespaces and one API file: a key the code never reads, a key read through a template, a placeholder the
Amharic lost, an Amharic text that is still the English one, and brand words that may stay as they are.
"""
from __future__ import annotations

import csv
import json
import os
import shutil
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))

import i18n_export  # noqa: E402
import i18n_import  # noqa: E402
import i18n_report  # noqa: E402

FIXTURE = os.path.join(HERE, "fixtures", "i18n")
HEADER = ["key", "screen", "english", "amharic", "correction", "comment"]


class Copy(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.mkdtemp()
        self.root = os.path.join(self.tmp, "repo")
        shutil.copytree(FIXTURE, self.root)
        self.sheet = os.path.join(self.root, "docs", "i18n", "am-review.csv")

    def tearDown(self) -> None:
        shutil.rmtree(self.tmp)

    def read(self, *parts: str) -> str:
        with open(os.path.join(self.root, *parts), encoding="utf-8") as fh:
            return fh.read()

    def rows(self) -> list[dict[str, str]]:
        with open(self.sheet, encoding="utf-8-sig", newline="") as fh:
            return list(csv.DictReader(fh))

    def fill(self, corrections: dict[str, tuple[str, str]]) -> None:
        rows = self.rows()
        for row in rows:
            if row["key"] in corrections:
                row["correction"], row["comment"] = corrections[row["key"]]
        with open(self.sheet, "w", encoding="utf-8-sig", newline="") as fh:
            writer = csv.DictWriter(fh, fieldnames=HEADER, lineterminator="\n")
            writer.writeheader()
            writer.writerows(rows)


class ReportTest(Copy):
    def test_lists_copies_of_the_english_lost_placeholders_and_unused_keys(self) -> None:
        report = i18n_report.build_report(self.root)

        self.assertEqual(report["same_as_english"], ["web:pad.same"])
        self.assertEqual(report["placeholders"], [("web:pad.greet", ["name"], ["nam"])])
        self.assertEqual(report["unused"], ["web:orphan.old", "api:shifts.shift.gone"])
        self.assertEqual(report["counts"], {"web": 9, "api": 4})

    def test_never_fails_the_build(self) -> None:
        self.assertEqual(i18n_report.main(["--root", self.root]), 0)


class ExportTest(Copy):
    def test_writes_one_row_per_message_with_its_screen(self) -> None:
        self.assertEqual(i18n_export.main(["--root", self.root]), 0)

        rows = self.rows()
        with open(self.sheet, encoding="utf-8-sig", newline="") as fh:
            self.assertEqual(next(csv.reader(fh)), HEADER)
        self.assertEqual(len(rows), 13)
        by_key = {r["key"]: r for r in rows}
        self.assertEqual(by_key["web:pad.title"]["english"], "Waiter pad")
        self.assertEqual(by_key["web:pad.title"]["amharic"], "የአስተናጋጅ ፓድ")
        self.assertEqual(by_key["web:pad.title"]["screen"], "/pad")
        self.assertEqual(by_key["api:shifts.shift.owner"]["english"], "This is another waiter's shift.")
        self.assertEqual(by_key["api:shifts.shift.owner"]["screen"], "server message (shifts)")
        self.assertTrue(all(r["correction"] == "" and r["comment"] == "" for r in rows))

    def test_keeps_the_reviewers_work_when_written_again(self) -> None:
        i18n_export.main(["--root", self.root])
        self.fill({"web:pad.same": ("ዝጋ", "a button")})

        i18n_export.main(["--root", self.root])

        by_key = {r["key"]: r for r in self.rows()}
        self.assertEqual((by_key["web:pad.same"]["correction"], by_key["web:pad.same"]["comment"]), ("ዝጋ", "a button"))


class ImportTest(Copy):
    def test_applies_corrections_to_am_json_and_the_php_files_and_keeps_the_rest(self) -> None:
        i18n_export.main(["--root", self.root])
        php_before = self.read("apps", "api", "lang", "am", "shifts.php")
        self.fill({
            "web:pad.same": ("ዝጋ", "a button"),
            "web:pad.greet": ("ሰላም {name}", "the placeholder was wrong"),
            "api:shifts.shift.already_open": ("ፈረቃ :name ተከፍቷል፤ ይቀላቀሉት።", ""),
            "api:shifts.shift.owner": ("የሌላ ሰው ፈረቃ ነው፤ 'ባለቤቱ' ብቻ", ""),
            "web:pad.title": ("", "fine as it is"),
        })

        self.assertEqual(i18n_import.main(["--root", self.root, self.sheet]), 0)

        am = json.loads(self.read("apps", "web", "messages", "am.json"))
        self.assertEqual(am["pad"]["same"], "ዝጋ")
        self.assertEqual(am["pad"]["greet"], "ሰላም {name}")
        self.assertEqual(am["pad"]["title"], "የአስተናጋጅ ፓድ")
        self.assertEqual(self.read("apps", "web", "messages", "am.json"), json.dumps(am, ensure_ascii=False, indent=2) + "\n")
        php = self.read("apps", "api", "lang", "am", "shifts.php")
        expected = php_before.replace("'ፈረቃ :name አስቀድሞ ተከፍቷል።'", "'ፈረቃ :name ተከፍቷል፤ ይቀላቀሉት።'").replace(
            "'ይህ የሌላ አስተናጋጅ ፈረቃ ነው።'", "'የሌላ ሰው ፈረቃ ነው፤ \\'ባለቤቱ\\' ብቻ'")
        self.assertEqual(php, expected)
        self.assertIn("// A comment the import must keep.", php)

    def test_refuses_the_whole_sheet_when_a_row_is_wrong(self) -> None:
        i18n_export.main(["--root", self.root])
        json_before = self.read("apps", "web", "messages", "am.json")
        php_before = self.read("apps", "api", "lang", "am", "shifts.php")
        self.fill({
            "web:pad.same": ("ዝጋ", ""),
            "api:shifts.shift.already_open": ("ፈረቃው ተከፍቷል።", "lost :name"),
            "web:pad.send": ("ላክ", "lost the plural"),
        })
        with open(self.sheet, "a", encoding="utf-8", newline="") as fh:
            fh.write("web:pad.nothing,/pad,,,ምንም,\n")

        problems = i18n_import.apply_sheet(self.root, self.sheet, dry_run=False)

        self.assertEqual(sorted(problems), [
            "api:shifts.shift.already_open: placeholders differ from the English (en :name, correction none)",
            "web:pad.nothing: no such key",
            "web:pad.send: placeholders differ from the English (en count, correction none)",
        ])
        self.assertEqual(self.read("apps", "web", "messages", "am.json"), json_before)
        self.assertEqual(self.read("apps", "api", "lang", "am", "shifts.php"), php_before)
        self.assertEqual(i18n_import.main(["--root", self.root, self.sheet]), 1)


if __name__ == "__main__":
    unittest.main()
