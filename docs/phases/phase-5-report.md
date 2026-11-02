# Phase 5 report — Hardening and Amharic (the phase so far)

Commit: `main` at `8ec9452` (pull request #99, `phase-5/perf-load`, step 5.5d, the last step merged before this report), with step 5.6b's
branch `phase-5/gate` (pull request #104) on top · CI runs before this step: 41405560226 (PR #99's last head `0f4ac0a`) and 41405359490
(PR #102's last head `9858247`), every job green in both; the gate run of 5.6b is recorded under "CI evidence" · Date: 2026-11-02

**This is a report of the phase so far, not a claim that it is done.** Thirteen of the plan's sixteen steps are merged (the plan itself was
PR #87), 5.3b, the print bridge program (PR #101), last. One is an open pull request: 5.5c, the System page and the uptime workflow
(PR #103).
Step 5.4b waits for the Amharic reviewer (Q5). Step 5.6b is this step (PR #104). STATUS keeps phase 5 `in_progress`, no `done_commit` is
set and no tag is made: `phase_status.py claim-done 5` runs `gate_check.py --mode done --phase 5` first and refuses until every required
item of the go-live checklist passes, and eight of those items are sign-offs only Ramin can make.

## Verdict

NOT CLAIMED. The automated part of the gate is built: the `gate:p5` tests, the `gate-p5` job and its needs (`gate-p4`, `e2e-p5`,
`i18n-check`, `backup-drill`, `perf-load`, `print-bridge`), and the go-live checklist that `gate_check` reads. One CI row of the
checklist cannot pass until PR #103 is merged (`uptime-monitor`), and the eight required sign-offs are `WAIT (Ramin)`. The section "What is open" is the whole list.

## What was built, step by step

Each paragraph says what the step built as built (the As built notes under section 6 of `docs/phases/phase-5-plan.md`), not what the
plan intended. The steps were built on parallel branches and merged out of the plan's order.

**5.1a `phase-5/device-enforcement` (PR #88).** `EnsureEnrolledDevice` (alias `device`) behind `identity.require_enrolled_device` (env
`IDENTITY_REQUIRE_DEVICE`, off by default), applied to the station routes: 58 at this step, listed in `tests/Support/station_routes.php`
with the screen that calls each, and the other 126 in `non_station_routes.php`; `StationRoutesTest` keeps every route classified. The check
runs after `actor` and before the permission and the idempotency key, so a refusal is never stored; inside, unknown comes first, then
another branch, then revoked. A route the back office also reads (the order list, one order, a shift, a count) stays a station route when a
floor screen needs it, so with the flag on an admin computer that opens those pages is enrolled like a tablet (ADR 0017, decision 4).
Device kinds, `GET /devices/this` (`{enrolled, revoked, device, enforcement}`, which a revoked device can still read), rename, and the
extended `POST /devices` (`client_uuid` required; a bridge's token of 32 random bytes, stored as its sha256, and the answer that carries
it kept encrypted in the idempotency store). The device routes moved from `people.manage` to `devices.manage` (the admin keeps them) and `devices.self`;
`last_seen_at` is written at most once a minute through a cache lock. M1 and M2a, `DeviceEnrolled` and `DeviceRevoked`, ADR 0017
proposed, the manifest corrections of 10.9, the `Phase5Seeder` skeleton. Live: the flag off.

**5.1b `phase-5/web-devices` (PR #90).** Web only, no API change. Every request sends `X-Device-Id` (`dev-<uuid>`, kept under the pad's
old storage key, so tablets keep the ids they had). `DeviceGate` replaces a page refused for its device with the "not enrolled" screen
(the short id, the API's message) and offers "Enrol this device" to whoever holds `devices.manage`; a revoked id is renewed once. The admin
Devices screen at `/admin/people/devices`: this device, every device of the branch with kind, code, last seen and status, rename, revoke,
and "Add a print bridge" with the token shown once. Every Playwright fixture enrols a fixed device per role and run (`e2e-<run>-<role>`);
the `@live` suites use stable `e2e-live-<role>` ids, probed and enrolled only when unknown. `compose.ci.yml` turns enforcement on for
`app` and `worker`, so `e2e-p0` to `e2e-p5` and `perf-dashboards` run with it; the Pest runs inside that stack pass
`IDENTITY_REQUIRE_DEVICE=false` and turn it on where they test it. `e2e-p5` runs beside `e2e-p4` (profile `full`, two days of history,
30 minutes from `e2e.yml`). The `p5-device` flow: an unknown tablet sees the screen on the pad, the KDS and the till, the admin enrols it in
place, a revoke brings the screen back, another branch's device is refused. Live: the flag stays off.

**5.2a `phase-5/outbox-idb` (PR #93).** Web only. The outbox moved from `localStorage` to IndexedDB (database `bakery-ops`, store
`outbox`; the old queue is moved in once, never doubled) and takes every station write without a PIN. Items are ordered by group (an
order, a line, a batch, a count); `depends_on` with `needs` puts a value from the dependency's answer into the dependent. No answer, a
5xx, 408 and 429 retry with the same key after 1, 2, 4 … 60 s; any other 4xx makes the item and its dependents a conflict kept with the
API's body; a device refusal keeps everything. Screens that wait for the answer use `submit()`: the till completes a payment offline
("saved on this tablet"), production steps and count drafts queue. `OnlineOnlyButton` on void, refund, approve, co-sign, reject and
unlock; the admin frame is disabled offline. The `p5-offline` reload aborts every API call to prove the queue lives in IndexedDB (CI
serves `next start`, which has no service worker). `IdempotencyReplayAfterExpiryTest` proves a create replayed after its key expired is
answered by its `client_uuid`.

**5.2b `phase-5/photo-queue` (PR #95).** Every receipt photo is kept in IndexedDB first (store `photos`, database version 2), so a
photo channel can be completed offline: Complete queues one `upload_photos` write per batch, then the batch with a `needs` entry per
photo; the outbox writes each file id as soon as it is answered. A photo quota (`NEXT_PUBLIC_PHOTO_QUEUE_MB`, 50 by default), an
abandoned-photo sweep (12 h), `409 idempotency.in_flight` retried with its key, and `outbox.photo_missing` in both languages.
`FileUploadRaceTest` (Phase 4's missing test) found that the losing upload of a race answered 404; `Files::put` now inserts under a
savepoint and reads the winner with a locking read, and only the upload that stored the file answers 201 and is audited (the other 200).

**5.2c `phase-5/offline-conflicts` (PR #100).** Web only. The conflict tray ("Changes not saved") replaces 5.2a's strip: one entry per
refused write with the writes it held back, the reason read from the key (shortfall, stock, shift, closed, photo, dependency, session,
other), the API's messages in both languages, and the actions Edit and resend (the cart rebuilt from the 409 body or the order), Send
again (new ids and keys) or Discard. Reconnect (`online`, or visible again after 30 s hidden) resumes the outbox and reads the pad's and
the till's lists again from the start. The lookup read was not added: a create is answered by its replay, and a `send_existing` or
`cancel_order` refused as closed is settled by `GET /orders/{id}`. ADR 0018 proposed.

**5.3a `phase-5/print-jobs` (PR #96).** `print_jobs` (M3), the print permissions (M2b) and `paper_width_mm` and `print_logo` on
`receipt_settings` (M4). The bridge routes behind `AuthenticateBridge` (`Authorization: Bridge <token>`), idempotent by the job's state
rather than by `Idempotency-Key` (a bridge has no user); a claim not acknowledged in 60 s may be taken over, the job fails after its third
claim (`PrintJobFailed`), a job older than 15 minutes expires, `print:sweep` runs every minute. The receipt is `ReceiptLayout` drawn by
`ReceiptRaster` (1 bit, 576 or 384 dots, Amharic as pixels in Noto Sans Ethiopic) and encoded by `EscPos`; it names the cashier and
carries the non-fiscal words of Q9 in both languages. Golden PNGs for 80 and 58 mm, Amharic and English, compared through
`EscPosEmulator`; `print:doctor`; `ReceiptText` prints the English rows in the printer's font without FreeType. `PrintClaimRaceTest`
(two bridges, one claim). ADR 0019 proposed, here rather than in 5.3b.

**5.3b `phase-5/print-bridge` (PR #101), merged while 5.6b was open.** The bridge program in `tools/print-bridge/`: Node 22, no
dependency at all, a config whose token lives in its own file (read again after a 401). It polls every 3 s, every 15 s after a quiet
minute and at once after a job, backs off from 3 s to 60 s on errors and honours `Retry-After`. A claim and a report each carry a
`client_uuid` and are asked again with it; the bytes are written once, and a printed job whose report was lost is reported again
without a second print. A TCP printer on port 9100 is asked its status first (`paper_out`, `cover_open`); a Windows share or a device
file can only tell `offline`. The log rotates by size and is scrubbed of the token. `install-service.ps1` runs it as the scheduled task
`BakeryOpsPrintBridge`. The local mode `--serve 127.0.0.1:9180` forwards the till page's own token for one request and keeps none; it
replaces the pull path when no bridge runs, but still needs the API. A Cloudflare challenge is backed off like an outage. The JS
emulator is the PHP one rule for rule. CI `print-bridge` (20 minutes) runs 103 `node --test` tests and parses the install script;
`docs/runbook/print-bridge.md` is the runbook. The job is a need of `gate-p5`, and STATUS `print-bridge` is `done`.

**5.3c `phase-5/web-print` (PR #102).** The till's Print button in `ReceiptActions` (the pay sheet's receipt, the order record, the refund
dialog), online only. The job's state is read with `GET /print-jobs?order_id=` and its ETag while the job is queued or claimed (every
2 s, every 10 s after a minute). A failed or expired job, or `409 payments.print.no_bridge` or `not_paid`, makes the receipt link with its
QR code (`uqr` 0.1.2). `paper_width_mm` (58 or 80) and `print_logo` joined `GET` and `PUT /receipt-settings` and the receipt settings
screen. `print.spec.ts` (four flows) runs in `p5-device` on the CI stack with a fake bridge in the test process; it checks the ESC/POS
framing and width (`e2e/support/escpos.ts`); decoding them with 5.3b's emulator is left to a later step. It left STATUS
`print-bridge` `in_progress` for 5.3b.

**5.4a `phase-5/i18n-checks` (PR #91).** `LangParityTest` (the API's `lang/en` and `lang/am`: files, keys at every depth, shape, empty
or `__TODO__` values, placeholders; the 490 messages already agreed). A local lint rule, `local/no-literal-text`, for letters of any
script in JSX text, children and the attributes a person reads. `scripts/i18n_report.py` (a report, not a gate), the review sheet
`docs/i18n/am-review.csv` with `i18n_export.py` and `i18n_import.py`, and `docs/i18n/glossary.md`. The Playwright project `p5-amharic`
sweeps every screen of each role under `/app/am/` in the seeded demo bakery on an enrolled tablet; the gaps its first run found were fixed
in the step.

**5.4b `phase-5/i18n-review`: not built.** It applies the reviewer's corrections from the sheet, settles the glossary and leaves the
report clean. It waits for the reviewer (Q5); STATUS `i18n` stays `in_progress`.

**5.5a `phase-5/ops-errors` (PR #92).** The 15 domain refusal classes carry `DomainRefusal` (`DomainRefusalTest` pins the list) and are
logged at info (`refused: <key>`, no trace) instead of ERROR, Phase 4's gap. Sentry support on the API and the web (`@sentry/browser`
11.7.0), registered only when a DSN is set, so it is off today; the scrubbing is tested against a fake ingest, and the CI job
`web-errors` proves the web export holds no SDK without a DSN and sends a scrubbed event with one. The daily prune of failed jobs older
than 30 days (04:10 Addis Ababa time). The test process may use 2 GB. ADR 0021 proposed.

**5.5b `phase-5/ops-backups` (PR #94).** The contracts `Inventory\Contracts\LedgerOracle` and `HashChain::verifyAll`, then `App\Ops`:
`backup:run` (`mysqldump` with the password in an option file deleted afterwards, gzip, a manifest with sha256, row counts before and
after the dump, the triggers, the migration count and the commit; the files folder as hard-link snapshots; 30 dumps and a 2 GB cap) and
`backup:drill` (restores the newest dump into `BACKUP_DRILL_DATABASE`, runs the ledger oracle, the hash chains, the append-only triggers
and the row counts; `skipped` while the database is not set), `ops_runs` (M5), the schedule (backup 02:30 daily, drill Sundays 03:30,
Addis Ababa time) and the off-site hook `BACKUP_REMOTE`, built and off. The CI job `backup-drill` replaced the placeholder: the seed,
`backup:run`, `backup:drill --keep`, then one Pest process with `--group=gate:p0 --group=gate:p5:restored` (`RestoredLedgerGateTest` on
the restored copy). `docs/runbook/backup-restore.md`, ADR 0020 proposed. Nothing was run against the live host.

**5.5c `phase-5/ops-status`: open (PR #103), not merged when this is written.** What the plan says it adds: `GET /system/status` and the
admin System page (polled every 30 s, conditional) with the scheduler heartbeat, the queue depth and the oldest job's age, failed jobs,
the last backup and drill, the disk used, the devices enrolled and seen in 15 minutes, the rollout flags and whether error tracking is
on; the hourly `BackupStale` check (M6, M2c); `.github/workflows/uptime.yml` (one `GET /health` every 15 minutes, failing after two
misses, which emails the repository owner); and `e2e/p5/ops-live.spec.ts` (`@live`, the System page on the live host, the scheduler
ticked in the last 3 minutes, and once backups have run, the last good backup younger than 26 h and the last drill younger than 8
days). The PR also carries the listeners of `PrintJobFailed`, `BackupFailed` and `DrillFailed`. STATUS `ops` stays `in_progress`.

**5.5d `phase-5/perf-load` (PR #99).** `scripts/perf/load.k6.js` on the CI compose stack, never the live host: 60 devices (34 pads, 12
KDS, 8 tills, 3 orders boards and 3 shift screens) polling at the web's own cadences, read from `tests/Fixtures/perf/poll-profile.json`
(`test_poll_profile.py` fails when the web changes one), and a burst of 300 orders in 10 minutes from pad to till, a quarter paid by
Telebirr with a fixture photo. Thresholds: polls p95 < 300 ms, writes p95 < 800 ms, errors < 0.5 %, `orders_paid == 300` and no
dropped iteration. The after-checks (`perf:load`, `LoadRunGateTest`): exactly 300 orders, all closed (225 cash, 75 Telebirr with a
photo), both ledger oracles ok, `failed_jobs` empty. `compose.perf.yml` serves PHP with 8 workers and OPcache after `artisan optimize`,
as the release does. `PollBudgetTest` (`gate:p5`). `Phase5Seeder` enrols `load-01` to `load-N` with `LOAD_DEVICES`. Every `ci.yml` job
that had no timeout got 20 minutes.

**5.6a `phase-5/go-live-checklist` (PR #98).** `docs/go-live-checklist.json` (24 items) and its JSON Schema; `gate_check.py` reads it
(verifiers `ci_job` by needs or by workflow run, `script`, `file_exists` with or without `signoff`; rows PASS, FAIL, SKIP, WAIT, WARN);
`phase_status.py claim-done` refuses a phase whose checklist does not pass; `check_invariants.py --signoffs` in the `invariants` job;
`scripts/ops/check_branch_protection.py`; `docs/go-live/README.md` and `docs/go-live/signoff-template.md`; the admin handbook
(`docs/handbook/`: a README, ten task pages and the training plan); `docs/runbook/on-call.md`, by role. `gate-p5` got its token here
(`actions: read`, `GH_TOKEN`).

**5.6b `phase-5/gate` (PR #104), this step.** `Phase5Seeder` enrols the demo branch's devices through `EnrolDevice` as the demo admin:
`demo-pad-1` (a tablet), `demo-kds-1` (a kitchen display at the kitchen station), `demo-till-1` (a till at the till station) and
`demo-bridge-1` (a print bridge, its token made at random and never printed); when the receipt settings have no TIN it saves the sample
TIN `0000000100` (made up, ten digits) on 80 mm paper, and a TIN already typed is left. A second run writes nothing, and in production
it writes nothing at all. The phase's to-do marker in the class is gone. `Phase5GateTest` (`gate:p5`): with the flag on, every station
route refuses a device nobody enrolled with 403, the admin too, and lets an enrolled device past, while every back-office route the admin
may call answers the admin the same with the flag on and an unknown device as with the flag off; an order sent from an enrolled pad and
replayed with the same key and `client_uuid` is one row; the checklist parses, its ids are unique and every verifier type is one
`gate_check` implements. The `gate-p5` job needs `status`, `gate-p4`, `e2e-p5`, `i18n-check`, `backup-drill` and `perf-load` (25
minutes); it loads the API image, runs `pest --group=gate:p5` on the compose stack and fails on a skipped or warning test, then runs
`gate_check.py --mode done --phase 5 --ci`. ADRs 0017 to 0021 accepted, this report, and the skill's references updated with what the
phase taught.

## CI evidence

### Figures recorded in the As built notes

| What | Figure | Where |
|---|---|---|
| Station routes | 58 station routes, 126 others, every one classified (at step 5.1a) | `StationRoutesTest` |
| Upload race | 30 forked rounds, the second upload 0 to 580 ms after the first: one row and one object each; in Docker 18 rounds raced and 12 ran one after the other | `FileUploadRaceTest` (5.2b) |
| Print claim race | 30 rounds, one claim each, `Innodb_deadlocks` unchanged | `PrintClaimRaceTest` (5.3a) |
| Golden receipts | equal within a two-dot neighbourhood, 0.5 % of the dots and 2 rows of height; the image's GD with FreeType 2.12 differs by 0.4 % at most (a one-dot neighbourhood gave 1.6 %, which failed the first CI run) | `ReceiptRasterTest` (5.3a) |
| API messages | 490 keys agree in `en` and `am` | `LangParityTest` (5.4a) |
| Literal text | 220 web source files pass `local/no-literal-text` | `web-lint` (5.4a) |
| Amharic sweep | 104 gaps on the admin's screens in the first run (the same kinds for the manager, cashier and chef), all fixed; the report lists 5 keys that may be unused and 12 Latin words in technical messages, for 5.4b | `p5-amharic`, `i18n-check` (5.4a) |
| `api-arch` | about 3 400 tests in one PHP process; out of memory three times at 1 GB, now 2 GB | 5.5a |
| Checklist tests | 53 tests, a fixture for every verifier type | `scripts/tests/test_gate_checklist.py` in `invariants` (5.6a) |
| Poll budget | 740 requests a minute at 12 tablets, 3 590 at 60 devices (pad 46, KDS 76, till 116, orders board 34, shift screen 28 a minute) | `PollBudgetTest` (5.5d), runbook "Poll budget" |
| `perf-load` without OPcache | polls p95 206 ms on one runner and 351 ms on a slower one (which failed the 300 ms threshold); writes p95 265 and 393 ms; no failed request | PR #99 |
| `perf-load`, run 41388083454 | polls p50 65 ms, p95 171 ms; writes p50 92 ms, p95 230 ms (the send 297 ms); 44 233 requests (61.2 a second), none failed; 300 orders closed (225 cash, 75 Telebirr with a photo); the ledger reconciled over 125 item-locations; no failed job; 14.5 minutes, the 8-day seed played in 52 s | PR #99 |
| `perf-load`, run 41396586731 | polls p95 45 ms, writes p95 64 ms (a faster runner); 45 132 requests, none failed; the oracle ok over 57 items and 6 places; 14 minutes | PR #99 |
| `perf-load`, run 41405560226 | polls p95 148 ms, writes p95 201 ms; 44 361 requests (61.3 a second), none failed; 300 orders closed; the oracle ok over 57 items and 6 places; no failed job; 14 minutes | PR #99's last head (its description) |
| Timeouts | the 16 `ci.yml` jobs that had none got 20 minutes (the longest measured 7 minutes, `api-arch`, main run 41363303323); `backup-drill` 25; `perf-load` 30; `e2e-p5` 30 (`e2e.yml`'s); `web-errors` 20; `mod-inventory` 30; `gate-p5` 25 (5.6b); `print-bridge` 20 and `uptime` 5 come with PRs #101 and #103 | 5.1b, 5.5a, 5.5b, 5.5d, 5.6b |

### The last full runs before this step

- **Run 41405560226** (PR #99, head `0f4ac0a`, every job green, 07:19 to 08:50 UTC, about 90 minutes). `e2e-p4`'s 60-day seed: 61 days
  (2026-09-02 to 2026-11-01) played in 450 s, the 63 days rebuilt in 7 s, 1 569 orders and 1 771 payments (the plan's ceiling for the
  seed step: 540 s); `e2e-p4` 96 passed in 1.1 minutes. `e2e-p5`'s seed (`SEED_PHASE=5`, two days of history): 3 days played in 22 s,
  `DemoSeeder` 40.6 s; 56 passed in 5.1 minutes (before 5.3c's print flows reached `main`). Job times: `mod-inventory` 20 m 10 s
  (timeout 30), `backup-drill` 4 m 11 s (25), `perf-load` 14 m 22 s (30), `perf-dashboards` 12 m 42 s, `e2e-p4` 10 m 31 s, `e2e-p5`
  7 m 18 s (30), `gate-p4` 10 m 42 s, `gate-p5` 6 s (then `gate_check` only).
- **Run 41405359490** (PR #102, head `9858247`, every job green). `e2e-p5`: 67 passed in 5.4 minutes, the four print flows included (job
  7 m 57 s). Its informational `gate_check`: the ADR, OpenAPI, ERD, phase-marker and skip rows PASS, the gate report WARN (this file did
  not exist); checklist rows `gates-green`, `branch-protection` (main requires `gate-p0` to `gate-p5`), `offline-replay`, `i18n-strict`,
  `backup-drill-ci`, `live-ops`, `handbook`, `on-call-plan`, `restore-runbook` and `adrs` PASS; `device-enforcement` (no `gate:p5`
  step yet), `print-emulator`, `load-test` (`perf-load` not yet a need), `uptime-monitor` and `print-runbook` FAIL shown as WARN; the
  eight required sign-offs WAIT; `push-telegram` WARN (optional). Step 5.6b gives `device-enforcement` its `self` step and `load-test`
  its need.

### The gate run of step 5.6b

Recorded from PR #104's gate-p5 run when it is green.

## What is open

The phase is **not claimed**. `phase_status.py claim-done 5` refuses until every required checklist item passes, and no tag
`phase-5-complete` is made. What stands between this report and the claim:

- **Step 5.4b**: waits for the Amharic reviewer (Q5). When the filled sheet comes back, `i18n_import.py` applies it, the glossary is
  settled and the report is clean; Ramin then signs `am-reviewed`. The 5 possibly unused keys and the 12 Latin words of the report are
  for that step.
- **The print flow test** (`e2e/p5/print.spec.ts`) still checks the claimed bytes' framing and width only; decoding them with the
  bridge's emulator is left to a later step (5.3b's note).
- **PR #103 (step 5.5c), open at the time of writing.** Until it is merged, `uptime-monitor` fails (no `uptime.yml`, so no run within a
  day); `PrintJobFailed`, `BackupFailed` and `DrillFailed` have no listener; STATUS `ops` stays `in_progress`. `live-ops` passes today on
  the deploy's `live-e2e` job alone; from 5.5c on, that job also runs `ops-live.spec.ts`, which reads the System page and, once the first
  live drill has run, requires a good backup within 26 h and a passing drill within 8 days.
- **The live restore drill** records `skipped` until Ramin creates the scratch database and `BACKUP_DRILL_DATABASE` is set in the server
  `.env` (Q8). The off-site hook stays off until Q2 is answered.
- **D16's fallback (signing from the PC) is not built.** Nothing in the repository can tell such a commit apart from an agent's, so it
  waits for Ramin's choice; until then a sign-off counts only when every commit that touched it was made in GitHub's web editor.
- **`perf-load` runner variance** (5.5d, left open): the 4-core runner is close to busy at 61 requests a second; one slow runner moved the
  poll p95 by 70 % and a fast one cut it to a quarter. If the job turns flaky, the choices are a larger runner (paid, Ramin's call) or
  fewer devices per job; the plan's thresholds are kept as written.
- **Device enforcement stays off on the live host** until Ramin signs `devices-enrolled`. Then a one-line PR turns the code default on
  (10.5, the pattern of 3.7e); the kill switch is `IDENTITY_REQUIRE_DEVICE=false` in the server `.env`, then `php artisan config:cache`.
- **Left open in the As built notes**:
  - the conflict tray shows every conflict of the tablet, whoever is signed in; a rebuilt cart waits while the person's cart holds
    another order; discarding a refused send whose order the server holds open (a shortfall) leaves that order open, shown as not sent
    on the waiter's "My orders" with its Cancel; line actions are not looked up after a lost answer, they show with Discard only (5.2c);
  - the kitchen display reconnect flow and the two-tablet flow were not run red before their code (5.2c);
  - why an order with no number reaches the kitchen rail of the seeded demo bakery is left to the orders owner (5.4a);
  - the receipt settings have a logo switch, but no logo can be added yet (5.3c);
  - `api-arch` runs about 3 400 tests in one process at 2 GB; its groups are split over two jobs if it nears that limit (5.5a);
  - the handbook pages `printer.md` and `system-page.md` describe steps 5.3b and 5.5c, which are not merged; they are checked against the
    build when those steps land.
- **Phase 4 gaps carried**, unchanged by Phase 5:
  - display-case products made in the house have no cost of sales, and modifier recipe deltas are not costed (D6: after go-live unless
    Ramin picks a fix: Orders snapshots the recipe version on display-case lines, or Production values its batch output);
  - summaries of a payment changed on an earlier day (verified, disputed or voided later) follow at the nightly run or by hand (ADR 0014);
  - `php artisan analytics:bench` on the live host stays Ramin's optional run (listed in the handbook).

## The go-live checklist

`docs/go-live-checklist.json`, 24 items. `gate_check.py --mode done --phase 5` prints one row per item, `checklist <id>`. While the phase
is open, `gate-p5` shows a FAIL as a warning and a missing sign-off as `WAIT (Ramin)`; after the claim every required item must pass.

| # | Item | What it checks | Verifier | Who |
|---|---|---|---|---|
| 1 | `gates-green` | gate-p0 to gate-p4 still green | `ci_job` jobs `gate-p4` (which needs the earlier gates) | CI |
| 2 | `branch-protection` | `main` requires gate-p0 to gate-p5 | `script` `check_branch_protection.py --require gate-p0..gate-p5` | CI |
| 3 | `device-enforcement` | an unknown device gets 403 on station routes; admin routes are unaffected | `ci_job` jobs `e2e-p5`, `self` (the gate job's own `gate:p5` step) | CI |
| 4 | `offline-replay` | an order sent offline replays once: the server has exactly one order | `ci_job` jobs `e2e-p5` | CI |
| 5 | `print-emulator` | receipt bytes decode to the golden images; the bridge's own tests pass | `ci_job` jobs `print-bridge`, `self` | CI |
| 6 | `i18n-strict` | parity strict, API parity, every `am` screen swept | `ci_job` jobs `i18n-check`, `e2e-p5`, and `script` `check_invariants.py --i18n --strict-i18n` | CI |
| 7 | `backup-drill-ci` | a dump restores into a scratch database and passes the ledger tests | `ci_job` jobs `backup-drill` | CI |
| 8 | `load-test` | k6 load test within its thresholds | `ci_job` jobs `perf-load` | CI |
| 9 | `live-ops` | live: a good backup in the last 26 h, a passing drill in the last 8 days | `ci_job` workflow `deploy.yml`, job `live-e2e`, branch `main`, within 7 days | CI |
| 10 | `uptime-monitor` | the uptime workflow exists and has passed in the last day | `file_exists` `.github/workflows/uptime.yml`, and `ci_job` workflow `uptime.yml` on `main` within 1 day | CI |
| 11 | `handbook` | the admin handbook and the training plan | `file_exists`: the README and the eleven pages of `docs/handbook/` | CI |
| 12 | `on-call-plan` | the on-call plan, by role | `file_exists` `docs/runbook/on-call.md` | CI |
| 13 | `restore-runbook` | the backup and restore runbook | `file_exists` `docs/runbook/backup-restore.md` | CI |
| 14 | `print-runbook` | the print bridge runbook | `file_exists` `docs/runbook/print-bridge.md` | CI |
| 15 | `adrs` | ADRs 0017 to 0021 | `file_exists`, the five ADR files | CI |
| 16 | `devices-enrolled` | every shop tablet is enrolled, and device enforcement may go on | `file_exists` + `signoff` | Ramin |
| 17 | `print-bridge-shop` | the bridge runs on the shop computer and the shop printer prints a bilingual receipt with the right TIN | `file_exists` + `signoff` | Ramin |
| 18 | `am-reviewed` | a fluent reviewer has read the Amharic dictionary | `file_exists` + `signoff` | Ramin |
| 19 | `training-done` | training sessions held for every role | `file_exists` + `signoff` | Ramin |
| 20 | `on-call-agreed` | the on-call people agree to the on-call plan | `file_exists` + `signoff` | Ramin |
| 21 | `offsite-backup` | an off-site backup is configured and one copy verified, or the host-only risk is accepted | `file_exists` + `signoff` | Ramin |
| 22 | `error-tracking` | error tracking is on and a test event was seen, or going live without it is accepted | `file_exists` + `signoff` | Ramin |
| 23 | `receipt-wording` | the customer receipt wording (not a fiscal receipt) is accepted for the shop | `file_exists` + `signoff` | Ramin |
| 24 | `push-telegram` | web push and Telegram keys (optional, `required: false`) | `file_exists` + `signoff` | Ramin, optional |

Every sign-off verifier reads `docs/go-live/signoffs/<item>.md`.

### Ramin's items

- [ ] **`devices-enrolled`** (Q6, D9): sign in as an admin once on every shop tablet, till computer and kitchen screen and enrol it
      ("Enrol this device", or Admin > People > Devices), check on the Devices screen that each one is enrolled and seen, then sign. A
      one-line PR then turns device enforcement on (10.5).
- [ ] **`print-bridge-shop`** (Q3, Q10): install the bridge on the shop computer (`docs/runbook/print-bridge.md`),
      add it as a print bridge on the Devices screen and paste its token, set the paper width and the shop's own TIN in the receipt
      settings, print a receipt on the shop printer and check both languages and the TIN, then sign. If the bridge's requests are ever
      challenged by the proxy's bot protection, a rule that lets `/api/v1/bridge/*` through is part of this item.
- [ ] **`am-reviewed`** (Q5): have a fluent reviewer fill the correction column of `docs/i18n/am-review.csv` (and read the glossary), send
      the sheet back for step 5.4b to import, then sign.
- [ ] **`training-done`** (Q7): hold the sessions of `docs/handbook/training.md` for every role, then sign. Who attended stays outside the
      repository.
- [ ] **`on-call-agreed`** (Q7): put names and numbers to the roles of `docs/runbook/on-call.md` outside the repository, change the hours
      or the call order if needed, have the people agree, then sign.
- [ ] **`offsite-backup`** (Q2): set up off-site storage (a bucket or a drive of his own), configure `BACKUP_REMOTE` and verify that one
      copy restores, **or** accept in writing that the only copy is on the host's own disk, then sign.
- [ ] **`error-tracking`** (Q1): create an error-tracking project, set `SENTRY_LARAVEL_DSN` in the server `.env` and the repository
      variable `NEXT_PUBLIC_SENTRY_DSN`, and see a test event arrive, **or** accept going live without it, then sign.
- [ ] **`receipt-wording`** (Q9): decide whether the shop needs a fiscal receipt from its own sales register, and accept the wording "a
      customer receipt, not a fiscal receipt" printed in both languages, then sign.
- [ ] **`push-telegram`** (Q4, optional): set the web push (VAPID) and Telegram bot keys and sign; never a go-live blocker.

Outside the checklist:

- [ ] Create the scratch database for the live restore drill and set `BACKUP_DRILL_DATABASE` in the server `.env` (Q8); `live-ops`
      depends on it once PR #103 is merged.
- [ ] Choose whether a sign-off may also be committed from the PC (D16's fallback, not built).
- [ ] Decide the display-case COGS gap (D6) when convenient.
- [ ] If `perf-load` turns flaky across runner generations: a larger runner (paid) or fewer devices per job.

**How he signs** (`docs/go-live/README.md`): on GitHub, open `docs/go-live/signoff-template.md` and copy its text; in the `docs/go-live`
folder choose Add file > Create new file, name it `signoffs/<item>.md`, paste and fill the four fields (`item`, the same as the file
name; `date`, `YYYY-MM-DD`, not in the future; `signed_by`, his name or his role, since the repository is public; `statement`, in his own
words what he checked or the risk he accepts), and commit in the browser, through a pull request or straight to `main`. Every commit
that touched a sign-off file must have GitHub's web-editor committer, or the item fails; `check_invariants.py --signoffs` fails a pull
request that carries a local commit in `docs/go-live/signoffs/`, and agents never write in that folder.

## Deviations from the plan and assumptions taken

The As built notes under section 6 of `docs/phases/phase-5-plan.md` list them step by step. The ones that change what the plan's text
says:

- **Admin computers and station routes** (5.1a, 5.1b): a route the back office also reads stays a station route when a floor screen needs
  it, so with the flag on an admin computer that opens those pages must be enrolled like a tablet (ADR 0017's 2026-10-29 note; plan 4.1
  corrected). "Admin routes unaffected" therefore means the back-office routes, which is what `Phase5GateTest` checks: the admin's answer
  with the flag on and an unknown device equals the answer with the flag off.
- **The lookup read was not added** (5.2c): replays by key or `client_uuid` and `GET /orders/{id}` cover what it was for.
- **Print is online only** (5.3c), against D10's "every station write without a PIN joins the outbox": a print sent when the line came
  back could reach the printer long after, and the API expires a job after 15 minutes. The bridge's local mode needs the API too
  (5.3b), so nothing prints while the till is offline.
- **The bridge's writes are idempotent by the job's state**, not by `Idempotency-Key` (5.3a), and ADR 0019 was written in 5.3a, not 5.3b.
- **The `p5-device` match** is by file name (`/[\\/](device|print|ops)[^\\/]*\.spec\.ts$/`), not the plan's `/device|print|ops/`, which
  matched the checkout's folder name (5.3c).
- **The lint rule** is a local one, not `react/jsx-no-literals` (5.4a).
- **The refusal classes are 15**, found by a scan, and `web-errors` is a CI job of its own, not in 7.1's table (5.5a).
- **`RestoredLedgerGateTest` is in `gate:p5:restored`**, not `gate:p5` as the 5.5b item says; it runs only in `backup-drill` (5.5b).
- **The load profile** (5.5d): the plan's "6 admin and shift screens" are 3 orders boards and 3 shift screens; `orders_paid == 300` and no
  dropped iteration were added to the thresholds; the stack serves PHP with 8 workers and OPcache instead of the image's single-process
  server.
- **`gate-p5` got its token in 5.6a**, not 5.6b; the sign-off README and template live outside `docs/go-live/signoffs/` (5.6a).
- **`gate-p5`'s needs** came in two steps: `perf-load` in 5.6b and `print-bridge` with PR #101; the merge of main into 5.6b keeps both,
  in `ci.yml`, the skill's copy and the manifest (`ci.gate_jobs`, `extra_ci_jobs`).
- **The sample TIN** is saved only when the receipt settings have no TIN (section 8 says the seeder sets it), so a TIN typed for the shop
  is never overwritten (5.6b).
