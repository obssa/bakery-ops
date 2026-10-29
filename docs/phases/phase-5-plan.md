# Phase 5 plan — Hardening and Amharic

Status: **approved under Ramin's standing approval (2026-10-19)**, committed by `phase-5/plan` together with `plan_approved: true`,
`plan_approved_by: "standing approval: Ramin, 2026-10-19"` and phase 5 `in_progress` in `docs/phases/STATUS.json` (SKILL.md step 3, as
Phase 4's plan commit `3bca94b` did). Nothing in this plan changes the meaning of the ledger, so no question blocks the build; the items
that need Ramin's **account, money, hardware or a person** (10.2, part A) wait for him and are never created by an agent, and every step
goes ahead without them.

Written 2026-10-29 from `origin/main` at `2cd8ead` (Phase 4 closed at `b7b58e9`, tag `phase-4-complete`, `gate-p4` run 40821698063, 39 of 39
`@live`): `CLAUDE.md`, the skill (`SKILL.md` step 3, `references/phases/phase-5-hardening-amharic.md`, `assets/dependency-manifest.json`
`phases["5"]` and `ci`, the references `ci-cd`, `testing-strategy`, `traps`, `invariants`, the template), `docs/phases/phase-4-plan.md`
(layout and As built notes only), `docs/phases/phase-4-report.md` (known gaps, figures), `docs/runbook/server.md`, `scripts/gate_check.py`,
`scripts/phase_status.py`, `.github/workflows/{ci,e2e}.yml`, `apps/web/playwright.config.ts` and the code Phase 5 builds on: Identity's
`devices` table, `DevicesController` and `ResolveActor` (the `X-Device-Id` header), the web outbox (`src/lib/outbox/store.ts`), `src/lib/acting.ts`,
`scripts/build-sw.mjs`, the receipt routes and `receipt_settings` (4.3e), `apps/web/messages/{en,am}.json`, `apps/api/lang/{en,am}`, the health route.

Decisions written down as ADRs in this phase: **0017** device enrolment and enforcement, **0018** the offline outbox and its conflicts,
**0019** printing through a pull bridge, **0020** backups and restore drills on a shared host, **0021** observability on a shared host
(section 9). The manifest's `phases.5.adrs` is empty; step 5.1a lists the five there so `gate_check --mode done` requires them.

What the phase delivers, in one paragraph: nothing new in the domain; what a real shop needs to survive a day. Every station route answers
only an **enrolled tablet** once the flag is on (admin routes unaffected), and the admin enrols a tablet in place and revokes it from a
Devices screen. Every write a station screen makes without a PIN goes through an **outbox in IndexedDB** that survives a reload, replays
exactly once (the Idempotency-Key and `client_uuid` prove one row), queues receipt photos, and shows a refusal that arrives on sync (a stock
shortfall, a closed shift) in a conflict tray instead of losing it. A small **print bridge** on a shop computer pulls receipt jobs over HTTPS
and prints a bilingual receipt with the TIN on an ESC/POS printer, with the existing receipt link as the fallback. The Amharic dictionary
is complete and **reviewed by a person**, the API's language files reach the same parity, and every screen is swept in `am`. **Backups**
are `mysqldump` plus the files folder to the host's disk every night with a restore drill that proves the ledger, **k6** measures 60
devices and a 300-order burst in CI, errors can go to Sentry once Ramin gives a DSN (off until then), an admin **System** page shows the
queue, the scheduler, the last backup and the devices, and a **go-live checklist** with a verifier per item decides the gate, including
the items only Ramin can sign.

## 1. Entry check

`python scripts/gate_check.py --mode entry --phase 5 --no-tests` on this PC (no PHP here; Docker is for debugging only), before the
plan commit:

```
== gate_check: entry phase 5 (Hardening and Amharic)
  [PASS] phase 4 done               status=done done_commit=b7b58e9710f14d9db5c4ca365231f666ae263d34
  [PASS] phase 4 commit in history  b7b58e9710f1 is an ancestor of HEAD
  [SKIP] gate:p4 green              pest --group=gate:p4 not run (--no-tests/--ci)
  [PASS] clean working tree         clean
  [FAIL] phase plan exists          docs/phases/phase-5-plan.md
  [FAIL] plan approved by Ramin     plan_approved=False
  [SKIP] Phase5Seeder               not yet (created during the phase)
== result: FAIL (2 failed, 0 warnings, 7 checks)
```

The two red lines are the ones this PR answers (the plan, and `plan_approved` under the standing approval). The same command after
the plan, approval and placeholder commits (`54d65b1`):

```
== gate_check: entry phase 5 (Hardening and Amharic)
  [PASS] phase 4 done               status=done done_commit=b7b58e9710f14d9db5c4ca365231f666ae263d34
  [PASS] phase 4 commit in history  b7b58e9710f1 is an ancestor of HEAD
  [SKIP] gate:p4 green              pest --group=gate:p4 not run (--no-tests/--ci)
  [PASS] clean working tree         clean
  [PASS] phase plan exists          docs/phases/phase-5-plan.md
  [PASS] plan approved by Ramin     plan_approved=True by standing approval: Ramin, 2026-10-19
  [PASS] approval author            obssa77@gmail.com
  [SKIP] Phase5Seeder               not yet (created during the phase)
== result: PASS (0 failed, 0 warnings, 8 checks)
```

**`gate:p4 green` is proved by CI, not here.** Most of `gate:p4` reads the 90-day seed that only the `gate-p4` job builds
(`Tests\Support\SeededGate`, `GATE_SEED_DATABASE`), so a local run of the group on an unseeded stack proves nothing. The proof is `gate-p4`
green on `main` at the done commit (run 40821698063) and again on this PR's own run (`gate-p4` is a required check and runs on every PR).

| Item | State |
|---|---|
| Previous phase | Phase 4 `done`: `done_commit` `b7b58e9`, tag `phase-4-complete`, `gate-p4` run 40821698063, report `docs/phases/phase-4-report.md`, ADRs 0014 to 0016 accepted. |
| Contracts required | none (`phases.5.contracts_required` is empty: no new module). Two consumer-side contract files are added inside the phase (section 2). |
| Upstream modules | all eleven `stable` after Phase 4: shared, audit, identity, catalog, inventory, orders, payments, production, notifications, shifts, analytics. |
| `Phase5Seeder` | optional at entry (the check prints SKIP); the real one is step 5.6b, a skeleton comes with 5.1a. |
| Required checks on `main` | `gate-p0` to `gate-p5` (gate-p5 added at Phase 4's close). |
| Live-host facts that bind this plan | section 10.1 |

Upstream capabilities this phase consumes, and how each is proven before use:

| Consumer (new code) | Provider | Capability | Proof |
|---|---|---|---|
| `EnsureEnrolledDevice` (Identity) | Identity | `ResolveActor::deviceFor` (enrolled, unrevoked, same branch) | Identity's own module tests, extended in 5.1a |
| Every station route (all modules) | Identity | the `device` middleware alias (a string in route files, no import) | `StationRoutesTest` and the 403 matrix (5.1a) |
| Print jobs (Payments) | Payments | the receipt of 4.3e (`ReceiptBuilder` and its `Receipt` view in `Payments/Support/Receipts`), `receipt_settings` | Payments' module tests (same module, no contract) |
| `App\Ops` backup drill | Inventory | **new** `Inventory\Contracts\LedgerOracle::check(connection)` | `contract:ops->inventory` (new, 5.5b) |
| `App\Ops` backup drill | Shifts | `Shifts\Contracts\HashChain` verification on a named connection | `contract:ops->shifts` (new, 5.5b) |
| System status (Ops) | Notifications | **event only**: `BackupFailed`, `BackupStale`, `DrillFailed`, `PrintJobFailed` | listener tests in `mod-notifications` |
| Web outbox | API | `Idempotency-Key` replay and `client_uuid` uniqueness on every queued write | `IdempotencyCoverageTest` (exists) and the replay-after-expiry case (5.2a) |

## 2. Contract tests to write first (green before any consumer code)

No `contracts_required` (no new module). Two small contracts are written **red** in the first commit of step 5.5b, before `App\Ops`
exists, and turned green by the provider work in the same PR (as 4.1b/4.1c did inside Phase 4):

| Contract | File | Cases |
|---|---|---|
| `ops->inventory` (new) | `apps/api/tests/Contract/Ops/InventoryContractTest.php` | `LedgerOracle::check($connection)` returns `{ok, items, locations, mismatches[]}`; on a seeded ledger it is `ok`; a row deleted from `stock_movements` behind the triggers' back (a raw statement on a scratch copy) makes it report the `(item, location)` and the two sums; `stock_balances` that disagree with the movements are reported separately; it reads only the named connection (the default connection's rows are never touched); it writes nothing (the query log holds only SELECTs); decimal strings at scale 4 |
| `ops->shifts` (new) | `apps/api/tests/Contract/Ops/ShiftsContractTest.php` | `HashChain::verifyAll($connection)` (a new method beside the existing per-location `verify`) answers `ok` on a seeded chain, names the first broken link when a locked count's stored hash is changed on the scratch copy, and reads only the named connection |

Group names `contract:ops->inventory`, `contract:ops->shifts`; both run in `mod-inventory` and `mod-shifts` respectively from 5.5b on, so a
provider change fails there first. The manifest gets `ops` under `contracts` (and `phases.5.intra_phase_contracts`), not under
`contracts_required`, for the reason 4.1a gave (a provider of an earlier phase would make the entry check require it before it exists).

## 3. Migrations (in order)

Same conventions as Phase 4: every tenant table has `branch_id` (FK, index), money `DECIMAL(12,2)`, quantities `DECIMAL(14,4)`, a CHECK
for each enum list (MariaDB and PostgreSQL), thin portable DDL, `in_array($driver, ['mysql','mariadb'], true)` for driver checks.
Prefixes start at `2026_11_11_` so they sort after Phase 4's last (`2026_11_10_100100`); the **names and the order** are fixed. A deploy runs
migrations on the live database the moment a step merges and never a seeder, so each one must be safe on the live rows listed.

| # | Migration | Module | Contents | Live tables touched |
|---|---|---|---|---|
| M1 | `extend_devices_for_enrolment` | Identity | `devices`: `kind` VARCHAR(16) NOT NULL DEFAULT `tablet` CHECK in (`tablet`, `till`, `kds`, `bridge`); `station_id` BIGINT UNSIGNED NULL FK `stations` (a tablet's home station, informative only); `last_seen_at` TIMESTAMP NULL; `client_uuid` UUID NULL, UNIQUE `(branch_id, client_uuid)` (an enrolment made through the outbox-free admin screen still carries one); `token_hash` CHAR(64) NULL UNIQUE (sha256 of a bridge's token, `bridge` only, CHECK `kind = 'bridge' OR token_hash IS NULL`); `revoked_by` FK `users` NULL. Existing rows become `tablet`. | `devices` (a handful of rows) |
| M2a | `grant_phase_5_device_permissions` | Identity | thin caller of `GrantRegistryPermissions` exactly as `grant_phase_4_permissions` (never revokes, writes nothing the second time, clears spatie's cache): `devices.manage` (admin) split out of `people.manage` for the three device routes, `devices.self` (every role: the "is this tablet enrolled" read) | `permissions`, `role_has_permissions` |
| M3 | `create_print_jobs_table` | Payments | `print_jobs`: `id`, `branch_id`, `client_uuid` UUID UNIQUE with branch, `order_id` FK, `kind` VARCHAR(16) CHECK in (`receipt`), `copies` TINYINT UNSIGNED DEFAULT 1, `status` VARCHAR(16) CHECK in (`queued`, `claimed`, `printed`, `failed`, `expired`), `attempts` TINYINT UNSIGNED, `claimed_by_device_id` FK `devices` NULL, `claimed_at`, `printed_at`, `failed_at` TIMESTAMP NULL, `failure_key` VARCHAR(64) NULL, actor stamp of the requester (`created_by_user_id`, `acting_user_id`, `shift_instance_id`, `station_id`, `device_id`), timestamps, soft deletes; index `(branch_id, status, id)` (the bridge's claim). Not append-only: a job is a work item; its history is in `audit_log`. | none (new) |
| M2b | `grant_phase_5_print_permissions` | Identity | `print.create` (admin, manager, cashier), `print.view` (same); the bridge's own routes are authorised by its device token, not by a permission (section 4.3) | `permissions`, `role_has_permissions` |
| M4 | `add_paper_width_to_receipt_settings` | Payments | `receipt_settings.paper_width_mm` TINYINT UNSIGNED NOT NULL DEFAULT 80 CHECK in (58, 80); `print_logo` BOOLEAN DEFAULT false (reserved; no logo upload in Phase 5) | `receipt_settings` (one row per branch) |
| M5 | `create_ops_runs_table` | Ops | `ops_runs`: `id`, `kind` VARCHAR(16) CHECK in (`backup`, `drill`, `offsite`), `status` VARCHAR(12) CHECK in (`running`, `ok`, `failed`, `skipped`), `started_at`, `finished_at`, `bytes` BIGINT UNSIGNED NULL, `sha256` CHAR(64) NULL, `path_hint` VARCHAR(191) NULL (a name relative to the backup folder, never an absolute path), `detail` JSON NULL (row counts, oracle summary, the failure key), timestamps. **No `branch_id`**: one host, one database; listed in the `branch_id` allowlist with that reason (not a `TODO`), and in `AppendOnlyTest`'s "system tables" set. | none (new) |
| M6 | `extend_notification_types_for_ops` | Notifications | the `notifications.type` CHECK gains `print_failed`, `backup_failed`, `backup_stale`, `drill_failed` (as `extend_notification_types_for_refunds` did) | `notifications` (CHECK only) |
| M2c | `grant_phase_5_ops_permissions` | Identity | `system.view` (admin: the System page and `GET /system/status`) | `permissions`, `role_has_permissions` |

Build order: 5.1a M1 M2a; 5.3a M3 M2b M4; 5.5b M5; 5.5c M6 M2c. Nothing in Phase 5 touches `stock_movements`, `orders`, `payments`,
`audit_log` or any append-only table; the ERD gains `print_jobs` and `ops_runs`, and the manifest lists them under `modules.payments.tables`
(Phase 5 additions under `tables_phase_5` until their step migrates them, as Phase 4 did) and under a new `ops` entry that is **not a
module** (no `phase` step of its own, no module job; its tables and contracts are listed so `gate_check` and the ERD check see them).

## 4. Endpoints

Conventions as in Phase 4: `/api/v1`, bearer token, the `actor` middleware, every write carries `idempotency` and a `client_uuid`,
state transitions are actions, errors are `{message_key, message_en, message_am, ...extra}` (`lang/en|am/identity.php`, `payments.php`,
`ops.php`). Every route is in `tests/Support/route_permissions.php`, every write in `write_endpoints.php`; Phase 5 adds
`tests/Support/station_routes.php` (4.1).

### 4.1 Devices (Identity, steps 5.1a and 5.1b)

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `GET /devices` (exists) | `devices.manage` (was `people.manage`) | read | `?kind=&revoked=` | list: `id, name, kind, station, enrolled_at, enrolled_by, last_seen_at, revoked_at` | 403 |
| `POST /devices` (exists, extended) | `devices.manage` | yes | `{name, fingerprint, kind, station_id?, client_uuid}`; `kind=bridge` makes and returns a token **once** | the device (+ `token` for a bridge, shown once, stored as sha256) | 422 `identity.device.fingerprint_taken`, `identity.device.kind_invalid` |
| `POST /devices/{id}/rename` (new) | `devices.manage` | yes | `{name, station_id?, client_uuid}` | the device | 404, 409 `identity.device.revoked` |
| `POST /devices/{id}/revoke` (exists) | `devices.manage` | yes | `{client_uuid}` | the device | 404; a revoked id is never enrolled again (the tablet makes a new id, 10.2 D8) |
| `GET /devices/this` (new) | `devices.self` (every signed-in role) | read | header `X-Device-Id` | `{enrolled: bool, revoked: bool, device: {id, name, kind}?, enforcement: bool}` | never 403 for an unknown device: it is how the "not enrolled" screen knows |

**`EnsureEnrolledDevice`** (alias `device`) runs after `actor` on every route listed in `tests/Support/station_routes.php` and is a no-op
while `identity.require_enrolled_device` (env `IDENTITY_REQUIRE_DEVICE`, unset means false) is off. On: a missing or unknown `X-Device-Id` is
`403 identity.device.unknown`, a revoked one `403 identity.device.revoked`, another branch's `403 identity.device.other_branch`; the check
writes `last_seen_at` at most once a minute per device (a database cache lock, the live host has no Redis). **Station routes** are the ones a
shared tablet calls: the pad (menu, orders, send, replace lines, serve, cancel, tables), the KDS (tickets, accept, ready, reject), the till
(order list, payments, receipts, receipt links, print), production (batch actions at a station), the shift bar (start, counts drafting,
cash declaration, handover) and the acting-session routes. **Not station routes**: login and logout, `GET /me`, `GET /devices/this`, the
purely admin surface (catalog, people, devices, analytics, audit, settings), the public signed routes. (As built in 5.1a: a route the admin
console shares with a floor screen, such as the order list, a shift, a count, the channels or the receipt settings, is a station route, so an
admin computer is enrolled like a tablet; ADR 0017 decision 4 and its 2026-10-29 note.) `StationRoutesTest` fails
when a route is in neither `station_routes.php` nor the explicit `non_station_routes` list beside it, so a new route must be classified.

### 4.2 Offline (web, steps 5.2a to 5.2c)

No new write endpoint. The outbox replays the existing writes with their original `Idempotency-Key` and `client_uuid`. One read is added
if the reconcile step needs it (5.2c decides from the code): `GET /orders/by-client-uuid/{uuid}` (`orders.create`, read, same branch, 404
when the server never saw it), so a tablet that lost the answer of a send learns whether it landed even after the idempotency key's
retention has passed. Reconnect also replays `GET /tickets?since=` and refetches the order list (both exist, conditional with ETag).

### 4.3 Printing (Payments, steps 5.3a to 5.3c)

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `POST /orders/{order}/print` | `print.create` (admin, manager, cashier) + `device` | yes | `{copies? (1..3), client_uuid}` | the job `{id, status, created_at}` (202) | 409 `payments.print.not_paid` (only a paid or closed order has a receipt), 409 `payments.print.no_bridge` when no bridge of the branch was seen in `print.bridge_seen_seconds` (120): the till shows the link fallback at once |
| `GET /print-jobs?order_id=` | `print.view` + `device` | read, conditional (ETag) | | the order's jobs, newest first | 403 |
| `GET /bridge/jobs/next` | bridge token (`Authorization: Bridge <token>`, middleware `bridge`) | read only | | 204 when nothing waits (one indexed SELECT, no session, no write: the poll costs the host almost nothing); 200 `{job_id}` of the oldest `queued` job of the bridge's branch | 401 unknown or revoked bridge |
| `POST /bridge/jobs/{id}/claim` | bridge token | yes | `{width (576\|384), client_uuid}` | 200 `{job_id, order_number, copies, escpos_base64, expires_at}`; the job becomes `claimed` by this bridge (a row lock; a second bridge gets 409) | 409 `payments.print.already_claimed`, 410 `payments.print.expired` |
| `POST /bridge/jobs/{id}/printed` | bridge token | yes | `{client_uuid}` | 204 | 409 `payments.print.not_claimed_by_you` |
| `POST /bridge/jobs/{id}/failed` | bridge token | yes | `{failure_key}` (`paper_out`, `offline`, `cover_open`, `unknown`) | 204; after 3 attempts the job is `failed` and `PrintJobFailed` is raised | as above |
| `GET /orders/{order}/receipt.escpos` | `print.view` | read | `?width=576\|384` | `application/octet-stream`: the ESC/POS bytes of the receipt (for a till PC that prints locally, 10.2 D11) | 404, 409 not paid |

A claimed job that is not acknowledged within 60 s goes back to `queued` (at most 3 claims), and a job older than 15 minutes is `expired`
(a receipt printed half an hour late is worse than none); both by the scheduler. The bridge routes are in `public_routes.php` with the reason
(they authenticate a device, not a user) and are exempt from `actor`; their writes are audited with the system actor plus `device_id`
(ADR 0011's queued-actor pattern). The bridge polls every 3 s and backs off to 15 s after a minute of 204s (section 10.1: a CPU-limited host).

### 4.4 Ops (steps 5.5a to 5.5c)

| Method + path | Permission | Idempotent | Response | Notes |
|---|---|---|---|---|
| `GET /health` (exists, public) | public | read | `{status, database}` unchanged | the uptime workflow and the deploy smoke use it; it stays minimal (public) |
| `GET /system/status` (new) | `system.view` (admin) | read, conditional | `{app: {commit, released_at, migrations}, scheduler: {last_tick_at, ok}, queue: {pending, oldest_age_s, failed_24h, failed_total}, backups: {last_ok_at, last_status, bytes, age_h, kept, disk_used_mb, drill: {last_at, status}}, files: {used_mb}, devices: {enrolled, seen_15m, revoked}, flags: {require_enrolled_device, require_shift_for_cash, ...}, error_tracking: {enabled}}` | never a path, a key, a password or a host name; the scheduler writes `ops.scheduler.tick` in the cache every minute |

No HTTP route starts a backup, a drill or a restore: they are console commands run by the scheduler (and by hand from the runbook).

## 5. Domain events and listeners

| Event | Payload | Listeners (module) | Queued? |
|---|---|---|---|
| `DeviceEnrolled`, `DeviceRevoked` (new, Shared) | device_id, branch_id, kind, by_user_id | none (audit only; the admin screen polls) | n/a |
| `PrintJobFailed` (new) | job_id, order_id, order_number, failure_key, branch_id | Notifications `NotifyPrintFailed` (the cashier who asked and the managers on shift; inbox) | queued |
| `BackupFailed`, `BackupStale` (new, raised by Ops) | run_id, kind, failure_key, age_h | Notifications `NotifyOps` (every admin; inbox, Telegram when keys exist) | queued |
| `DrillFailed` (new) | run_id, failure_key, mismatches (count only) | Notifications `NotifyOps` | queued |

`BackupStale` is raised by the hourly scheduler check when the last `ok` backup is older than 26 h, once per stale period (deduplicated like
ADR 0011's stock alerts). Payloads are arrays of ids and keys, never a path, a host or a token.

## 6. Build order inside the phase

Small PRs, branches `phase-5/<topic>`, each green in CI and deployed before the next, as in Phase 4. Inside a PR the order is always:
tests committed red, then the code, then the registries (`write_endpoints.php`, `route_permissions.php`, `public_routes.php`,
`station_routes.php`), lang files in both languages, the OpenAPI export and the ERD, the step's As built note under its entry below, and its
STATUS line (`phase_status.py module <name> in_progress|done`; the phase's STATUS "modules" are the manifest's six steps). The reference's
order is kept (devices, offline, print, i18n, ops, docs); the sub-steps are this plan's split. **Device enforcement comes first** because it
changes every e2e fixture: from 5.1b on every Playwright context of every phase sends an enrolled device id, so later steps are written
against the stack as it will run in the shop. The 16 steps:

| Step | Branch | One line |
|---|---|---|
| 5.1a | `phase-5/device-enforcement` | `EnsureEnrolledDevice` behind `IDENTITY_REQUIRE_DEVICE` (off), station-route registry, device kinds, `GET /devices/this`, rename |
| 5.1b | `phase-5/web-devices` | `X-Device-Id` on every request, admin Devices screen, "not enrolled" screen, every e2e fixture enrols its device, CI stacks run with the flag on, `p5-device` |
| 5.2a | `phase-5/outbox-idb` | the outbox moves to IndexedDB and takes every station write without a PIN, with dependencies and online-only marking |
| 5.2b | `phase-5/photo-queue` | receipt photos queued offline (blobs in IndexedDB), uploaded before the payment that needs them |
| 5.2c | `phase-5/offline-conflicts` | the conflict tray for refusals that arrive on sync, reconnect reconcile, `p5-offline` complete |
| 5.3a | `phase-5/print-jobs` | `print_jobs`, the raster receipt (Amharic as pixels), the ESC/POS encoder and its emulator, the bridge routes |
| 5.3b | `phase-5/print-bridge` | the bridge program (`tools/print-bridge/`, Node 22, no native modules), tested against a fake printer, CI job `print-bridge` |
| 5.3c | `phase-5/web-print` | the till's Print button, job status, the receipt-link fallback, paper width in receipt settings |
| 5.4a | `phase-5/i18n-checks` | API language parity, the "same as English" report, the literal-string lint, the review sheet, `p5-amharic` screen sweep |
| 5.4b | `phase-5/i18n-review` | the reviewer's corrections applied (lands whenever the reviewed sheet arrives; blocks only the claim) |
| 5.5a | `phase-5/ops-errors` | domain refusals no longer logged at ERROR, Sentry support off by default (API and web), the failed-jobs prune |
| 5.5b | `phase-5/ops-backups` | `backup:run` (mysqldump and the files folder), `backup:drill`, `ops_runs`, the two contracts, CI `backup-drill` |
| 5.5c | `phase-5/ops-status` | `GET /system/status`, the admin System page, stale-backup alerts, the uptime workflow, the live ops spec |
| 5.5d | `phase-5/perf-load` | k6 load profile (60 devices, 300-order burst) in CI `perf-load`, the poll budget test |
| 5.6a | `phase-5/go-live-checklist` | `docs/go-live-checklist.json`, `gate_check` reads it, the sign-off folder, handbook, on-call plan, restore runbook |
| 5.6b | `phase-5/gate` | `Phase5Seeder`, `gate:p5` tests, ADRs accepted, `phase-5-report.md`; claim-done waits for Ramin's sign-offs |

This PR (`phase-5/plan`) carries the plan, the STATUS change and two `test.fixme` placeholders, `apps/web/e2e/p5/device.spec.ts` and
`offline.spec.ts`, because `e2e.yml` refuses an empty Playwright project once its phase is `in_progress` (Phase 4's 4.1a did the same).
They are replaced by real specs in 5.1b and 5.2a. Each carries the phase's to-do marker (the one `gate_check --mode done` forbids for
the phase being closed), so the phase cannot close while a placeholder is left.

1. **5.1 identity.devices** (STATUS `identity.devices`), two PRs.
   - **5.1a `phase-5/device-enforcement`** (API only; STATUS `identity.devices` `in_progress`). M1 and M2a (`devices`, the permission
     tables). `EnsureEnrolledDevice` with the flag `identity.require_enrolled_device` read from config on every request (unset is false;
     `DeployableCachesTest` covers the new config); `tests/Support/station_routes.php` and its `non_station_routes` list; the alias applied
     to the station routes of every module's route file; `GET /devices/this`, `POST /devices/{id}/rename`, the extended `POST /devices`
     (kinds, the bridge token made once and stored hashed); the three device routes move from `people.manage` to `devices.manage` (admin
     keeps them: no role loses access); `DeviceEnrolled`/`DeviceRevoked`; `last_seen_at` debounced to once a minute. The manifest
     corrections of 10.9 (ADRs 0017 to 0021 in `phases.5.adrs`, `ops`, e2e-p5's profile) and ADR 0017 **proposed**. `Phase5Seeder`
     skeleton (composes after `Phase4Seeder`, writes nothing yet). Tests: `EnsureEnrolledDeviceTest` (flag off: today's behaviour in every
     case; flag on: unknown, revoked, other branch, enrolled, missing header, each with its key), `StationRoutesTest` (every route
     classified; with the flag on, a table test sends every station route with an unknown device and expects 403, and every non-station
     route expecting no 403 from the device check), `DeviceLastSeenTest` (one write per minute under 30 requests), the device endpoints'
     feature tests (403 per role, replay, validation); `RoutePermissionMatrixTest` picks up `devices.manage` and `devices.self`. No
     Playwright change: the flag stays off everywhere in this PR, so no fixture breaks. Live: M1 adds columns with defaults to `devices`;
     the flag is off.
   - **As built (step 5.1a, `phase-5/device-enforcement`).** Tests red first, then the code with the registries, then the docs. Where the
     code chose what the plan left open:
     - **The station routes** are 58 (`tests/Support/station_routes.php`, each with the screen that calls it), the other 126 in
       `tests/Support/non_station_routes.php`. Besides the plan's list they include the till's receipt photo (`POST /files`, `DELETE
       /files/{file}`), the display case, the KDS strip's transfer request (`POST /transfers`), the production board's reads, the count
       screen's reads and `GET /variance-reasons`, the till's channels and receipt settings. A route the back office also reads (the order
       list, one order, a shift, a count) stays a station route when a floor screen needs it, so once the flag is on an admin computer that
       opens those pages is enrolled like a tablet (ADR 0017 decision 4; 5.1b's screen should say so). Approve, unlock, assign, rosters,
       templates, the approval queue, `GET /shifts`, `GET /payments`, verify and dispute stay back office. The acting-session route is the
       public `POST /short-code/verify`: it carries `device`, and with no user there only "unknown" and "revoked" apply.
     - **Order of the checks**: `device` runs after `actor` and before the permission and the idempotency key (the middleware priority list
       in `bootstrap/app.php`), so an unenrolled tablet gets `identity.device.unknown` whoever signed in, and a refusal is never stored.
       Inside: unknown (no header, an unknown or a deleted fingerprint), then other branch, then revoked.
     - **`GET /devices/this`** answers `{enrolled, revoked, device: {id, name, kind}|null, enforcement}`. `ResolveActor` skips the device
       on that one route (`DEVICE_PROBE_ROUTE`), so a revoked device reads `revoked: true` instead of the actor middleware's 403; a device of
       another branch reads as unknown.
     - **`POST /devices`**: `client_uuid` required (no web caller existed), `kind` optional (tablet), `station_id` optional (a station of the
       branch). The fingerprint check left the validator for its own key (`422 identity.device.fingerprint_taken`, any branch, deleted or
       not); a `client_uuid` already used answers that device with 200 and no token. A bridge's token is 32 random bytes as 64 hex
       characters; `token` joined `Idempotency::SECRET_FIELDS`, so the answer that carries it is stored encrypted, as a short code's is.
       Rename and revoke create no row, so they take no `client_uuid` (the Idempotency-Key makes a retry safe); rename's `station_id` is
       changed only when the key is sent (null clears it). The list answers `station_id`, not a nested station.
     - **`last_seen_at`**: skipped when the loaded row was seen less than a minute ago, else a lock of the default cache store (the
       `database` store on the live host; `DeviceLastSeenTest` runs it on `database` too) that lives a minute and is never released; the
       write sets `last_seen_at` alone (no `updated_at`, no audit row).
     - **Messages**: `identity.device.revoked` now says to enrol the tablet as a new device (a revoked id is never enrolled again).
     - **Manifest** (10.9): `phases.5.adrs` names the five ADR files (`0018-offline-outbox-and-conflicts`, `0019-printing-through-a-pull-bridge`,
       `0020-backups-and-restore-drills-on-a-shared-host`, `0021-observability-on-a-shared-host`: later steps write them under exactly
       these names); `ops` is a top-level entry, not under `modules`, because `SkeletonInvariantsTest` asks every module for an
       `app/Modules/<Name>` directory and Ops is `App\Ops`; `phases.5.e2e_profile` records the e2e-p5 stack; the 5.1 and 5.5 step texts
       follow 10.9. `ci.e2e_jobs` and `ci.yml` are left for 5.1b, `extra_ci_jobs` for 5.6b (7.1).
     - **Live**: M1 adds columns with defaults to `devices` (every row becomes a tablet; no row is updated) and M2a inserts two
       `permissions` rows and their default grants in `role_has_permissions`. The flag is off; no Playwright change.
   - **5.1b `phase-5/web-devices`** (STATUS `identity.devices` `done`). Web: `src/lib/api/client.ts` sends `X-Device-Id` on every request
     (today only the acting calls of `lib/acting.ts` do); a "this tablet is not enrolled" screen on 403 `identity.device.unknown` or
     `revoked` (bilingual, shows the short form of the id and "ask an admin to sign in here and enrol it"); a revoked id is dropped and a
     new one made; the admin **Devices** screen (list with last seen, enrol this device in place, rename, revoke with a confirmation, the
     bridge token shown once with a copy button), linked from People. **Fixtures:** `e2e/support/devices.ts` gives each browser context a
     fixed device id per role and run (`e2e-<run>-<role>`) and enrols it through the API as the admin in global setup; the `@live` suites
     use stable ids per role (`e2e-live-<role>`) so the live E2E branch does not grow a device per run; `compose.ci.yml` sets
     `IDENTITY_REQUIRE_DEVICE=true`, so `e2e-p0` to `e2e-p5` and `perf-dashboards` run with enforcement (seeders write in process and are
     unaffected). CI: `e2e-p5` moves to `profiles: "full"` (polling, as on the live host; ADR 0009), `history_days: "2"` (its projects need
     no history; the default would replay 90 days) and `needs: [status, mod-analytics, web-build, docker-build]` so it runs beside
     `e2e-p4` (10.2 D17). Playwright `p5-device` (`e2e/p5/device.spec.ts`, replaces the placeholder): an unknown tablet sees the
     not-enrolled screen on the pad, the KDS and the till while the same tablet's purely admin pages (catalog, people, devices) work (an
     admin page that reads a station route shows the same screen: ADR 0017's 2026-10-29 note); the admin enrols it in place and the
     pad works after a reload; after a revoke the next request shows the screen again; a device of another branch is refused. Live: the
     flag stays **off** (the live `.env` sets nothing) until Ramin's "every tablet enrolled" sign-off (10.2 D9); `@live` keeps passing.
   - **As built (step 5.1b, `phase-5/web-devices`).** Tests red first (the `p5-device` flow and `device-logic.spec.ts`), then the web with
     its fixtures, then CI, then the docs. No API change: no route, permission, registry, OpenAPI or ERD change. Where the code chose what
     the plan left open:
     - **The conflict with 5.1a's station routes** is settled in ADR 0017's 2026-10-29 note: the classification stands, no one is exempt,
       and an unenrolled computer is treated the same everywhere (the screen also appears on an admin page that reads a station route).
       Section 4.1's "not station routes" sentence and this step's `p5-device` text above are corrected to match.
     - **The device id** (`src/lib/device.ts`): `dev-<uuid>`, made once and kept under the pad's old localStorage key, so tablets keep the
       `pad-…` ids they had; `request`, `requestBlob` and `conditionalGet` send it last, after any caller's headers (an outbox item stored
       with an old id sends the current one; `actingHeaders` now carries only the acting session). Its short form, the last eight
       characters in capitals, is what the screen and the admin's list show.
     - **The screen** (`components/DeviceGate.tsx`, around every page in `AppShell`): a device refusal is reported with the page it was made
       for and replaces that page only; going to another page clears it. One title for the three keys; the API's own message says which
       (unknown, revoked, another branch). Whoever holds `devices.manage` gets "Enrol this device" (name and kind: tablet, till or kitchen
       screen) and the page reloads once it is enrolled; for another branch's device the enrol makes a new id first (fingerprints are
       unique across branches). The outbox keeps a write refused for the device (it waits with its key until the page starts again) instead
       of dropping it as a refusal; 5.2a carries that into IndexedDB.
     - **A revoked id** is renewed once (the other calls that went out with the same old id leave the new one alone). The actor middleware
       refuses a revoked device on every signed-in route whatever the flag, so `GET /me` is asked once more with the new id instead of
       signing the person out. With the flag off (the live host) a revoked tablet shows the screen once and works again after a reload.
     - **The Devices screen** is `/admin/people/devices` (the People tab stays current; People links to it for `devices.manage`): this
       device (the probe, its code, enforcement on or off, enrol in place), every device of the branch with kind, code, enrolled, last seen
       and status, rename and revoke (with its confirmation) on the active ones, and "Add a print bridge" (the page makes the fingerprint
       `bridge-<uuid>`; the token is shown once in a read-only field with Copy and Done; a replay answers no token). Rename sends the name
       only (`station_id` waits for a screen that needs it).
     - **Fixtures**: the roles are the admin, the seven fixed staff, `other` (any other account) and `demo_admin` (the demo bakery's admin,
       CI only: its device in the demo branch is also the flow's "another branch" device). The run is fixed in global setup for every worker
       (`E2E_DEVICE_RUN`) and the enrolled ids reach the workers in `E2E_DEVICES`. On the live host the stable `e2e-live-<role>` ids are
       probed with `GET /devices/this` and enrolled only when unknown; a revoked or taken one moves to `e2e-live-<role>-2` (up to five).
       The fixtures' `Api` sends its account's device on every request (`onDevice()` for another); `openAs` places the device in the
       page's storage (option `device` for a tablet a flow enrols), and so do the two specs that sign in through the form. A failed
       enrolment stops a CI run; on the live host (flag off) and on a developer's machine it is only reported. The pad's short-code lockout
       counts per branch and device, and every waiter context now shares the run's waiter device, so the lockout flow
       (`p1/pad-order-flow.spec.ts`) runs on a device of its own, new for each attempt (`freshDevice`: enrolled only where the API enforces
       devices, so the live branch does not grow one per run); before this step each context had a random id of its own.
     - **CI**: `compose.ci.yml` sets the flag on `app` and `worker`. The Pest runs inside that stack (`gate-p4`, `files-s3`) pass
       `IDENTITY_REQUIRE_DEVICE=false`: the tests keep the code default and turn the flag on where they test it (`files-s3` posts to
       `POST /files`, a station route). `e2e-p5`'s 30 minutes is `e2e.yml`'s job timeout (a job that calls a reusable workflow cannot set
       its own). The manifest's `ci.e2e_jobs` and the skill's `assets/ci` carry the same changes (the mirror has no `files-s3` job).
     - **Live**: the flag stays off. The deploy's `@live` run enrols the stable ids in the E2E Test Branch on its first run after this
       merge (nine devices, a probe and an enrolment each), then only probes them; nothing touches the demo branch.
2. **5.2 web.offline** (STATUS `web.offline`), three PRs, web only unless 5.2c needs the lookup read.
   - **5.2a `phase-5/outbox-idb`** (`web.offline` `in_progress`). The outbox store moves from `localStorage` to IndexedDB (a small
     in-house wrapper, or `idb` pinned if the wrapper grows past a page), migrating any items left in `localStorage` on first start; items
     carry `depends_on` (an item waits for another's server id: a payment after its photo, a line action after its order's send) and keep
     strict order per order; **every station write without a PIN** joins (10.2 D10): the pad and KDS kinds that exist, the till's payments
     on every channel, production batch start and finish, the shift bar's count drafts and cash declaration drafts; a write that needs a
     PIN (void, refund, count approval, handover signature) or an admin screen is **online-only** and its button says so while offline.
     Retries keep the same key; a 4xx moves the item to the conflict list (5.2c), a 5xx or a network failure backs off (1, 2, 4 … 60 s).
     The pending indicator shows the count per screen. Tests: the outbox logic specs (`e2e/p5/offline-logic.spec.ts`, pure functions:
     ordering, dependencies, back-off, the migration from `localStorage`), and the first `p5-offline` spec, replacing the placeholder:
     `context.setOffline(true)`, send an order, reload the page with the API unreachable (CI serves `next start`, which has no `sw.js`: see
     the As built note), go online, and the API holds **exactly one** order with that `client_uuid`; the answer to a send is dropped
     by `page.route` after the server committed it and the replay still yields one order (idempotency proves it). API: a replay-after-expiry
     case beside `IdempotencyCoverageTest` (a key older than the retention is gone; the `client_uuid` unique index still refuses a second
     order and the answer names the existing one).
   - **As built (step 5.2a, `phase-5/outbox-idb`).** Tests red first (`offline-logic.spec.ts`, the two `p5-offline` flows,
     `IdempotencyReplayAfterExpiryTest`), then the web. No API change: no route, permission, OpenAPI or ERD change. Where the code chose
     what the plan left open:
     - **The store** is a wrapper of our own (`src/lib/outbox/db.ts`: `all` and `replaceAll`, one transaction each; database `bakery-ops`,
       store `outbox`, version 1, so 5.2b adds its photo store as version 2). The rules are pure functions (`src/lib/outbox/logic.ts`);
       `store.ts` keeps the queue in memory and writes it through. Without IndexedDB (a private window) the queue lives in the tab's
       memory. The `localStorage` queue (`bakery-ops.outbox.v1`) moves in on the first start; its key is cleared only after the write, and
       a queue already moved is not doubled. Its `after` becomes `depends_on`; its kitchen and bar items do not know their screen.
     - **Order**: every item has a `group`: an order's writes by its client_uuid (else its id), a kitchen, bar or serve action by its line,
       a batch's steps by request (`production:<id>`), a count's drafts by count (`count:<id>`). The head of each group goes first, and a
       group waiting on a failure holds up only itself. **`depends_on`** with `needs: {token, from}`: when the dependency is accepted, the
       token in the dependent's path and body becomes the value at `from` in its answer (`id`, `batch.id`, `lines[client_uuid=…].id`); an
       answer without it makes the dependent a conflict. No screen queues a line action on an unsent order yet (the pad lists only orders
       the server has), so 5.2b's payment after its photo is the first user of `needs`.
     - **Answers**: none (0), 5xx, 408 and 429 retry with the same key after 1, 2, 4 … 60 s (the browser's online event resets the waits);
       a network failure ends the pass, a 5xx holds up only its own item. Any other 4xx (an ended acting session included) makes the item,
       and every item that depends on it, a conflict kept in IndexedDB with the API's body (`blockedBy` names the refused one); the shell
       strip shows them with Dismiss until 5.2c's tray, and the pad still takes its own inline ones. A device refusal keeps everything and
       stops the timer (5.1b's branch); a 401 signs out and keeps everything.
     - **Screens that wait for the answer** use `submit()`: online they get the answer, or the refusal thrown as before (not kept as a
       conflict: the screen shows it); offline, or when the write cannot go now, they are told it is queued. The till: offline no longer
       blocks Complete (`pay-sheet-logic.spec.ts` changed to match); a queued batch shows "saved on this tablet" with the change to give, and
       the till's card holds that order until the outbox sent it. A photo channel still needs its photo uploaded (5.2b). Production: start
       and finish go through the outbox; the card says the step is saved and keeps its other actions disabled until it was sent. Counts:
       line drafts (a typed figure stays on its line) and the cash declaration (the tally stays as typed); handing in waits for them.
     - **Online-only**: `OnlineOnlyButton` (disabled offline, "(Needs a connection)" in its text) on void, refund, approve, co-sign, reject
       and unlock; the admin frame shows a notice and wraps its screens in a disabled fieldset (its links and tabs stay usable).
     - **Pending per screen**: each item keeps the screen it was queued on (`screenOf` of the page path); the strip says "N waiting to
       send" for this screen and "M waiting from other screens".
     - **A page that starts while the API is unreachable** knows nobody (no `GET /me`); the browser's online event now asks again, so the
       outbox goes on without a tap on Retry.
     - **The `p5-offline` reload**: CI serves `next start`, which has no `sw.js` (the shell cache is built only for the static export the
       live host serves), so the spec reloads with every API call aborted and the browser online: what it proves is that the queue lives in
       IndexedDB. The specs are not `@live`.
     - **Left for 5.2b and 5.2c**: the photo store and its upload item; the conflict tray (items are already stored as `conflict`); the
       lookup read. A write that is not a create (a send, a line action) replayed after its key expired runs again and may be refused
       because it already happened; 5.2c's reconcile decides how to show that. Two tabs of one tablet share IndexedDB but each sends from
       its own memory; the same key makes a double send harmless.
   - **5.2b `phase-5/photo-queue`**. A receipt photo taken offline is shrunk in the browser as today, kept as a blob in IndexedDB with
     its `client_uuid`, uploaded first when online (`POST /files`, multipart, replay-safe since 4.2b), and the payment that waits on it is
     sent with the file id; a quota guard refuses a new photo when more than `NEXT_PUBLIC_PHOTO_QUEUE_MB` (50) waits, and says so; a photo
     whose payment was abandoned is dropped locally (the server already purges orphans after 24 h). `p5-offline` spec: offline, pay by a
     channel that needs a photo, attach it, go online: one file, one payment, the photo readable through its signed URL.
   - **5.2c `phase-5/offline-conflicts`** (`web.offline` `done`). The **conflict tray**: every item refused on sync (a stock shortfall
     `orders.send.shortfall` or `inventory.stock.insufficient`, `inventory.shift.required`, `payments.shift.required`, a closed order, a revoked device) stays visible with the
     server's message in both languages, what was attempted, and the actions the refusal allows (edit and resend as a new write with a new
     key, or discard); nothing refused is dropped silently. **Reconnect reconcile**: on `online`, and on visibility after a gap, the KDS
     replays `tickets?since=<cursor>`, the pad and till refetch their lists, and an item whose answer was lost is looked up (`GET
     /orders/by-client-uuid/{uuid}`, added only if 5.2a's replay case shows the existing reads cannot answer it). `p5-offline` specs: a
     stock conflict made while the pad was offline (another context sells the last unit) lands in the tray with the shortfall message and
     the order can be edited and resent; a KDS that was offline for a minute shows the tickets sent meanwhile after reconnect; two tablets
     offline at once replay without duplicates. ADR 0018 proposed.
3. **5.3 print-bridge** (STATUS `print-bridge`), three PRs.
   - **5.3a `phase-5/print-jobs`** (`print-bridge` `in_progress`). M3, M2b, M4. `ReceiptRaster`: the receipt of 4.3e (the view model the
     PDF uses) drawn into a 1-bit image 576 or 384 dots wide with GD and FreeType, Noto Sans Ethiopic for Amharic (the font folder of
     `receipts:fonts`) and a Latin font beside it. Amharic is printed as pixels because ESC/POS printers carry no Ethiopic code page;
     Ethiopic needs no shaping, so FreeType without HarfBuzz draws it correctly. The receipt shows the business name in both languages,
     address, phone, **TIN**, order number, lines, totals, payments by channel, the cashier, the Gregorian date (and the Ethiopian one when
     the branch's `ethiopian_dates` is on), the footer and the non-fiscal wording of 10.2 Q9. The `EscPos` encoder (`ESC @`, `GS v 0`
     raster in bands, feed, `GS V` cut). `tests/Support/EscPosEmulator.php` parses a byte stream back into commands and a bitmap; the tests
     decode the encoder's output and compare the bitmap with golden PNGs (a pixel tolerance) for 58 and 80 mm, Amharic on and off. The
     print endpoints of section 4.3, the claim lock, the 60-second reclaim and the 15-minute expiry (scheduler), `PrintJobFailed`.
     A new `print:doctor` reports `FreeType Support` from `gd_info()`; when it is missing the raster falls back to the printer's own Latin text
     without Amharic and the till offers the link (the deploy smoke prints the doctor line, non-fatal). Feature tests for every route
     (403 per role, the bridge token cases, replay, two bridges racing to claim one job).
   - **5.3b `phase-5/print-bridge`**. `tools/print-bridge/`: Node 22, plain JavaScript (ESM), no native modules, one dependency at most;
     a config file `bridge.config.json` (API base URL, token file path, the printer: `tcp` host and port 9100, a Windows shared printer
     name, or a device path; the width); it polls `GET /bridge/jobs/next` (3 s, 15 s after a quiet minute), claims, writes the bytes,
     reports `printed` or `failed` with a key, logs to a rotating file and never logs the token. Windows: `install-service.ps1` registers
     it with the built-in Task Scheduler (no third-party service wrapper). An optional local mode (`--serve 127.0.0.1:9180`) lets a till PC
     print its own receipts from `receipt.escpos` while the internet is down (10.2 D11). Tests (`node --test`): a fake API and a fake TCP
     printer (the emulator's rules ported to JS) record the bytes; paper-out and printer-offline paths; the back-off; no log line holds the
     token. CI job `print-bridge` (no Docker, about a minute). `docs/runbook/print-bridge.md`: install, enrol the bridge as a device of kind
     `bridge`, paste the token, test print, what each failure key means. ADR 0019 proposed.
   - **5.3c `phase-5/web-print`** (`print-bridge` `done`). The till: Print after payment and on the order record; the job's state (queued,
     printing, printed, failed) by polling `GET /print-jobs?order_id=`; a failed or expired job, or `409 no_bridge`, shows the receipt link
     and its QR code (4.3e's signed link) for the customer to scan; receipt settings get the paper width. Playwright `e2e/p5/print.spec.ts`
     runs under `p5-device` (its `testMatch` widens to `/device|print|ops/`: a till is a device; `ops-live.spec.ts` of 5.5c joins it), with a fake bridge in the test process against
     the CI stack, checking the printed bytes with the JS emulator. The shop test is a human item of the checklist.
4. **5.4 i18n** (STATUS `i18n`), two PRs.
   - **5.4a `phase-5/i18n-checks`** (`i18n` `in_progress`). The web dictionary already has 2 289 keys in both languages and no `__TODO__`
     (`i18n-check` has run `--strict-i18n` since phase 5 became current). Added: `LangParityTest` (Pest, `invariants`): `apps/api/lang/en`
     and `am` have the same files and keys at every depth, no empty value, no `__TODO__`, the same `:placeholders`; `scripts/i18n_report.py`
     lists `am` values identical to `en` (an allowlist for brand names, units, `ETB`, codes), placeholders that differ and keys the code no
     longer uses (a report, not a gate); an ESLint rule (`react/jsx-no-literals` with an allowlist, or a short local rule) that fails on
     literal text in JSX; the review sheet `scripts/i18n_export.py` to `docs/i18n/am-review.csv` (key, screen, English, Amharic, the
     reviewer's correction, a comment) and `scripts/i18n_import.py`, which applies a filled sheet back to `am.json` and the PHP files;
     `docs/i18n/glossary.md` (one Amharic word for each domain term: shift, handover, count, ledger, refund, void, station, batch).
     Playwright project **`p5-amharic`** (`e2e/p5/amharic.spec.ts`; added to `playwright.config.ts`, the manifest's
     `phases.5.gate.playwright_projects`, STATUS and e2e-p5's list in the same PR): signed in as each role, visit every screen of that role
     under `/app/am/` and assert no visible text node holds Latin letters outside the allowlist (numbers, `ETB`, names the admin typed,
     the order number format).
   - **5.4b `phase-5/i18n-review`** (`i18n` `done`). The reviewer's corrections imported from the sheet, the glossary settled, the report
     clean. Opened when the sheet comes back (10.2 Q5); 5.5 and 5.6 do not wait for it. The checklist item `am-reviewed` is a sign-off.
5. **5.5 ops** (STATUS `ops`), four PRs.
   - **5.5a `phase-5/ops-errors`** (`ops` `in_progress`). The Phase 4 gap first: `PaymentError`, `OrderError`, `IdentityError`, the
     Inventory and Shifts refusals and `IllegalTransition` implement a `DomainRefusal` marker and are **not reported**
     (`$exceptions->dontReport`), logged once at `info` with the key; a 500 stays `error`. Sentry: `sentry/sentry-laravel` pinned, active
     only when `SENTRY_LARAVEL_DSN` is set (unset: no client, no network); `before_send` scrubs PINs, passwords, tokens, `Authorization`,
     phone numbers, signed-URL parameters and the bodies of the identity routes; `send_default_pii` false; traces off. Web: the Sentry
     browser SDK is loaded lazily only when `NEXT_PUBLIC_SENTRY_DSN` was set at build time (a GitHub variable; unset: not in the bundle).
     Tests: a test event reaches a fake ingest (an HTTP fake for the API, a `page.route` sink for the web) with nothing that should be
     scrubbed left in it; with no DSN nothing is sent. The scheduler gets `queue:prune-failed --hours=720` daily (10.2 D7). ADR 0021
     proposed.
   - **5.5b `phase-5/ops-backups`**. The two contract files red first (section 2), then `Inventory\Contracts\LedgerOracle` and
     `HashChain::verifyAll`. M5. `App\Ops` (not a module: a thin layer that may use modules' `Contracts\*` only; an arch test says so).
     `backup:run`: `mysqldump --single-transaction --quick --routines --triggers --hex-blob --no-tablespaces`, the password in a
     `--defaults-extra-file` written 0600 and deleted in `finally` (never on the command line), gzip, a manifest JSON (sha256, bytes, row
     counts of the main tables, the migration count, the app commit), into `BACKUP_PATH` (default `~/backups/bakery-ops`, outside the app
     folder, so the deploy's `rsync --delete` never sees it). The files folder is snapshotted beside it as **hard links**: receipt photos
     are written once and never changed, so a nightly snapshot costs no space and still keeps a photo the app later purges. Retention: 30
     daily dumps and a cap `BACKUP_MAX_MB` (2048) that deletes the oldest first and never the newest good one; an `ops_runs` row per run;
     `BackupFailed` on failure. `backup:drill`: restores the newest dump into `BACKUP_DRILL_DATABASE` (a scratch database Ramin creates
     once, 10.2 Q8; unset, the drill records `skipped` and the System page says why), runs `LedgerOracle::check` and
     `HashChain::verifyAll` on that connection, checks that the append-only triggers exist and fire there, compares row counts with the
     manifest, records the result, raises `DrillFailed` on a failure, then empties the scratch database. Schedule, Addis time: backup
     02:30 daily, drill Sundays 03:30 (the chain check stays at 03:00). An off-site hook (`BACKUP_REMOTE`, an rclone-style remote) exists
     and is off (10.2 Q2). CI **`backup-drill`** replaces the placeholder job: compose stack, `SEED_PHASE=4` with 14 days of history,
     `backup:run`, `backup:drill` into `bakery_drill`, then `pest --group=gate:p0` with `GATE_SEED_DATABASE=bakery_drill` so Phase 0's
     ledger property and oracle tests also run against the restored server, and `RestoredLedgerGateTest` (`gate:p5`, `SeededGate`) on the
     restored data. `docs/runbook/backup-restore.md`: where the dumps are, a full restore by hand (the database and the files folder of the
     same night), the drill, what to do when the disk fills. ADR 0020 proposed.
   - **5.5c `phase-5/ops-status`**. M6, M2c. `GET /system/status` and the admin **System** page (polls every 30 s, conditional):
     scheduler heartbeat, queue depth and the oldest job's age, failed jobs, last backup and drill, the disk used by the files and the
     backups, devices enrolled and seen in 15 minutes, the rollout flags, whether error tracking is on. The hourly `BackupStale` check.
     `.github/workflows/uptime.yml`: every 15 minutes GitHub's scheduler sends one `GET /health` to the live URL (the existing repository
     variable, not written in the file) and fails when it is not `ok` twice in a row, which emails the repository owner (10.2 D14): the
     same read of the public health route the deploy smoke makes. `e2e/p5/ops-live.spec.ts` (`@live`, project `p5-device`, run by the deploy as the E2E
     admin): the System page renders and the scheduler ticked in the last 3 minutes; once backups have run, the last good backup is
     younger than 26 h and the last drill younger than 8 days and `ok` (until the first drill has run the spec reports "not configured
     yet" without failing, then it enforces).
   - **5.5d `phase-5/perf-load`** (`ops` `done`). `scripts/perf/load.k6.js` on the CI compose stack (never the live host): 60 devices
     (34 pads, 12 KDS, 8 tills, 6 admin and shift screens) polling at the web app's own cadences with `If-None-Match`, and a burst of
     300 orders in 10 minutes, each sent, accepted, made ready, served and paid (a quarter by photo channels with a small fixture photo).
     Thresholds: polls p95 < 300 ms, writes p95 < 800 ms, errors < 0.5 %; afterwards **exactly 300 orders**, the ledger oracle `ok`, no
     `failed_jobs`. Job **`perf-load`**: needs `mod-analytics` and `docker-build`; `SEED_PHASE=5` with 7 days of history; the 60 devices
     enrolled by `Phase5Seeder` when `LOAD_DEVICES=60`; k6 in the pinned image `perf-dashboards` uses; timeout 30. `PollBudgetTest` (Pest,
     `gate:p5`) computes the requests per minute N devices make at the configured cadences, so the runbook can state what the shared host
     is asked for (12 tablets, 60 tablets) next to the load average it showed in Phase 4.
6. **5.6 docs** (STATUS `docs`), two PRs.
   - **5.6a `phase-5/go-live-checklist`** (`docs` `in_progress`). `docs/go-live-checklist.json` and its schema (10.3), the `gate_check.py`
     change that reads it (the repository copy and the skill's copy, kept identical; `scripts/tests/test_gate_checklist.py` with a fixture
     for every verifier type, run by the `invariants` job), `scripts/ops/check_branch_protection.py` (reads the required checks of `main`
     through the branches API, `GITHUB_TOKEN` in CI), `docs/go-live/signoffs/README.md` and `TEMPLATE.md` (10.3). The **admin handbook**
     `docs/handbook/` (a README and one page per task: opening and closing the day, enrolling and revoking tablets, the printer and its
     bridge, refunds and voids, counts and handover, what the screens do offline and how to clear the conflict tray, the System page,
     backups and who restores them, changing a channel, reading the dashboards), in English with the Amharic page titles, linking the
     runbook rather than repeating it. **`docs/runbook/on-call.md`**: hours, the first checks (the System page, `/health`, the queue), the
     kill switches (shift rules, device enforcement), who is called in which order **by role** (names and numbers live outside the public
     repository), when to restore. A training plan (`docs/handbook/training.md`): sessions per role and what each must be able to do.
   - **5.6b `phase-5/gate`** (`docs` `done`). `Phase5Seeder` (the demo branch: a pad, a KDS, a till and a bridge device enrolled, receipt
     settings with a sample TIN, `LOAD_DEVICES` for `perf-load`); `Phase5GateTest` (`gate:p5`): unknown device 403 on every station route
     with the flag on and no 403 on admin routes, an order replayed with the same key and `client_uuid` is one row, the checklist parses and
     every verifier type resolves; the `gate-p5` job of 7.1 runs the group; ADRs 0017 to 0021
     **accepted**; `docs/phases/phase-5-report.md`; the skill copies (`ci-cd.md`, `testing-strategy.md`, `traps.md`) updated with what the
     phase taught. Then `gate_check --mode done --phase 5` passes every automated row and lists the sign-offs still waiting: **claim-done
     waits for Ramin** (10.3). When his "every tablet enrolled" sign-off lands, a one-line PR flips the device-enforcement default to on
     (the pattern of 3.7e), with the kill switch in the runbook.

## 7. Test list and CI

- **Unit** (no database): `EscPosEncoderTest` (byte layout of init, raster bands, feed, cut), `ReceiptRasterTest` (golden PNGs, 58 and
  80 mm, Amharic on and off, the TIN line, a 40-line order wraps to a second band), `DeviceKindTest`, `BackupRetentionTest` (30 dumps, the
  cap, never the newest good one), `ScrubberTest` (Sentry `before_send`: PIN, password, token, phone, `signature=` and `expires=` gone),
  `PollBudgetTest`; web logic specs: `offline-logic.spec.ts` (ordering, `depends_on`, back-off, the `localStorage` migration),
  `print-logic.spec.ts` (job states, when the link fallback shows), the bridge's `node --test` suite.
- **Feature** (one per endpoint, 403 per role from the matrix, idempotent replay, validation): every route of section 4;
  `RoutePermissionMatrixTest`, `IdempotencyCoverageTest` and the new `StationRoutesTest` pick routes up from the registries;
  `EnsureEnrolledDeviceTest`; `BridgeAuthTest` (unknown, revoked, another branch's job); `SystemStatusTest` (no path, key or host name in
  the body; conditional 304); `BackupRunTest` and `BackupDrillTest` (against the test server: a real `mysqldump`, the
  `--defaults-extra-file` deleted afterwards, the password never in the process arguments, a corrupted dump makes the drill fail with a key).
- **Property / concurrency**: `PrintClaimRaceTest` (two bridges, one job: one claim; `DatabaseTruncation`, forks), `FileUploadRaceTest` (the
  Phase 4 gap, now likelier with the photo queue: the same `client_uuid` from two processes gives one row and one object), the replay of
  50 random outbox sequences against the API (`OutboxReplayPropertyTest`: each sequence sent twice with the same keys, in random
  interleavings, leaves exactly the rows of one pass; seeded, the seed printed on failure).
- **Contract**: section 2.
- **Invariants/arch**: `LangParityTest`; `BranchIdTest` (allowlist + `ops_runs`, with its reason); `NumericColumnsTest`;
  `AuditCoverageTest` (device enrol, rename and revoke, print requests and the bridge's acknowledgements are audited; backup runs are
  `ops_runs` rows, not audit entries, ADR 0020); `ModuleBoundariesTest` (`App\Ops` uses `Contracts\*` only); `OctaneSafetyTest`;
  `DeployableCachesTest` (the new configs: `identity.require_enrolled_device`, `print.*`, `backup.*`, `sentry`).
- **Playwright** (`apps/web/e2e/p5/`): `p5-device` (`device.spec.ts`, `print.spec.ts`), `p5-offline` (`offline.spec.ts`,
  `offline-photo.spec.ts`, `offline-conflicts.spec.ts`; the only project with `serviceWorkers: "allow"`), `p5-amharic` (`amharic.spec.ts`,
  new); every earlier project runs with device enforcement on from 5.1b; `@live`: `ops-live.spec.ts`, and the existing live suites with
  stable device ids.
- **Gate test files**: `apps/api/tests/Gate/Phase5GateTest.php` (`gate:p5`), `RestoredLedgerGateTest` (`gate:p5:restored`, run by
  `backup-drill` on the restored database).

### 7.1 CI for Phase 5

| Job | Needs | Timeout | What it runs |
|---|---|---|---|
| `e2e-p5` (changed in 5.1b) | `status`, `mod-analytics`, `web-build`, `docker-build` (beside `e2e-p4`) | 30 (`e2e.yml`) | profiles `full` (polling, no Reverb), `history_days: "2"`, `IDENTITY_REQUIRE_DEVICE=true`; projects `p5-device p5-offline`, plus `p5-amharic` from 5.4a |
| `backup-drill` (replaces the placeholder in 5.5b) | `status`, `docker-build`, `mod-inventory` | 25 | compose stack, `SEED_PHASE=4` with 14 days, `backup:run`, `backup:drill` into `bakery_drill`, `pest --group=gate:p0` and `--group=gate:p5:restored` on the restored database |
| `perf-load` (new, 5.5d) | `status`, `docker-build`, `mod-analytics` | 30 | compose stack, `SEED_PHASE=5`, 7 days, `LOAD_DEVICES=60`, k6 `load.k6.js` in the pinned image, thresholds and the after-checks of 5.5d, the figures in the step summary |
| `print-bridge` (new, 5.3b) | `status` | 20 | Node 22, `node --test` in `tools/print-bridge` |
| `gate-p5` (changed in 5.6b) | `status`, `gate-p4`, `e2e-p5`, `i18n-check`, `backup-drill`, `perf-load`, `print-bridge` | 25 | loads the image, `pest --group=gate:p5` on the compose stack (fails on a skip or a warning, as `gate-p4` does), then `gate_check.py --mode done --phase 5 --ci` with `GH_TOKEN` and `permissions: actions: read` for the checklist's workflow items |
| `uptime` (new workflow `uptime.yml`, 5.5c) | schedule `*/15 * * * *` | 5 | one `GET /health` against the live URL variable, a second after 60 s if the first fails; never anything else |

- **Timeouts.** Every new job states 20 to 30 minutes (the uptime job 5). Jobs that have none today (`status`, `api-lint`, `web-lint`,
  `i18n-check`, `web-build`, `docker-build`, `openapi-check`, `erd-check`, `invariants`, `gate-p0` to `gate-p3`) get an explicit
  `timeout-minutes` in 5.5d, 20 unless a measured run needs more, so a hung step cannot hold a runner for six hours. `mod-inventory` keeps
  30 (18 m 34 s and 19 m 18 s measured in Phase 4); if any run passes 25 minutes, its `property,concurrency` groups move to their own job
  `mod-inventory-props` (same needs) in the PR that sees it, rather than raising the timeout again.
- **The `e2e-p4` 60-day seed stays under 10 minutes.** It measured 441 s (seed step) in Phase 4. Phase 5 adds nothing to the history
  loop: device enrolment happens in Playwright's global setup (a few API calls) and `Phase5Seeder` runs after the history, outside the
  timed loop. Any PR that touches `database/seeders/**` or `e2e/support/**` reads the `Phase 4:` line of the step summary; above 540 s it
  stops and says so in the PR before merging.
- **Manifest** (`ci` section, 5.1b and 5.6b): `e2e_jobs.e2e-p5` gets the new needs, `gate_jobs.gate-p5` adds `perf-load` and
  `print-bridge`, `phases.5.gate.extra_ci_jobs` lists `i18n-check`, `backup-drill`, `perf-load`, `print-bridge`.
- The whole run grows by `perf-load` (beside `e2e-p4` and `perf-dashboards`) and `gate-p5` (about 5 minutes after `gate-p4`); `e2e-p5`
  no longer waits for `e2e-p4`, so the critical path stays `mod-analytics` then `perf-dashboards` then `gate-p4` then `gate-p5`.

## 8. Seeds

`Phase5Seeder` (skeleton in 5.1a, real in 5.6b; composed by `DemoSeeder` after `Phase4Seeder` when `SEED_PHASE >= 5`): in the demo
branch only, enrols a pad, a KDS, a till and a bridge device with fixed fingerprints (`demo-pad-1`, `demo-kds-1`, `demo-till-1`,
`demo-bridge-1`; the bridge's token is made at random and never printed), sets the receipt settings' sample TIN (a made-up value of the
right length) and paper width 80, and with `LOAD_DEVICES=N` enrols `load-01` to `load-N` for `perf-load`. It writes no stock movement,
order or payment, so no earlier gate's figures move. Earlier seeders do not change: they write in process, where the device flag does not
apply, and the e2e fixtures enrol their own devices through the API. The live host is never seeded by a deploy; the live "Demo Bakery"
gets nothing (10.2 D5).

## 9. ADRs this phase writes

| ADR | Decision | Written in |
|---|---|---|
| **0017 device enrolment and enforcement** | the flag and its kill switch; which routes are station routes and the registry test that keeps them classified; enrol in place by an admin signed in on the tablet; a revoked id is never re-enrolled; `last_seen_at` debounced; the bridge as a device with a hashed token; why the live flag waits for the "every tablet enrolled" sign-off | 5.1a (proposed) |
| **0018 the offline outbox and its conflicts** | which writes are queued (station writes without a PIN) and which are online-only; IndexedDB; ordering and `depends_on`; the same key on every retry; why a refusal on sync is shown, never dropped; reconnect reconcile; what is lost if the browser's storage is cleared | 5.2c (proposed) |
| **0019 printing through a pull bridge** | a shop computer pulls jobs over HTTPS (the host cannot reach the shop; a page cannot reach a LAN printer); Amharic as a raster; the receipt wording and TIN; the link fallback; the local mode for a till PC | 5.3b (proposed) |
| **0020 backups and restore drills on a shared host** | `mysqldump` from the scheduler to the account's disk, the files folder as hard-link snapshots, retention and the cap, the weekly drill and what it proves (ledger oracle, chain, triggers), off-site later, what a restore loses | 5.5b (proposed) |
| **0021 observability on a shared host** | domain refusals are not errors; Sentry off until a DSN with scrubbing; no Pulse; the System page and the scheduler heartbeat; the uptime workflow | 5.5a (proposed) |
| note on ADR 0009 | Phase 5 stays on polling: `e2e-p5` drops `ws`; the bridge's and the System page's cadences | 5.1b |
| note on ADR 0011 | the bridge writes with the system actor plus its `device_id` | 5.3a |
| note on ADR 0006 | backups, the drill database and the host tools they need | 5.5b |

All five are **accepted** in 5.6b. Phase 5 adds them to `phases.5.adrs`, so `gate_check --mode done --phase 5` requires the files.

## 10. Assumptions and open questions

Nothing in Phase 5 changes the meaning of the ledger, so no question blocks the build (standing approval). Every question below has a
safe default the build takes. The ones in **part A** need Ramin's **account, money, hardware or a named person**: they wait for him, no
agent creates an account, buys anything, or signs in his name, and every step is built so it goes ahead without them. **Part B** is
decided by the default unless he objects.

### 10.1 Live-host facts that shape this plan

The reference was written for a VPS with Docker, PostgreSQL, Redis and WebSockets. The live host is a **shared cPanel account** behind
Cloudflare: MariaDB 10.11 (not PostgreSQL), CloudLinux `alt-php83`, **no Docker, no Redis, no WebSocket server, no long-running process**;
live updates are polling with ETags (ADR 0009); `database` cache, session and queue, the queue drained by cron once a minute
(`queue:work --stop-when-empty --max-time=50`) and the scheduler by `schedule:run` every minute; the web app is a static export under `/app`;
the app lives in its own folder in the account's home (`DEPLOY_PATH`); files sit on the account's private disk (`FILES_DISK=files`); the host is CPU-limited (load
average around 10 at busy times; live tests see ECONNRESET and the e2e client retries with the same key). Rules that follow and are used
above: every ops job is a console command run by the scheduler, bounded so it finishes well inside its slot (the dump at 02:30, when the
shop is closed); nothing listens on a port on the host (the print bridge pulls); every new poll is conditional and debounced (devices'
`last_seen_at`, the bridge's 204, the System page at 30 s); k6 runs only on the CI stack; backups go to the account's disk first; no test
or agent touches the live host except the deploy workflow's own `@live` stage and the uptime workflow's health read.

### 10.2 Open questions, each with the default the build takes

**A. Needs Ramin's account, money, hardware or a person (waits for him; never created by an agent).**

| # | Question | Default taken (the build goes ahead) | Checklist item |
|---|---|---|---|
| Q1 | **Error tracking: a Sentry account and its DSN** (or another service). | Sentry support is built and tested against a fake ingest, **off** while `SENTRY_LARAVEL_DSN` and `NEXT_PUBLIC_SENTRY_DSN` are unset; refusals stop being logged at ERROR either way (5.5a). | `error-tracking`: the DSN set and a test event seen, or Ramin's written choice to go live without it |
| Q2 | **Off-site backup storage** (an R2 or S3 bucket, Backblaze, or a drive he owns) and who pays for it. | Nightly dumps and file snapshots on the account's own disk (`~/backups/bakery-ops`, 30 days, 2 GB cap); the `BACKUP_REMOTE` hook is built and off. A copy on the same host does not survive the loss of the host: the sign-off makes that explicit. | `offsite-backup`: configured and one copy verified, or the host-only risk accepted in writing |
| Q3 | **The shop's receipt printer (model, 58 or 80 mm, network or USB) and the machine that runs the bridge** (a Windows PC at the till, a small Linux box). | Built against an ESC/POS emulator: 80 mm (576 dots) by default, 58 mm as an option; TCP port 9100, a Windows shared printer, or a device path; Node 22 on Windows 10/11 or Linux. | `print-bridge-shop`: the bridge installed on the shop machine and a bilingual receipt with the right TIN printed on the shop printer |
| Q4 | **Web push (VAPID) and Telegram bot keys** (open since Phase 2). | Both stay off; the in-app inbox carries every notification, including the new print and backup alerts. | `push-telegram`: **optional**, not a go-live blocker |
| Q5 | **Who reviews the Amharic dictionary** (a fluent member of staff, by role in the repo). | The review sheet (`docs/i18n/am-review.csv`) and the glossary are generated in 5.4a; 5.4b applies the corrections whenever they come back; nothing else waits. | `am-reviewed` |
| Q6 | **The tablets**: how many, which station each serves, and enrolling each one (an admin signs in on it once). | Device enforcement is built and on in CI, **off** on the live host until Ramin signs that every tablet is enrolled; then the code default flips (10.5). | `devices-enrolled` |
| Q7 | **Training sessions and the on-call people** (names and phone numbers stay outside the public repository). | The training plan and the on-call plan are written by role in 5.6a. | `training-done`, `on-call-agreed` |
| Q8 | **A scratch database for the live restore drill** (cPanel, his account; free). | The drill records `skipped` and the System page says "drill database not configured" until `BACKUP_DRILL_DATABASE` is set in the server `.env` (runbook step); the CI drill proves the mechanism on every run. | covered by `live-ops` (the drill must have run and passed within 8 days) |
| Q9 | **Does the shop need a fiscal receipt** (a registered sales register) for what it sells, and is a customer receipt from this system allowed beside it? (his business, a legal question) | The receipt carries the TIN and says in both languages that it is a customer receipt, **not a fiscal receipt**; the shop's own sales register, if it has one, stays the fiscal record. Nothing in the system claims to be fiscal. | `receipt-wording` |
| Q10 | **Cloudflare** (his account): if the bridge's requests are ever challenged by bot protection, a rule that lets `/api/v1/bridge/*` through. | No change; the bridge sends a plain `User-Agent` and the 5.3b runbook says what a challenge looks like. Only needed if the shop test shows one. | part of `print-bridge-shop` |

**B. Decided by default unless Ramin objects.** The Phase 4 open items first.

| # | Question | Default taken |
|---|---|---|
| D1 | May a manager override a product's refund setting on one refund? (Phase 4) | **No.** Refunds follow the product's two settings, as Ramin's rule says; not built. |
| D2 | Ethiopian date preference per device or on the server? (Phase 4) | **Per device** (browser storage per signed-in user), unchanged. |
| D3 | Negative stock? (since Phase 0) | **Refused**, unchanged. |
| D4 | May a solo admin approve their own batch or count? (Phase 2 and 3) | **Refused**, unchanged; the handbook says a shop needs a second manager or admin for approvals. |
| D5 | Load demo data on the live host? (Phases 1 to 4) | **Not run.** No seeder runs on the live host. |
| D6 | Display-case COGS and modifier recipe deltas (Phase 4 known gaps). | **After go-live**: dashboards keep "cost incomplete" for those lines. If Ramin picks a fix (Orders snapshots the recipe version on display-case lines, or Production values its batch output) before 5.6b, it becomes a step 5.6x; neither changes the ledger. |
| D7 | The old `failed_jobs` rows on the live host (3 529 on 2026-10-26). | The scheduler prunes failed jobs older than 30 days (`queue:prune-failed --hours=720`, 5.5a), so those rows go on their own after 2026-11-25; nobody deletes anything by hand. |
| D8 | How is a tablet enrolled, and can a revoked one come back? | An admin signs in on the tablet and taps "Enrol this tablet"; a revoked id is never enrolled again (the tablet makes a new id and is enrolled anew). |
| D9 | When does device enforcement go on for the live host? | After the `devices-enrolled` sign-off, by a one-line PR that flips the code default (the pattern of 3.7e); kill switch `IDENTITY_REQUIRE_DEVICE=false` in the server `.env`, then `config:cache`. |
| D10 | Which writes work offline? | Every station write without a PIN (orders, station actions, payments on every channel with their photos, production batch steps, count and cash drafts). PIN actions (void, refund, approval, handover signature) and admin screens are online-only and say so. |
| D11 | How does a receipt reach the printer? | A pull bridge on a shop computer, a raster receipt made by the server, the signed receipt link as the fallback; a till PC may print locally from `receipt.escpos` when the internet is down. |
| D12 | Laravel Pulse? | **Not installed** (its server recorder needs a long-running process, and it writes on every request on a CPU-limited host); the System page shows what Pulse would have. |
| D13 | Backup timing and retention. | Dump 02:30 daily, drill Sundays 03:30 (Addis time), 30 dumps, 2 GB cap, the files folder as hard-link snapshots. |
| D14 | Uptime monitoring without a new account? | A GitHub scheduled workflow reads `/health` every 15 minutes; a failure emails the repository owner. A paid or external monitor can replace it later. |
| D15 | How many devices to plan for? | `perf-load` measures the manifest's 60 devices on CI; the shop is assumed to run **12 tablets or fewer** on day one, and `PollBudgetTest` states the live request rate for both. |
| D16 | How does Ramin sign a checklist item? | He adds `docs/go-live/signoffs/<item>.md` in **GitHub's web editor** (10.3); agents never write in that folder. If he prefers to commit from his PC, the check falls back to the file's fields only. |
| D17 | Where does `e2e-p5` run? | Beside `e2e-p4` after `mod-analytics`, polling (`full`, no `ws`), two days of history. |
| D18 | Receipt paper width. | 80 mm (576 dots); 58 mm (384) selectable in receipt settings. |
| D19 | What may stay in Latin script on an `am` screen? | Numbers, `ETB`, the order number format, and names the admin typed (products, channels, people); everything else is Amharic. |
| D20 | How does the bridge sign in? | As a device of kind `bridge` with a random token shown once and stored hashed; no user account and no new role. |
| D21 | What may reach Sentry? | No PII: PINs, passwords, tokens, phone numbers, signed-URL parameters and identity request bodies are scrubbed; traces and replays off. |

### 10.3 The go-live checklist and how `gate_check.py --mode done --phase 5` reads it

`docs/go-live-checklist.json` (5.6a), one object per item:

```json
{
  "schema": 1,
  "phase": 5,
  "items": [
    { "id": "backup-drill-ci", "title": "A dump restores into a scratch database and passes the ledger tests",
      "required": true, "verifier": { "type": "ci_job", "jobs": ["backup-drill"] } },
    { "id": "live-ops", "title": "Live: a good backup in the last 26 h, a passing drill in the last 8 days",
      "required": true, "verifier": { "type": "ci_job", "workflow": "deploy.yml", "job": "live-e2e", "branch": "main", "max_age_days": 7 } },
    { "id": "branch-protection", "title": "main requires gate-p0 to gate-p5",
      "required": true, "verifier": { "type": "script", "run": ["python3", "scripts/ops/check_branch_protection.py", "--require", "gate-p0..gate-p5"] } },
    { "id": "handbook", "title": "Admin handbook",
      "required": true, "verifier": { "type": "file_exists", "paths": ["docs/handbook/README.md", "docs/handbook/training.md"] } },
    { "id": "training-done", "title": "Training sessions held for every role",
      "required": true, "verifier": { "type": "file_exists", "paths": ["docs/go-live/signoffs/training-done.md"], "signoff": true } }
  ]
}
```

The items (titles shortened):

| Item | Verifier | Who completes it |
|---|---|---|
| `gates-green`: gate-p0 to gate-p4 still green | `ci_job` `gate-p4` (which needs the earlier gates) | CI |
| `branch-protection` | `script` `check_branch_protection.py` | CI |
| `device-enforcement`: unknown device 403, admin unaffected | `ci_job` `e2e-p5` and `self` (the gate job's own `gate:p5` step) | CI |
| `offline-replay`: exactly one order after an offline send | `ci_job` `e2e-p5` | CI |
| `print-emulator`: receipt bytes decode to the golden images; the bridge's tests | `ci_job` `print-bridge`, `self` | CI |
| `i18n-strict`: parity strict, API parity, every `am` screen swept | `ci_job` `i18n-check`, `e2e-p5`; `script` `check_invariants.py --i18n --strict-i18n` | CI |
| `backup-drill-ci` | `ci_job` `backup-drill` | CI |
| `load-test`: k6 within thresholds | `ci_job` `perf-load` | CI |
| `live-ops`: backup and drill fresh on the live host | `ci_job` workflow `deploy.yml`, job `live-e2e` (matched by id or display name), within 7 days of the claim | CI (the deploy's `@live` stage) |
| `uptime-monitor` | `file_exists` `.github/workflows/uptime.yml` and `ci_job` workflow `uptime.yml` green within 1 day of the claim | CI |
| `handbook`, `on-call-plan`, `restore-runbook`, `print-runbook`, `adrs` | `file_exists` (the handbook pages, `docs/runbook/on-call.md`, `backup-restore.md`, `print-bridge.md`, ADRs 0017 to 0021) | CI |
| `devices-enrolled` (every tablet enrolled, enforcement on) | `file_exists` + `signoff` | **Ramin** |
| `print-bridge-shop` | `file_exists` + `signoff` | **Ramin** (hardware) |
| `am-reviewed` | `file_exists` + `signoff` | **Ramin** (with the reviewer) |
| `training-done`, `on-call-agreed` | `file_exists` + `signoff` | **Ramin** |
| `offsite-backup`, `error-tracking`, `receipt-wording` | `file_exists` + `signoff` (configured, or the risk accepted in writing) | **Ramin** (account, money, or a legal answer) |
| `push-telegram` | `file_exists` + `signoff`, `required: false` | Ramin, optional |

**How `gate_check` reads it** (5.6a; the manifest's `phases.5.gate` gains `"checklist": "docs/go-live-checklist.json"`, and the code runs for
any phase whose gate names a checklist). `mode_done` loads the file, validates it against the schema (unknown verifier types and duplicate
ids fail), and adds one row per item, `checklist <id>`:

- `ci_job` with `jobs`: in CI (`--ci`), each job must be in `ci.gate_jobs["gate-p5"]` of the manifest (the gate job only runs after all of
  them succeeded, so being needed is the proof; `self` means the gate job's own Pest step, which runs before `gate_check`); a job that is
  not needed is a FAIL ("not a need of gate-p5"). Locally the row is SKIP ("trusted to CI").
- `ci_job` with `workflow`: the newest successful run of that workflow (and job, if named) on `branch`, read from the Actions API with
  `GH_TOKEN` (CI) or `gh` (locally); it must have finished within `max_age_days` **before the reference time**: now while the phase is open,
  `done_at` once it is claimed, so `gate-p5` on `main` does not turn red a week after go-live.
- `script`: runs `run` (an argument list, no shell) from the repository root with a 120 s timeout; exit 0 is PASS, the last lines of its
  output are the detail.
- `file_exists`: every path exists and is not empty. With `"signoff": true` the file must also have the fields `item` (equal to its file
  name), `date` (ISO, not in the future), `signed_by` and `statement` (his words), and **every commit that touched it** must have been made in
  GitHub's web editor (committer `GitHub <noreply@github.com>`, read with `git log --format=%cn%x09%ce -- <path>`). Agents commit under
  Ramin's git identity from this PC, so the author cannot tell them apart; the web editor is a path no agent uses, the skill and
  `CLAUDE.md` forbid agents from writing in `docs/go-live/signoffs/`, and `check_invariants.py --signoffs` (in `invariants`) fails a PR that
  carries a local commit touching that folder. A sign-off item without its file is reported as `WAIT (Ramin)`, not as a plain failure.

Rows are PASS, FAIL, SKIP or WAIT. While phase 5 is not claimed, `gate-p5` runs with `--ci` informationally (as today: failures become
warnings), so the phase can be built while sign-offs are missing. Run locally, `gate_check --mode done --phase 5` exits non-zero while any
`required` item is not PASS, and `phase_status.py claim-done 5` (changed in 5.6a) runs it first and refuses with the list of waiting items.
After the claim, `gate-p5` on `main` is strict: every required item must pass. **The phase cannot be claimed done until Ramin has signed
the human items.** `docs/go-live/README.md` and `docs/go-live/signoff-template.md` (outside the `signoffs/` folder) say how to sign: open
the template on GitHub, copy it to `docs/go-live/signoffs/<item>.md`, fill the four fields, commit in the browser.

### 10.4 Phase 4 carry-overs: where each lands

| Item | From | Phase 5 |
|---|---|---|
| Manager overrides a product's refund setting on one refund | Phase 4 question | D1: no, not built |
| Ethiopian date preference per device or on the server | 4.5f | D2: per device |
| Push and Telegram keys | Phase 2 | Q4: off; optional checklist item |
| Negative stock | Phase 0 | D3: refused |
| A solo admin approving their own batch or count | Phases 2 and 3 | D4: refused; handbook note (5.6a) |
| Loading demo data on the live host | Phases 1 to 4 | D5: not run |
| Display-case products have no COGS; modifier deltas not costed | 4.4a | D6: after go-live unless Ramin picks a fix |
| Domain refusals logged at ERROR | 4.3c | **fixed in 5.5a** |
| Old `failed_jobs` rows on the live host | 4.4c | D7: the daily prune of rows older than 30 days (5.5a) |
| `FileUploadRaceTest` not built | 4.2a | **built in 5.2b** (the offline photo queue makes the race likelier) |
| Summaries of a payment changed on an earlier day follow at night | ADR 0014 | unchanged; after go-live |
| `analytics:bench` on the live host (optional) | 4.6b | unchanged; Ramin's optional run, listed in the handbook |

### 10.5 Device enforcement on the live host

Three states, each reversible by one line: (1) 5.1a to 5.6b: code default off, live `.env` silent, CI e2e on; the live tablets start
sending `X-Device-Id` on every request from 5.1b and the admin can enrol them as soon as 5.1b is deployed. (2) Ramin enrols every tablet
(the System page shows how many are enrolled and seen) and signs `devices-enrolled`. (3) A one-line PR turns the code default on; the
deploy's `@live` suites already send enrolled ids. Kill switch: `IDENTITY_REQUIRE_DEVICE=false` in the server `.env`, then
`php artisan config:cache` (the runbook's kill-switch section gets this fourth line).

### 10.6 New permissions and how they reach the live database

`devices.manage` (admin), `devices.self` (every role), `print.create` and `print.view` (admin, manager, cashier), `system.view` (admin),
each added to the registry and granted by a thin `grant_phase_5_*` migration (M2a, M2b, M2c) at the deploy of its step, before any screen
uses it; never revoked. The three device routes move from `people.manage` to `devices.manage`, which admin holds, so nobody loses access.

### 10.7 The live demo and E2E branches

The live E2E branch gets one device per role (`e2e-live-<role>`), enrolled by the live suites through the API and reused on every run;
no bridge, no print job reaches a real printer (the live suites never ask for one). The live "Demo Bakery" is untouched.

### 10.8 Other assumptions (safer defaults)

The bridge's job expires after 15 minutes and is reclaimed after 60 s without an answer; a print request on an unpaid order is refused;
the outbox photo queue caps at 50 MB; the System page polls at 30 s; `last_seen_at` is written at most once a minute; the stale-backup
alert fires at 26 h; ESC/POS Latin text mode is the fallback when the host's GD has no FreeType.

### 10.9 Where the phase reference and manifest conflict with the live host or with Phase 4 as built

1. **"Nightly `pg_dump` container to object storage"**: MariaDB and no Docker on the host, so `mysqldump` from the scheduler to the
   account's disk; object storage is Q2. The CI drill restores on the compose MariaDB.
2. **"Restore drill into a scratch DB"** on the host needs a second database (Q8); until then the CI drill is the proof and the live
   drill reports `skipped`.
3. **k6** runs only in CI (`perf-load`); the live host is described by `PollBudgetTest` and Phase 4's `analytics:bench`.
4. **Sentry** is built and off (Q1). **Laravel Pulse** is not installed (D12).
5. **"WS devices"** on the status page becomes devices seen in the last 15 minutes (polling, ADR 0009); `e2e-p5`'s `full,ws` becomes `full`.
6. **"Small local ESC/POS bridge"** becomes a bridge that pulls over HTTPS: a page served over HTTPS cannot call a printer on the shop's
   network (mixed content, Chrome's private network rules), and the host cannot reach into the shop. The local mode remains for a till PC.
7. **"Admin enrolment UI"**: the `devices` table and its three routes exist since Phase 0; Phase 5 adds enforcement, kinds, last seen,
   the screens.
8. **`i18n-check.mjs --strict`**: the repository's check is `check_invariants.py --i18n --strict-i18n`, already strict since phase 5
   became current; the skill's `ci-cd.md` row is corrected in 5.4a.
9. **`phases.5.adrs` is empty**: 5.1a lists 0017 to 0021. **`gate_check` does not read a checklist**: 5.6a. **`e2e-p5` after `e2e-p4`**:
   beside it (D17). **`extra_ci_jobs`**: `perf-load` and `print-bridge` added. **A third Playwright project** `p5-amharic` (5.4a).
10. The reference's Idempotency trap ("Redis `SET NX`") is the database cache lock on the live host, as built since Phase 0.

## 11. Risks from `references/traps.md` that apply

- **Playwright and service workers**: only `p5-offline` allows the worker; a precache from an earlier run must not leak into another
  project (one context per test, the worker unregistered in teardown).
- **Browser storage is not forever**: Safari evicts script-written storage of a site not added to the home screen after 7 days without
  use, and any browser may evict under storage pressure; a lost device id makes the tablet "not enrolled", a lost outbox loses unsent
  writes. Mitigations: `navigator.storage.persist()` on start, the app installed to the home screen (handbook), the pending count always
  visible, the not-enrolled screen telling the admin what to do.
- **The live host under load**: new polls (the bridge, the System page) and per-request writes (`last_seen_at`) on a CPU-limited host;
  every one is conditional or debounced, and `PollBudgetTest` keeps the sum visible.
- **Settling ETag**: the System page and the print-job list are conditional reads; tests that expect a 304 travel past the 5 s window.
- **One query key, one shape**: the Devices screen, `GET /devices/this` and the System page each get their own key.
- **Static export record pages**: Devices and System are fixed routes; no dynamic segment.
- **Login rate limit**: the fixtures enrol devices with the admin token already cached for the run (`E2E_TOKEN_FILE`), not a new login.
- **Backups on a shared host**: `mysqldump` must carry the triggers (the append-only rules live there), restore as the same account user
  (trigger `DEFINER`), and `log_bin_trust_function_creators` may matter when binary logging is on (provisioning step 5 of the runbook);
  the drill checks the triggers fire after a restore. A copy on the same disk is not a backup against losing the host (Q2). The disk quota
  is not reported by the host: the cap and the System page's disk figures are the guard.
- **GD without FreeType** on the host would break the Amharic raster: the doctor line and the Latin fallback.
- **Driver checks**: the dump tool may be `mysqldump` or `mariadb-dump`; the command looks for both, and every SQL branch uses
  `in_array($driver, ['mysql','mariadb'], true)`.
- **Seeds**: `Phase5Seeder` writes no ledger rows; the `e2e-p4` 60-day seed is watched against its 10-minute budget (7.1).
- **The sign-off guard has a limit**: everything on this PC acts as Ramin, so the web-editor check and the rule are what keep an agent
  from signing; the gate report states this plainly.
- **Phase scope creep**: COGS, a server-side date preference, refund overrides and email stay out (10.4); anything else found during the
  phase becomes a line in the report's "after go-live" list, never a silent addition.
