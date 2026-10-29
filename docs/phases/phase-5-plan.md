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

The two red lines are the ones this commit answers (the plan, and `plan_approved` under the standing approval). The run after the
commits is pasted at the end of this section by the same PR.

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
| Print jobs (Payments) | Payments | the receipt view of 4.3e (`Receipts::forOrder`), `receipt_settings` | Payments' module tests (same module, no contract) |
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
| `ops->shifts` (new) | `apps/api/tests/Contract/Ops/ShiftsContractTest.php` | `HashChain::verifyAll($connection)` (a new method beside the existing per-branch verification) answers `ok` on a seeded chain, names the first broken link when a locked count's stored hash is changed on the scratch copy, and reads only the named connection |

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
whole admin surface (catalog, people, devices, analytics, audit, settings, channels), the public signed routes. `StationRoutesTest` fails
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
They are replaced by real specs in 5.1b and 5.2a; the 5.6b gate test fails on any `fixme` left under `e2e/p5`.

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
     not-enrolled screen on the pad, the KDS and the till while the same tablet's admin pages work; the admin enrols it in place and the
     pad works after a reload; after a revoke the next request shows the screen again; a device of another branch is refused. Live: the
     flag stays **off** (the live `.env` sets nothing) until Ramin's "every tablet enrolled" sign-off (10.2 D9); `@live` keeps passing.
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
     `context.setOffline(true)`, send an order, reload the page while offline (the shell is precached by `sw.js`), go online, and the API
     holds **exactly one** order with that `client_uuid`; the answer to a send is dropped by `page.route` after the server committed it and
     the replay still yields one order (idempotency proves it). API: a replay-after-expiry case beside `IdempotencyCoverageTest` (a key
     older than the retention is gone; the `client_uuid` unique index still refuses a second order and the answer names the existing one).
   - **5.2b `phase-5/photo-queue`**. A receipt photo taken offline is shrunk in the browser as today, kept as a blob in IndexedDB with
     its `client_uuid`, uploaded first when online (`POST /files`, multipart, replay-safe since 4.2b), and the payment that waits on it is
     sent with the file id; a quota guard refuses a new photo when more than `NEXT_PUBLIC_PHOTO_QUEUE_MB` (50) waits, and says so; a photo
     whose payment was abandoned is dropped locally (the server already purges orphans after 24 h). `p5-offline` spec: offline, pay by a
     channel that needs a photo, attach it, go online: one file, one payment, the photo readable through its signed URL.
   - **5.2c `phase-5/offline-conflicts`** (`web.offline` `done`). The **conflict tray**: every item refused on sync (a stock shortfall
     `orders.stock.*`, `inventory.shift.required`, `payments.shift.required`, a closed order, a revoked device) stays visible with the
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
     print endpoints of 4.3, the claim lock, the 60-second reclaim and the 15-minute expiry (scheduler), `PrintJobFailed`. A line in
     `receipts:doctor` reports `FreeType Support` from `gd_info()`; when it is missing the raster falls back to the printer's own Latin text
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
     runs under `p5-device` (its `testMatch` widens to `/device|print/`: a till is a device), with a fake bridge in the test process against
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
     same read of the public health route the deploy smoke makes. `e2e/live/ops-live.spec.ts` (`@live`, run by the deploy as the E2E
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
     every verifier type resolves, no `fixme` under `e2e/p5`; the `gate-p5` job of section 7 runs the group; ADRs 0017 to 0021
     **accepted**; `docs/phases/phase-5-report.md`; the skill copies (`ci-cd.md`, `testing-strategy.md`, `traps.md`) updated with what the
     phase taught. Then `gate_check --mode done --phase 5` passes every automated row and lists the sign-offs still waiting: **claim-done
     waits for Ramin** (10.3). When his "every tablet enrolled" sign-off lands, a one-line PR flips the device-enforcement default to on
     (the pattern of 3.7e), with the kill switch in the runbook.
