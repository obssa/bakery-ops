# Runbook: backups, the restore drill and a restore by hand

Phase 5, step 5.5b (ADR 0020). The commands below run in the account's terminal (cPanel Terminal or SSH as the account user), in the app
folder (`DEPLOY_PATH`, written `<app>` here), with the CLI PHP of the host (`/opt/alt/php83/usr/bin/php`, written `php` here). Nothing in
this runbook is run by an agent or by CI against the live host.

## What runs, and when

| Job | When (Africa/Addis_Ababa) | What it does |
|---|---|---|
| `backup:run` | every night at 02:30 | dumps the database (gzip) and snapshots the files folder as hard links into the backup folder, writes the manifest, applies retention |
| `backup:drill` | Sundays at 03:30 | restores the newest good dump into the scratch database, checks the ledger, the hash chains, the triggers and the row counts, empties it again |

Both run from the **existing** `schedule:run` cron entry (docs/runbook/server.md, "Cron entries"): **no new cron line is needed.** Each run
is a row in `ops_runs`; the System page of step 5.5c shows the last backup and the last drill.

## One-time setup on the host

1. **Check the client programs** the cron environment will find:
   `command -v mysqldump mysql` (both must print a path) and `mysqldump --version` (MariaDB 10.11 on this host).
2. **The backup folder.** Nothing to do for the default, `~/backups/bakery-ops` (created 0700 on the first run, outside the app folder, so
   a deploy's `rsync --delete` never touches it). To put it elsewhere, set `BACKUP_PATH=/home/<account>/<folder>` in `<app>/.env`; a folder
   inside the app is refused.
3. **The scratch database for the drill** (plan 10.2 Q8; free, but it is Ramin's account): in cPanel, *MySQL Databases*, create a database
   (cPanel prefixes it with the account name, e.g. `<account>_drill`), add **the app's own database user** to it with **ALL PRIVILEGES**,
   then set `BACKUP_DRILL_DATABASE=<account>_drill` in `<app>/.env` and run `php artisan config:cache`. It must be a database that holds
   nothing else: every drill empties it. The drill refuses the app's own database. Until this is done the drill records `skipped`
   (`drill.not_configured`). The same user matters: the dump's triggers and view are created with that user as their DEFINER.
4. **First run by hand**, to see it work: `php artisan backup:run`, then (once step 3 is done) `php artisan backup:drill`. Each prints one
   line with its result (never a path).

Settings (all in `<app>/.env`, then `php artisan config:cache`): `BACKUP_PATH` (empty: the default folder), `BACKUP_KEEP` (30),
`BACKUP_MAX_MB` (2048), `BACKUP_DRILL_DATABASE` (empty: drill skipped), `BACKUP_REMOTE` (empty: no off-site copy).

## Where the dumps are

In the backup folder, one night is three entries named by the UTC time the backup started:

- `bakery-ops-YYYYMMDD-HHMMSSZ.sql.gz` the dump (gzip of a plain SQL file);
- `bakery-ops-YYYYMMDD-HHMMSSZ.json` its manifest: `sha256`, `bytes`, the row counts of the main tables (`rows`, and `rows_after` for a
  table that changed during the dump), `migrations`, the `triggers`, `app_commit`, and the `files` snapshot's figures;
- `bakery-ops-YYYYMMDD-HHMMSSZ.files/` the files folder of that night (hard links: they take no space while the app still has the file).

A dump without its manifest is not a good backup (the run was interrupted): retention deletes it. Anything ending in `.partial` is a run in
progress or one the host killed; the next run removes it.

To check a dump by hand: `sha256sum bakery-ops-...sql.gz` equals the manifest's `sha256`, and `zcat bakery-ops-...sql.gz | tail -1` reads
`-- Dump completed on ...`.

## The drill

`php artisan backup:drill` (or wait for Sunday). It passes when the newest good dump restores and:

- the ledger adds up (Inventory's LedgerOracle: the movements equal `stock_balances` and `item_stock_view`);
- every hash chain is whole (Shifts' HashChain, every place of every branch);
- the triggers are there and the append-only tables refuse an UPDATE and a DELETE;
- every counted table holds the manifest's rows.

`php artisan backup:drill --keep` leaves the restored copy in the scratch database for you to look at (with phpMyAdmin, read only); run
the drill again without `--keep` afterwards, or empty it in phpMyAdmin, so it does not hold a copy of the data longer than needed.

## A full restore by hand

A restore replaces the live data with the data of one night: everything written after that dump started is lost (ADR 0020). It is Ramin's
decision. Restore the database **and** the files folder of the **same** night.

1. **Pick the night**: the newest whose drill passed, or the newest good dump before the problem. Check its sha256 (above).
2. **Stop writes**: `php artisan down` (the app answers 503 to everyone; the cron jobs keep running but write nothing that matters while
   no one uses the app). Pause the `queue:work` cron line if the restore will take more than a few minutes.
3. **Keep what is there now**: `php artisan backup:run` (a dump of the state you are about to replace, in case the restore was the wrong
   call). Note its name.
4. **Restore the database** into the app's database. The dump replaces every table it holds (`DROP TABLE IF EXISTS` then `CREATE`):
   - in phpMyAdmin: *Import*, the `.sql.gz` file, format SQL; or
   - in the terminal: `zcat <folder>/bakery-ops-<night>.sql.gz | mysql <app database>` (the client asks for the password with `-p`, or
     reads it from `~/.my.cnf`; never type it on the command line).
5. **Restore the files folder** of the same night: `rsync -a --delete <folder>/bakery-ops-<night>.files/ <app>/storage/app/private/files/`.
6. **Check it** before opening: `php artisan migrate --force` (nothing to run when the release is the dump's `app_commit`; a later
   release migrates forward), `php artisan shifts:verify-chain` (every chain whole), then `php artisan up`.
7. **Record what happened**: which night, why, and what was lost. The next nightly backup runs as usual.

A restore into a different database (to look at old data without touching live): point a copy of the restore at the scratch database
instead, or use `backup:drill --keep`, which restores the newest good dump there.

## When the disk fills

The account's disk holds the app, its files folder (receipt photos), the database and the backups. Retention keeps 30 dumps under
`BACKUP_MAX_MB` (2 GB): the oldest go first, the newest good one never. A run that cannot stay under the cap even with one dump records
`over_cap` in its row.

1. Look at the sizes: `du -sh ~/backups/bakery-ops <app>/storage/app/private/files` and the cPanel *Disk Usage* page.
2. Lower the number kept (`BACKUP_KEEP=14`) or the cap (`BACKUP_MAX_MB=1024`), then `php artisan config:cache`; the next run applies it.
3. The files snapshots are hard links: they free space only when the app has also purged the same photos. Deleting an old night's
   `.files/` folder by hand loses only photos the app no longer has.
4. When the photos are what fills the disk, move them to an S3-compatible bucket (`FILES_DISK=files_s3`, docs/runbook/server.md, "Files").
5. Never delete the newest good dump to make room. If the disk is full now, delete the oldest nights (all three entries of a night).

## When a run fails

The run's row says why (`detail.failure_key`); the System page shows it, and from step 5.5c every admin gets an inbox line. The client's
own error text is in `storage/logs` (`ops: mysqldump failed`, `ops: mysql failed`).

| Key | Meaning | What to do |
|---|---|---|
| `backup.unsupported_driver` | the database is not MySQL or MariaDB | the backups are built for this host's MariaDB; on PostgreSQL use `pg_dump` by hand |
| `backup.no_path` | no `BACKUP_PATH` and no home folder | set `BACKUP_PATH` to an absolute folder |
| `backup.path_inside_app` | the folder is inside the app | move it outside (a deploy would delete it) |
| `backup.path_unwritable` | the folder cannot be created or written | check the folder's owner and the disk |
| `backup.dump_failed` | mysqldump exited with an error (`exit_code`) | run `php artisan backup:run` by hand and read the log line; check `command -v mysqldump` |
| `backup.dump_incomplete` | the dump did not end with `-- Dump completed` | usually a killed process or a full disk |
| `backup.write_failed` | the dump or manifest could not be written | the disk is full or the folder unwritable |
| `backup.files_failed` | the files snapshot could not be made | check the files folder and the disk |
| `offsite.copy_failed` | rclone could not copy (only with `BACKUP_REMOTE` set) | the local backup stands; check `rclone` and its remote |
| `drill.not_configured` | (`skipped`) no `BACKUP_DRILL_DATABASE` | one-time setup step 3 |
| `drill.same_database` | the drill database is the app's own | set a separate scratch database |
| `drill.no_dump` | no good dump in the folder | look at the last backups |
| `drill.checksum_mismatch` | the dump is not the one its manifest describes | the file was changed or damaged: do not restore it |
| `drill.restore_failed` | the mysql client could not restore it (`exit_code`) | read the log line; check the user's privileges on the drill database |
| `drill.ledger_mismatch` | the restored ledger does not add up (`detail.ledger`) | treat as serious: compare with the live database (`shifts:verify-chain`, the ledger checks) |
| `drill.chain_broken` | a restored hash chain is broken (`detail.chains.first_broken`) | as for a live break (docs/runbook/server.md, "When the chain reports a break") |
| `drill.triggers_missing` / `drill.trigger_silent` | the restored copy lacks an append-only trigger, or it does not refuse | the dump lost them: check the live database's triggers |
| `drill.row_counts` | a restored table's rows differ from the manifest (`detail.rows.off`) | the dump is not the night the manifest describes |
| `backup.stale` | (step 5.5c, not a run's key: the hourly `backup:check-stale`) the newest good backup is older than 26 hours, or none has ended ok more than 26 hours after the first try | find the failed nights above; run `php artisan backup:run` by hand once the cause is fixed. Each admin is told once per stale period |

## Off-site copy (off)

`BACKUP_REMOTE` is empty: no copy leaves the host, and a copy on the same host does not survive the loss of the host (plan 10.2 Q2; the
go-live checklist item `offsite-backup`). To turn it on, Ramin picks the storage (an R2 or S3 bucket, Backblaze, a drive he owns), installs
`rclone` in the account, runs `rclone config` to create a remote (its credentials stay in rclone's own config file, never in the app's
`.env` or this repository), and sets `BACKUP_REMOTE=<remote>:<folder>`. Each good backup then copies its dump, manifest and files snapshot
there and writes an `offsite` row.

## Downloading a backup by hand

Ramin decided on 2026-11-02 to go live without the off-site copy and to download a backup by hand instead. Do this until
`BACKUP_REMOTE` is set. The owner or the technical on-call does it with the hosting account. No admin screen can download a backup.

**How often:**

- Weekly. The best time is Monday, after Sunday's drill has passed, so the copy you take is one the drill restored.
- After any big change: a deploy that ran migrations, a large import, or a big clean-up of the menu, the stock or the people.
- Before any risky work on the host.

**Where the files are:**

- The backup folder is `BACKUP_PATH` (`config/backup.php`). When it is unset, it is `backups/bakery-ops` in the hosting account's home
  folder.
- That folder is beside the app's folder, not inside it, so a deploy never touches it.
- Each night's files are named as in [Where the dumps are](#where-the-dumps-are).

**Which one to take:**

1. On the System page, read **Last good backup**. It gives the date and time of the newest good backup, in Addis Ababa time.
2. The file names carry the UTC time the backup started, which is three hours behind Addis Ababa. A backup that started at 02:30 in
   Addis Ababa is named `...-233000Z` of the day before. The nightly backup starts at 02:30, so a copy is named like this.
3. Take the newest `bakery-ops-YYYYMMDD-HHMMSSZ.sql.gz` that has its `.json` manifest beside it. A dump without a manifest, or anything
   ending in `.partial`, is not a good backup.

**How to download it:**

- **With cPanel File Manager:**
  1. Open the home folder, then `backups/bakery-ops`.
  2. Select the `.sql.gz` and its `.json`, and choose **Download**, one file at a time.
- **With SFTP** (for example WinSCP or FileZilla):
  1. Sign in with the hosting account's SFTP access.
  2. Go to the same folder and copy the two files to your computer.
  3. Keep the SFTP login in your password manager, never in this repository.
- **The night's files folder** (`.files/`, the payment photos) is optional and can be large. To take it:
  1. Compress it in File Manager to a `.zip` outside the backup folder.
  2. Download the `.zip`, then delete it from the host.

**How to check the copy:** compare the dump's SHA-256 with the `sha256` in the manifest. The two must be equal (ignore upper and lower
case).

- **Windows PowerShell:** `Get-FileHash .\bakery-ops-...sql.gz -Algorithm SHA256`
- **Windows command prompt:** `certutil -hashfile bakery-ops-...sql.gz SHA256`
- **Linux or macOS:** `sha256sum bakery-ops-...sql.gz` (on macOS, `shasum -a 256`)

A copy whose checksum does not match is damaged. Download it again, or take the night before.

**Where to keep it:**

- Off the shop premises, so a fire or a theft at the shop does not take both the computers and the copy.
- Encrypted, because the dump holds the staff list (names and phone numbers) and every sale and payment. Use an encrypted USB drive (for
  example BitLocker To Go), or an encrypted archive (for example 7-Zip with AES-256) in the owner's own cloud storage.
- Keep the password apart from the copy.
- Keep the last four weekly copies, plus any taken after a big change, and delete older ones.
- Never put a copy in this repository, in an email, or in a chat.

To restore from a downloaded copy:

1. Upload the `.sql.gz` and its `.json` back into the backup folder.
2. Check the checksum again.
3. Follow [A full restore by hand](#a-full-restore-by-hand).
