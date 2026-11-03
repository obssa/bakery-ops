# ADR 0020: Backups and restore drills on a shared host

- Status: **accepted** (step 5.6b, 2026-11-02; proposed in step 5.5b, 2026-11-01, from phase-5 plan sections 6, 9, 10.2 Q2, Q8 and D13).
  The decision is accepted as built in step 5.5b. The first live drill (checklist item `live-ops`, which the deploy's `@live` run checks)
  and the off-site answer (`offsite-backup`, which Ramin signs: a remote configured and one copy verified, or the host-only risk accepted
  in writing) stay go-live checklist rows that must pass before the phase is claimed: they gate the claim, not the decision.
- Date: 2026-11-01
- Phase: 5

## Context

The ledger is the product: `stock_movements` is append-only, every locked count is a link of a hash chain, money is DECIMAL. A database
that is lost, or restored wrong, loses exactly what the rest of the design protects. The live host is a shared cPanel account (ADR 0006):
MariaDB 10.11, CloudLinux alt-php83, no Docker, no object storage, no long-running process, the scheduler and the queue run by cron once a
minute; receipt photos sit on the account's private disk (`FILES_DISK=files`, ADR 0015). Until Phase 5 nothing copied the database or the
files folder anywhere, and nothing ever proved that a copy would restore into a ledger that still adds up. The architecture names backups to
object storage and a restore drill into a scratch database; the host has neither a bucket nor a second database yet, and both need Ramin's
account (plan 10.2 Q2 and Q8).

## Decision

1. **A nightly dump from the scheduler, to the account's own disk.** `php artisan backup:run`, daily at 02:30 Africa/Addis_Ababa on the
   existing `schedule:run` cron line (the shop is closed; the chain check follows at 03:00; `withoutOverlapping`). It runs
   `mysqldump --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces --default-character-set=utf8mb4` on the app's
   database without a shell and gzips the output as it streams, so the dump is one consistent InnoDB snapshot, nothing is held in memory or
   written twice, and the triggers and the stock view come back with the tables. The credentials go in an option file
   (`--defaults-extra-file`, the first argument) written 0600 in the backup folder and deleted in `finally`: the password is never in the
   process arguments, the environment or a log line. A dump must end with mysqldump's `-- Dump completed` line.
2. **Where it goes.** `BACKUP_PATH`, by default `~/backups/bakery-ops` of the account, outside the app folder so the deploy's
   `rsync --delete` never sees it; a folder inside the app is refused before anything is created there. A night is three entries named by
   its UTC start time: `bakery-ops-YYYYMMDD-HHMMSSZ.sql.gz`, its manifest `.json`, and the files snapshot `.files/`. Work in progress
   carries `.partial` and is renamed when complete.
3. **The manifest is what makes a dump good.** Written last, atomically: the dump's sha256 and size, the row counts of the main tables read
   just before the dump started and, for a table that changed while it ran, again just after (a restored count must lie between the two),
   the migration count, every trigger of the database, the app's commit (the release's `REVISION`) and the files snapshot. A dump without a
   readable manifest that names it and gives its size is not a good backup: retention deletes it and the drill never restores it.
4. **The files folder as hard links.** Every file of the private files disk is hard-linked into the night's `.files/` folder (a copy only
   when a link cannot be made, counted apart). A receipt photo is written once and never changed, so a snapshot costs no space while the app
   keeps the file, and still keeps a photo the app later purges (the hourly orphan sweep). A database restored without the files of the
   same night has receipt rows pointing at nothing; restoring both from the same night is the runbook's procedure. A disk that is not local
   (`FILES_DISK=files_s3`) has no folder to snapshot: the bucket keeps its own versions.
5. **Retention.** 30 dumps (`BACKUP_KEEP`) under a cap on the dumps and their manifests (`BACKUP_MAX_MB`, 2048): a dump without a good
   manifest goes at once; of the rest, the oldest goes while more than 30 stay or while they weigh more than the cap. The newest good dump
   is never deleted, even when it alone is over the cap (the run then records `over_cap`). A night's dump, manifest and files go together.
   Hard links make the files snapshots nearly free, so the cap counts the dumps only; the runbook says what to do when the disk fills.
6. **A weekly restore drill proves the backup.** `php artisan backup:drill`, Sundays at 03:30. It takes the newest good dump, checks its
   sha256 against the manifest, empties the scratch database `BACKUP_DRILL_DATABASE` (same server, same database user; never the app's own
   database, which it refuses by name before and again before emptying), restores the dump into it with the `mysql` client and checks, on
   that connection: Inventory's `LedgerOracle::check` (the movements added up by the ledger's rule equal `stock_balances` and
   `item_stock_view`), Shifts' `HashChain::verifyAll` (every chain of every branch, the stored bytes rebuilt from the restored rows), the
   manifest's triggers present, every append-only table's UPDATE and DELETE triggers present and refusing a statement (inside a
   transaction that is rolled back), and every counted table's rows within the manifest's range. Then it empties the scratch database again
   (`--keep` leaves it, for a person restoring by hand and for the CI job). Until Ramin creates the scratch database (plan 10.2 Q8) the
   drill records `skipped` with `drill.not_configured` and the System page says so; the CI drill proves the mechanism on every run.
