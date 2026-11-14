# ADR 0028: Pruning the idempotency snapshots, and the backup clients under the host's statement limit

- Status: **accepted** (phase-6/backup-fix, 2026-11-14)
- Date: 2026-11-14
- Phase: 6 (a fix after the claim)

## Context

The nightly `backup:run` (02:30 Addis Ababa) failed on 2026-11-13 and 2026-11-14 with `backup.dump_failed`, exit code 3. The last good
backup was 2026-11-11 23:30 UTC, so the `@live` check "the last good backup is younger than 26 hours" went red, as it should. The log line:

`mysqldump: Error 2013: Lost connection to server during query when dumping table idempotency_keys at row: 43208`

What was read on the live host (read only, 2026-11-14):

- MariaDB enforces `max_statement_time=20`, global and per session. A plain `SELECT` of `idempotency_keys` from tinker is stopped with
  error 1969.
- `idempotency_keys` held 45,413 rows and 603.6 MB, by far the largest table (then `audit_log` 165 MB, `stock_count_lines` 125 MB,
  `stock_counts` 82 MB, `failed_jobs` 64 MB, which is already pruned at 30 days). Nothing ever deleted its rows. ADR 0003 says snapshots
  expire after 24 hours, but the middleware only deleted an expired row when its own key came back, and most keys never come back. Most rows
  are probably from the `@live` runs after each deploy.
- The database user has every privilege on its own database and none on the server, so it cannot change a global variable.

