"""scripts/ops/crontab-sync.sh against a fake crontab (docs/runbook/server.md, "The shared crontab").

The fake stands in for the host's /usr/bin/crontab: `-l` prints the table (or "no crontab for" when there is none), `-` installs
stdin. It can be told to answer the first N reads with an empty table (the wrapper's race), refuse the first N writes, and it
counts every call. The table file doubles as the account's mirror file, as on the host. Needs bash and flock (Linux CI).
"""
import os
import shutil
import subprocess
import tempfile
import textwrap
import unittest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SCRIPT = os.path.join(ROOT, "scripts", "ops", "crontab-sync.sh")

FAKE = textwrap.dedent(
    """\
    #!/usr/bin/env bash
    d="$FAKE_DIR"
    echo "$1" >> "$d/calls"
    n() { local v; v=$(cat "$d/$1" 2>/dev/null || echo 0); echo "$v"; }
    if [ "$1" = "-l" ]; then
      if [ "$(n empty_reads)" -gt 0 ]; then echo $(( $(n empty_reads) - 1 )) > "$d/empty_reads"; exit 0; fi
      [ -f "$d/tab" ] || { echo "no crontab for tester" >&2; exit 1; }
      cat "$d/tab"
    elif [ "$1" = "-" ]; then
      if [ "$(n refuse_writes)" -gt 0 ]; then echo $(( $(n refuse_writes) - 1 )) > "$d/refuse_writes"; cat > /dev/null; echo "BlockingIOError" >&2; exit 1; fi
      cat > "$d/tab"
    else
      echo "fake: unsupported $*" >&2; exit 2
    fi
    """
)

OTHER = [
    'MAILTO=""',
    "* * * * * /bin/bash /srv/other/watchdog.sh >/dev/null 2>&1 # other",
    "*/5 * * * * /bin/bash /srv/other/deploy.sh >/dev/null 2>&1 # other-autodeploy",
    "0 3 * * * /usr/bin/untagged-job",
]
SITE = [
    "* * * * * cd $HOME/site && php artisan schedule:run >> /dev/null 2>&1 # site",
    "* * * * * cd $HOME/site && php artisan queue:work --stop-when-empty >> /dev/null 2>&1 # site",
]