7. **What the drill relies on is a contract.** `Inventory\Contracts\LedgerOracle::check($connection)` and
   `Shifts\Contracts\HashChain::verifyAll($connection)` read only the named connection, write nothing, and are proven by
   `contract:ops->inventory` and `contract:ops->shifts` on a scratch copy of the test database, tampered behind the triggers' back.
   `App\Ops`, which runs the jobs, is not a module: it sits above them and uses their `Contracts\*` only (an arch test).
8. **Every run is a row, not an audit entry.** `ops_runs` (kind `backup`, `drill` or `offsite`; status `running`, `ok`, `failed` or
   `skipped`; the dump's size, sha256 and file name; a JSON detail of counts and the failure key; no `branch_id`: one host, one database).
   No person acts, so nothing goes to `audit_log`. A run the host killed stays `running`. A failure raises `BackupFailed` or `DrillFailed`
   (host-wide events with ids, keys and numbers only); Notifications tells every admin from step 5.5c. Nothing stored or raised carries a
   path, a host name, a password or the client's own error text (that goes to the log).
9. **Off-site later, and off.** `BACKUP_REMOTE` names an rclone remote; when set, a good backup's dump, manifest and files snapshot are
   copied there and an `offsite` row records it. It is unset: no account, bucket or credential exists, and none is created by this build
   (plan 10.2 Q2). The remote's credentials would live in rclone's own config on the host, never in this repository or the `.env`.
10. **No HTTP route starts a backup, a drill or a restore.** They are console commands, run by the scheduler and by hand from
    `docs/runbook/backup-restore.md`.

## Consequences

- A bad night is visible the next morning: the run is `failed` with a key, and the System page (5.5c) shows the age of the last good backup
  and the last drill. The scheduler's heartbeat and `BackupStale` (no good backup for 26 hours) arrive with 5.5c.
- **A copy on the same host does not survive the loss of the host.** Until the off-site copy is configured, a deleted account, a failed
  disk or a hosting dispute loses the database and its backups together. The go-live checklist makes that explicit (`offsite-backup`).
- **What a restore loses:** everything written after the dump started (up to a day: the orders, payments, counts and photos of the day
  before a 02:30 failure), plus anything the files snapshot of that night did not have. The append-only ledger cannot be partially replayed
  from elsewhere: the last night is the recovery point. A restore is a decision for Ramin, done by hand from the runbook.
- The dump and the drill need the MariaDB client programs on the host (`mysqldump`, `mysql`) and, for the drill, a second database the
  app's user may use. The API image carries the same clients (Debian bookworm's `mariadb-client`, MariaDB 10.11) so CI runs the real thing.
- The disk holds about 30 dumps; their size grows with the ledger. The cap and `over_cap` keep that bounded and visible; the runbook says
  how to move the folder or lower the cap.

## Enforcement

- `tests/Contract/Ops/InventoryContractTest.php`, `ShiftsContractTest.php` (`contract:ops->inventory` in `mod-inventory`,
  `contract:ops->shifts` in `mod-shifts`).
