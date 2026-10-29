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
| `GET /bridge/jobs/next` | bridge token (`Authorization: Bridge <token>`, middleware `bridge`) | read with a claim | `?width=` | 204 when nothing waits (cheap: one indexed SELECT, no session); 200 `{job_id, order_number, copies, escpos_base64, expires_at}` and the job becomes `claimed` | 401 unknown or revoked bridge |
| `POST /bridge/jobs/{id}/printed` | bridge token | yes (`client_uuid` = job id) | | 204 | 409 `payments.print.not_claimed_by_you` |
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