@unittest.skipUnless(shutil.which("bash") and shutil.which("flock"), "needs bash and flock")
class CrontabSyncTest(unittest.TestCase):
    def setUp(self):
        self.home = tempfile.mkdtemp()
        self.fake_dir = os.path.join(self.home, "fake")
        os.makedirs(self.fake_dir)
        self.fake = os.path.join(self.fake_dir, "crontab")
        with open(self.fake, "w", newline="\n") as f:
            f.write(FAKE)
        os.chmod(self.fake, 0o755)
        self.frag_dir = os.path.join(self.home, "crontab.d")
        os.makedirs(self.frag_dir)
        self.fragment("site", SITE)

    def tearDown(self):
        shutil.rmtree(self.home, ignore_errors=True)

    def fragment(self, name, lines):
        with open(os.path.join(self.frag_dir, f"{name}.cron"), "w", newline="\n") as f:
            f.write("\n".join(lines) + "\n")

    def set_tab(self, lines):
        with open(os.path.join(self.fake_dir, "tab"), "w", newline="\n") as f:
            f.write("\n".join(lines) + "\n")

    def tab(self):
        with open(os.path.join(self.fake_dir, "tab")) as f:
            return [line for line in f.read().splitlines() if line.strip()]

    def set_counter(self, name, value):
        with open(os.path.join(self.fake_dir, name), "w") as f:
            f.write(str(value))

    def calls(self):
        path = os.path.join(self.fake_dir, "calls")
        if not os.path.exists(path):
            return []
        with open(path) as f:
            return f.read().split()

    def run_sync(self, *args, mirror=True):
        env = dict(os.environ)
        env.update(
            CRONTAB_BIN=self.fake,
            CRONTAB_MIRROR=os.path.join(self.fake_dir, "tab") if mirror else os.path.join(self.fake_dir, "absent"),
            CRONTAB_D=self.frag_dir,
            CRONTAB_SYNC_HOME=self.home,
            CRONTAB_GAP="0",
            CRONTAB_TRIES="3",
            CRONTAB_SYNC_LOCK_WAIT="1",
            FAKE_DIR=self.fake_dir,
        )
        return subprocess.run(["bash", SCRIPT, *args], env=env, capture_output=True, text=True)

    def test_adds_the_fragment_and_keeps_every_other_line_in_order(self):
        self.set_tab(OTHER)
        r = self.run_sync()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(self.tab(), OTHER + SITE)

    def test_a_second_run_reads_the_mirror_only_and_never_calls_crontab(self):
        self.set_tab(OTHER)
        self.run_sync()
        before = len(self.calls())
        r = self.run_sync()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("in sync", r.stdout)
        self.assertEqual(len(self.calls()), before)

    def test_a_changed_line_of_the_site_is_put_back_once_not_duplicated(self):
        self.set_tab(OTHER + ["* * * * * sleep $((RANDOM \\% 60)) && cd $HOME/site && php artisan schedule:run >> /dev/null 2>&1 # site"])
        r = self.run_sync()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(self.tab(), OTHER + SITE)

    def test_lines_are_replaced_where_they_stand(self):
        # the site's lines sit between two other lines: a changed line is fixed there, not moved to the end
        old = "* * * * * sleep 5 && cd $HOME/site && php artisan schedule:run # site"
        self.set_tab([OTHER[0], OTHER[1], old, OTHER[2], OTHER[3]])
        r = self.run_sync()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(self.tab(), [OTHER[0], OTHER[1], *SITE, OTHER[2], OTHER[3]])

    def test_a_site_that_puts_its_own_lines_last_and_this_tool_agree(self):
        # another site's own installer moves its two lines to the end; the tool keeps that order and writes nothing
        self.fragment("other", OTHER[1:3])
        self.set_tab([OTHER[0], *SITE, OTHER[3], OTHER[1], OTHER[2]])
        r = self.run_sync()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertIn("in sync", r.stdout)
        self.assertNotIn("-", self.calls())

    def test_tags_are_matched_exactly(self):
        # a site called "site-test" is not "site": its line stays untouched
        test_line = "* * * * * cd $HOME/site-test && php artisan schedule:run # site-test"
        self.set_tab(OTHER + [test_line])
        self.run_sync()
        self.assertEqual(self.tab(), OTHER + [test_line] + SITE)

    def test_an_emptied_crontab_gets_the_other_sites_back_from_the_last_good_copy(self):
        self.set_tab(OTHER)
        self.run_sync()
        self.set_tab([])
        r = self.run_sync()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(self.tab(), OTHER + SITE)
        self.assertIn("last good copy", r.stdout)

    def test_a_read_that_comes_back_empty_once_is_not_trusted(self):
        self.set_tab(OTHER)
        self.set_counter("empty_reads", 1)
        r = self.run_sync("--full")
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(self.tab(), OTHER + SITE)

    def test_a_refused_write_is_tried_again(self):
        self.set_tab(OTHER)
        self.set_counter("refuse_writes", 1)
        r = self.run_sync()
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(self.tab(), OTHER + SITE)
        self.assertEqual(self.calls().count("-"), 2)

    def test_check_reports_and_never_writes(self):
        self.set_tab(OTHER)
        r = self.run_sync("--check")
        self.assertEqual(r.returncode, 3)
        self.assertNotIn("-", self.calls())
        self.assertEqual(self.tab(), OTHER)

    def test_a_fragment_line_without_its_tag_stops_everything(self):
        self.set_tab(OTHER)
        self.fragment("broken", ["* * * * * /bin/true"])
        r = self.run_sync()
        self.assertEqual(r.returncode, 2)
        self.assertEqual(self.calls(), [])
        self.assertEqual(self.tab(), OTHER)

    def test_a_tag_that_belongs_to_another_name_stops_everything(self):
        self.set_tab(OTHER)
        self.fragment("mine", ["* * * * * /bin/true # other"])
        r = self.run_sync()
        self.assertEqual(r.returncode, 2)
        self.assertEqual(self.tab(), OTHER)

    def test_no_crontab_at_all_installs_the_fragments(self):
        r = self.run_sync(mirror=False)
        self.assertEqual(r.returncode, 0, r.stderr)
        self.assertEqual(self.tab(), ['MAILTO=""'] + SITE)

    def test_a_held_lock_writes_nothing(self):
        self.set_tab(OTHER)
        lock = os.path.join(self.home, ".crontab-sync.lock")
        holder = subprocess.Popen(["flock", lock, "sleep", "5"])
        try:
            import time

            time.sleep(0.3)
            r = self.run_sync()
        finally:
            holder.kill()
            holder.wait()
        self.assertEqual(r.returncode, 1)
        self.assertEqual(self.tab(), OTHER)
        self.assertNotIn("-", self.calls())


if __name__ == "__main__":
    unittest.main()