What was checked against the MariaDB clients (10.3 to 10.11.19, the API image's Debian client included):

- **mysqldump already lifts the statement limit for its own session.** On connecting it sends `/*M!100100 SET @@MAX_STATEMENT_TIME=0 */`.
  Its option `--max-statement-time` is documented as "if unset, overrides server default with 0". Under a global limit of 0.5 s, a plain
  `SELECT` of a 183 MB table stopped with 1969, and mysqldump dumped the same table. A per-user limit (`WITH MAX_STATEMENT_TIME`) is lifted
  the same way.
- **mysqldump has no `init-command`.** With `init-command=...` in the option file's `[client]` or `[mysqldump]` group it stops with
  `unknown variable` and exit code 7 before dumping anything. `loose-init-command` is ignored with a warning. Putting an init-command in the
  option file would have broken every backup.
- **A dump stopped by the statement limit says 1969, not 2013.** With the limit imposed on the dump's own session:
  `Error 1969: Query execution was interrupted (max_statement_time exceeded) when dumping table ... at row: N`, exit 3.
- **2013 means the server dropped the connection.** The live line's exact form (`Error 2013: Lost connection to server during query when
  dumping table ... at row: N`, exit 3) was reproduced by a reader that stops reading for longer than the server's `net_write_timeout`: the
  server gives up on the connection. A host watchdog that kills long-running connections would end the same way. Both get worse as one
  table's single `SELECT` takes longer, and neither can be lifted from the client: mysqldump has no option for `net_write_timeout`, and a
  watchdog is outside MariaDB.
- **An option file of the host can put a limit back.** A `~/.my.cnf` with `[mysqldump] max-statement-time=0.01` makes mysqldump set that
  limit on itself and fail with 1969. It is read after `--defaults-extra-file`, and only the command line wins over it. A cPanel account
  usually has a `~/.my.cnf`.
- **The mysql client sets no limit of its own**, so a restore statement that takes longer than 20 seconds would be stopped. It does have
  `--init-command`.

## Decision

1. **`bakery:prune-idempotency` deletes the snapshots whose replay window ended more than 24 hours ago**
   (`bakery.idempotency.prune_after_hours`). It runs daily at 02:00 in the shop's time, before the backup of 02:30, with `withoutOverlapping`.
   - **The margin.** A row's window ends at its own `expires_at`, which is the write plus `ttl_hours`. A row is deleted 24 hours after that,
     so 48 hours after the write with the defaults.
     - No answer changes: the middleware never replays an expired row (it deletes one when it sees it), and the tables an offline client
       creates keep their own `client_uuid` unique index (ADR 0003).
     - The day of margin keeps the expired answers for whoever looks into "did that retry replay?" the morning after. It also absorbs any
       clock difference between the app and the database.
     - The cutoff follows each row's own `expires_at`, so raising `ttl_hours` keeps the newer rows longer without touching the prune.
       Lowering it never cuts a row's window short.
     - A longer margin buys nothing a client can see, and the table grows by tens of megabytes a day.
   - **In flight.** Each row is deleted only while the run holds that row's (branch, user, key) lock, the same lock the middleware takes
     (`Idempotency::lockName`). A row whose key a request holds right now is skipped and counted, and goes on a later night.
   - **Chunks.** It reads at most 500 ids at a time on the `expires_at` index, never the response bodies, and deletes them by primary key in
     one statement. Each statement takes milliseconds, far under 20 s. It stops at the first chunk after 15 minutes (`--budget=900`) and
     says so; the next night goes on from there. About 45,000 rows is about 91 chunks.
2. **The backup clients lift the statement limit on their command line**, which needs no privilege and carries no secret:
   - mysqldump gets `--max-statement-time=0`. It does this by default, but the flag makes it explicit and wins over any option file of the
     host.
   - The restore drill's mysql gets `--init-command=SET SESSION max_statement_time=0`.
   - The option file still holds only the user, the password and the server, written 0600 and deleted afterwards, as before (ADR 0020).

## Consequences

- The 2013 is a dropped connection, not the statement limit, so part 2 alone would not have fixed the failed nights. What fixes them is
  part 1: it removes about 600 MB from the dump and its largest single `SELECT`. The first run after the deploy (or `php artisan
  bakery:prune-idempotency` by hand) removes the backlog well inside its budget. The next backup at 02:30 should then pass.
- What dropped the connection is still to be read on the host, read only. `SELECT @@net_write_timeout, @@net_read_timeout, @@wait_timeout`
  shows the timeouts. In tinker, `SET SESSION max_statement_time=0` followed by a long `SELECT` that completes rules out a watchdog. A dump
  that dies at about the same number of seconds every night points to one.
- **The limit that remains.** The other big tables (`audit_log`, `stock_count_lines`, `stock_counts`) grow with the shop and are never
  pruned (they are the record). If one of them alone reaches the point where its single `SELECT` outlasts what the host allows, the dump
  fails again in the same way.
  - The way out is not a cleverer client option. It is dumping that table in primary-key ranges, or writing the raw dump to disk before
    compressing it so the reader never stalls the server. Either changes how consistent or how large a night's dump is, and needs its own
    decision.
  - The System page and the hourly stale check (26 hours) say so the morning after.
- `tests/Ops/StatementLimitTest.php` (group `ops`, the backup-drill CI job) proves it on the CI MariaDB 10.11 with the real clients:
  - Under a global limit of 10 ms set as root, a plain read of a 120 MB `idempotency_keys` stops with 1969, and `backup:run` still dumps
    it to its last row.
  - With a host `~/.my.cnf` that sets mysqldump's limit, a dump without the flag fails with 1969, and `backup:run` passes.
  - A restore statement that the same limit stops on a fresh connection is restored by the drill's client.

## Alternatives considered

- **`init-command` in the option file:** refused by mysqldump (exit 7). Rejected.
- **`SET GLOBAL max_statement_time=0`, or a per-user limit:** needs privileges the shared host does not give, and would lift the limit for
  every request of the app. Rejected.
- **Deleting at `expires_at` with no margin:** saves a day of rows and loses the morning-after view of what was replayed. Rejected.
- **One `DELETE ... WHERE expires_at < ?` for the whole backlog:** a 600 MB delete in one statement could itself outlast 20 s on the
  shared host, and holds its locks for all of it. Rejected for chunks by primary key.
- **Dumping `idempotency_keys` without its rows (`--ignore-table` or structure only):** the snapshots of the last two days are what make a
  restore safe against an outbox replay after it. Kept in the dump.