- `tests/Ops/BackupRunTest.php`, `BackupDrillTest.php` (group `ops`, the real clients against the test server: the password out of the
  arguments and the option file deleted, hard links, retention, the off-site hook, a corrupted dump, a tampered ledger, missing triggers,
  the app's own database refused, the schedule); `tests/Unit/Ops/BackupRetentionTest.php`.
- CI `backup-drill` (checklist item `backup-drill-ci`): the ops group, then the demo bakery with 14 days of history, `backup:run`,
  `backup:drill --keep` into `bakery_drill`, and gate:p0 with `RestoredLedgerGateTest` (`gate:p5:restored`: both oracles, the chains, a
  trigger, new movements and every table against the source).
- `ModuleBoundariesTest` (`App\Ops` uses `Contracts\*` only; nothing reaches up into it), `BranchIdTest` (`ops_runs` allowlisted with its
  reason), `DrillAppendOnlyListTest` (the drill's append-only list equals `scripts/check_invariants.py`), `DeployableCachesTest`
  (`backup.*` caches unset and set).
- Live: `docs/runbook/backup-restore.md`; checklist item `live-ops` (a good backup within 26 hours and a passing drill within 8 days),
  read by the `@live` stage from step 5.5c.

## Alternatives considered

- **cPanel's own backups** (the host's daily account backup, JetBackup where offered): outside the app's control and evidence; nothing
  proves they restore into a working ledger, and their schedule and retention belong to the host. They stay a second line.
- **A dump through PHP (a pure-PHP dumper over PDO):** no dependency on the client programs, but slower, easy to get subtly wrong for
  triggers, views, binary columns and DEFINERs, and not what a person restoring by hand would use. The MariaDB clients are on every cPanel
  host.
- **Object storage now** (Cloudflare R2, Backblaze B2, S3): the right off-site home, but it needs Ramin's account and money (plan 10.2 Q2).
  The rclone hook is the place it plugs in.
- **Binary logs and point-in-time recovery:** not available to a shared-host account.
- **Restoring the drill into the app's own server-side copy with `CREATE DATABASE`:** the cPanel user cannot create databases from SQL;
  the scratch database is created once in cPanel.

## Note, 2026-11-02: accepted at the Phase 5 gate (step 5.6b)

This ADR was written with the build in step 5.5b. What the build (the step's As built note in phase-5 plan section 6) changed from, or
made concrete in, the decision:

- **Where the drill's checks live** (decisions 6 and 7): only the Ledger may name the stock tables in `app/` (`LedgerIsSoleWriterTest`),
  so `LedgerOracle::check` is `Inventory\Ledger\Reconciliation` behind the contract, and the drill's list of append-only tables is
  `config/backup.php` `append_only_tables` (kept equal to `scripts/check_invariants.py` by `DrillAppendOnlyListTest`), not a constant of
  `App\Ops`. The oracle compares the movements with `stock_balances` and with `item_stock_view` separately, so a view restored with
  another definition is caught.
- **`HashChain::verifyAll($connection)`** (decision 7) points the default connection at the named one for the call and puts it back,
  with the current branch, afterwards: the payload rebuild reads through the models. It is a console-time read.
- **The trigger probe** (decision 6) runs only where the trigger exists (a missing one is reported once, as missing) and only on a table
  that has a row: on an empty append-only table the drill checks that the triggers are there, not that they refuse.
- **The contracts' scratch copy** is a second database on the test server, `bakery_scratch` (`SCRATCH_DB_DATABASE`), made by
  `scripts/ci/mysql-prepare.sh` in the module jobs and by `docker/mysql/init.sql` on a fresh compose volume, beside `bakery_drill`.
- **CI `backup-drill`** runs `--group=gate:p0 --group=gate:p5:restored` in one Pest process. Phase 0's gate tests keep their own test
  database on the same server; only `RestoredLedgerGateTest` reads the restored copy (the plan had Phase 0's ledger tests run on it too).
- **`BackupFailed` and `DrillFailed`** (decision 8) are plain host-wide events in `App\Shared\Events` (no `branch_id`, so not domain
  events). Step 5.5c gave them a listener: `NotifyOps` tells the active admins and holders of `system.view` of every branch, each in
  their own inbox (critical, in-app and Telegram when that driver is on), with the failure key as written. `backup:check-stale` runs
  hourly at :15 and raises `BackupStale` once the newest good backup is more than 26 hours old (`backup.stale_hours`), told once per
  stale period. The System page shows the last good backup, its age, the copies kept, the disk they use and the last drill.
- **`live-ops`** (Enforcement) is, as built in step 5.6a, a CI row: the deploy's `live-e2e` job passed on `main` within 7 days. The `@live`
  check of the ages the row's title names (a good backup within 26 hours, a passing drill within 8 days) is `ops-live.spec.ts` of step
  5.5c, run by that job; it enforces the backup's age once backups have run on the live host.

The rest (the dump and its options, the folder and the names, the manifest, the hard-link snapshots, retention and the cap, the
schedule, the off-site hook left off, no HTTP route) is as proposed.

## Note, 2026-11-03 (phase 6, step 6.1b): the media folder in the nightly snapshot

Phase 6 stores product photos on their own disk, `media` (`storage/app/private/media`, ADR 0022). From step 6.1b a night is four entries:
the dump, its manifest, `.files/` and `.media/`. `FilesSnapshot::take($target, $disk)` takes a disk's name, and `backup:run` takes the files
disk into `.files/` and the media disk (`catalog.images.disk`) into `.media/`, both as hard links (a rendition is written once under its
sha256 and never changed, like a receipt photo). The manifest and the run's detail gain `media` with the same figures as `files`; a
manifest written before reads it as empty, so its format version stays 1. Retention deletes `.media/` with its night, and the off-site copy
(still off) copies it. A media disk that is not local records `not_local`, as the files disk does. The restore runbook restores the media
folder with the database and the files folder of the same night. The drill is unchanged: it checks the restored database, not the files.
