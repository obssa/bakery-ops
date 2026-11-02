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
   - **As built (step 5.2b, `phase-5/photo-queue`).** Tests red first (the five photo-queue cases of `offline-logic.spec.ts`, the
     `p5-offline` photo flow, `FileUploadRaceTest`), then the API fix, then the web. No route, permission, request rule, OpenAPI or ERD
     change. Where the code chose what the plan left open:
     - **The store**: `bakery-ops` version 2 adds the object store `photos` (key `clientUuid`; a record keeps the shrunk blob, its size,
       the upload's Idempotency-Key fixed when the photo was taken, the account, the time, and the file id once it is up). `upgradeDb`
       creates only the stores that are missing (it looks at the stores, not at the old version: a database first opened without a version
       is version 1 with no store), so a tablet's queued writes are kept. Without IndexedDB, or when a write to it fails, the photos live in
       the tab's memory, as the queue then does.
     - **Every photo is kept first** (`keepPhoto`), online too. Online it is still uploaded at once (`uploaded`); offline, or when its
       upload gets no answer or a 5xx, it stays `kept`, which completes its row: the till block is lifted. A 4xx makes the row need
       another photo (the "Try again" button is gone: what it retried is now `kept` and goes with the payment).
     - **One upload write per batch**: Complete queues `upload_photos` (body `{photos: [client_uuid, ...]}`, no group) for the batch's kept
       photos, then the batch with `depends_on` that write and `needs` as a **list**, one entry per photo
       (`{token: "{{photo:<client_uuid>}}", from: "files[client_uuid=<client_uuid>].id"}`); 5.2a's single `{token, from}` still works. The
       outbox sends each photo (POST /files, multipart) with its own client_uuid and key, writes the file id into the photo's record as soon
       as it is answered (a retry after a failure halfway sends only the rest), and answers `{files: [{client_uuid, id}]}`. Rows whose photo
       was uploaded online carry their file id as before.
     - **Refusals**: a photo's 4xx names it (`photo_client_uuid` added to the API's body); the upload and the batch become conflicts. A photo
       no longer on the tablet is refused on the tablet (`outbox.photo_missing`, status 0, both languages); `errors.ts` and the pay sheet
       show such a body's own message rather than "network". A screen waiting for a write refused through its dependency (the till, online)
       is given that refusal; the write, and the refused upload when nothing else waits on it, leave the queue, and the row's photo is
       cleared for a new one. `409 idempotency.in_flight` (a slow upload whose answer timed out here, a second tab) is now retried with its
       key instead of becoming a conflict.
     - **Quota**: `NEXT_PUBLIC_PHOTO_QUEUE_MB` (unset, or not a positive number: 50; not set in `deploy.yml`). A new photo is refused when
       the photos still waiting for their upload (no file id yet) and it would pass the limit; the row shows `till.photo_quota` with the MB
       waiting and the limit, in both languages.
     - **Abandoned photos** (`abandonedPhotos`): a photo that no queued write uploads (pending, or a conflict that may be sent again) and no
       open pay sheet of this tab holds (`holdPhotos`: the till's drafts) is dropped at once when it is let go of (a row's photo replaced or
       removed, a conflict dismissed, its upload accepted, its batch paid), and otherwise after 12 h (`ABANDONED_AFTER_MS`, swept at start:
       a sheet a reload or a closed tab left behind; the grace keeps another tab's open sheet safe). The server still purges uploaded
       orphans after 24 h.
     - **API**: `FileUploadRaceTest` (30 forked rounds, the second upload 0 to 580 ms after the first, one client_uuid, two keys) found the
       losing upload answered **404**: inside the controller's transaction, its `firstOrFail` read the snapshot taken before the winner
       committed. `Files::put` now inserts under a savepoint (PostgreSQL would abort the whole transaction) and reads the winner with a
       locking read; the controller audits, and answers 201, only for the upload that stored the file (the other gets 200). In Docker: 18
       rounds raced, 12 went one after the other.
     - **The flow** (`p5-offline`, not `@live`): offline, the whole bill on the run's wallet channel with its reference and the photo
       attached (kept; Complete enabled); IndexedDB holds the shrunk photo and the two writes, which survive a reload with the API
       unreachable; online, one POST /files (201), then one payment with that file, the order closed, the photo read through its signed URL
       at 1600 x 1200.
     - **Left for 5.2c**: the tray shows an `upload_photos` conflict with the photo it names; editing and resending a refused batch needs
       its photo, which stays on the tablet until the conflict is dismissed.
   - **5.2c `phase-5/offline-conflicts`** (`web.offline` `done`). The **conflict tray**: every item refused on sync (a stock shortfall
     `orders.send.shortfall` or `inventory.stock.insufficient`, `inventory.shift.required`, `payments.shift.required`, a closed order, a revoked device) stays visible with the
     server's message in both languages, what was attempted, and the actions the refusal allows (edit and resend as a new write with a new
     key, or discard); nothing refused is dropped silently. **Reconnect reconcile**: on `online`, and on visibility after a gap, the KDS
     replays `tickets?since=<cursor>`, the pad and till refetch their lists, and an item whose answer was lost is looked up (`GET
     /orders/by-client-uuid/{uuid}`, added only if 5.2a's replay case shows the existing reads cannot answer it). `p5-offline` specs: a
     stock conflict made while the pad was offline (another context sells the last unit) lands in the tray with the shortfall message and
     the order can be edited and resent; a KDS that was offline for a minute shows the tickets sent meanwhile after reconnect; two tablets
     offline at once replay without duplicates. ADR 0018 proposed.
   - **As built (step 5.2c, `phase-5/offline-conflicts`).** Tests red first (the tray's nine cases in `offline-logic.spec.ts`, and
     `offline-conflicts.spec.ts` in `p5-offline`), then the web. No API change: no route, permission, request rule, OpenAPI or ERD change.
     ADR 0018 proposed. Where the code chose what the plan left open:
     - **The tray** (`src/lib/outbox/tray.ts` pure, `components/ConflictTray.tsx`) sits in the shell where 5.2a's strip was, region
       "Changes not saved". One entry per refused write, with the writes it held back (`blockedBy`) under it; a held write whose refused
       write is gone is an entry of its own. The reason comes from the key: `shortfall`, `stock`, `shift` (`inventory.` and
       `payments.shift.required`), `closed` (`orders.order.not_open`, `orders.order.paid`, `payments.order.not_payable`,
       `state_machine.illegal_transition`), `photo` (`outbox.photo_missing`, or a body naming `photo_client_uuid`), `dependency`, `session`
       (an ended acting session) and `other`. Each entry shows what was tried, the server's `message_en` and `message_am` (and a
       shortfall's lines) in `lang="en"`/`lang="am"` spans, so the `p5-amharic` sweep stays clean, and a hint per reason.
     - **Actions**: the cart's sends (`send_order`, `replace_lines`, `send_existing`) get **Edit and resend**; a write refused for a shift
       or for stock gets **Send again** (a new id and Idempotency-Key, every `client_uuid` in its body new; the writes it held back wait
       for it again with their own keys); a closed order, a photo and a dependency only **Discard**, which every entry has. Discard drops
       the held writes too and passes their photos to `releasePhotos`. A refused photo upload is discarded and the payment taken again on
       the till; a batch refused for a shift after its upload was accepted is sent again with the uploaded file id.
     - **Edit and resend**: a cart still in memory is simply shown (the screen the write was queued on, pad or till). Otherwise it is
       rebuilt (`cartSourceOf`, `cartFrom`): after a shortfall, from the open order in the 409 body (same client_uuids and server id, the
       shortfall on its line, then PUT lines and send, each a new write with a new key); a refused new order as a NEW order with new
       client_uuids; a refused change without the order in its body after `GET /orders/{id}`. The rebuilt cart goes to the next person who
       opens the pad or till with an empty cart (or the same order). The cart's next send supersedes the order's refused cart writes (by
       its client_uuid, and the ones it was rebuilt from). The pad's own refusals are put on the cart once (no longer dismissed), so they
       stay in the tray until the order is sent again or discarded, online too.
     - **A device refusal** keeps every write pending, as in 5.1b; the tray counts them with the API's message in both languages ("Nothing
       is lost") and offers no action: the tablet is enrolled on the "not enrolled" screen below it.
     - **Reconnect** (`src/lib/reconnect.ts`): the `online` event every time, and visibility after 30 s or more hidden. The outbox resumes
       (and on `online` asks `GET /me` again, as before); the pad's order list and the till's queue are read again from the start
       (`LiveFeed.reload`: no cursor, no ETag, the answer replaces the state; a failed reload is retried by the next look). The kitchen and
       bar rails already replayed `tickets?since=<cursor>` through the poll transport (it looks again on `online` and on visibility), so the
       kitchen display flow tests behaviour that existed; it and the two-tablet flow were not run red (no stack on this PC).
     - **The lookup read was not added.** A create whose answer was lost is answered by its replay: the stored answer under its key, or
       after the key's retention the `client_uuid` (`IdempotencyReplayAfterExpiryTest`); `GET /orders` carries `client_uuid`. What the
       reads did not cover was a non-create replayed after its key expired: a `send_existing` or `cancel_order` tried before without an
       answer and refused as `closed` is looked up with `GET /orders/{id}` and settled when the order already shows it (sent, cancelled).
       Line actions are not looked up (there is no read of one line): they show in the tray with Discard.
     - **Left open**: the tray shows every conflict of the tablet, whoever is signed in (the queue is the tablet's; only the account that
       queued a write sends it); a rebuilt cart waits while the person's cart holds another order; discarding a refused send whose order
       the server holds open (a shortfall) leaves that order open, shown as not sent on the waiter's "My orders" with its Cancel.
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
   - **As built (step 5.3a, `phase-5/print-jobs`).** Tests red first (the encoder and its test printer, the golden receipts, then every
     route, the sweep, the migrations and the race), then the code, then the docs. Where the code chose what the plan left open:
     - **Migrations** `2026_11_11_100300_create_print_jobs_table` (M3; besides the plan's index, `(branch_id, order_id, id)` for the till's
       poll; `shift_instance_id` keyed to `shift_instances` like every shift stamp since 3.2a, which `ShiftStampForeignKeysTest` asks), `2026_11_11_100400_grant_phase_5_print_permissions` (M2b, `GrantRegistryPermissions`) and
       `2026_11_11_100500_add_paper_width_to_receipt_settings` (M4: `paper_width_mm` DEFAULT 80 with CHECK
       `receipt_settings_paper_width_check`, `print_logo` DEFAULT false). Live tables touched: none by M3 (new table), `permissions` and
       `role_has_permissions` by M2b, `receipt_settings` (one row per branch, filled by the defaults, no row updated) by M4.
     - **The bridge's token** is checked by Identity's `AuthenticateBridge` (alias `bridge`): `Authorization: Bridge <token>`, looked up by
       its sha256; 401 `identity.bridge.unknown` (no header, a bearer token, a wrong or deleted bridge's token) or `identity.bridge.revoked`.
       It sets the bridge's branch, puts the device (no user) on the actor and moves `last_seen_at` through `DeviceLastSeen` (once a minute),
       which is what `print.bridge_seen_seconds` (120) reads through the new contract `Identity\Contracts\Bridges::seenWithin`.
     - **The bridge's writes are idempotent by the job's state, not by `Idempotency-Key`** (that store is scoped to a user, and a bridge has
       none): the bridge that holds a job asking claim, printed or failed again gets the same answer and nothing changes. Every bridge write
       carries a `client_uuid` (the plan's `failed` row had none: it has one too), kept in the audit row's context. The four bridge routes
       are in `public_routes.php` (and `check_invariants.py`'s mirror) and in `non_station_routes.php`.
     - **Who writes**: the bridge's and the sweep's changes are audited as the person who asked for the print (`ActorContext::runAs` with
       the job's stamp) on the bridge's device (the note on ADR 0011): `print_job.create`, `claim`, `printed`, `failed`, `requeue`, `expire`.
     - **Claims**: a claim not acknowledged in 60 s may be taken over by any bridge at once (the scheduler on the live host ticks once a
       minute after a random sleep), counting as a new claim; after the third claim a failure report, or the sweep finding it silent
       (`failure_key unacknowledged`), fails the job and raises `PrintJobFailed` (payload job_id, order_id, order_number, failure_key,
       requested_by_user_id; no listener until 5.5c). A claim of a job older than 15 minutes expires it and answers 410; `next` never offers
       one. A printed, failed or expired job answers a claim with 409 `payments.print.already_claimed {status}`. `print:sweep` runs every
       minute (`withoutOverlapping`).
     - **The receipt** is `ReceiptLayout` (rows from the 4.3e view model) drawn by `ReceiptRaster` (Noto Sans Ethiopic from
       `resources/fonts`, the file `receipts:fonts` installs; DejaVu Sans and Bold from `vendor/dompdf/dompdf/lib/fonts`, config `print.fonts`)
       and encoded by `EscPos` (`ESC @`, `GS v 0` bands of 960 rows, `ESC d 4`, `GS V 1` partial cut, per copy). It names the **cashier**:
       the person who asked for the print (the one acting by short code first); the PDF and the customer's link still name nobody. The
       non-fiscal words are `receipts.non_fiscal` in both languages (Q9, awaiting `receipt-wording`). Without FreeType, or with
       `PRINT_RASTER=false`, `ReceiptText` prints the English rows in the printer's font (48 or 32 columns); `print:doctor` says which.
     - **Widths**: the claim's and `receipt.escpos`'s `width` is optional; left out, the receipt settings' paper (58 mm: 384, else 576).
       `receipt_settings.paper_width_mm` is not yet in `GET`/`PUT /receipt-settings`: 5.3c adds it with the screen.
     - **`GET /orders/{order}/receipt.escpos` is a station route** like `receipt.pdf` (a till PC); `GET /print-jobs` answers `{data: [...]}`
       with an ETag over the jobs' ids, states and attempts; `POST /orders/{order}/print` answers the whole job (202).
     - **Tests**: `ReceiptRasterTest` lives in `tests/Modules/Payments` (it needs the labels and font paths of the container, no database);
       the goldens are `tests/Fixtures/receipts/receipt-{80,58}-{am,en}.png`, drawn on CI's runner (Ubuntu 24.04, PHP 8.3, libgd 2.3.3,
       FreeType 2.13.2) and compared through `EscPosEmulator` within a two-dot neighbourhood, 0.5 % of the dots and 2 rows of height: the
       PHP image's bundled GD with FreeType 2.12 places glyphs a dot or two apart (0.4 % at most; at one dot it was 1.6 %, which failed the
       first CI run). `UPDATE_RECEIPT_GOLDENS=1` rewrites them, on CI's environment. `PrintClaimRaceTest` is in `tests/Gate` (`CommitsForConcurrency`,
       30 rounds, `Innodb_deadlocks` unchanged). ADR 0019 is written here (proposed), not in 5.3b.
   - **5.3b `phase-5/print-bridge`**. `tools/print-bridge/`: Node 22, plain JavaScript (ESM), no native modules, one dependency at most;
     a config file `bridge.config.json` (API base URL, token file path, the printer: `tcp` host and port 9100, a Windows shared printer
     name, or a device path; the width); it polls `GET /bridge/jobs/next` (3 s, 15 s after a quiet minute), claims, writes the bytes,
     reports `printed` or `failed` with a key, logs to a rotating file and never logs the token. Windows: `install-service.ps1` registers
     it with the built-in Task Scheduler (no third-party service wrapper). An optional local mode (`--serve 127.0.0.1:9180`) lets a till PC
     print its own receipts from `receipt.escpos` while the internet is down (10.2 D11). Tests (`node --test`): a fake API and a fake TCP
     printer (the emulator's rules ported to JS) record the bytes; paper-out and printer-offline paths; the back-off; no log line holds the
     token. CI job `print-bridge` (no Docker, about a minute). `docs/runbook/print-bridge.md`: install, enrol the bridge as a device of kind
     `bridge`, paste the token, test print, what each failure key means. ADR 0019 proposed.
   - **As built (step 5.3b, `phase-5/print-bridge`).** Tests red first (the emulator, the config, the log, the printer, the bridge against a
     fake API with 5.3a's rules and a fake TCP printer, the back-off, the local mode, the command line), then the code, the install
     script, CI and the runbook. No PHP and no route changed. Where the code chose what the plan left open:
     - **The program**: Node 22, ESM, no dependency at all. `src/main.js` (`--config`, `--serve`, `--check` one poll that claims
       nothing, `--test-print` a raster stripe and text with no API, `--version`; exit 0, 1 refused or not printed, 2 a wrong config),
       `bridge.js` (the loop), `api.js`, `printer.js`, `log.js`, `local.js`, `config.js`, `test-receipt.js`, `escpos-emulator.js`.
       `bridge.config.example.json` ships; `bridge.config.json`, `bridge.token` and `logs/` are git-ignored.
     - **Config**: `apiBaseUrl` is https (plain http only to loopback: the token never travels in clear); the token lives in
       `tokenFile`, never in the config, and is read again after a 401, so pasting a new token needs no restart; `printer` is `tcp`
       (host, port 9100, `statusCheck`), `windows` (the share name, written through `\\host\share` like `copy /b`) or `device` (opened
       without `O_CREAT`: a missing printer is `offline`, never a new file); `width` 576, 384 or null (the claim leaves it out and the
       receipt settings decide); `log` 1 MB times 5 files; `serve.origins`. Unknown keys are refused.
     - **The pace** (`PollSchedule`, tested with the production numbers; the integration tests scale every wait down): 3 s; 15 s once
       60 s passed since the last job (an outage is not activity); at once after a printed or failed job; 3 s after a skipped one (404,
       409, 410, 422, so a refused job cannot spin); errors 3, 6, 12 ... 60 s, a `Retry-After` honoured up to 5 minutes; a 401 waits 60 s.
     - **A job**: the claim carries a `client_uuid` and is asked again with the same one (up to 3 times) after a network error, a 5xx,
       a 429 or a challenge; the bytes are written once, under a lock the local mode shares; the report has its own `client_uuid`,
       asked again every 2 s until 55 s after the claim (printer timeouts: 5 s connect, 2 x 1.5 s status, 25 s write). A printed job
       whose report was given up is remembered for 15 minutes: offered again, it is claimed and reported printed without a second
       print. A `409 not_claimed_by_you` on a report is logged, not retried.
     - **Failure keys**: before a job a TCP printer is asked `DLE EOT 2` and `DLE EOT 4` (real-time queries, not part of the job's bytes:
       the fake printer answers them outside the emulator). Roll paper end or "stopped at the paper end" is `paper_out`, cover open
       `cover_open`, the error bit `unknown`; no answer within 1.5 s prints anyway (`printer.status_unanswered`, once). Refused, reset or
       timed-out connections and a missing share or device are `offline`; anything else `unknown`. A Windows share and a device file
       cannot tell paper out; the runbook says so and prefers `tcp`.
     - **The log**: `<ISO time> <LEVEL> <event> <JSON>`, rotated by size, written synchronously; every line is scrubbed of the secrets
       it knows (the bridge token; a local-mode user token while its request lasts) and of whatever follows `Bridge ` or `Bearer `.
       An event is logged once per change (an outage is two lines) and a poll with nothing writes nothing. The tests scan every log
       file and the console after runs with refusals, challenges, lost reports and failures.
     - **How the local mode gets a user token** (D11): it keeps none. The till page sends its own `Authorization: Bearer` and
       `X-Device-Id` with `POST http://127.0.0.1:9180/print {order_id, width?}`; the bridge forwards them to
       `GET /orders/{order}/receipt.escpos` for that one call, prints the bytes and forgets them. `GET /health` lets the page see it.
       Loopback only (`--serve` refuses any other address); CORS for `serve.origins` only, with `Access-Control-Allow-Private-Network`
       for Chrome's private network preflight; a Host header that is not its own is 403 (DNS rebinding). Answers: 200
       `{status: printed}`, 503 `{status: failed, failure_key}`, the API's 4xx `{status: refused, message_key}`, 502
       `{status: unreachable}`. It needs the API: it replaces the pull path when no bridge runs (ADR 0019 point 8), not the internet
       (the item above says "while the internet is down", which cannot hold while the server draws the receipt).
     - **Windows**: `install-service.ps1` (ASCII, for Windows PowerShell 5.1): an elevated shell, Node 22 or later, `--check`, `icacls`
       narrows the token file to SYSTEM and Administrators (by SID), the task `BakeryOpsPrintBridge` at start-up as SYSTEM
       (`-RunAs CurrentUser` at logon instead), no time limit, started again a minute after a failure (999 times); `-Serve`,
       `-SkipCheck`, `-Uninstall`.
     - **Cloudflare (Q10)**: a 403, 429 or 503 with `cf-mitigated: challenge` or an HTML body is `api.challenged`, backed off like an
       outage and logged once; the `User-Agent` is `bakery-ops-print-bridge/<version> node/<version>`, no cookies. The runbook shows
       the line and the skip rule for `/api/v1/bridge/` in the owner's account.
     - **The emulator**: `src/escpos-emulator.js` is the PHP one rule for rule (the same command names and refusal messages), with no
       Node-only import and `escpos-emulator.d.ts` for 5.3c's TypeScript (`package.json` exports `./escpos-emulator`).
       `tests/support/escpos.js` is a JS copy of the server's `EscPos` encoder.
     - **CI** `print-bridge` (needs `status`, phase 5 and later, 20 minutes): at most one dependency and no native module,
       `node --test --test-reporter=spec "tests/*.test.js"` (103 tests; a skip, todo or cancel fails; the counts go to the step
       summary), `install-service.ps1` parsed by `pwsh` and checked ASCII. It is a need of `gate-p5` in `ci.yml`, the skill's
       mirror, the manifest's `ci.gate_jobs` and `phases.5.gate.extra_ci_jobs` (`ci.module_jobs.print-bridge` has none), so the
       checklist's `print-emulator` row finds it. STATUS `print-bridge` is `done` here: 5.3c merged first (PR #102) and left it to
       this step. 5.3c's `e2e/p5/print.spec.ts` still checks the claimed bytes' framing with `e2e/support/escpos.ts`; decoding them
       with this emulator there is left to a later step (the four print flows stay as merged).
   - **5.3c `phase-5/web-print`** (`print-bridge` `done`). The till: Print after payment and on the order record; the job's state (queued,
     printing, printed, failed) by polling `GET /print-jobs?order_id=`; a failed or expired job, or `409 no_bridge`, shows the receipt link
     and its QR code (4.3e's signed link) for the customer to scan; receipt settings get the paper width. Playwright `e2e/p5/print.spec.ts`
     runs under `p5-device` (its `testMatch` widens to `/device|print|ops/`: a till is a device; `ops-live.spec.ts` of 5.5c joins it), with a fake bridge in the test process against
     the CI stack, checking the printed bytes with the JS emulator. The shop test is a human item of the checklist.
   - **As built (step 5.3c, `phase-5/web-print`).** Tests red first (`ReceiptSettingsTest` with the paper, `print-logic.spec.ts`,
     the four `print.spec.ts` flows, `pay-admin-logic`'s receipt form), then the API, then the web. Where the code chose what the plan
     left open:
     - **Print is online only**, against D10's "every station write without a PIN joins the outbox": a print the outbox sent when the
       line came back could reach the printer an hour later, in front of the next customer, and the API expires a job after 15 minutes
       anyway; the bridge needs the internet as much as the till, so a print queued offline would wait for both. Offline the button is
       an `OnlineOnlyButton` ("Needs a connection"), like the PIN actions. D11's local mode (5.3b's `--serve`) stays the way to print
       while the internet is down.
     - **Where Print lives**: in `ReceiptActions` (`components/till/ReceiptPanel.tsx`, the button and the state in
       `components/till/PrintReceipt.tsx`), so the pay sheet's receipt after payment, the order record and the refund dialog's receipt
       all print. One copy per press (the API's `copies` stays 1); the button is disabled while the latest job waits or prints, then
       reads "Print again". A new `Idempotency-Key` and `client_uuid` per press, kept only when the outcome is unknown (`keepsKey`).
     - **The job's state**: `usePrintJobs` (`lib/api/till.ts`) reads `GET /print-jobs?order_id=` with its ETag (the last answer is kept
       per order for the 304) and polls through react-query's `refetchInterval` only while the latest job is `queued` or `claimed`:
       every 2 s, every 10 s once a queued job has waited a minute (`lib/print.ts` `printPollMs`); a printed, failed or expired job stops
       the poll. The answer of the POST is put in the query at once, which starts the poll. Shown as "Waiting for the printer…",
       "Printing…" (claimed), "Printed.", or "Not printed: <why>" with the failure key's reason (`paper_out`, `offline`, `cover_open`,
       `unknown`, `unacknowledged`; another key reads as unknown) or "Not printed: the receipt waited too long" (expired).
     - **The fallback**: a failed or expired job this screen asked for, or `409 payments.print.no_bridge` or `not_paid` (whose API
       message is shown too), makes the receipt link once without a press (POST `receipt-link`, the same key as the copy button) and shows
       it with its QR code. An old failed job seen on the order record does not make a link by itself (a link is an audited write). The
       QR code is drawn by `uqr` 0.1.2 (MIT, no dependencies, pinned in the lockfile), error correction M, as one SVG path with a
       four-module quiet zone; the code shows whenever the link does.
     - **Paper width in the API**: `paper_width_mm` (58 or 80) and `print_logo` join `GET` and `PUT /receipt-settings` (the view, the
       defaults 80 and false, `ReceiptSettings::PAPER_WIDTHS`). In `PUT` both are optional: left out, the saved value stays (a till that
       cached the screen before 5.3c must not turn a 58 mm printer back to 80 mm; a first save without them keeps M4's defaults). 70,
       576 and words are 422. `docs/openapi.json` re-exported. The screen has a radio group (80 mm, the wide roll; 58 mm, the narrow
       roll) and the logo switch with a note that no logo can be added yet.
     - **The `p5-device` match**: the plan's `testMatch` `/device|print|ops/` matched the repo folder name (Playwright matches the
       absolute path, and CI checks out into `.../bakery-ops/bakery-ops/`, so "ops" took every spec in `e2e/p5`, 5.5a's `errors-on`
       among them). It is `/[\\/](device|print|ops)[^\\/]*\.spec\.ts$/`: the file name only (device, print and ops specs and their
       logic specs), the same line as 5.5c's.
     - **Tests**: `print.spec.ts` runs under `p5-device`, serial, CI stack only (skipped on live: the
       live E2E branch must not collect bridges). Its fake bridge (`e2e/support/bridge.ts`) is enrolled per flow and revoked after; the
       no-printer flow first revokes every bridge of the branch (a revoked bridge never counts as seen). 5.3b's JS emulator was not on
       main yet: `e2e/support/escpos.ts` checks the framing instead (`ESC @` first, `GS v 0` bands of one width, the `GS V` cut last,
       per copy) and the width (576 dots on 80 mm, 384 once the settings say 58 mm). When 5.3b lands, the spec switches to its emulator.
     - **STATUS**: `print-bridge` stays `in_progress` until 5.3b merges.
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
   - **As built (step 5.4a, `phase-5/i18n-checks`).** Tests red first (`LangParityTest` with its planted fixture, the scripts' fixture
     tests, the lint rule's cases), then each piece, then the sweep. No API change: no route, permission, registry, OpenAPI or ERD change.
     Where the code chose what the plan left open:
     - **`LangParityTest`** compares through `tests/Support/LangParity.php` (files, keys at every depth, shape, empty or `__TODO__`
       values, `:placeholders`); `tests/Fixtures/LangParity` plants one gap of each kind. The 490 API messages already agreed.
     - **The lint rule** is a short local one, not `react/jsx-no-literals`: `local/no-literal-text` (`apps/web/eslint-rules/`) reports a
       letter of any script (an Amharic literal is as wrong on the English screen) in JSX text, in a string or template placed as a child
       (also through `?:`, `&&`, `||`, `??`) and in the attributes a person reads or hears (aria-label, title, placeholder, alt);
       punctuation, digits and templates whose letters all come from expressions pass. `jsx-no-literals` flagged only punctuation (`·`,
       `—`, `×`) and missed `{"text"}` unless it flagged every template too. Nothing in the 220 source files broke it; its `node --test`
       cases run in `web-lint`.
     - **The scripts** share `scripts/i18n_common.py`, which reads the PHP files with a small tokenizer (no PHP on the host) and rewrites
       only the corrected string literal. Keys are `web:<path>` and `api:<file>.<path>`. The sheet is UTF-8 with a byte-order mark (a
       spreadsheet opens the Ethiopic script as it is); exported again it keeps each row's correction and comment. The import checks the
       whole sheet first (the key exists, the correction names the English placeholders, no `__TODO__`) and writes nothing when a row is
       wrong. The report's "unused" list is a heuristic (a key's path or last part as a string in the code, or a template or a dotted
       string joined to a variable that starts it), so it lists, never deletes; 5 keys today for 5.4b to check by hand. Fixture tests
       and the report run in `i18n-check`.
     - **The glossary** flags refund (ተመላሽ on lists, ገንዘብ መመለስ on the buttons and the refund screen), void (ውድቅ on the screens,
       ስረዛ in the API messages; ውድቅ also names a rejected count or transfer, ሰርዝ a cancellation) and batch (ዙር, ምርት, በጅምላ).
     - **`p5-amharic`** runs in the seeded demo bakery of the CI stack (orders at every stage, shifts and history, names typed in
       Amharic), not the E2E branch, on a tablet enrolled there with `freshDevice` and opened with `openAs`; CI only. Each role's screen
       list is in the spec (a screen a role may not use still shows its Amharic refusal); on each screen it follows one link with a query
       to a screen of the same list (an order's record, an item, a product's dashboard, a shift's replay), and the waiter's pad is
       unlocked with a short code the demo admin resets. The sweep (`e2e/support/amharic.ts`) reads text nodes and the read labels; the
       allowlist is D19 plus the file formats and codes the dictionary keeps (`ETB`, PDF, CSV, Excel, JSON, JPEG, PNG, WebP, SKU, TIN),
       hex digests (a count's chain hash: a number in base 16, at least eight hex digits with a digit among them), the typed names in the
       form an Amharic screen shows (`*_am` and the single-language fields such as a payment's reference number, never `*_en`) and the
       devices' short ids, matched as whole words, text inside an element marked `lang="en"` (the language switch, a row's
       other-language name) and machine identifiers marked `translate="no"` (the audit log's action codes and changed fields, a pattern
       example). `amharic-logic.spec.ts` proves it on planted pages. The report gained a fourth list, Latin words left in an Amharic
       text (12 today, all codes in technical messages: HTTP headers, field names, regex parts; for the reviewer, not a gate).
     - **Gaps fixed** (the first CI run listed 104 on the admin's screens, the same kinds for the manager, cashier and chef): the cash
       tally's Latin `x` is `×` in both languages; an order with no number read "ትዕዛዝ null" on the kitchen rail and the orders board
       (`orderNoText` gives a dash, as the payments list already did; why a numberless order reaches the rail of the seeded bakery is
       left to the orders owner); three device texts of 5.1b named the app "Bakery Ops" in Latin (now ቤከሪ ኦፕስ); the other-language
       name of a variance reason and of a payment channel, and the English receipt fields, carry `lang="en"`; the pattern hint's example
       is `<code translate="no">` through `t.rich` (`Field`'s hint takes markup).
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
   - **As built (step 5.5a, `phase-5/ops-errors`).** Tests red first, then the code, then the docs. Where the code chose what the plan left
     open:
     - **The refusal classes are 15**, found by a scan rather than by name: the five the plan names (`PaymentError`, `OrderError`,
       `IdentityError`, Inventory's `InventoryError`, `PurchaseError`, `TransferError`, `ReorderError`, Shifts' `ShiftError`,
       `IllegalTransition`) and the others that answer a message_key the same way (`CatalogError`, `ProductionError`,
       `NotificationsError`, `AnalyticsError`, `AuditError`, `FilesError`). `DomainRefusalTest` pins the list and fails on any Throwable in
       `app/` that renders itself or carries a message key without the marker. The marker carries two methods, `messageKey()` and
       `httpStatus()`: `FilesError` can also carry a 500 (`processing_failed`) and a 503 (`unavailable`), which stay reported at error.
       Internal control-flow exceptions (`SendRefused`, `SentMeanwhile`, the `Already*` ones) are caught where they are thrown
       (`SendRefused` becomes the controller's 409 shortfall answer), and the append-only guards are real failures: neither is marked.
     - **The info line** is written where Laravel decides not to report (`dontReportWhen`, `App\Shared\Observability\RefusalLog`), so it
       covers HTTP, queue and console alike: `refused: <key>` with `message_key`, `status`, `class`, no trace.
     - **Sentry 4.29.0** (`sentry/sentry` 4.34.0) is kept out of package discovery and registered by `SharedServiceProvider` only with a
       DSN (`App\Shared\Observability\ErrorTracking`): without one there is not even a client. Only the error provider, never the tracing
       one. `bootstrap/app.php` hands what is reported to `ErrorTracking::capture`, which returns at once without a provider. Only
       `App\Shared\Observability` may use the SDK (`ModuleBoundariesTest`). The identity routes whose bodies are dropped are matched by
       path (`login`, `logout`, `me`, `short-code`, `users`, `stations`, `devices`, `broadcasting/auth`).
     - **The fake ingest** is the SDK's own `http_client` option (`Tests\Support\FakeSentryIngest`), so the event goes through the real
       client, `before_send` and serialisation, and nothing leaves the process. The secrets in `ErrorTrackingTest` are written in halves:
       Sentry sends the source lines around in-app frames (the repository's code), which would otherwise carry them.
     - **The web SDK is `@sentry/browser` 11.7.0** (the newest release at least two weeks old). SDK 11 replaced `sendDefaultPii` with
       `dataCollection`, set to collect nothing but the browser's name; the session and client-report envelopes are off, and an API
       refusal (4xx) or a lost connection is never sent. The proof is a CI job of its own, **`web-errors`** (needs `web-build`, 20 min, beside
       the e2e jobs): `p5-errors-off` on web-build's artifact (no SDK in `.next/static`, no request but the page's and the API's when a page
       fails, and the scrubber's logic spec), then a build with a made-up DSN (the SDK must be found by the same grep, so the negative
       check proves something) and `p5-errors-on` (a `page.route` sink gets the error, scrubbed). `web-build` also greps the static export.
       `deploy.yml` passes the repository variable `NEXT_PUBLIC_SENTRY_DSN` to the export build (unset today).
     - **The prune** runs daily at 04:10 Addis Ababa time (after the 03:00 chain check), and `.env.example` names both DSNs, empty.
     - **The test process may now use 2 GB** (`phpunit.xml`, was 1 GB). `api-arch` runs some 3 400 tests in one PHP process that keeps
       every test case and the arch plugin's parsed sources; with this step's ~70 tests it ran out three times in the route matrix, near
       the end of its run, even after the new tests were made leaner. Measured locally, the step's runtime wiring costs the same memory
       per test as main (the matrix grows about 64 KB a test either way), so this is the suite's size, not a leak of 5.5a. For 5.5d, which
       sets the jobs' limits: split `api-arch`'s groups over two jobs if it nears 2 GB.
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
   - **As built (step 5.5b, `phase-5/ops-backups`).** The two contracts red in the first commit, then their providers, then the ops tests
     red, then `App\Ops`, then the CI job and the docs. Where the code chose what the plan left open, or differs from the text above:
     - **The contracts' scratch copy** is a second database on the test server, `bakery_scratch` (`SCRATCH_DB_DATABASE`), created and
       granted by `scripts/ci/mysql-prepare.sh` in the module jobs and by `docker/mysql/init.sql` on a fresh compose volume (with
       `bakery_drill`). `Tests\Support\ScratchCopy` migrates it when its migrations differ from the test database's (once per process),
       copies the test's rows into it with its triggers dropped for the copy and put back as SHOW CREATE TRIGGER printed them, and plays the
       tamper behind the triggers' back on it. `LedgerOracle::check` returns `{ok, items, locations, mismatches}`, each mismatch
       `{source, branch_id, item_id, location_id, movements: {on_hand, reserved}, recorded: {on_hand, reserved}}`: the movements are added
       up in SQL by `MovementReason`'s own rule and compared with `stock_balances` and with the restored `item_stock_view` separately (a
       view restored with another definition is caught). The reading is `Inventory\Ledger\Reconciliation` behind the contract, because
       only the Ledger may name the stock tables in `app/` (`LedgerIsSoleWriterTest`); for the same reason the drill's list of
       append-only tables is `config/backup.php` `append_only_tables` (kept equal to `scripts/check_invariants.py` by
       `DrillAppendOnlyListTest`), not a constant of `App\Ops`. `HashChain::verifyAll` returns `ChainSummary` `{ok, places, links, first_broken,
       failures}`; it runs `verify()` over every place of every branch with the default connection pointed at the named one for the call
       and put back, with the current branch, afterwards (a console-time read: the payload rebuild reads through the models).
     - **M5 is `2026_11_11_100600_create_ops_runs_table`** (100300 to 100500 left to 5.3a's M3, M2b and M4, 100700 and 100800 to M6 and
       M2c). `ops_runs` is in `BRANCH_ALLOWLIST` with its reason. `AppendOnlyTest` has no "system tables" set and `ops_runs` is not
       append-only (a run moves from `running` to its result), so nothing was added there.
     - **`App\Ops`** (`app/Ops`, `OpsServiceProvider`): `Backup\BackupRunner`, `MysqlClient` (the clients run by `proc_open` without a
       shell, the dump gzipped as it streams, the option file and the client's stderr in the backup folder, deleted in `finally`; the
       client's error text goes to the log only), `Manifest`, `BackupFolder`, `FilesSnapshot`, `Retention` (pure), `Offsite`, and
       `Drill\DrillRunner`. Failure keys are listed in the runbook. The manifest carries, beside the plan's fields, the triggers of the
       database and `rows_after`: the counts are read just before and just after the dump, and a restored count must lie between them.
       A dump without a good manifest is deleted at once by retention; the cap counts the dumps and manifests (the hard links are free).
       `BackupFailed` and `DrillFailed` are plain host-wide events in `App\Shared\Events` (no `branch_id`, so not `DomainEvent`s); no
       listener yet (5.5c's `NotifyOps`).
     - **`backup:drill --keep`** leaves the restored copy: the CI job reads it after the drill, and the runbook uses it to look at old data.
       The drill refuses the app's own database by name before restoring and again before emptying. It probes a trigger only where it
       exists (a missing one is reported once, as missing) and only on a table with a row.
     - **CI `backup-drill`** (needs `status`, `docker-build`, `mod-inventory`; 25 minutes): the `ops` group (tests/Ops, the real clients;
       only this job runs it, inside the API image, which now installs Debian bookworm's `mariadb-client`, MariaDB 10.11), the seed, then
       `backup:run` and `backup:drill --keep`, then **one Pest process with `--group=gate:p0 --group=gate:p5:restored`**. The group of
       `RestoredLedgerGateTest` is `gate:p5:restored` (as sections 7 and 7.1 say), not `gate:p5` (as the item above says): the gate-p5 job
       runs `gate:p5` on a stack without a restored copy. Phase 0's gate tests keep `RefreshDatabase` on `bakery_test` (phpunit.xml pins it)
       of the same server, so `GATE_SEED_DATABASE=bakery_drill` serves `RestoredLedgerGateTest` only; that test runs the restored copy
       through both ledger oracles (Inventory's and the suite's own `ledgerOracle()`), the chains, a trigger, new Ledger movements in a
       rolled-back transaction, and compares every table with the source (`GATE_SOURCE_DATABASE=bakery`; the ledger, chain and money
       tables row for row).
     - **Live**: no new cron line (the existing `schedule:run` entry runs both commands); `BACKUP_DRILL_DATABASE` waits for the scratch
       database Ramin creates in cPanel (10.2 Q8); the off-site hook waits for 10.2 Q2. Nothing was run against the live host.
   - **5.5c `phase-5/ops-status`**. M6, M2c. `GET /system/status` and the admin **System** page (polls every 30 s, conditional):
     scheduler heartbeat, queue depth and the oldest job's age, failed jobs, last backup and drill, the disk used by the files and the
     backups, devices enrolled and seen in 15 minutes, the rollout flags, whether error tracking is on. The hourly `BackupStale` check.
     `.github/workflows/uptime.yml`: every 15 minutes GitHub's scheduler sends one `GET /health` to the live URL (the existing repository
     variable, not written in the file) and fails when it is not `ok` twice in a row, which emails the repository owner (10.2 D14): the
     same read of the public health route the deploy smoke makes. `e2e/p5/ops-live.spec.ts` (`@live`, project `p5-device`, run by the deploy as the E2E
     admin): the System page renders and the scheduler ticked in the last 3 minutes; once backups have run, the last good backup is
     younger than 26 h and the last drill younger than 8 days and `ok` (until the first drill has run the spec reports "not configured
     yet" without failing, then it enforces).
   - **As built (step 5.5c, `phase-5/ops-status`).** Tests red first, then M6, M2c and the listeners, then the status read, then the page,
     then the uptime workflow and the docs. Where the code chose what the plan left open:
     - **M6 is `2026_11_11_100700_extend_notification_types_for_ops`** (the CHECK only: the sixteen types before it and the four new ones; its
       `down()` deletes the four types' lines first) and **M2c `2026_11_11_100800_grant_phase_5_ops_permissions`** (`system.view`, admin).
       `OpsMigrationsTest` never runs M6's `down()`: DDL commits on MariaDB inside the test's transaction.
     - **Who hears the host's alerts.** `BackupFailed`, `BackupStale` and `DrillFailed` have no branch, so `NotifyOps` tells the active admins
       and holders of `system.view` of **every** branch, each in their own branch's inbox, running as nobody (on the live host that includes
       the admins of the demo and E2E branches). Severity critical, channels `in_app` and `telegram` (DriverRegistry sends by Telegram only
       with the driver on and its keys set), inbox keys `backup_failed:{run}`, `backup_stale:{run}`, `drill_failed:{run}`; every line opens
       `/admin/system`. The failure key is written as it is in the body: it is the line of `backup-restore.md` that says what to do.
     - **The stale check.** `backup:check-stale` (`App\Ops\Backup\StaleCheck`), hourly at :15 with `withoutOverlapping`, reads `ops_runs`
       only: stale once the newest `ok` backup finished more than `backup.stale_hours` (26, in `config/backup.php`, no env) ago. The stale
       period is named by that run (`run_id`), or 0 while no backup has ever ended ok, counted from the first backup tried; before any backup
       was tried nothing is stale (the heartbeat shows a scheduler that never ran). The check raises `BackupStale` every hour the period lasts,
       and "once per period" is Notifications' delivery gate `backup_stale:{run}` (the stock alerts' one-insert claim, ADR 0011) with the
       inbox key: a job lost in the queue heals within the hour and nobody is told twice. Its key `backup.stale` is in the runbook.
     - **"The managers on shift"** for `NotifyPrintFailed`: the managers among `Recipients::onDuty` of every station of the branch (the
       running shifts' rosters), or every active manager when none is on duty, besides the cashier who asked (`byIds`). `PrintJobFailed`
       carries no station and Notifications reads nothing of Payments, so it is not limited to the till's roster. `print_failed` is
       warning, inbox only, `print_failed:{job}`, opens `/till`; the reason is worded (`notifications.print_failure.*`; a key Payments adds
       later reads as "a problem"); a job without an order number gets the unnumbered title.
     - **`GET /system/status`** (`App\Ops\Http\SystemStatusController`, `routes/modules/ops.php` loaded by `OpsServiceProvider`; registered
       with module `shared` in `route_permissions.php`, since App\Ops has no module job, and as a non-station route). The ETag is taken over
       the body (`ConditionalPoll::etag('system.status', ...)`): the body is a few indexed counts and a listing of some 30 files, and the
       heartbeat moves the tag about once a minute. Field by field (`App\Ops\Status\SystemStatus`): `app.commit` from the `REVISION` file
       (hex only), `released_at` its mtime, `migrations` the rows of the migrations table; `scheduler.ok` while the tick is at most 180 s old;
       `queue` reads the `jobs` table when the default connection's driver is `database` (pending, the oldest `created_at`), otherwise the
       driver's size and a null age, and `failed_jobs` for `failed_24h` and `failed_total` (30 days, D7); `backups.last_ok_at` and `bytes`
       from the newest `ok` backup run, `last_status` from the newest backup run of any status, `age_h` whole hours, `kept` the good dumps
       and `disk_used_mb` the dumps and manifests (the hard-linked snapshots cost nothing), read only when the folder already exists
       (`BackupConfig::existingFolder`: a read creates nothing); `drill.last_at` its `finished_at` (or `started_at` while running);
       `files.used_mb` the stored files of every branch (one disk), deleted ones left out; `devices` the branch's own through a new
       Identity contract, `DeviceCensus::count()` with its `DeviceCount` view (enrolled: not revoked; seen: not revoked and seen in 900 s;
       revoked); `flags` `require_enrolled_device`, `require_shift_for_cash`, `require_shift_for_station_locations`,
       `open_ad_hoc_on_accept`. Sizes are whole MiB rounded half up from integer bytes.
     - **The heartbeat** is `Schedule::call(new SchedulerTick)` every minute, named `ops.scheduler.tick`: a callback in `schedule:run`'s
       own process (no second PHP process a minute on the shared host) that puts the UTC time in the cache for a day.
     - **The page** (`/admin/system`, `RequireAuth` `system.view`, a System tab and an overview card for its holders): Scheduler, Backups
       with the restore drill inside it, Background jobs, Tablets and devices, Rollout switches, Release. `lib/system.ts` holds the reading
       the page and the specs share: `skipped` and no drill read "Not configured yet", an `ok` drill older than 8 days reads "old", a backup
       older than 26 h "Too old". The commit and any switch the page does not name yet are marked `translate="no"`.
     - **`uptime.yml`** reads `vars.DEPLOY_URL` with no fallback (the job is skipped while it is unset), with a cache-busting query like
       the deploy smoke, and checks `status` and `database` are both `ok`.
     - **`ops-live.spec.ts` also runs in CI's e2e-p5** (the `p5-device` project, now `/device|print|ops/`, runs its `@live` specs on the
       compose stack too): there the heartbeat is not enforced (that stack runs no scheduler); on the live site it is. The "not configured
       yet" cases are test annotations. `ops-logic.spec.ts` pins the reading.
     - **Live database.** M6 changes only the CHECK of `notifications` (no row is written or changed); M2c inserts the `system.view` row in
       `permissions` and its admin grant in `role_has_permissions`. `ops` stays `in_progress` until 5.5d merges; 5.5d merged first (8ec9452), so the merge of main into this branch sets `ops` `done`.
   - **5.5d `phase-5/perf-load`** (`ops` `done`). `scripts/perf/load.k6.js` on the CI compose stack (never the live host): 60 devices
     (34 pads, 12 KDS, 8 tills, 6 admin and shift screens) polling at the web app's own cadences with `If-None-Match`, and a burst of
     300 orders in 10 minutes, each sent, accepted, made ready, served and paid (a quarter by photo channels with a small fixture photo).
     Thresholds: polls p95 < 300 ms, writes p95 < 800 ms, errors < 0.5 %; afterwards **exactly 300 orders**, the ledger oracle `ok`, no
     `failed_jobs`. Job **`perf-load`**: needs `mod-analytics` and `docker-build`; `SEED_PHASE=5` with 7 days of history; the 60 devices
     enrolled by `Phase5Seeder` when `LOAD_DEVICES=60`; k6 in the pinned image `perf-dashboards` uses; timeout 30. `PollBudgetTest` (Pest,
     `gate:p5`) computes the requests per minute N devices make at the configured cadences, so the runbook can state what the shared host
     is asked for (12 tablets, 60 tablets) next to the load average it showed in Phase 4.
   - **As built (step 5.5d, `phase-5/perf-load`).** Tests red first (`PollBudgetTest`, the load-device cases of `Phase5SeederTest`),
     then the profile and the seeder, then the script and the job, then the docs. Where the code chose what the plan left open:
     - **The cadences are one file**, `apps/api/tests/Fixtures/perf/poll-profile.json` (inside the image, so `gate:p5` can run it
       there): per kind of device, the feeds of its screen, each with its interval and the web constant it comes from;
       `scripts/tests/test_poll_profile.py` (run by `i18n-check`'s unittest discovery) fails when `apps/web` changes one. Only visible
       screens with nothing failing are modelled. A hidden tab's slower cadence is a saving, not a load. The kinds: a pad (its orders
       2 s, the menu 5 s, the bell 15 s: 46 a minute), a KDS (tickets and stock strip 2 s, its shift 5 s, the bell: 76), a till (queue
       and display case 2 s, menu, production board, shift and cash 5 s, the counts' 15 s refetch and the bell: 116), the admin's
       orders board (34) and a shift screen (running shifts and counts queue 5 s, the bell: 28). The plan's "6 admin and shift screens"
       are 3 of each. Day one's 12 tablets are the same mix scaled: 7 pads, 2 KDS, 2 tills, 1 board. **740 requests a minute at
       12 tablets, 3 590 at 60** (runbook "Poll budget", beside the Phase 4 load average of about 10 from 10.1).
     - **`Phase5Seeder::LOAD_MIX`** orders the kinds the way the profile does (`load-01`..`34` tablets, `35`..`46` KDS, `47`..`54`
       tills, `55`..`60` tablets; any device past 60 is a tablet). Each device is enrolled through `EnrolDevice` as the demo admin
       (audited `device.enrol`, no station on the device), a fingerprint already enrolled is skipped, and `LOAD_DEVICES` outside 1-99
       is refused. With no `LOAD_DEVICES` the seeder still writes nothing. Its demo devices and receipt settings stay for 5.6b (a
       to-do marker of the phase in the class, gone since 5.6b).
     - **The burst** is one k6 iteration per order, every 2 s from 30 s in: a dine-in order of one toast slice (kitchen, from its
       stock) and one buna (bar, made to order), sent from a pad by one of the two demo waiters (who sign in themselves: the pad
       account's short code would add a write per order and is limited to 30 a minute). The chef and the barista accept the order and
       make it ready on their KDS devices (X-Station-Id set). The waiter serves it, and a cashier pays it at a till. Every fourth order
       is paid by Telebirr with a unique reference and `scripts/perf/fixtures/receipt.jpg` (10 KB, 360 by 480) through `POST /files`.
       The rest are paid in cash. Setup signs in seven accounts (the login allows ten a minute per address), starts any of the
       kitchen, bar and till shifts that is not running (carried, as the demo admin) and buys in 400 toast slices and 6 kg of ground
       coffee, so the burst never meets a shortfall. The polls stagger their first look within one interval and then poll one
       interval after each answer, as the web's poll transport does. A threshold `orders_paid == 300` and `dropped_iterations == 0` sit
       beside the plan's three. The arrival schedule stops at 598 s: a duration of exactly 600 s starts a 301st order, which the first
       run showed.
     - **The server**: the image's `artisan serve` answers one request at a time and, being the CLI, runs without OPcache. So
       `compose.perf.yml` starts PHP's built-in server on Laravel's own router file with `PHP_CLI_SERVER_WORKERS=8` and OPcache on
       (timestamps unchecked), and the job runs `php artisan optimize` before k6, as `scripts/deploy/release.sh` does on the live host
       (`optimize:clear` before the after-checks, so Pest reads `phpunit.xml`). The live host runs LiteSpeed, so the k6 figures
       describe the code under load and the poll budget describes the host.
     - **The after-checks** are the group `perf:load` (`tests/Gate/LoadRunGateTest.php`), run in the app container once the queue
       has drained (`Queue::size()` through tinker). The checks: exactly 300 orders after the id noted before the run, all closed and
       settled; 225 paid in cash and 75 by Telebirr, each with a photo; `Inventory\Contracts\LedgerOracle::check` (5.5b) reports ok
       on the stack's database, and `ledgerOracle()` of `tests/Support/helpers.php` (movements = balances = view) agrees; `failed_jobs`
       is empty. The worker starts after the seed so `migrate:fresh` never races it.
     - **Timeouts**: the 16 `ci.yml` jobs that had none (`status`, `api-lint`, `api-arch`, `invariants`, `openapi-check`,
       `erd-check`, `web-lint`, `i18n-check`, `web-build`, `docker-build`, the placeholder `backup-drill`, `gate-p0` to `gate-p3`,
       `gate-p5`) were given 20. The longest of them measured 7 minutes (`api-arch`, main run 41363303323). 5.5b's real
       `backup-drill` came with its own 25 and keeps it. The same was done in the skill's `assets/ci/ci.yml`, which also carries
       `perf-load`.
     - **STATUS**: `ops` stays `in_progress`, because 5.5c is not merged (5.5a and 5.5b are).
     - **Measured runs** (PR #99). Without OPcache or the caches: polls p95 206 ms on one runner and 351 ms on a slower one (that
       run failed the threshold; writes 265 and 393 ms, no failed request either time). With them (run 41388083454): polls p50 65 ms
       and p95 171 ms, writes p50 92 ms and p95 230 ms (the slowest step, the send, 297 ms), no failed request of 44 233 (61.2 a
       second, against the profile's 59.8 for polls alone), 300 orders closed (225 cash, 75 Telebirr with a photo), the ledger
       reconciled over 125 item-locations, no failed job. The job took 14.5 minutes, with the seed of 8 days played in 52 s. The next
       full run (41396586731, after mod-analytics) landed on a faster runner: polls p95 45 ms, writes p95 64 ms, no failed request of
       45 132, the oracle ok over 57 items and 6 places, 14 minutes.
       **Left open**: how the poll threshold behaves across GitHub's runner generations. The 4-core runner is close to busy at 61
       requests a second; one slow runner moved p95 by 70 % and a fast one cut it to a quarter. If `perf-load` turns flaky, the choices are a larger runner (paid,
       Ramin's call) or fewer devices per job; the plan's thresholds are kept as written.
     - **For 5.6b**: `gate-p5` gains `perf-load` in its needs and the manifest's `extra_ci_jobs` lists it. `Phase5Seeder` gets the demo
       devices beside `LOAD_DEVICES`.
6. **5.6 docs** (STATUS `docs`), two PRs.
   - **5.6a `phase-5/go-live-checklist`** (`docs` `in_progress`). `docs/go-live-checklist.json` and its schema (10.3), the `gate_check.py`
     change that reads it (the repository copy and the skill's copy, kept identical; `scripts/tests/test_gate_checklist.py` with a fixture
     for every verifier type, run by the `invariants` job), `scripts/ops/check_branch_protection.py` (reads the required checks of `main`
     through the branches API, `GITHUB_TOKEN` in CI), `docs/go-live/README.md` and `docs/go-live/signoff-template.md` (10.3; corrected
     2026-11-02 in 5.6a: this line said `docs/go-live/signoffs/README.md` and `TEMPLATE.md`, inside the folder, which 10.3 keeps for
     the web editor alone, so no agent commit ever touches `signoffs/`). The **admin handbook**
     `docs/handbook/` (a README and one page per task: opening and closing the day, enrolling and revoking tablets, the printer and its
     bridge, refunds and voids, counts and handover, what the screens do offline and how to clear the conflict tray, the System page,
     backups and who restores them, changing a channel, reading the dashboards), in English with the Amharic page titles, linking the
     runbook rather than repeating it. **`docs/runbook/on-call.md`**: hours, the first checks (the System page, `/health`, the queue), the
     kill switches (shift rules, device enforcement), who is called in which order **by role** (names and numbers live outside the public
     repository), when to restore. A training plan (`docs/handbook/training.md`): sessions per role and what each must be able to do.
   - **As built (step 5.6a, `phase-5/go-live-checklist`).** Tests red first (`scripts/tests/test_gate_checklist.py`, 53 tests), then the
     scripts, the checklist, CI, then the docs. Where the code chose what the plan left open:
     - **The checklist** has the 24 items of the 10.3 table, each with `who` (`CI` or `Ramin`) and an optional `note`. An item's
       `verifier` is one object or a list that must all pass (`i18n-strict`: `ci_job` and `script`; `uptime-monitor`: `file_exists`
       and `ci_job` with `workflow`); the item's row takes the worst state. `docs/go-live-checklist.schema.json` is the JSON Schema;
       `gate_check.checklist_errors` applies the same rules with the standard library (the gate stays stdlib only). A schema error
       stops the item rows: one `checklist schema` FAIL row per error.
     - **`ci_job` with `jobs`** counts a job needed by the gate job directly or through the jobs it needs (manifest `ci.module_jobs`,
       `e2e_jobs`, `gate_jobs`: a job runs only after every need succeeded), and the job must also be defined in `ci.yml`. `self` passes
       only while the gate job's block in `ci.yml` runs `--group=gate:p5`, which 5.6b adds; until then `device-enforcement` and
       `print-emulator` say so.
     - **`ci_job` with `workflow`** asks for the workflow's successful runs on the branch created up to the reference time, newest
       first, and with a `job` its run's jobs, matched by id or by the display name read from the workflow file (`live-e2e` is
       "Playwright @live against the site"). A run whose job was skipped is passed over for an older one.
     - **Rows.** WAIT fails a local run and a strict CI run like FAIL. In the informational CI run (phase open) a checklist FAIL
       becomes `WARN` with `FAIL:` kept in its detail, and WAIT stays `WAIT` without failing the job. An optional item
       (`push-telegram`) that is not PASS is `WARN` ("optional, WAIT: ..."), never a blocker. Locally, `ci_job` rows are `SKIP` and do
       not block, so the claim can be made from the PC.
     - **Sign-offs**: the four fields are lines `field: value` (the template wraps them in `---`). A value still in angle brackets
       counts as missing. `item` must equal the file name. `date` is `YYYY-MM-DD` and not after today in Addis Ababa. Every commit
       that touched the file has the committer `GitHub <noreply@github.com>`, and an uncommitted file fails. D16's fallback (sign from
       the PC, fields only) is **not built**: nothing in the repository can tell that fallback apart from an agent's commit, so it
       waits for Ramin's choice.
     - **`phase_status.py claim-done N`**, for a phase whose gate names a checklist, runs the sibling `gate_check.py --mode done
       --no-tests --no-e2e` (the tests ran in the gate job) and refuses, listing its FAIL and WAIT rows, until it passes. A phase
       without a checklist is claimed as before.
     - **`check_invariants.py --signoffs`** is its own flag, not part of `--all`. It reads every commit that touched the folder
       (`git log`, default history simplification, so a merge that only brings a browser-made sign-off in from `main` is not listed),
       fails a shallow clone, and flags an uncommitted file there. The `invariants` job checks out with `fetch-depth: 0` and runs it
       and the new tests before the API setup. Only the repository copy of `check_invariants.py` changed: the skill's copy already
       differs (the Phase 4 tables and routes).
     - **`gate-p5` got its token in this step**, not in 5.6b: `permissions: { contents: read, actions: read }` and
       `GH_TOKEN: ${{ github.token }}` on the gate_check step, for `live-ops`, `uptime-monitor` and `branch-protection`. Its needs
       are unchanged: 5.6b adds `perf-load` and `print-bridge` once they exist.
     - **`check_branch_protection.py`** reads `.protection.required_status_checks.contexts` (and the `checks` contexts) from
       `GET /repos/{owner}/{repo}/branches/main`, through `GH_TOKEN`/`GITHUB_TOKEN` or the `gh` CLI. `--require` takes names, comma
       lists and ranges (`gate-p0..gate-p5`). `is_git_repo` in `gate_check.py` now accepts a `.git` file too (a linked worktree).
     - **Rows that fail informationally until their step lands**: `print-emulator` (`print-bridge`, 5.3b; and `self`), `load-test`
       (`perf-load`, 5.5d), `uptime-monitor` (`uptime.yml`, 5.5c), `restore-runbook` (`backup-restore.md`, 5.5b), `print-runbook`
       (`print-bridge.md`, 5.3b/c), `adrs` (0018 to 0020), and `device-enforcement` (`self`, 5.6b). `backup-drill-ci` passes as a need
       of `gate-p5` although the job is still the placeholder; the gate's phase-marker row (no `TODO(p` + `5)` left) catches the placeholder until 5.5b
       replaces it. `live-ops` depends on the deploy's `@live` run on `main` within 7 days.
     - **Handbook pages written from the plan**, each marked "Written from the plan; checked against the build in 5.6b": `printer.md`
       (5.3a to 5.3c), the conflict tray section of `offline.md` (5.2c), `system-page.md` (5.5c), `backups.md` (5.5b). The photo
       section of `offline.md` follows 5.2b as built (merged before this step).
       Their Amharic titles follow `docs/i18n/glossary.md`; the reviewer (Q5) may correct them. The on-call plan's hours and call
       order are a default by role (shift manager, shop admin, technical on-call, owner) for Ramin to change before he signs
       `on-call-agreed`.
   - **5.6b `phase-5/gate`** (`docs` `done`). `Phase5Seeder` (the demo branch: a pad, a KDS, a till and a bridge device enrolled, receipt
     settings with a sample TIN, `LOAD_DEVICES` for `perf-load`); `Phase5GateTest` (`gate:p5`): unknown device 403 on every station route
     with the flag on and no 403 on admin routes, an order replayed with the same key and `client_uuid` is one row, the checklist parses and
     every verifier type resolves; the `gate-p5` job of 7.1 runs the group; ADRs 0017 to 0021
     **accepted**; `docs/phases/phase-5-report.md`; the skill copies (`ci-cd.md`, `testing-strategy.md`, `traps.md`) updated with what the
     phase taught. Then `gate_check --mode done --phase 5` passes every automated row and lists the sign-offs still waiting: **claim-done
     waits for Ramin** (10.3). When his "every tablet enrolled" sign-off lands, a one-line PR flips the device-enforcement default to on
     (the pattern of 3.7e), with the kill switch in the runbook.
   - **As built (step 5.6b, `phase-5/gate`).** Tests red first (`Phase5GateTest`, the demo-device cases of `Phase5SeederTest`), then
     the seeder, then the job, then the docs. Where the code chose what the plan left open:
     - **`Phase5Seeder`** enrols `DEMO_DEVICES` through `EnrolDevice` as the demo admin (audited `device.enrol`): `demo-pad-1` (a tablet,
       no station), `demo-kds-1` at the kitchen, `demo-till-1` at the till and `demo-bridge-1`, whose token `EnrolDevice` makes and the
       seeder drops (it prints nothing). The receipts get `SAMPLE_TIN` (`0000000100`, ten digits, the same made-up value as Phase 4's
       demo receipts) and 80 mm paper **only when they have no TIN**, through `SaveReceiptSettings` with the rest of the settings kept;
       a TIN already typed is the shop's own and is left with its paper (section 8 says "sets"; after `Phase4Seeder` the TIN is already
       there, so a full seed writes nothing here). With `APP_ENV=production` it writes nothing at all, `LOAD_DEVICES` included (10.2 D5).
       5.5d's `LOAD_DEVICES` part is unchanged, the marker it left is gone, and a second run still writes nothing.
     - **`Phase5GateTest`** (`gate:p5`; each case also carries `module:identity`, `module:orders` or `invariants`, so a module job runs it
       an hour before the gate does): (1) with the flag on, every station route answers an unknown device and a missing header with
       `403 identity.device.unknown`, the admin included, and lets an enrolled tablet of the branch past the check; (2) every back-office
       route the admin may call (`non_station_routes.php`, admin among the route's roles in `route_permissions.php`), one case each,
       answers the admin with the flag on and an unknown device with the same status as with the flag off and no `identity.device.*` key,
       so the flag never adds a 403 there (a status is read with `getStatusCode()`: the exports answer a streamed response); (3) an order
       sent from an enrolled pad with the flag on and replayed three times with the same key and `client_uuid` is one order, the first
       answer each time, with no new stock movement or audit row; (4) the checklist parses (schema 1, phase 5, ids unique) and **every
       verifier type resolves**: it is one of `VERIFIER_TYPES` in `scripts/gate_check.py`, the schema allows exactly those, a `script`
       verifier's program is in the repository and a sign-off names its one file. Whether a named job or workflow exists is left to
       `gate_check`'s own rows, so the test never waited for `print-bridge` (PR #101) or `uptime.yml` (PR #103), both merged while
       this step was open.
     - **The `gate-p5` job** needs `status`, `gate-p4`, `e2e-p5`, `i18n-check`, `backup-drill`, `perf-load` and `print-bridge` (the
       last from 5.3b, kept by the merge of main; 25 minutes; 5.6a's
       `permissions` and `GH_TOKEN` kept). It loads the image, starts the stack, copies `docs/` and `scripts/` into the container (the
       image holds `apps/api` only) and runs `pest --group=gate:p5` there with `GATE_REPO_ROOT` naming the copy and
       `IDENTITY_REQUIRE_DEVICE=false` (the tests turn the flag on themselves), failing on no tests, a skip or a warning as `gate-p4`
       does; then `gate_check --mode done --phase 5 --ci`, whose checklist rows go to the step summary. No seed: the group's tests make
       their own rows. The manifest (`ci.gate_jobs`, `extra_ci_jobs`) and the skill's `assets/ci/ci.yml` match.
     - **ADRs 0017 to 0021 are accepted**, each with a note of 2026-11-02 on what changed from the proposal. The sign-offs their status
       lines named gate the phase's claim, not the decisions. **`docs/phases/phase-5-report.md`** reports the phase so far; the skill's
       `ci-cd.md`, `testing-strategy.md` and `traps.md` record the phase's lessons. `docs/handbook/backups.md` was checked against 5.5b
       and corrected (retention, what the drill checks, who restores); `printer.md` against 5.3a to 5.3c and the bridge's runbook (the
       button and its states, the setup through Add a print bridge, and that the local mode still needs the website, so nothing prints
       while the till is offline). ADR 0019's note covers 5.3b too. The 5.5d note above spelled the
       phase marker, which the done gate's marker row would count; it is reworded.
     - **Merges**: main was merged in twice, with ordinary commits: after 5.3b (`gate-p5` keeps both `perf-load` and `print-bridge`)
       and after 5.5c (ADRs 0017, 0020 and 0021, `backups.md` and the report then describe the System page, the alerts and the uptime
       workflow as built). Every build step is merged but this one; 5.4b waits for the reviewer.
     - **A trap met**: `Phase5SeederTest` first wrote `$devices->except('demo-bridge-1')` on a collection keyed by fingerprint. An
       Eloquent collection's `except()` takes model ids, not the collection's keys, so it removed nothing and `mod-shifts` failed (run
       41440931690). The test now lists the devices that hold a token and expects the bridge alone (D20).
     - **STATUS**: `docs` `done`, beside `ops` and `print-bridge` `done` from their steps; `i18n` stays `in_progress` (5.4b waits for the
       reviewer). The phase is not claimed and nothing is tagged: `claim-done 5` refuses while Ramin's sign-offs wait (10.3).

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
