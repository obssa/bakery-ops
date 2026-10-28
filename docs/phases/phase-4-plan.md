# Phase 4 plan — Payments and analytics

Status: **approved under Ramin's standing approval (2026-10-19)**, committed by step 4.1a (`phase-4/plan-and-contracts`) together with
`plan_approved: true`, `plan_approved_by: "standing approval: Ramin, 2026-10-19"` and phase 4 `in_progress` in `docs/phases/STATUS.json`
(SKILL.md step 3, as Phase 3's 3.1a did). **One question is still open with Ramin: R-C (what a refund does to stock, 10.2); step 4.1b waits for his answer.**
The plan was drafted on 2026-10-24 from `origin/main` before Phase 3 closed; step 4.1a corrected what Phase 3's final state (3.8, merged at `3b37a69`) made stale.

Written 2026-10-24 from `origin/main` (read only): `CLAUDE.md`, the skill (`SKILL.md`, `references/phases/phase-4-payments-analytics.md`,
`assets/dependency-manifest.json` `phases["4"]`, `modules.analytics`, `modules.payments`, `contracts`, `cycle_breaks`, `ci`, the references
`dependency-graph`, `domain-model`, `invariants`, `traps`, `testing-strategy`, `ci-cd`, `workflows-and-screens`, the template),
`docs/architecture.md` (sections 4.5, 5, 7, 8, 9, 10), ADRs 0006 and 0013, `docs/phases/phase-3-plan.md` (section layout, the "As built"
notes of steps 3.1 to 3.7e, sections 8, 10.6, 10.7) and the code Phase 4 builds on: `Payments/**` (RecordPayment, Totals, Channels,
PaymentStatus, PaymentError), `Orders/Contracts/Orders.php`, `Orders/Actions/VoidOrder.php`, `Orders/Listeners/ApplyPayment.php`,
`Orders/Support/Settlement.php`, `Shifts/Contracts/**`, `Shared/**` (there is no `Files` yet), `config/{payments,ledger,filesystems}.php`,
the Orders, Payments and Shifts routes, the migrations and the web app tree.

Decisions written down as ADRs in this phase: **0014** summary rebuild semantics (manifest), **0015** file storage portability (local
disk now, S3-compatible later; the way ADR 0006 treats the database), **0016** refunds, voids and the cash drawer (what is reversed,
what is not, and where the money goes). Dated notes, not new ADRs: ADR 0013 (a payment on any channel is stamped with the till's
shift), ADR 0006 (the Files row of its table becomes concrete), ADR 0009 (Phase 4 stays on polling; the new feeds).

What the phase delivers, in one paragraph: the till takes **any enabled channel**. A channel that needs a receipt photo keeps Pay disabled
until a photo is attached; the photo is shrunk in the browser, re-encoded on the server (EXIF stripped, 1600 px longest side), stored on the
account's private disk and read back only through short-lived signed URLs. A bill can be split over channels until the balance is zero, in
one atomic action. The admin runs the channels (rename, reorder, switch off, add, per-channel rules), verifies or disputes receipts against
the bank's statement, and a manager can void a wrong payment before the order is paid or refund a paid order with a PIN: the stock side is
written as **new reversing movements** that point at the originals, the money side as new rows, nothing is edited. Every sale has a
bilingual receipt (PDF, or a signed link). Behind the dashboards sit **summary tables** rebuilt per local day by one idempotent
`Rebuild::day`, fed only by provider-owned `*Facts` read ports; the admin gets sales, channel, product, station, staff and shift
dashboards, Excel/PDF exports and a Monday summary, plus the order timeline with its photos and a screen for the audit log.

## 1. Entry check

`python scripts/gate_check.py --mode entry --phase 4`, run by step 4.1a (2026-10-24) on the Docker stack. Summary lines of the run with the tests, taken
before the approval commit (so `clean working tree` and `plan approved` failed then, for that reason only), and of the run after the commits with
`--no-tests` (the structure lines):

```
[PASS] phase 3 done                      status=done done_commit=3b37a697ad3e26b8547eafdda55837670dda6044
[PASS] phase 3 commit in history         3b37a697ad3e is an ancestor of HEAD
[PASS] gate:p3 green                     group gate:p3: 197 passed (9382 assertions)
[PASS] clean working tree / plan approved by Ramin / approval author   (after the commits)
[PASS] contract payments->orders file/first/green   group contract:payments->orders: 5 passed (79 assertions)
[PASS] contract analytics->facts file / first        FactsContractTest.php; consumer unchanged since previous gate
[FAIL] contract analytics->facts green   group contract:analytics->facts failed: 10 failed (0 assertions)
[PASS] dep analytics->{orders,payments,production,inventory,shifts,catalog,shared,identity} stable
[PASS] Phase4Seeder                      exists
```

**The only red line is `contract analytics->facts green`** (10 of 10 cases fail: `App\Shared\Time\LocalDay` and the `Facts` ports do not exist). It turns green in
step 4.1c, after which the entry gate passes. `contract payments->orders green` passes now only because the nine new refund cases carry the group
`contract:payments->orders:refund`; step 4.1b gives them the original group name too, so that the entry gate and `mod-orders` see them (case 10, the void, has carried
both names since step 4.3c, which built the void side).

What the reading shows (origin/main after Phase 3):

| Item | State |
|---|---|
| Previous phase | Phase 3 `done`: `done_commit` `3b37a69`, tag `phase-3-complete`, `gate-p3` run 40073782837, report `docs/phases/phase-3-report.md`; `Phase3Seeder` and `Demo\DemoClock` exist (step 3.8) and ADR 0012 and 0013 are accepted. |
| `plan_approved` | `false` for phase 4 (set by the commit that adds this file, standing approval) |
| Contracts required | `payments->orders` (file exists since Phase 1; its "Phase 4: Orders::refund" case is not implemented), `analytics->facts` (file does not exist). The entry script skips a contract whose provider is built in the same phase (`payments->orders`) and requires it at the consumer's step. |
| `Phase4Seeder` | the entry check requires the file. Step 4.1a adds a **skeleton**: `DemoSeeder` composes it after `Phase3Seeder` (every `PhaseNSeeder` that exists, up to `SEED_PHASE`), it refuses a missing demo branch like `Phase3Seeder` and writes nothing; the real one is step 4.6a. |
| MinIO in CI | manifest entry criterion, **moved to the portability job `files-s3`** (section 6, 4.2b; 10.9 item 1): the live host has no MinIO, so the phase does not need it to start. |
| Upstream modules | all `stable` after Phase 3: shared, audit, identity, catalog, inventory, orders, payments (Phase 1 level), production, notifications, shifts. Phase 3 left Payments `stable` after adding `Totals::cash/byChannel`. |
| Live-host facts that bind this plan | section 10.1 |

Upstream capabilities this phase consumes, and how each is proven before use:

| Consumer (new code) | Provider | Capability | Proof |
|---|---|---|---|
| Payments (full) | Orders | `forPayment`, `totals`, **new** `refund`, the `ApplyPayment` listener, **new** `ReversePayment` listener | `contract:payments->orders` (extended, 4.1a/b) |
| Payments | Shared | **new** `Files::put`, `signedUrl` | `contract:payments->files` (new, 4.1a; an addition to the manifest, section 10.9) |
| Payments | Identity | `can('payments.take')`, `verifyPinFor('payments.void')`, grants of new permissions | `contract:payments->identity` does not exist; covered by the grant-migration test and the endpoint 403 matrix (as Phase 3 did for `payments.take`) |
| Orders (refund) | Inventory | **new** `Reversals::of(movementIds, ref)` over `Ledger::appendMany` | `contract:orders->inventory` gets cases 13 to 15 (4.1b) |
| Shifts (unchanged code) | Payments | `Totals::cash(shift)` now **net of cash refunds** | `contract:shifts->payments` gets one case (4.3d) |
| Analytics | Orders, Payments, Production, Inventory, Shifts, Catalog, Identity | provider-owned `Facts` classes (new), `Recipes::version`, `RecipeCost`/`item_cost_history`, names | `contract:analytics->facts` (4.1a/c) |
| Analytics | Notifications | **event only**: `WeeklySummaryReady` | listener test in `mod-notifications` |
| Audit UI | Identity | **new** Shared `ActorDirectory::names(ids)` bound by Identity (Audit stays a leaf); as built (4.5a) `Shared\Contracts\ActorNames::names(ids)`, bound to Identity's `ActorDirectory` of 4.4c | `ActorNamesBindingTest` (as built) |

## 2. Contract tests to write first (green before any consumer code)

Files are committed **red** in 4.1a, in this order, and the provider work of 4.1b/4.1c turns them green with `app/Modules/Payments` and
`app/Modules/Analytics` untouched relative to the Phase 3 `done_commit` (except Payments' skeleton routes file being absent).

| Contract | File | Cases |
|---|---|---|
| `payments->orders` (extend; case 4 of the manifest becomes five) | `apps/api/tests/Contract/Payments/OrdersContractTest.php` | 5. `Orders::refund(order, reason, approver, lines, dispositions)` on a paid and closed order writes, for each line with disposition `restock`, ONE reversing `return` movement of the line's sold quantity at the location the original left, with `ref_type='stock_movement'`, `ref_id=<original id>`, and one `stock_movement_reversals` row; the original `sale` / `production_consume` rows are byte-identical afterwards (re-read and compared) · 6. a line with disposition `waste` or `none` writes no movement and its originals stand · 7. a movement can be reversed once: a second refund of the same line is `409 orders.refund.line_refunded`, a second `Reversals::of` of the same ids is refused · 8. refund needs a `closed` order (`409 orders.refund.not_refundable` for open, sent..served, paid-but-open, cancelled, voided), a non-empty reason, `refunded_total <= paid_total`; the order stays `closed`, gets `refunded_total`, `refunded_at`, timeline `line.refunded` per line and `order.refunded`; `OrderRefunded` is announced after commit and never when the refund rolled back · 9. a refund of a line whose location's station has no open shift and disposition `restock` at a station location is `409 inventory.shift.required` and writes nothing (the rule of ADR 0013 is not bypassed) · 10. a payment that is **voided** while the order is `sent..served` takes its amount back out of `paid_total`, clears `settled_at` of a settled but unserved order and records `payment.voided` on the timeline; on `paid`/`closed` it is refused (`409 payments.void.order_closed`, "refund it") · 11. a part payment leaves the order unsettled, the last one settles it, and display-case lines are sold exactly once however many payments there were · 12. two payments racing for the last balance: one is recorded, the other is `422 payments.amount.mismatch` (fork test, `contract` group) · 13. `Orders::totals` additionally reports `refunded` and `net_paid` as decimal strings (additive) |
| `payments->files` (new) | `apps/api/tests/Contract/Payments/FilesContractTest.php` | `Files::put` of a 4000x3000 JPEG with GPS EXIF returns a key whose stored bytes have no EXIF/GPS/ICC (parsed by an independent reader), longest side 1600, aspect kept, orientation applied · a PNG with alpha is flattened and stored as JPEG · a file that is not an image, a truncated image, a 25 MP image and a file over `files.max_bytes` are refused with distinct keys and nothing is stored · `signedUrl(key, ttl)` verifies until `ttl` and not after, fails when any query parameter is changed, and another branch's key is never signable (404) · `put` twice with the same `client_uuid` returns the same row, one object · nothing is world-readable: the object path is outside `public/` and a plain GET of it is 404 · the key and the signed URL never appear in the log (log tap) |
| `shifts->payments` (extend) | `apps/api/tests/Contract/Shifts/PaymentsContractTest.php` | `Totals::cash(shift)` = non-voided cash payments taken in that shift **minus** cash refunds given in that shift (a refund is keyed on its own shift, like a payment); `Totals::byChannel(shift)` adds `refunds` and `refund_total` per channel; voided payments and other shifts/branches stay out |
| `orders->inventory` (extend) | `apps/api/tests/Contract/Orders/InventoryContractTest.php` | 13. `Reversals::of` writes one `return` per original, qty = -original qty, at the original's location, atomically with its `stock_movement_reversals` row, in the Ledger's ascending lock order · 14. refused when the original is not a `sale`/`production_consume`, belongs to another branch, or is already reversed · 15. the Phase 0 ledger oracle (`item_stock_view` equals the SUM of movements) still holds after reversals, and a property test of 50 random sale/reverse sequences keeps `on_hand >= 0` and `reserved >= 0` |
| `analytics->facts` | `apps/api/tests/Contract/Analytics/FactsContractTest.php` | one `describe` per port (`Orders\Facts::sales/lines`, `Payments\Facts::payments/refunds`, `Production\Facts::batches`, `Inventory\Facts::movements/closingBalances/stockOuts/unitCosts`, `Shifts\Facts::instances`): empty range gives `[]` (never null) · stable keys and types: money strings with scale 2, quantities scale 4, ids ints, days `YYYY-MM-DD` · **bucketed by `Africa/Addis_Ababa`**: a row at 23:59:59 local (20:59:59Z) is day D, 00:00:00 local (21:00:00Z) is D+1; both ends of the range are inclusive local dates · each port names the clock it buckets by (orders: `settled_at`; payments: `received_at`; refunds: `refunded_at`; batches: `finished_at`; movements: `created_at`; shifts: opening and closing local dates) · excludes voided payments and voided/cancelled/open orders · branch isolation · deterministic order `(day, id)` · `closingBalances(day)` equals `Stock::balanceAt(end of local day)` for every item with stock (oracle) · `unitCosts(day)` equals the `item_cost_history` row in force at the end of that local day · `Shifts\Facts::instances(range)` returns a shift once, with `date` (its local start date), `opened_at`, `closed_at`, `locked_at`, for any range that contains a local day between its opening and its lock (a Night shift 22:00 to 06:00 is found by a range holding either day) |

Group names: `contract:payments->orders`, `contract:payments->files`, `contract:shifts->payments`, `contract:orders->inventory`,
`contract:analytics->facts`. The Facts file runs in **every provider's module job** (`mod-orders`, `mod-payments`, `mod-production`,
`mod-inventory`, `mod-shifts`), so a provider's schema change fails there first (cycle 9).

## 3. Migrations (in order)

Dates follow the day each step merges (Phase 3 used `2026_10_24` to `2026_10_27`); the **names and the order** are fixed. Every table has
`branch_id` (FK `branches`, index), money `DECIMAL(12,2)`, quantities `DECIMAL(14,4)`, no float, a CHECK for each enum list (MariaDB and
PostgreSQL both), and every migration is a thin portable DDL (no partial indexes, no materialized views). Driver checks use
`in_array($driver, ['mysql','mariadb'], true)` (traps.md).

| # | Migration | Module | Contents |
|---|---|---|---|
| M1 | `create_stored_files_table` | Shared | `stored_files`: `id`, `branch_id`, `client_uuid` UUID, `purpose` VARCHAR(24) CHECK in (`receipt`, `refund_proof`), `disk` VARCHAR(24), `key` VARCHAR(255) UNIQUE (`receipts/{branch_id}/{yyyy}/{mm}/{uuid}.jpg`), `mime` VARCHAR(32), `bytes` INT UNSIGNED, `width` / `height` SMALLINT UNSIGNED, `sha256` CHAR(64) of the stored bytes, actor stamp (`uploaded_by_user_id`, `acting_user_id?`, `shift_instance_id?`, `station_id?`, `device_id?`), `attached_type` VARCHAR(24) NULL (`payment`, `payment_refund`), `attached_id` BIGINT UNSIGNED NULL, `attached_at` DATETIME NULL, timestamps, `deleted_at`. UNIQUE `(branch_id, client_uuid)`; INDEX `(branch_id, sha256)`, `(attached_type, attached_id)`, `(branch_id, attached_at, created_at)`. Soft delete; the object is deleted only by the orphan purge (section 5). Not append-only. |
| M2 | `grant_phase_4_permissions` | Identity | thin caller of `GrantRegistryPermissions` exactly as `2026_10_24_100100_grant_phase_3_permissions` (never revokes, writes nothing the second time, clears spatie's cache). New registry entries in 10.6. |
| M3 | `extend_payment_channels_for_rules` | Payments | `client_uuid` UUID NULL, UNIQUE `(branch_id, client_uuid)` (a channel created through the API; NULL for the defaults; added in 4.3a, see its As built note), `reference_pattern` VARCHAR(120) NULL (a regex body without delimiters, validated when saved), `reference_hint_en` / `reference_hint_am` VARCHAR(120) NULL (a bilingual pair), `photo_hint_en` / `photo_hint_am` NULL. No default pattern is seeded (nobody here knows each bank's current format; the admin sets one). |
| M4 | `extend_payments_for_phase_4` | Payments | `receipt_file_id` BIGINT UNSIGNED NULL FK `stored_files` (the existing `receipt_photo_key` is filled with the file's key too, so Phase 1 readers and the ERD keep meaning), `reference_norm` VARCHAR(64) NULL (upper case, no spaces/dashes; INDEX `(branch_id, channel_id, reference_norm)`, not unique: a repeated reference is a warning, 10.8 R-B), `batch_uuid` UUID NULL (payments recorded together), `disputed_by_user_id` FK users NULL, `disputed_at` DATETIME NULL, `dispute_reason` VARCHAR(255) NULL, `dispute_resolved_at` DATETIME NULL, `refunded_amount` DECIMAL(12,2) DEFAULT 0. INDEX `(branch_id, batch_uuid)`. CHECKs: `amount > 0`, `refunded_amount >= 0 AND refunded_amount <= amount`. M4 changes no state: `PaymentStatus::transitions()` becomes `recorded -> verified|disputed|voided`, `verified -> voided`, **`disputed -> verified|voided`** (a dispute can be resolved; before it was terminal) in step 4.3c, which builds the dispute, together with `tests/Gate/Phase1StateMachinesTest` and the exhaustive table (corrected by 4.3b, see its As built note). |
| M5 | `create_receipt_settings_table` | Payments | `receipt_settings`: `branch_id` UNIQUE, `business_name_en/am`, `address_en/am`, `phone`, `tin` VARCHAR(32), `footer_en/am` VARCHAR(255), `show_amharic` BOOL DEFAULT 1, `ethiopian_dates` BOOL DEFAULT 0, `created_by_user_id`, timestamps. One row per branch, created on first save; a read of a missing row returns the defaults (branch name, no TIN). |
| M6 | `create_stock_movement_reversals_table` | Inventory | `stock_movement_reversals`: `id`, `branch_id`, `movement_id` (the new `return` row) UNIQUE FK, `reverses_movement_id` UNIQUE FK `stock_movements`, `created_at`. **Append-only** (the update/delete triggers, as `order_events`). The unique `reverses_movement_id` is what makes "reversed once" a database fact, without a partial index. Written only by `Inventory\Contracts\Reversals`, inside the Ledger transaction. |
| M7 | `create_order_refunds_tables` | Orders | `order_refunds`: `id`, `branch_id`, `client_uuid` (UNIQUE with branch), `order_id`, `reason` VARCHAR(255), `amount` DECIMAL(12,2) CHECK `> 0`, `is_full` BOOL, `requested_by_user_id`, `approved_by_user_id`, actor stamp (`acting_user_id?`, `shift_instance_id?`, `station_id?`, `device_id?`), `created_at`; `order_refund_lines`: `id`, `branch_id`, `order_refund_id`, `order_line_id` UNIQUE (a line is refunded once), `qty` DECIMAL(14,4), `line_amount` DECIMAL(12,2), `disposition` CHECK in (`restock`, `waste`, `none`), `movement_ids` JSON (the reversing movements), `created_at`. Both **append-only**. |
| M8 | `add_refund_columns_to_orders` | Orders | `orders.refunded_total` DECIMAL(12,2) DEFAULT 0 CHECK `>= 0` (and `<= paid_total` as a trigger-free application rule, pinned by a test), `orders.refunded_at` DATETIME NULL; `order_lines.refunded_at` DATETIME NULL. |
| M9 | `extend_order_events_check` | Orders | drops and re-adds `order_events_event_check` with four more events: `payment.recorded`, `payment.voided`, `line.refunded`, `order.refunded`. DDL only; the append-only triggers are untouched (a test re-runs `AppendOnlyTest` after it). `ApplyPayment` now also writes `payment.recorded` (payload: `payment_id`, `channel_code`, `amount`, `receipt` bool; **never a URL or a key**). **Split in two (step 4.3c):** the payment half (`payment.recorded`, `payment.voided`) is `2026_11_02_100200_extend_order_events_check_for_payments`, built by 4.3c with the void; 4.1b adds `line.refunded` and `order.refunded` in a migration of its own that lists all 22 events. |
| M10 | `create_payment_refunds_table` | Payments | `payment_refunds`: `id`, `branch_id`, `client_uuid` (UNIQUE with branch), `order_id`, `order_refund_id` FK, `payment_id` FK, `channel_id` FK, `amount` DECIMAL(12,2) CHECK `> 0`, `reference_no` VARCHAR(64) NULL (of the outgoing transfer), `proof_file_id` FK `stored_files` NULL, `refunded_by_user_id`, `approved_by_user_id`, actor stamp, `refunded_at` DATETIME, `created_at`. INDEX `(branch_id, refunded_at)`, `(payment_id)`. **Append-only.** |
| M11 | `add_facts_indexes` | Orders, Payments, Production, Inventory | read indexes for the Facts queries, each added only if `EXPLAIN` in the provider's test shows the existing one is not used: `orders (branch_id, settled_at)`, `orders (branch_id, voided_at)`, `payments (branch_id, status, received_at)`, `production_batches (branch_id, finished_at)`, `stock_movements (branch_id, created_at, reason)`, `shift_instances (branch_id, date)`. DDL on tables of tens of thousands of rows, inside the release window (as 10.7 of the Phase 3 plan). |
| M12 | `create_analytics_days_table` | Analytics | `analytics_days`: PK `(branch_id, day)`, `day` DATE (local), `version` INT UNSIGNED, `rebuilt_at` DATETIME(6), `trigger` VARCHAR(16) CHECK in (`shift_locked`, `nightly`, `manual`, `touch`, `seed`), `duration_ms` INT UNSIGNED, `row_counts` JSON, `fingerprint` CHAR(64) (sha256 of the canonical sums of every summary row of the day, so two rebuilds can be compared with one string), `changed` BOOL (the fingerprint differs from the previous run). It is the lock target of `Rebuild::day` and the freshness feed. Derived data: physical delete allowed (ADR 0014). |
| M13 | `create_daily_sales_tables` | Analytics | `daily_sales` PK `(branch_id, day)`: `orders_count` INT, `items_qty` DECIMAL(14,4), `subtotal` / `discount_total` / `tax_total` / **`revenue`** DECIMAL(12,2), `avg_ticket` DECIMAL(12,2), `voided_count`, `voided_total`, `cancelled_count`, `refund_count`, `refund_total`, `net_revenue` (= revenue - refund_total, cash basis), `dine_in_revenue`, `takeaway_revenue`, `delivery_revenue`, `advance_revenue`, `rebuilt_at`. `daily_hour_sales` PK `(branch_id, day, hour)` `hour` TINYINT 0-23: `orders_count`, `items_qty`, `revenue`. |
| M14 | `create_daily_channel_totals_table` | Analytics | PK `(branch_id, day, channel_id)`: `receipts_count`, **`amount`** DECIMAL(12,2), `refund_count`, `refund_amount`, `net_amount`, `without_reference_count`, `without_photo_count`, `verified_count`, `disputed_count`, `duplicate_reference_count`, `rebuilt_at`. |
| M15 | `create_daily_item_metrics_table` | Analytics | PK `(branch_id, day, item_id)`: `category_id`, `kind` VARCHAR(10) (as built; CHAR pads on PostgreSQL), `units_sold_qty`, `refunded_qty`, `produced_qty`, `consumed_qty`, `waste_qty`, `purchased_qty`, `adjusted_qty` (signed), `closing_on_hand_qty` (all DECIMAL(14,4)), `stockout_count` INT, `revenue`, `cogs_total`, `margin_total`, `closing_value_total` (DECIMAL(12,2)), `cogs_complete` BOOL (false when a used ingredient has no cost yet), `rebuilt_at`. |
| M16 | `create_daily_station_and_user_metrics_tables` | Analytics | `daily_station_metrics` PK `(branch_id, day, station_id)`: `tickets_count`, `lines_served`, `lines_rejected`, `avg_prep_seconds` INT NULL, `p90_prep_seconds` INT NULL, `batches_finished`, `produced_qty`, `waste_qty`, `shifts_count`, `shifts_locked_count`, `variance_line_count`, `cash_variance_total` DECIMAL(12,2), `stockout_count`. `daily_user_metrics` PK `(branch_id, day, user_id, role_kind)` `role_kind` in (`waiter`, `cashier`, `station`): `orders_count`, `items_qty`, `revenue`, `payments_count`, `payments_amount`, `voids_count`, `refunds_count`, `lines_prepared`, `avg_prep_seconds`. (The manifest names four tables; `daily_hour_sales` and `daily_user_metrics` are this plan's additions for the hour and waiter/cashier dimensions of architecture section 8, 10.9.) |
| M17 | `add_audit_log_read_indexes` | Audit | `(branch_id, id)`, `(branch_id, action, id)`, `(branch_id, subject_type, subject_id, id)`, `(branch_id, actor_id, id)` for the Audit UI's filters, as built (4.5a) also `(branch_id, acting_user_id, id)` (the person's filter reads both columns). Only indexes: the append-only triggers do not care. |

Invariant consequences, decided here so the checks do not surprise the build:

- The analytics tables are **derived and deleted physically by every rebuild**. `SoftDeletesTest` and `BranchScopedTest` get them on a
  documented allowlist with the reason "derived summary, rebuilt from facts (ADR 0014)"; `ActorStampTest` does not list them (they are not
  operational writes); `BranchIdTest` still demands `branch_id NOT NULL` + FK + index on all of them (it is part of every primary key).
  `NumericColumnsTest` stays satisfied: money DECIMAL(12,2), quantities DECIMAL(14,4), no column named `*cost*` outside the (14,4) rule
  (`cogs_total` is a total of money, 12,2).
  **As built (4.4a):** Analytics has no models (it writes with the query builder), so `SoftDeletesTest` and `BranchScopedTest`, which read
  models, need no allowlist; `BranchIdTest` and `NumericColumnsTest` pass unchanged.
- `payment_refunds`, `order_refunds`, `order_refund_lines`, `stock_movement_reversals` join the append-only list of `AppendOnlyTest`.
- `docs/erd.dbml` and `docs/openapi.json` are regenerated in the PR that adds each table / route (`erd-check`, `openapi-check`).

## 4. Endpoints

Conventions as in Phase 3: `/api/v1`, bearer token, the `actor` middleware, every write carries `idempotency` and a `client_uuid` in the
body, state transitions are actions, money and quantities are decimal strings, errors are `{message_key, message_en, message_am, ...extra}`
(`lang/en|am/payments.php`, `files.php`, `analytics.php`). Reads that screens poll are conditional (`ConditionalPoll`: weak-compared ETag,
`If-None-Match` to `304`, `Cache-Control: no-cache, private`). Every route is listed in `tests/Support/route_permissions.php`, every write
in `write_endpoints.php`, the two signed public routes in `public_routes.php` (with the reason).

### 4.1 Files (Shared, step 4.2)

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `POST /files` | `files.upload` | key + `client_uuid` (multipart field) | `multipart/form-data`: `file` (jpeg, png, webp), `purpose` (`receipt` or `refund_proof`), `client_uuid` | `201 File {id, purpose, width, height, bytes, sha256, url, url_expires_at, attached: false}`; `200` the same body for a used `client_uuid` | `422 files.missing`, `422 files.not_image`, `422 files.too_large` `{max_bytes}`, `422 files.too_many_pixels` `{max_pixels}`, `422 files.unsupported_type`, `403` |
| `GET /files/{file}/url` | `payments.view` (receipts), `files.upload` for one's own unattached upload | read | | `200 {url, expires_at}`: a fresh signed URL (ttl `files.url_ttl_seconds`, default 300) | `404` another branch's or a deleted file |
| `GET /files/{file}` | **signed**, no bearer (an `<img>` cannot send one) | read | `?expires&signature` | `200` the JPEG with `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline` | `403` bad/expired signature, `404` |
| `DELETE /files/{file}` | the uploader (or `payments.verify`) | key | | `204`; only a file that is **not attached** and younger than 24 h; soft delete, the object goes with the purge | `409 files.attached` |

The upload is the only multipart route. The `Idempotency` middleware hashes the request: for multipart it hashes the field names, `client_uuid`
and the file's sha256, never the whole body (test: two identical uploads under one key give one row; the same key with another file is `422
idempotency.key_reused`). `GET /files/{file}` is why `public_routes.php` gets one entry beyond `login`: its protection is the signature.

### 4.2 Channels (Payments, step 4.3a)

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `GET /payment-channels` | `payments.take` (exists) | read | `?all=1` needs `channels.manage` and adds the **removed** channels, after the live ones (the plain list already shows switched-off channels, as in Phase 1) | `200 {data: [Channel]}`, `Channel` = the Phase 1 keys `{id, code, name_en, name_am, is_enabled, requires_photo, requires_reference, sort, accepted_now}` in their order, then `{reference_pattern, reference_hint_en/am, photo_hint_en/am, is_cash, deleted_at}`; from 4.3b `accepted_now` is simply `is_enabled` (a photo no longer blocks; until then a photo channel is not accepted) and is kept so the Phase 1 client still works | `403` for `?all=1` without `channels.manage` |
| `POST /payment-channels` | `channels.manage` | key + `client_uuid` | `{client_uuid, code, name_en, name_am, requires_photo, requires_reference, is_enabled?, reference_pattern?, hints?, sort?}` | `201 Channel`; `200` the same channel for a `client_uuid` already used | `422 payments.channel.code_taken`, `422 payments.channel.pattern_invalid` (does not compile, longer than 120, or a nested quantifier that can backtrack catastrophically), `422 validation.failed` |
| `PATCH /payment-channels/{channel}` | `channels.manage` | key | any of the above except `code` and `client_uuid` (the code never changes: a body naming it is `422 validation.failed`) | `200 Channel` | `409 payments.channel.cash_locked` (the `cash` channel cannot be switched off, removed, or given a photo/reference rule: the drawer count is built on it), `422` |
| `PUT /payment-channels/order` | `channels.manage` | key | `{ids: [int]}` every live channel exactly once | `200 {data: [Channel]}` renumbering `sort` 1..n in one transaction | `422 payments.channel.order_incomplete` |
| `DELETE /payment-channels/{channel}` | `channels.manage` | key | | `200 Channel` with `deleted_at` (soft; the payments made on it keep pointing at it) | `409 payments.channel.cash_locked` |
| `GET /receipt-settings`, `PUT /receipt-settings` | `payments.take` (read), `channels.manage` (write) | key on PUT | the M5 columns | `200 ReceiptSettings` | `422` |

### 4.3 Taking payments (Payments, step 4.3b)

`RecordPayment` changes, in order of its checks: the channel (unknown, disabled), then **`receipt_file_id` required when `requires_photo`** (`422
payments.channel.photo_required`; the file must exist, be of this branch, purpose `receipt`, unattached, uploaded within
`payments.photo_max_age_hours` = 12, else `422 payments.photo.invalid`), `reference_no` required when `requires_reference` and matching
`reference_pattern` when set (`422 payments.channel.reference_required`, `422 payments.reference.invalid` `{hint_en, hint_am}`), then the
shift rule (10.1 R-A), then the order's state, then **`0 < amount <= balance`** (a part payment is now allowed; `amount > balance` is still
`422 payments.amount.mismatch` `{balance, amount}`, the key is kept so the Phase 1 client's message still works; cash still carries
`tendered_amount`/`change` and may tender more than the amount, never more than a payment). The row, the file's `attached_*` and
`PaymentRecorded` are written in the one transaction `ApplyPayment` already shares.

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `POST /orders/{order}/payments` | `payments.take` | key + `client_uuid` | `{client_uuid, channel_code, amount, tendered_amount?, reference_no?, receipt_file_id?}` | `201 {payment, order, warnings: []}` (`200` replay); `warnings` may hold `reference.duplicate` `{payment_ids}` and `receipt.duplicate` `{payment_ids}` (same `sha256` on another payment), never blocking; as built each is `{code, message_key, message_en, message_am, payment_id, payment_ids}` | the list above, plus the Phase 1 keys (`payments.order.not_payable`, `.settled`, `.tendered.too_low`, `.client_uuid.reused`, `payments.shift.required`) |
| `POST /orders/{order}/payment-batches` | `payments.take` | key + `client_uuid` (the batch) | `{client_uuid, rows: [{client_uuid, channel_code, amount, tendered_amount?, reference_no?, receipt_file_id?}, ...]}` 1 to 6 rows | `201 {payments: [the payment of POST /orders/{order}/payments], order, warnings}`; **the sum of the amounts must equal the balance**, all rows or none, one `AuditedAction`, one `batch_uuid` | `422 payments.batch.sum_mismatch` `{balance, sum}`, `422 payments.batch.row_invalid` `{row, error: the row's own refusal}`, `409` as above |
| `GET /orders/{order}/payments` | `payments.view` | read | | `200 {data: [Payment], balance}`; each `Payment` = `{id, channel {id, code, name_en, name_am}, amount, tendered_amount, change_amount, reference_no, status, received_at, received_by {id, name}, shift_id, receipt {file_id, url, url_expires_at} or null, verified_at, disputed_at, dispute_reason, refunded_amount, void_reason, voided_at}`, as built also `order_id`, `client_uuid`, `currency`, `batch_uuid` and `flags {duplicate_reference, duplicate_receipt}`; the signed URL is made on read | `404` |
| `GET /payments` | `payments.view` | read, **polled** (ETag from `Payments::fingerprint()`: count, max id, max `updated_at`, plus the `settling` second while the newest row is younger than 5 s, the lesson of `Stock::fingerprint`) | `?from=&to=` (local dates), `?channel_id=`, `?status=`, `?shift_id=`, `?order_no=`, `?reference=`, `?missing_photo=1`, `?flagged=1` (duplicate reference/receipt or disputed), `?cursor=`, `?limit=` (50, max 200) | `200 {data: [Payment + order {id, order_no, business_date, table_code, total}], next_cursor, server_time}` newest first | `422 validation.failed` |
| `GET /payments/{payment}` | `payments.view` | read | | `200 Payment` with `order`, `refunds [PaymentRefund]` | `404` |
| `GET /payments/reconciliation` | `payments.verify` | read, polled | `?from=&to=` (max 62 days), `?channel_id=` | `200 {days: [{day, channels: [{channel, receipts, amount, with_photo, without_photo, with_reference, verified, disputed, voided, refunds, refund_amount, net_amount}]}], totals}` computed from `payments` and `payment_refunds` (this is Payments reading its own tables, not a summary); as built also `from`, `to`, `timezone`, and `totals` = the sums plus `channels` | `422 payments.reconciliation.range_too_large {max_days}` (as built), `422 validation.failed` |
| `GET /payments/export` | `payments.verify` | read | the same filters + `?format=csv|xlsx` | the file, at most 20 000 rows (`422 payments.export.too_large`), signed photo URLs are **not** in it; as built the filters are those of `GET /payments` without paging, plus `?locale=en|am` | `422 payments.export.too_large {max_rows, rows}`, `422 validation.failed` |

### 4.4 Verify, dispute, void and refund (Payments, steps 4.3c and 4.3d)

PIN rules follow `VoidOrder`: the asker acts as themselves (or as the acting-as person of a shared till), the approver's PIN is checked by the
controller **before** the transaction through `verifyPinFor('payments.void')`, so a wrong PIN counts toward the lockout even though nothing is
written; the PIN is never stored, logged or audited.

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `POST /payments/{payment}/verify` | `payments.verify` | key | `{note?}` | `200 Payment` `recorded or disputed -> verified`; a repeat answers `200`; emits `PaymentVerified` | `409 state_machine.illegal_transition` |
| `POST /payments/{payment}/dispute` | `payments.verify` | key | `{reason}` (3 to 255) | `200 Payment` `recorded -> disputed`; emits `PaymentDisputed` | `422 payments.dispute.reason_required`, `409` |
| `POST /payments/{payment}/void` | `orders.void_request` + approver PIN of a `payments.void` holder | key | `{reason, pin}` | `200 Payment` `status voided`; one `AuditedAction` `payment.void`; emits `PaymentVoided` (Orders' `ReversePayment` takes it out of `paid_total` and unsettles the order in the same transaction) | `409 payments.void.order_closed` (the order is `paid`/`closed`: refund it instead), `409 payments.void.refunded` (money already went back), `403 auth.pin_invalid`, `423 auth.pin_locked`, `422 payments.void.reason_required` |

**As built (step 4.3c)**, where the code differs from the table: `Payment` is the body of `GET /payments/{payment}` (the read shape plus `order` and `refunds`) for all three. A
refused PIN is `403 identity.pin.rejected` and a locked prompt `423 identity.short_code.locked` (Identity's keys, as `POST /orders/{order}/void` answers). A missing or
wrong reason is `422 validation.failed` with `errors.reason` carrying the text of `payments.dispute.reason_required` or `payments.void.reason_required` (the request rule,
as `VoidOrderRequest` does); a PIN of the wrong shape carries `payments.void.pin_required`. A voided payment is `409 state_machine.illegal_transition` for all three, and
for the dispute so are verified and disputed ones. `payments.void.order_closed` carries the order's `status`, `payments.void.refunded` the `refunded_amount`; the refunded
check comes first (a refunded payment's order is closed). The verify note is kept in the audit context only (no column).
| `POST /orders/{order}/refund` | `orders.void_request` + approver PIN of a `payments.void` holder | key + `client_uuid` | `{client_uuid, reason, pin, lines?: [{line_id, disposition: restock|waste|none}], allocation?: [{payment_id, amount}], refund_reference?, refund_proof_file_id?}`; no `lines` = the whole order with the default dispositions of 10.2 R-C | `201 {refund {id, amount, is_full}, payments: [PaymentRefund], movements: [{id, item_id, qty, reverses}], order}` (`200` replay). One `AuditedAction` `order.refund` calling `Orders::refund` (stock and order side) then writing the `payment_refunds` rows, all in one transaction under the order's row lock | `409 orders.refund.not_refundable` `{status}`, `409 orders.refund.line_refunded` `{line_ids}`, `409 orders.refund.window_closed` `{hours}`, `422 orders.refund.partial_not_allowed` (the order carries a discount or tax: only a whole-order refund is defined, 10.2 R-D), `409 inventory.shift.required` `{location_id}`, `409 payments.shift.required` (a cash refund needs the till's open shift, the drawer must lose the money inside a declared shift), `422 orders.refund.reason_required`, `403`, `423` |

Allocation of the money: the refund amount is the sum of the refunded lines' `line_total` (the whole `total` for a full refund). It is returned
over the order's payments **last in, first out** up to each payment's `amount - refunded_amount`, on the channel it was taken on. A cash share
is handed out of the drawer; a digital share is a transfer made outside the app, recorded with `refund_reference` (and optionally a photo of
the outgoing transfer). No payment is edited except its `refunded_amount`.

### 4.5 Receipts (Payments, step 4.3e)

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `GET /orders/{order}/receipt.pdf` | `payments.view` | read | `?locale=en|am|both` (default: the receipt settings) | `200 application/pdf` (dompdf, Noto Sans Ethiopic embedded as a subset, Western digits), at most 2 pages: business name/TIN, order no and date (Addis time, and the Ethiopian date when enabled), lines with modifiers, subtotal/discount/tax/total, one line per payment (channel, reference tail only: last 4 characters), change, refunds if any, footer | `404`, `409 payments.receipt.unpaid` (a receipt of an unpaid order is a pro-forma, header says so: `?proforma=1` only) |
| `POST /orders/{order}/receipt-link` | `payments.take` | key | `{ttl_hours?: 72}` (max 168), as built also `locale?` | `200 {url, expires_at}`: a signed link to the public receipt page | `404`, `409 payments.receipt.unpaid` (as built), `422` |
| `GET /receipts/{order}` | **signed**, no bearer | read | | `200` the same PDF; no names of staff on it; `Cache-Control: private, no-store` | `403` (as built `payments.receipt.link_invalid`), `404`, `409 payments.receipt.unpaid` |

### 4.6 Analytics (Analytics, steps 4.4b to 4.4d)

Everything under `/analytics` reads **only** the summary tables (and names through Catalog/Payments/Identity contracts). Common query:
`?from=YYYY-MM-DD&to=YYYY-MM-DD` local dates, default the last 30 days, at most 400 days (`422 analytics.range.too_large`), `to` not after
today (`422 analytics.range.future`), `?compare=previous` adds the same-length period before. All answers carry `as_of` (the oldest
`rebuilt_at` of the days they used) and `stale_days` (days in range with no `analytics_days` row). All are conditional: the ETag is built from
the days' `version`s, so a dashboard that polls every 30 s gets `304` until a rebuild moves it.

| Method + path | Permission | Idempotent | Response (`data`; as built (4.4c) every dashboard read answers `{from, to, timezone, as_of, stale_days, data, previous?}`, see its As built note) |
|---|---|---|---|
| `GET /analytics/overview` | `analytics.view` | read | `{revenue, net_revenue, orders_count, avg_ticket, items_qty, refund_total, voided_total, by_type {...}, channels [{channel, amount, share}], top_items [5], previous {...}?}` |
| `GET /analytics/sales` | `analytics.view` | read | `?group=day|week|month` (weeks start Monday, local) `{series: [{key, orders_count, revenue, net_revenue, avg_ticket}]}` |
| `GET /analytics/sales/hourly` | `analytics.view` | read | `{hours: [{hour, orders_count, revenue}]}` summed over the range |
| `GET /analytics/channels` | `analytics.view` | read | `{channels: [{channel {id, code, name_en, name_am}, receipts_count, amount, refund_amount, net_amount, share, without_reference_count, without_photo_count, verified_count, disputed_count, duplicate_reference_count}], series}` |
| `GET /analytics/items` | `analytics.view` | read | `?kind=product|ingredient&category_id=&sort=revenue|units|margin|waste&limit=50` `{items: [{item {id, name_en, name_am, is_new}, units_sold_qty, revenue, cogs_total, margin_total, margin_pct, cogs_complete, produced_qty, waste_qty, stockout_count}]}` |
| `GET /analytics/items/{item}` | `analytics.view` | read | the product page: `{item, totals, series: [{day, units_sold_qty, revenue, margin_total, produced_qty, waste_qty, stockout_count, closing_on_hand_qty}], since_launch: {first_sale_day, weekly_units: [...]}}` (new products flagged) |
| `GET /analytics/stations` | `analytics.view`; `analytics.view_own` limited to the caller's stations (as built: `permission:analytics.view\|analytics.view_own`) | read | `{stations: [{station, lines_served, lines_rejected, avg_prep_seconds, p90_prep_seconds, batches_finished, produced_qty, waste_qty, variance_line_count, shifts_count}]}` |
| `GET /analytics/staff` | `analytics.view`; `analytics.view_own` limited to the caller (as built: `permission:analytics.view\|analytics.view_own`) | read | `?role_kind=waiter|cashier|station` `{users: [{user {id, name}, orders_count, revenue, payments_count, payments_amount, voids_count, refunds_count, lines_prepared, avg_prep_seconds}]}` |
| `GET /analytics/shifts` | `analytics.view` | read | `{days: [{day, shifts_count, shifts_locked_count, variance_line_count, cash_variance_total}]}` |
| `GET /analytics/freshness` | `analytics.view_own` | read, polled 30 s | `{as_of, days: [{day, version, rebuilt_at, trigger, changed}], running: bool}` (the dashboard's "updated 2 min ago" and its refetch trigger); as built (4.4b) also `from`, `to`, `timezone` and `stale_days`, `days` lists the built days only, the ETag is every built day's version and `rebuilt_at` plus `running` (no settling bucket: a version is seen whole or not at all) |
| `POST /analytics/rebuild` | `analytics.manage` | key | `{from, to, sync?: bool}`; `sync` only for at most 7 days and runs inline (what the live E2E uses, no cron wait); otherwise one `RebuildDay` job per day is queued; as built (4.4b) `to` defaults to `from` | `202 {queued: n}` / `200 {days: [...]}`; an `AuditedAction` `analytics.rebuild` records the range; as built both answers also carry `from`, `to`, `sync`, the 202 `days` (the dates queued), the 200 `days` each Rebuild result `{day, trigger, rows, version, changed, duration_ms, fingerprint}`; the audit row is `recordEvent` `{from, to, sync, days}`, written before the work | `422 analytics.range.too_large` (31 days queued, 7 inline) `{max_days}`, `409 analytics.rebuild.running` (as built: a queued rebuild of overlapping days still waits; `{from, to, asked_at}`), as built also `422 analytics.range.future {today}` |
| `GET /analytics/exports/{report}` | `analytics.view` | read | `report` in `sales`, `channels`, `items`, `stations`, `staff`, `shifts`; `?format=xlsx|pdf|csv`, `?locale=en|am`, the common range | the file (xlsx via a streaming writer, 20 000 rows max; PDF is a 1 to 3 page summary with the same figures and the freshness stamp); `422 analytics.export.too_large`; as built (4.4d) `{max_rows, rows}` beside it, an unknown report is `404`, xlsx by default, the rows are those the file holds (every table of an xlsx, the first of a CSV or a PDF), one audit row `analytics.export`, see its As built note |
| `POST /analytics/weekly-summary/send` | `analytics.manage` | key | `{week_of?}` | `202`; builds the same summary the Monday job does and announces `WeeklySummaryReady` (a way to test the Telegram path); as built (4.4d) `202 {week_start, week_end, run, summary}`, every send a new run (`manual-<uuid>`), one audit row `analytics.weekly_summary.send` | as built `422 analytics.weekly.week_open {week_start, week_end}` for a week that is not over |
| `GET /weekly-summaries/{week}` (as built, 4.4d) | **signed**, no bearer (the link of the weekly message) | read | `?branch&locale&expires&signature` | `200` the week's PDF, `Cache-Control: private, no-store`, `X-Robots-Tag: noindex, nofollow` | `403 analytics.weekly.link_invalid`, `404` |

### 4.7 Audit (Audit, step 4.5a; a gap in the manifest, 10.9)

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `GET /audit-log` | `audit.view` (admin, manager: read) | read | `?from=&to=` (local dates), `?actor_id=`, `?action=` (prefix match: `payment.`), `?subject_type=`, `?subject_id=`, `?station_id=`, `?shift_id=`, `?cursor=` (id), `?limit=` (50, max 200) | `200 {data: [{id, at, actor {id, name}, acting_user {id, name}?, action, subject_type, subject_id, station_id, shift_id, device_id, ip, summary}], next_cursor}` newest first; `name` comes from Shared `ActorDirectory` (Identity binds it; Audit imports nothing else); as built (4.5a) the seam is `Shared\Contracts\ActorNames`, `actor_id` finds the person as `actor_id` or `acting_user_id`, `summary` is `{changed, more, reason}` (see the As built note) | `422` (as built `validation.failed`) |
| `GET /audit-log/{id}` | `audit.view` | read | | `200` the row with `before` and `after` JSON as stored (already redacted by `Snapshot`: no PIN, no `reference_no`, no signed URL, no file key); as built (4.5a) also `context`, and all three redacted again on every read (rows older than a rule) | `404` |
| `GET /audit-log/export` | `audit.export` (admin) | read | the filters + `?format=csv|xlsx`, at most 50 000 rows; as built (4.5a) also `?locale=en|am`, oldest first | the file | `422 audit.export.too_large` (as built `{max_rows, rows}`) |

## 5. Domain events and listeners

Cross-module classes live in `App\Shared\Events` with array payloads (ids, decimal strings, bilingual strings; never a PIN, a key or a URL),
so Notifications and Analytics never import a producer.

| Event | Payload | Listeners (module) | Queued? |
|---|---|---|---|
| `PaymentRecorded` (exists) | adds `has_receipt`, `reference_dup` (bool) with their first consumer (4.3b kept the Phase 1 payload: nothing reads them yet) | Orders `ApplyPayment` (sync, same transaction; now also writes `payment.recorded`); Analytics `TouchToday` | sync; Analytics queued |
| `PaymentVerified` (new) | payment_id, order_id, channel_code, amount, by_user_id | Analytics `TouchToday` | queued |
| `PaymentDisputed` (new) | payment_id, order_id, channel_code, amount, reason, by_user_id, order_no, channel names en/am | Notifications `NotifyPaymentDisputed` (admins and managers: in-app, push, Telegram, deduped per payment), Analytics `TouchToday` | queued |
| `PaymentVoided` (new, in the manifest's event list but never built) | payment_id, order_id, channel_code, amount, reason, by_user_id, approved_by_user_id | Orders `ReversePayment` (**sync, in the voiding transaction**), Analytics `TouchToday` | sync + queued |
| `OrderRefunded` (new, Orders, after commit) | order_id, refund_id, amount, is_full, line_ids, movement_ids, by_user_id, approved_by_user_id, reason | Analytics `TouchToday` (and the refund's own day), Notifications `NotifyRefund` (admins: "a refund of 120.00 on order 17 approved by ...") | queued |
| `RefundPaid` (new, Payments, after commit) | refund_id, order_id, payment_refunds [{channel_code, amount}] | Analytics `TouchToday`; Shifts: nothing (it reads `Totals::cash`) | queued |
| `OrderPaid`, `OrderVoided` (exist) | | Analytics `TouchToday` | queued |
| `ShiftLocked` (exists, "no listener in Phase 3") | shift_id, station_id, date, locked_at, ... | Analytics `RebuildAfterShiftLocked`: the local dates from the shift's opening to its lock (one or two: a Night shift touches `date` and `date+1`), one unique job each | **yes** |
| `AnalyticsDayRebuilt` (new, Analytics) | day, trigger, version, changed | none (the freshness feed reads `analytics_days`); useful to tests | n/a |
| `WeeklySummaryReady` (new, Analytics) | week_start, week_end, headline figures, top items (en/am names), channel split, link (signed, 7 days); as built (4.4d) no link (the rule above: never a URL): `run`, `by_user_id` and the whole WeeklySummary, and Notifications makes the signed link when it writes the line (Shared `WeeklySummaryLinks`, bound by Analytics) | Notifications `NotifyWeeklySummary` (admins; in-app + Telegram; push optional; as built the holders of `analytics.manage`) | queued |

`TouchToday` is **debounced**: it dispatches `RebuildDay(branch, today, trigger=touch)` as a `ShouldBeUnique` job with `uniqueFor` = 120 s
and `delay` = 60 s, using the database cache's atomic lock (supported by the live host's `database` cache). So during service the dashboard's
today row is at most about three minutes behind (one cron tick plus the delay), the rebuild runs at most once every two minutes, and nothing
ever aggregates raw tables in a request. A day that is not today is rebuilt by the shift lock, the nightly job or by hand.

**As built (4.4b):** both listeners are `ShouldQueueAfterCommit` (a queued listener does not read `ShouldHandleEventsAfterCommit`, and
`PaymentRecorded` / `PaymentVoided` leave inside the payment's transaction). `TouchToday` rebuilds the event's own local day (never a day after
today), so a payment at 23:59:30 handled at 00:00:40 rebuilds the day it was taken on; it listens to `PaymentRecorded`, `PaymentVerified`,
`PaymentDisputed`, `PaymentVoided`, `OrderPaid` and `OrderVoided` (refunds join with 4.1b and 4.3d). `RebuildDay` is `ShouldBeUniqueUntilProcessing`:
the lock goes when the job starts, so a payment taken while a rebuild runs asks for the next one instead of waiting for the nightly run (with
`ShouldBeUnique` it would be dropped until then). `RebuildAfterShiftLocked` asks for every local day from the shift's `date` to the day it locked,
at most 31, never after today.

**Scheduler** (the existing `schedule:run` cron line, `routes/console.php`; all `withoutOverlapping`):

| Command | When (Addis Ababa) | What |
|---|---|---|
| `analytics:rebuild --nightly` | daily 00:05 | rebuilds **yesterday and the day before** (the first closes the boundary, the second heals a late lock), per branch, one job per day |
| `analytics:rebuild --since-last` | daily 05:30 | days with a `ShiftLocked` newer than their `rebuilt_at` (a Night shift that locked after 05:30 is also caught by its own event) |
| `analytics:weekly-summary` | Monday 07:00 | previous Monday to Sunday, announces `WeeklySummaryReady` |
| `files:purge-orphans` | daily 03:40 | unattached files older than 24 h: delete the object, then soft-delete the row; `--dry-run` lists |
| `files:doctor` | on demand | host checks (section 6, 4.2a) |

## 6. Module build order inside the phase

Small PRs, branches `phase-4/<step>`, each green in CI and deployed before the next, as in Phase 3. Inside a PR the order is always:
tests committed red, then the code, then registries (`write_endpoints.php`, `route_permissions.php`, `public_routes.php`), lang files in
both languages, OpenAPI export and ERD, and the module's STATUS line. Deploys run migrations (`release.sh`), so a PR's migrations must be
safe on the live database the moment it merges (no seeder is run by a deploy). The manifest's order is kept (contracts, files, payments,
analytics, web, docs); the sub-steps are this plan's own split. **Deviations from the manifest, argued in section 10:** an Audit read
API step (4.5a) before the Audit UI; provider-side Orders and Inventory work inside "contracts" (4.1b, 4.1c) because the contract tests cannot
be green otherwise; the real `Phase4Seeder` last, a skeleton first.

Build order of the migrations: 4.1b M6 M7 M8 M9; 4.1c M10 M11; 4.2a M1; 4.2b M2; 4.3a M3; 4.3b M4; 4.3e M5; 4.4a M12 to M16; 4.5a M17 (the numbers name them by topic, the steps order them).

1. **4.1 contracts and provider work** (STATUS `contracts`), three PRs. The entry gate passes after 4.1c.
   - **4.1a `phase-4/plan-and-contracts`**: this plan and the approval commit; the manifest, STATUS and skill-copy corrections of 10.9 (the
     `payments->files` contract, `analytics` tables, `Notifications.subscribes`, `e2e-p4` profiles); the contract files of section 2
     committed **red**; `Phase4Seeder` skeleton; `config/files.php` and `config/analytics.php` placeholders with plain values (so
     `DeployableCachesTest` keeps passing); the `e2e/p4/` spec files as `test.fixme` stubs so the Playwright projects list at least one test (a project of an `in_progress` phase may not be empty); `gate_check --mode done` forbids `->skip(`, so every stub is turned into a real test by 4.6b and the PR description keeps the list. Phase 4 `in_progress`.
   - **As built (step 4.1a, `phase-4/plan-and-contracts`).** Commits in the order the plan gave: the plan with the approval (`plan_approved: true`, phase 4
     `in_progress`, module `contracts` `in_progress`); the manifest, skill-copy, CI and runbook corrections; the three contract files red; the skeletons and stubs;
     this note. Where the code disagreed with the draft, or the draft left a choice:
     - **Contracts are red in the group the entry gate reads and invisible to CI.** `contract:analytics->facts` is red (10 of 10) and `mod-analytics` no longer lists it in
       its `contracts` input (`.github/workflows/ci.yml`; the skill template keeps it) until 4.1c adds it back, because `mod-analytics` would fail the whole run otherwise.
       The nine new `payments->orders` cases carry only `contract:payments->orders:refund` (8 red; the part-payment case passes because it pins existing behaviour),
       which no job runs; `FilesContractTest` carries `contract:payments->files` (8 of 8 red, class `App\Shared\Files\Files` missing), run by `mod-payments` from 4.2a.
       `OrdersConcurrencyTest` (case 12, two forked tills paying the last balance) **passes now**: Orders has locked `forPayment` since step 1.4; it pins the promise.
     - **New tables of earlier-phase modules are not in `modules.<m>.tables` yet.** `gate_check --mode done` for phases 0 to 3 requires the ERD to cover every listed table, and
       `stored_files`, `stock_movement_reversals`, `receipt_settings` and `payment_refunds` do not exist. They sit under `tables_phase_4` and move to `tables` in the PR that
       migrates each (4.1c, 4.2a, 4.3a/4.3d). The analytics tables are listed at once: their module's phase is 4. (gate-p0 caught this on the first push.)
     - **`payments->files` is not in `phases.4.contracts_required`.** The entry script requires every listed contract green when its provider is from an earlier phase,
       and Shared is phase 0. The manifest lists it under `contracts` and under a new `phases.4.intra_phase_contracts` (`payments->orders`, `payments->files`).
     - **`Orders::refund` signature pinned:** `refund(int $orderId, string $reason, User $approver, array $lines, array $dispositions, string $clientUuid): void`; lines are
       order-line ids (empty: the whole order), dispositions map a line id to `restock|waste|none`; a refusal is an `OrderError` with a `message_key`. The `client_uuid` is
       there because `order_refunds` is unique on it (M7). **No refund stock logic and no default disposition exists in this step** (R-C is open); the cases name the
       disposition explicitly, so they hold whichever defaults Ramin chooses.
     - **New names the cases pin:** `App\Shared\Events\PaymentVoided(branchId, paymentId, orderId, channelCode, amount, reason, byUserId, approvedByUserId)`,
       `App\Shared\Events\OrderRefunded` (payload `order_id`, `amount`, ...), `OrderTotalsView::$refunded` and `$netPaid`, `App\Shared\Time\LocalDay::between(from, to)`,
       the `Facts` classes in each `Contracts` namespace (their `toArray()` keys are in the test), `App\Shared\Files\Files::put/signedUrl` returning an object with
       `id`, `key`, `width`, `height`, and error keys `files.not_an_image`, `files.truncated`, `files.too_many_pixels`, `files.too_large`.
     - **The Facts factory setup was not exercised:** all ten cases stop at `LocalDay` before their rows are built, so the factory attributes (orders, payments, batches,
       shifts, cost history, `stock_alert_states`) are first proved by 4.1c, which may adjust the setup, not the expectations.
     - **`Phase4Seeder` is a skeleton:** it refuses a branch-less database like `Phase3Seeder`, prints one line and writes nothing; `DemoSeeder` composes it after `Phase3Seeder`
       (`DemoSeederTest` asserts `seedersUpTo(4)`). `config/files.php` and `config/analytics.php` hold plain values only; nothing reads them yet.
     - **`DemoClock`** anchors inside `around()` (`subDays(3)`); `anchor()` is the getter. Section 8 now says the days back become a function of the phase (4.6a).
     - **`e2e-p4` runs `full`, not `full,ws`** (10.9 item 3); the MinIO TODO in `ci.yml` is replaced by a note: e2e uses the local disk, as the live host does.
     - **Nine `e2e/p4` stubs** (`test.fixme`, one Phase 4 TODO marker each), 4.6b replaces every one but the refund flows': `pay-photo-split`, `pay-sheet-logic`, `pay-refund`, `pay-void`, `pay-camera-fallback`
       (project `p4-pay-photo-split`) and `dashboards`, `dashboards-live`, `dashboards-audit`, `dashboards-logic` (project `p4-dashboards`).
     - **Local run notes:** `DeployableCachesTest`'s two `config:cache` cases time out at 60 s on this PC's Docker (a bare `config:cache` takes about a minute there) and are left to CI;
       the new config files evaluate without error. `gate:p3` ran green here (197 tests) in about 25 minutes.
   - **4.1b `phase-4/orders-refund`**: M6 and M7 to M9; Inventory `Reversals` (inside the Ledger, same lock order, writes `return` plus the
     reversal row); Orders `Refund` action behind `Orders::refund`, `ReversePayment` listener, `payment.recorded` / `payment.voided` /
     `line.refunded` / `order.refunded` events on the timeline, `OrderRefunded`, `PaymentVoided` events, `OrderResource` gains
     `refunded_total` and `refunds`. `OrderStatus` is **not** changed (an order stays `closed` after a refund; `refunded_total` says how much
     came back), so the exhaustive state-machine table of Phase 1 does not move. Contract cases 5 to 13 of `payments->orders` and 13 to 15 of
     `orders->inventory` go green with Payments untouched; `VoidOrderTest` still refuses a paid order (`orders.order.paid`).
   - **Moved to step 4.3c (2026-10-26), while 4.1b waits for Ramin's answer on R-C.** A void is not a refund (it writes no stock movement and does not touch R-C), so
     4.3c built the void side of 4.1b and **4.1b no longer does**: the `PaymentVoided` event (`App\Shared\Events`, the signature 4.1a pinned), Orders' `ReversePayment`
     listener, `payment.recorded` (written by `ApplyPayment`, with `PaymentRecorded` gaining `has_receipt`) and `payment.voided` on the timeline, and the payment half
     of M9 (`2026_11_02_100200_extend_order_events_check_for_payments`); contract case 10 (the void) is green and carries `contract:payments->orders` too. **What 4.1b
     still builds:** M6, M7, M8 and the refund half of M9 (a new migration adding `line.refunded` and `order.refunded` to the 20 events the CHECK now lists; the
     4.3c migration is not edited, it has run on live by then), Inventory `Reversals`, the `Refund` action behind `Orders::refund`, `OrderRefunded`,
     `line.refunded` / `order.refunded`, `OrderTotalsView::$refunded` / `$netPaid`, `OrderResource`'s `refunded_total` and `refunds`, and contract cases 5 to 9, 11
     and 13 (case 11, part payments, passes already and only needs the group name). `ReversePayment` refuses a `paid`/`closed` order with
     `OrderError::paymentVoidOrderClosed` (key `payments.void.order_closed`); 4.1b's refund needs no change to it.
   - **4.1c `phase-4/facts-ports`**: M11; Shared `Time\LocalDay` (a `from`/`to` pair of local dates, `utcBounds(tz)`, `dayOf(instant, tz)`,
     inclusive ends; the branch's timezone, default `Africa/Addis_Ababa`); the five `Facts` classes with their readonly DTOs under each
     provider's `Contracts/` (`Orders\Contracts\Facts::sales/lines`, `Payments\Contracts\Facts::payments/refunds` (refunds read `payment_refunds`: M10 lands in
     this PR as schema only, nothing writes to it until 4.3d, so the port returns `[]` and its contract case seeds rows through a factory), `Production\Contracts\Facts::batches`,
     `Inventory\Contracts\Facts::movements/closingBalances/stockOuts/unitCosts`, `Shifts\Contracts\Facts::instances`); each returns a
     generator over a chunked, ordered query. M10 and M11 are in this PR. `contract:analytics->facts` green with `app/Modules/Analytics` absent. Groups added to the
     provider jobs. ModuleBoundariesTest: Analytics may use `*\Contracts\Facts*`, `Catalog\Contracts\*`, `Identity\Contracts\ActorDirectory`
     and `Shared\*` only; the arch test fails on any model import from Analytics.
   - **As built (step 4.1c, `phase-4/facts-ports`).** Order: M10 and M11, the ERD line and the `AppendOnlyTest` seed for `payment_refunds`, the `LocalDay` tests (red);
     `LocalDay` and `DayRows`; the five ports with their row classes and the contract setup fixes; the Analytics arch rule; the CI wiring; this note. Where the code
     disagreed with the plan or the plan left a choice:
     - **`LocalDay`** is `between(from, to, ?tz)` (also `of(day)`), with `timezone()`, `utcBounds(?tz)` returning `[start, end)` as UTC instants (end = the start of the day after
       `to`), `dayOf`, `hourOf`, `days`. The zone is the one given, else the current branch's, else `config('bakery.timezone')`. `DayRows::generate` is the one reader every port
       uses: it walks the local days of the range and runs a keyset-chunked query (`id > last ORDER BY id LIMIT 500`) inside each day, so the order is `(day, id)` and the
       clock filter is `clock >= start AND clock < end`, which the `(branch_id, clock)` indexes serve.
     - **M11 added two indexes, not six.** `payments (branch_id, received_at)`, `stock_movements (branch_id, created_at)` and `shift_instances (branch_id, date)` already exist and lead with the
       same columns; `orders (branch_id, voided_at)` and the three-column variants are not needed (voided orders are excluded by status). New: `orders (branch_id, settled_at)` and
       `production_batches (branch_id, finished_at)`. The plan's "only if EXPLAIN shows" is applied by reading the existing definitions, not by an EXPLAIN test.
     - **M10 `payment_refunds`**: `order_refund_id` and `proof_file_id` are plain unsigned columns; **4.1b adds the foreign key to `order_refunds` in its M7 migration, 4.2a the one to `stored_files`**
       (the way `shift_instance_id` waited for Shifts). Append-only (triggers), CHECK `amount > 0`, listed in `APPEND_ONLY_TABLES`. The manifest moved `payment_refunds` from
       `tables_phase_4` to `tables`.
     - **Port details.** Row classes are `SaleFact`, `LineFact`, `PaymentFact`, `RefundFact`, `BatchFact`, `MovementFact`, `ClosingBalanceFact`, `StockOutFact`, `UnitCostFact`, `ShiftFact`
       (named `*Fact`, so the arch rule allows `Facts` and `*Fact` of each provider's Contracts). Sales and lines count `paid` and `closed` orders only, by `settled_at`; lines leave out
       rejected and voided ones. Payments leave out voided, keep disputed. `closingBalances(day)` yields one row per (item, location) with `on_hand` and `reserved`, from the Ledger's own
       `sumsAt`, so it is the `Stock::balanceAt` oracle by construction. `unitCosts(day)` is the history row with the highest id written before the next local midnight. `Shifts\Facts`
       returns a shift once: found by its `date` in the range or by its life (`opened_at` to `locked_at`/`cancelled_at`, open-ended while running) overlapping it; `closed_at` is `submitted_at`
       (there is no closed_at column). The ledger read sits in `Inventory\Ledger\FactsReader` because the arch tests keep the movement model inside the Ledger namespace.
     - **Contract setup fixes (expectations unchanged).** The refunds case called `Orders::refund` and read `order_refunds`, both 4.1b's (on hold); it now uses a plain `order_refund_id = 1`
       (no FK until 4.1b, which should swap in the real refund). The batches case built three requests and the factory made a second `cake_room` station per branch; it now shares one station.
     - **CI**: `contract:analytics->facts` runs in `mod-inventory`, `mod-orders`, `mod-payments`, `mod-production`, `mod-shifts` and `mod-analytics`. Arch rule: Analytics may use the
       Facts classes, `Catalog\Contracts\*`, `Identity\Contracts\ActorDirectory` (not built yet) and Shared; `Identity` joined Analytics' allowed modules in the dependency table for it.
     - STATUS: module `contracts` stays `in_progress` until 4.1b lands.
2. **4.2 shared.files** (STATUS `shared.files`), two PRs.
   - **4.2a `phase-4/files-core`**: M1; `App\Shared\Files\Files` (final, resolved per use): `put(UploadedFile|string $bytes, purpose, clientUuid): StoredFile`,
     `signedUrl(StoredFile|key, ?ttl): string`, `stream(StoredFile)`, `delete(StoredFile)`, `exists`; `ImageProcessor` (decode, apply orientation, resize to a
     longest side of 1600 px without enlarging, flatten alpha onto white, re-encode as JPEG quality 82, which drops every metadata block because the encoder writes
     none; verify by re-reading the result); `config/files.php` (plain values): `disk` (env `FILES_DISK`, default `files`), `max_bytes` 8 388 608,
     `max_pixels` 24 000 000, `max_edge` 1600, `quality` 82, `url_ttl_seconds` 300, `orphan_hours` 24, `allowed` (`image/jpeg`, `image/png`, `image/webp`);
     **the disk**: `config/filesystems.php` gains `files` (driver `local`, root `storage/app/private/files`, `visibility` private, `throw` true) and
     `files_s3` (driver `s3`, the `AWS_*` variables that already exist, `use_path_style_endpoint` from env, no `url`, `visibility` private); the code only ever talks to
     `Storage::disk(config('files.disk'))`, `exists/get/put/delete`, and `signedUrl()` is the single place that knows the difference: for the `s3` driver
     `Storage::disk()->temporaryUrl($key, $ttl)`, for `local` `URL::temporarySignedRoute('files.show', $ttl, ['file' => $id])` (Laravel's own local `serve` route
     is not used so the headers and the log scrub are ours). The object key is `receipts/{branch_id}/{yyyy}/{mm}/{uuid}.jpg`, no original name ever stored.
     **Portability** (the way ADR 0006 treats the database): switching to R2 or MinIO is `FILES_DISK=files_s3` plus the `AWS_*` variables and `rclone`/`aws s3 sync`
     of `storage/app/private/files` into the bucket under the same keys; `stored_files.disk` records where each object lives, so a half-moved bucket still
     resolves. `league/flysystem-aws-s3-v3` is added to composer as a dependency only used by the portability test (`composer require` pinned; it adds no PHP extension).
     **PHP image extensions** the code needs: `gd` (with JPEG, PNG, WebP and FreeType support: the Dockerfile's `docker-php-ext-configure gd --with-freetype --with-jpeg`
     gains `--with-webp`, and `libwebp-dev` in the apt line), `exif` (new in `docker/php.Dockerfile`: `docker-php-ext-install exif`, used only to *read* the orientation tag
     before the strip; **optional at runtime**, a 40-line pure-PHP JPEG APP1 reader (`ExifOrientation`) is the fallback when the extension is missing), `fileinfo` (MIME sniffing,
     bundled), `mbstring` (dompdf and Excel, bundled), `zip` (xlsx, already in the image), `dom` and `xml`/`xmlwriter`/`xmlreader`/`libxml` (dompdf, OpenSpout; bundled), `intl`,
     `bcmath` (already there). Imagick is **not** assumed (shared hosts rarely have it). **How to check them on the host**: a new `php artisan files:doctor` prints, per row,
     `ok`/`missing`/`warn`: the PHP version and SAPI, each extension above through `extension_loaded`, `gd_info()` flags (`JPEG Support`, `PNG Support`, `WebP Support`,
     `FreeType Support`), `memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, disk free space and used size of the files folder, writability of
     `storage/app/private/files` and `storage/fonts`, and a **round trip**: it generates a 3000x2000 JPEG with a planted GPS block, runs it through `ImageProcessor`, reads it back and
     reports `exif stripped: yes`, longest edge, bytes and milliseconds. The runbook gets the one-liner for the CLI/cron PHP, the same checklist row as Phase 0 step 4:
     `/opt/alt/php83/usr/bin/php -m | grep -E 'gd|exif|fileinfo|mbstring|zip|dom|xmlwriter|xmlreader|libxml'` and
     `/opt/alt/php83/usr/bin/php ~/bakery-ops.orcamind.dev/artisan files:doctor`; because the **web** PHP and the CLI PHP can differ on cPanel (MultiPHP INI Editor, Select PHP
     Version extensions), the admin also gets `GET /files/doctor` (`channels.manage`) that returns the same table as seen by the web SAPI, and the 4.2b PR's live check compares the two.
     If `gd` lacks WebP the upload endpoint answers `422 files.unsupported_type` for WebP only; if `exif` is missing nothing changes; if `gd` is missing the feature is off and
     `files.upload` routes answer `503 files.unavailable` (the doctor says so first). Tests: `ImageProcessorTest` (orientation 1..8 fixtures made by injecting APP1 blocks
     into a generated JPEG, EXIF and GPS gone, ICC gone, 1600 px, no enlarge, alpha flatten, truncated and polyglot files refused), `ExifOrientationFallbackTest`,
     `FilesPutTest`, `SignedUrlTest`, `FilesDoctorTest`, `FilesCreateNoPublicPathTest`, `DeployableCachesTest` (config cached with and without `FILES_DISK`).
   - **As built (step 4.2a, `phase-4/files-core`).** Order: the manifest move, the tests (red), then `ExifOrientation`, `ImageProcessor`, `Files`, `StoredFile`, M1, the disks, the
     doctor, the signed route, lang, ERD, runbook. Where the code disagreed with the plan or the plan left a choice:
     - **One route is registered now: `GET /api/v1/files/{file}` (`files.show`)**, loaded by `SharedServiceProvider` from `routes/modules/files.php`, middleware `api` + `signed`, served by
       `Shared\Files\Http\ServeFile` (headers as section 4.1). The contract test fetches the signed URL, so the URL level alone was not enough. It is listed in `tests/Support/public_routes.php` and
       `PUBLIC_ROUTES` of `scripts/check_invariants.py` already; 4.2b adds the other four routes around it and need not touch these lists for it. A bad or expired signature answers
       `403 files.invalid_signature` (bilingual, a render in `bootstrap/app.php`); a deleted row or missing object is `404`.
     - **Error keys.** `FilesError::KEYS`: `files.not_an_image`, `truncated`, `too_many_pixels` (`{max_pixels}`), `too_large` (`{max_bytes}`), `unsupported_type` (a GIF, or WebP when `gd` lacks it),
       `unavailable` (503, no `gd`), `processing_failed` (500, the re-read failed) and `invalid_signature`. The plan's `files.missing` belongs to the 4.2b request validation.
     - **Refusal order** (header first, before anything is decoded): not an image, unsupported type, pixels, bytes, then the whole-file check (a JPEG must end at its EOI, a PNG at `IEND`, a WebP at its
       RIFF size; anything non-zero after the end is `files.not_an_image`, the polyglot case), then decode (a decoder warning is `files.truncated`). Pixels come before bytes because a flat 25 MP photo is small in bytes.
       The byte limit lives in `ImageProcessor` (`maxBytes`), not in `Files`.
     - **GD writes a `CREATOR` comment** (libgd's COM segment) next to the JFIF header, so the encoder alone does not leave "no metadata". `ImageProcessor::scrub` drops every COM and APP1 to APP15 segment
       before the scan; the re-read then checks that none of them is left.
     - **EXIF orientation** is read from a `php://memory` stream by `exif_read_data` when the extension is there (a `data://` URL needs `allow_url_fopen`, which a shared host may switch off), and by
       `ExifOrientation::fromJpeg` otherwise or when the extension finds no tag. Orientations 1 to 8 are tested through both readers.
     - **`Files`**: `put` is idempotent on `(branch_id, client_uuid)` and checks that first (a second call is not even decoded); the object is written first and removed again if the row cannot be saved
       (a lost race on `client_uuid` returns the winner's row). `delete` removes the object **and** soft-deletes the row (the 4.2b purge calls it; the plan said the object goes only with the purge, which is
       still the only caller). `signedUrl` takes the disk from the row, not from `config('files.disk')`. `StoredFile` hides `key` and `sha256` from any serialised form. `stream` returns a read resource.
     - **M1** is `2026_10_29_100100_create_stored_files_table`; the foreign key `payment_refunds.proof_file_id` is `2026_10_29_100200_add_payment_refunds_proof_file_foreign_key` (an `ALTER` of the empty table).
       `stored_files` moved from `tables_phase_4` to `tables` in the manifest; the ERD has it. The actor stamp columns are nullable (the doctor and seeders have no actor).
     - **Disks and config.** `files` has `serve => false` as well as `visibility private`, `throw true`; `files_s3` uses the existing `AWS_*` variables and no `url`. `league/flysystem-aws-s3-v3` is pinned at `3.35.3`
       (exact, like `web-push`) and is used by `SignedUrlTest` (a presigned URL needs no network) and, in 4.2b, the MinIO job. `config/files.php` keeps plain values (comment updated).
     - **Dockerfile**: `libwebp-dev`, `--with-webp`, `exif` in the extension list. Nothing is fetched. Locally the image was not rebuilt (the running dev image has no `exif`; the exif-agreement tests report "risky" there and
       run in CI), and the lock was updated but `composer install` could not download the new packages while GitHub was unreachable, so `SignedUrlTest`'s S3 case ran in CI only.
     - **`files:doctor`** is `App\Shared\Files\Doctor` (rows `check`, `status`, `detail`, also the data 4.2b's `GET /files/doctor` returns) plus `App\Console\Commands\FilesDoctorCommand`; exit 1 when a row is `missing`.
       `exif`, WebP, FreeType, a low `memory_limit` and a missing `storage/fonts` are `warn`. The runbook says how to read it.
     - **CI**: `contract:payments->files` is added to `mod-payments`; the unit and `module:shared` tests run in the foundation job. `DeployableCachesTest` caches the config with `FILES_DISK` unset and `files_s3`.
     - STATUS: `shared.files` was `in_progress` here; it went `done` with 4.2b.
   - **4.2b `phase-4/files-api`**: `POST /files`, `GET /files/{file}/url`, `GET /files/{file}` (signed), `DELETE`, `GET /files/doctor`, `files:purge-orphans`; `files.upload` and
     `payments.view` permissions (M2); the multipart branch of `Idempotency`; log scrub (a Monolog processor removes `signature=` and `expires=` from any logged URL and
     never logs a key); `FilesNoPublicAccessTest` (the object path is not under `public/`, `public/storage` has no `files`, a plain GET of `/files/{id}` without signature is 403);
     the portability job `files-s3` (Pest group `files-s3`: `put`, `signedUrl` fetched over HTTP, `delete` against **MinIO** in the e2e compose stack, see 10.1 R-F; `compose.ci.yml`
     gains `minio` and an `mc` init container creating the bucket, `AWS_ENDPOINT=http://minio:9000` for the app container versus `AWS_URL=http://localhost:9000` for the browser,
     the two-hosts trap in traps.md); runbook "Files" section (where they live, quota, backup, the move to S3). `shared.files` goes `done`.
   - **As built (step 4.2b, `phase-4/files-api`).** Order: the registries (per-method public routes, a multipart replay in the write registry), the tests (red, 40 of 44 failing for the missing
     routes, command, processor and migration), then the code, the compose stack and CI job, the runbook. Where the code disagreed with the plan or the plan left a choice:
     - **The controller is `App\Http\Controllers\Api\FilesController`, not in `Shared`.** `Shared` is a leaf that imports no module (`ModuleBoundariesTest`), and the writes are audited by `Audit`. The routes stay in
       `routes/modules/files.php` (loaded by `SharedServiceProvider`). The audit rows are `AuditedAction::recordEvent('file.upload' | 'file.delete')` with the purpose and size only: `Snapshot::of` would have
       stored the raw `key` attribute, which 10.1 R-F keeps out of the audit.
     - **`DELETE /files/{file}` shares its uri with the public signed read.** The public-route lists only knew uris, so they would have treated the DELETE as public and skipped every coverage check. An entry may now
       be `'METHOD uri'` (`tests/Support/public_routes.php`, `isPublicRoute()`, `PUBLIC_ROUTES` of `scripts/check_invariants.py`); the entry is `GET api/v1/files/{file}`.
     - **Who may delete and view.** The plan said "the uploader (or `payments.verify`)"; `payments.verify` is not in the registry until 4.3c, so the delete is the uploader's or a `payments.void` holder's (admin, manager).
       `GET /files/{file}/url` needs `payments.view`, or `files.upload` for one's own unattached upload (403 otherwise). The route middleware is `permission:payments.view|files.upload`; the registry row says `payments.view`.
       An attached file is `409 files.attached`; the "younger than 24 h" clause is dropped (the purge is the age rule).
     - **Errors.** `files.missing` (422: no usable file part) and `files.attached` (409) joined `FilesError::KEYS`, both languages. A part the server cut at `upload_max_filesize` is `files.too_large`. The response of
       POST is `{id, purpose, width, height, bytes, sha256, url, url_expires_at, attached}`; 201 first time, 200 for a used `client_uuid`.
     - **M2** is `2026_10_30_100100_grant_phase_4_permissions` (thin caller of `GrantRegistryPermissions`), for `files.upload` and `payments.view` only. Each later Phase 4 step that adds a permission (`payments.verify`,
       `analytics.manage`, `audit.export`) adds its own dated migration calling the same class, because this one has run on the live database by then.
     - **Multipart `Idempotency`.** The fingerprint replaces each uploaded file by the sha256 of its bytes (never its name or temp path). Same key and file: the stored response is replayed before any controller runs.
       Same key, another file or field: `422 idempotency.key_reused`. `assertIdempotent()` takes an optional `files` closure so the registry replays `POST /files` as multipart.
     - **`files:purge-orphans`** (all branches, `chunkById`, through `Files::delete`, exit 1 if any removal failed), hourly with `withoutOverlapping(30)`. It never touches an attached file.
     - **Log scrub.** `LogScrubProcessor` removes `signature=`, `expires=` and the S3 `X-Amz-*` parameters from message, context and extra, and replaces anything shaped like `receipts/{branch}/...` with `[file key]`. It is
       installed by the `ScrubLogs` tap on every leaf channel of `config/logging.php`. An exception object in the context is not scrubbed (the files code never puts a URL or a key in a message).
     - **MinIO.** `minio/minio` and `quay.io/minio/minio` are not pullable any more (checked 2026-10-25). The pinned image is `bitnamilegacy/minio:2025.7.23` (the last archived Bitnami build of the same server; it ships `curl` for
       the health check and the `mc` client). `compose.yml` keeps `minio` in the `s3` profile; `compose.ci.yml` adds the `mc` init service (bucket `bakery`, throwaway credentials `bakery` / `bakerysecret`, public repo, CI and local use
       only) and publishes port 9000. The `files-s3` job in `ci.yml` starts `app minio`, runs `mc`, then `pest --group=files-s3` with `FILES_S3=1` (the tests skip without it, so no module job meets MinIO), and fails if
       anything was skipped. `gate-p4` needs it. The test fetches the presigned URL from inside the app container, with the host it was signed for (`AWS_ENDPOINT`); `AWS_URL` became `http://localhost:9000` as the browser-side name.
     - **Runbook** `docs/runbook/server.md`, "Files (step 4.2b)": where the files live, who may do what, orphans, logs, quota, backup (database and folder together), the move to S3 or R2.
     - STATUS: `shared.files` is `done`.
3. **4.3 payments (full)** (STATUS `payments`), five PRs. Payments is `stable` again after 4.3e.
   - **4.3a `phase-4/channels-admin`**: M3; channel create, rename, reorder, switch off, soft delete, pattern validation (a test table of good and bad patterns, including
     `(a+)+$` refused), `cash_locked`, `GET /receipt-settings` placeholder; bilingual `lang/*/payments.php` keys. Tests: `ChannelAdminTest`, `ChannelPatternTest`,
     `ChannelOrderTest`, 403 matrix. `Channels::enabled()` keeps its shape (additive fields).
   - **As built (step 4.3a, `phase-4/channels-admin`).** Order: the tests and the registries (red: 139 of 143 failing on the missing routes and `ReferencePattern`), then M3,
     `ReferencePattern`, `ChannelRules`, the three actions, the controllers and routes, lang; then OpenAPI, ERD, STATUS and this note. Where the code disagreed with the plan or the plan left a choice:
     - **M3** is `2026_10_31_100100_extend_payment_channels_for_rules`: six nullable columns with no default and nothing backfilled, so on the live database every channel of every branch
       gets `NULL` in `client_uuid`, `reference_pattern` and the four hints and keeps its `requires_photo`, `requires_reference`, `is_enabled`, names and sort: a reference is still any
       non-empty text and no hint is shown. Each piece is added only when missing (a second run is a no-op). **`client_uuid` is an addition to the plan's column list**: the plan's POST body
       carries one and every write that creates a row carries one, while the Phase 3 configuration tables did without; with `UNIQUE (branch_id, client_uuid)` a retry whose answer was lost gets the
       same channel back (`200`) instead of `code_taken`. No column marks cash: it is the row with code `cash`, as `Totals` and `RecordPayment` already read it.
     - **The list.** `GET /payment-channels` keeps its Phase 1 answer (live channels, switched-off ones included, in sort order) with the new keys after the old ones; `?all=1` is the admin's
       (`channels.manage`, else `403 auth.forbidden`) and adds the removed channels after the live ones, with `deleted_at`. The plan said `?all=1` adds the disabled ones, which the Phase 1 list
       already shows. The route moved from `PaymentsController` to `ChannelsController` (same name `payments.channels`). `accepted_now` is still `is_enabled && !requires_photo`: a photo channel
       is refused by `RecordPayment` until 4.3b attaches the photo, and 4.3b changes `accepted_now` with it.
     - **Codes.** Lower case letters, digits, `_` and `-`, at most 32. A code the branch has, a removed channel's included, is `422 payments.channel.code_taken`. The code never changes: `code` in a
       PATCH is `prohibited` (`422 validation.failed`), not "immutable once a payment exists": the till sends it and `Totals` finds cash by it. A new channel goes last (`max(sort)` over all
       channels, removed ones included, plus one) unless a sort is given; sort is 0 to 32767 (SMALLINT).
     - **The cash lock** (`Support\ChannelRules`, `409 payments.channel.cash_locked` with the refused `field`): cash stays enabled, is never removed, and asks for no photo, reference or pattern,
       checked on the state the channel would have after the write. Its names, sort and hints are the admin's.
     - **Reference patterns** (`Support\ReferencePattern`, plain PHP, `ChannelPatternTest` 78 cases). The body is matched against the whole reference (`/\A(?:body)\z/u`; an unescaped `/` is escaped).
       Accepted: literals, `.`, classes, `\d \D \w \W \s \S` and escaped punctuation, `(...)`, `(?:...)`, `|`, `^`, `$`, `? * + {n} {n,} {n,m}` (lazy allowed) with counts up to 64. Refused as
       `422 payments.channel.pattern_invalid` with a `reason`: `too_long` (over 120); `does_not_compile` (on its own, so `a)|(b` cannot break out of the anchors, then anchored); `unsupported`
       (lookaround, backreference, named group, inline option, atomic group, recursion, verbs, `\p`, `\Q`, `\x`, `\b`, possessive repeats, counts over 64, an unescaped literal brace, POSIX classes,
       control characters, invalid UTF-8); `nested_quantifier` (a group that can repeat more than once and holds a variable repeat or a choice anywhere inside: `(a+)+$`, `(a|aa)+`, `(.*a){20}`;
       fixed counts such as `(\d{4}-){3}` pass; the rule errs on the safe side, `((a|b)c)+` is refused too); `backtracks` (a bounded probe over runs of 64 reference characters hit the PCRE
       limit, e.g. ten adjacent `\d*`). **Never unbounded:** `matches()` refuses a reference over 64 characters without running anything and runs with `pcre.backtrack_limit` 100 000 and
       `pcre.recursion_limit` 10 000 (put back afterwards), so even a stored `(a+)+$` answers "no match" in milliseconds; PCRE warnings never reach the caller. The framework trims request
       strings, so a pattern's leading and trailing spaces are dropped; an empty pattern clears it. **The pattern is stored, not yet applied**: `RecordPayment` checks it in 4.3b
       (`payments.reference.invalid`).
     - **Hints** are pairs: a request that names one of a pair names both, and both are text or both null (`present_with` plus `required_with`), at most 120 characters each.
     - **Reorder** (`ReorderChannels`): the live channels (enabled or not) are locked `FOR UPDATE` in an outer transaction first, so the audit context's `before` is the order being replaced. The list
       must name each exactly once, else `422 payments.channel.order_incomplete` with `missing` and `unexpected` (unknown, removed, another branch's or repeated ids), both ascending; then sort is
       1..n and only changed rows are written, in one `AuditedAction` `payment_channel.reorder` (subject `none`, context `{before, after}`). Removed channels keep their old sort.
     - **Writes**: `payment_channel.create`, `.update` and `.delete` (soft; the payments made on a removed channel keep pointing at it and the till answers `payments.channel.unknown` for it),
       each one `AuditedAction`. No new permission: `channels.manage` (admin) is in the registry since Phase 1, so this step has no grant migration.
     - **`GET /receipt-settings`** (`payments.take`) answers the defaults of a branch that has saved nothing: `business_name_en` and `_am` are the branch's name; address, phone, TIN and footer are
       `null`; `show_amharic` true, `ethiopian_dates` false; plus `is_default: true`. 4.3e adds M5, the PUT and the stored row in the same shape.
     - **Lang**: `payments.channel.code_taken`, `pattern_invalid`, `cash_locked` and `order_incomplete` in both languages (`PaymentsLangTest` now counts 14 keys).
     - **Phase 1 tests that changed**: `PaymentChannelsTest` (the nine Phase 1 keys stay first and in order; the seven new keys follow) and `PaymentsLangTest` (four keys). The matrix test gained a
       `{channel}` placeholder (a digital channel of the caller's branch).
     - **The migration test** (`ChannelRulesMigrationTest`) runs M3's real `down()` and `up()` on rows written by the Phase 1 code path (an edited branch, a plain one, and one created between the
       two). DDL commits the transaction `RefreshDatabase` wraps a test in; `RefreshDatabase` notices and migrates afresh before the next test (about 30 s locally), so it is the last test of its file.
     - STATUS: phase 4's `payments` step is `in_progress`, edited by hand as 4.2a did (`phase_status.py module` writes the module's own phase, which is 1 for Payments). The top-level module stays `stable`.
   - **4.3b `phase-4/payments-record-v2`**: M4; the new `RecordPayment` rules of 4.3 above, `payment-batches`, `GET /orders/{order}/payments`, `GET /payments` with `fingerprint()`,
     `GET /payments/{payment}`, the shift rule for every channel (10.1 R-A), duplicate warnings, the file attach (`attached_*`). **Phase 1 tests that change, listed so the PR is honest:**
     `RecordPaymentTest` (a digital channel without a photo used to be `payments.channel.photo_required` for *every* digital payment, now only without a `receipt_file_id`), the part-payment case
     of `payments.amount.mismatch` (now accepted), `ChannelsEndpointTest` (`accepted_now`; the file is `PaymentChannelsTest`), and `Phase1StateMachinesTest` for the new `disputed` exits (moved to 4.3c,
     see the note below). The Playwright Phase 1 flows only use cash and are untouched.
   - **As built (step 4.3b, `phase-4/payments-record-v2`).** Order: the tests and the registries (red: 62 failing cases, each on a missing route, column, class or method: the new and changed Payments files, the two new Orders contract
     cases and the Files attach), then M4, the Orders read ports, `RecordPayment` v2, `RecordPaymentBatch`, the reads and lang; then OpenAPI,
     ERD, runbook, the ADR 0013 note and this note. Where the code disagreed with the plan or the plan left a choice:
     - **M4** is `2026_11_01_100100_extend_payments_for_phase_4`: the plan's columns plus INDEX `(branch_id, batch_uuid)` (a batch is answered again by it); CHECKs `payments_amount_positive`
       and `payments_refunded_amount_range`. The only backfill is `reference_norm` of the Phase 1 rows that carry a reference (chunked, `updated_at` untouched, with the migration's own copy of
       the rule; `ReferenceNumberTest` keeps it equal to `Payments\Support\ReferenceNumber`: upper case, no white space, separators or dashes). Before any DDL, a row with `amount <= 0` (the code
       never wrote one) stops it with the ids, so MariaDB's non-transactional DDL cannot be left half applied; each piece is added only when missing. `PaymentsPhase4MigrationTest` runs `down()`
       and `up()` on Phase 1 rows: every Phase 1 column identical, `updated_at` included, `refunded_amount` 0.00, nothing attached, batched or disputed, a second run a no-op.
     - **`PaymentStatus` does not change here.** M4 is columns only and the status CHECK keeps its list, so `disputed -> verified|voided` and `Phase1StateMachinesTest` move to 4.3c, which builds the
       dispute (the M4 row said "the same PR"; corrected).
     - **R-A's switch** is `require_shift => env('PAYMENTS_REQUIRE_SHIFT')`, null by default, read by `Support\ShiftRule` on every call: null, empty or unreadable text follows `require_shift_for_cash`;
       `true`/`false` (or their text forms) split the two rules. The draft's nested `env()` gives the same on the server but freezes the value when the config loads, so every test that switches the
       cash rule off in process (`ShiftsFixture`, `CallInTest`, `ShiftAttributionTest`, `Phase3SeederTest`, the shifts->payments contract) would still have asked every channel for a shift. `.env.example`
       and `.env.ci` carry it commented out, the runbook's kill-switch section says it stays three lines, `DeployableCachesTest` asserts the cache holds null, `Tests\Support\Shifts::enforced()` also
       counts `require_shift === true`. `PaymentRequiresShiftTest` has the table (eight cases) and the digital channel on and off.
     - **Check order** as section 4.3: the channel, the photo, the reference (`Support\PaymentChecks`, before any lock), then in the transaction the shift rule (once, when any row needs it), the order's
       state, the amount; after them the cash tender (`payments.tendered.too_low`) and the photo's row, locked and checked again. Lock order everywhere: the order row, the till's shift row (shared),
       the photos' rows ascending (`Support\PaymentWriter`). `payments.photo.invalid` carries a `reason`: `not_found` (unknown, another branch's, removed), `wrong_purpose`, `attached` (also one photo
       named by two rows of a batch), `expired` (`payments.photo_max_age_hours` = 12, a plain value). A `receipt_file_id` on a channel that does not ask for a photo is checked and attached the same way.
       The pattern is matched against any reference given (trimmed, as typed, case-sensitive), not only a required one; the hint pair comes back as `hint_en`/`hint_am` (null when unset).
       `photo_required`'s `field` is now `receipt_file_id`.
     - **Races** (`SplitPaymentRaceTest`, `tests/Gate`, groups `concurrency` and `module:payments`, 30 forked rounds each, no InnoDB deadlock): two tills paying the whole balance of a *served*
       order: one is taken and closes it, the other is `409 payments.order.not_payable` (status `closed`; `payments.order.settled` is the answer only for an order not served yet); two parts that
       together exceed the balance: the second is `422 payments.amount.mismatch` with the balance left. Contract case 12's wording holds for its simulated path. 4.6b adds the file to `gate:p4`.
     - **Answers.** POST keeps the Phase 1 `payment` keys, adds `receipt_file_id` and `batch_uuid`, then `warnings` beside `order`. The batch answers `{payments, order, warnings}` with that same
       write shape per payment (the GETs have the read shape). A warning is `{code: reference.duplicate | receipt.duplicate, message_key: payments.warning.*, message_en, message_am, payment_id,
       payment_ids}`; a voided payment is nobody's duplicate. `row_invalid` is `{row (0-based), field: rows.N, error: the row's own bilingual body}`; a row id used before is `409
       payments.client_uuid.reused` with `field: rows.N.client_uuid`. One AuditedAction `payment.batch`, subject `none`, context `{order_id, batch_uuid, rows: [{channel_code, amount}]}`.
     - **Read shape**: the plan's keys plus `order_id`, `client_uuid`, `currency`, `batch_uuid`, `received_by {id, name}` and `flags {duplicate_reference, duplicate_receipt}` (the admin list's chips,
       4.5c). The list's `order` is `{id, order_no, business_date, table_code, total}` (numbers restart daily) from the new `Orders::summaries(ids)`; `?order_no=` uses `Orders::idsNumbered(n)`
       (two cases in `OrdersContractTest`, group `contract:payments->orders`). `GET /payments/{payment}` adds `refunds` read from `payment_refunds` (`[]` until 4.3d). Newest first by id; `cursor`
       is the last id seen (an integer); `missing_photo` = not cash and no photo; `flagged` = not voided, and disputed or a duplicate.
     - **A cashier's scope (10.2 R-N)**: a viewer without `payments.void` (4.3c's `payments.verify` has the same holders) sees the payments received since the start of the current minute, a day
       ago, and those taken in the shift they carry; `GET /payments/{payment}` outside it is 404.
     - **ETag**: `Payments::fingerprint()` `{count, max_id, max_updated_at, settling}` (one query over the branch) plus the validated query, the viewer's scope and a bucket of half the signed-URL
       lifetime, so a list that keeps answering 304 gets fresh receipt URLs before the old ones expire. The order's `total` is not in the tag: a line added to a part-paid order shows on the next
       change of a payment (the order's own reads are not polled through this list).
     - **Audit**: the snapshot also redacts `reference_norm` and `receipt_photo_key` (an object key is never logged); `receipt_file_id` stays.
     - **`accepted_now`** is `is_enabled` (`ChannelView`, `PaymentChannel`). The web till still offers cash only (`PaySheet` checks `accepted_now && code === cash`), so the Phase 1 to 3
       Playwright flows are untouched; the full pay sheet is 4.5b.
     - **Files**: `Files::attach(file, type, id)` writes `attached_*` once (`409 files.attached` otherwise). `DELETE /files/{file}` re-checks under the row lock, since a payment may claim the photo
       between its check and its delete; the orphan purge is safe by age (24 h against the 12 h within which a photo can be attached).
     - **`PaymentRecorded`'s payload is unchanged**: section 5's `has_receipt` and `reference_dup` have no consumer yet (`ApplyPayment` reads the amount; `payment.recorded` on the timeline waits for
       4.1b's M9), so they come with the first one.
     - **Phase 1 and 4.3a tests that changed**: `RecordPaymentTest` (a part payment is taken and only more than the balance is refused; `photo_required` only without a `receipt_file_id`),
       `PaymentChannelsTest` (the plan's `ChannelsEndpointTest`), `ChannelAdminTest` and `ChannelRulesMigrationTest` (`accepted_now`), `PaymentsLangTest` (20 keys; `photo_required`,
       `amount.mismatch` and `shift.required` say what Phase 4 does), `SnapshotTest` (one case), `DeployableCachesTest` (one assertion), `RoutePermissionMatrixTest` (a `{payment}` placeholder).
       `Phase1StateMachinesTest` is unchanged (4.3c). No test turns a rule off in process that did not before.
     - STATUS: phase 4's `payments` step stays `in_progress`; the top-level module stays `stable`.
   - **4.3c `phase-4/payments-verify-void`**: `PaymentStatus` `disputed -> verified|voided` with `Phase1StateMachinesTest` (moved from M4, see 4.3b), `verify`, `dispute`, `void` with the PIN path, `PaymentVerified/Disputed/Voided` events, `NotifyPaymentDisputed` (Notifications, with its
     bilingual copy), the `payments.verify` permission; tests `PaymentVerifyTest`, `PaymentDisputeTest`, `PaymentVoidTest` (void a part payment of a served order and pay again; void refused for
     `paid`; void of a refunded payment refused), `PaymentVoidRaceTest` (a void racing a last payment: the order lock serialises, `concurrency` group). **Taken over from 4.1b**
     (on hold for R-C; see the note under 4.1b): the `PaymentVoided` event, Orders' `ReversePayment` listener, `payment.recorded` / `payment.voided` on the timeline with
     the payment half of M9, and contract case 10 green under `contract:payments->orders`. Nothing refund-specific.
   - **As built (step 4.3c, `phase-4/payments-verify-void`).** Order: the tests and the registries (red: every new file failing on a missing class, route, permission or
     migration; the Phase 1 to 4.3b tests that change, below), then the events, the three migrations, the permission, the machine, Orders' side, the three actions and
     the controller, Notifications, lang; then OpenAPI, ERD and this note. Where the code disagreed with the plan or the plan left a choice:
     - **Migrations** (dates of the merge day): `2026_11_02_100100_grant_payments_verify_permission` (thin caller of `GrantRegistryPermissions`, M2 is not edited);
       `2026_11_02_100200_extend_order_events_check_for_payments` (the payment half of M9: the 18 Phase 1 events plus `payment.recorded`, `payment.voided`; its `down()`
       refuses while a payment event exists, the timeline being append-only); `2026_11_02_100300_extend_notification_types_for_payments` (`payment_disputed`; the plan
       did not list it, the inbox CHECK needs it). No column: the approver of a void is in the audit context and on the timeline (`approved_by_user_id`), not on the
       payment row, which keeps `voided_by_user_id` = who asked; the verify note is in the audit context only.
     - **Endpoints.** `PaymentDecisionsController` calls the actions (`VerifyPayment`, `DisputePayment`, `VoidPayment`) directly, as `OrdersController` calls
       `VoidOrder`; the `Payments` contract is unchanged. All three answer the `GET /payments/{payment}` body (`PaymentViews::detail`, which `show` now uses too). The
       person checked is the one acting by short code, else the account (`PaymentAccess::person`), for `payments.verify` and for `orders.void_request`.
     - **Verify**: `recorded|disputed -> verified` (`verified_by_user_id`, `verified_at`; from `disputed` also `dispute_resolved_at`, the dispute's columns stay). A
       payment that is verified already answers 200 and writes nothing (no audit row, no event), also when another request verified it while this one waited for the
       row lock (`AlreadyVerified` rolls the empty transaction back). `PaymentVerified {payment_id, order_id, channel_code, amount, by_user_id}` after the commit.
     - **Dispute**: only `recorded` (a verified or disputed payment is `409 state_machine.illegal_transition`). `PaymentDisputed` after the commit carries `order_no`
       (from `Orders::summaries`) and `channel_name_en/am` so Notifications reads nothing else. A disputed payment still counts (totals, the drawer, 10.8 R-Q).
     - **Void**: the checks run before the PIN and again under the locks, in the order voided (`409 state_machine.illegal_transition`), refunded (`409
       payments.void.refunded {refunded_amount}`; a refunded payment's order is closed, so this comes before), order `paid`/`closed` (`409 payments.void.order_closed
       {status}`); a refused void spends no PIN attempt. Then `verifyPinFor('payments.void')` outside the transaction, then one `AuditedAction` `payment.void` (actor =
       the asker, context `{reason, approved_by_user_id, order_id}`): `Orders::forPayment(lock)` first, then the payment row (`PaymentLock`), so a void and a payment
       of one order are serialised by the order lock and verify/dispute (payment lock only) never wait in the opposite order. A disputed payment that is voided gets
       `dispute_resolved_at`. **A payment of a voided order can be voided** (a part payment taken before `POST /orders/{order}/void`: its money goes back, the order
       stays voided); the plan only refuses `paid`/`closed`, and `VoidOrder` still voids a part-paid order as in Phase 1. Cancelled and open orders never carry a payment.
     - **Orders' side** (taken over from 4.1b). `ReversePayment` (sync, in the void's transaction): refuses `paid`/`closed` with `OrderError::paymentVoidOrderClosed`
       (key `payments.void.order_closed`, Payments' lang file), else `paid_total -= amount`, `settled_at = null`, `version + 1` and `payment.voided {payment_id,
       channel_code, amount, reason, by_user_id, approved_by_user_id}` with the approver in the row's `approved_by_user_id`; a void of more than the order holds is a
       `LogicException` (rolls back). `ApplyPayment` writes `payment.recorded {payment_id, channel_code, amount, receipt}` before `order.settled`, so the last three events
       of a closing payment are still `order.settled`, `order.paid`, `order.closed`. `PaymentRecorded` gained `has_receipt` (its first consumer), as the last
       constructor argument after `occurredAt`, so every Phase 1 caller and test keeps its call.
     - **Notification** `payment_disputed`: severity `warning`, `in_app`, `push`, `telegram`, inbox key `payment_disputed:{payment_id}` (a payment is disputed once),
       deep link `/admin/payments?payment={payment_id}` (the screen of 4.5c). Recipients: the holders of `payments.verify` (admin, manager: the plan's "admins and
       managers") without the person who disputed it. Queued after commit, run as the disputer.
     - **`payments.verify`** replaces `payments.void` in the cashier scope of the payment reads (`PaymentScope`) and, as the plan's 4.1 row says, in the delete rule of
       an unattached file (`FilesController`; 4.2b's stand-in). Same default holders, so nothing changes for the roles; a hand-made grant now follows the new name.
     - **`PaymentVoidRaceTest`** (`tests/Gate`, `concurrency` + `module:payments`): the void checks a PIN before it locks the order, so a payment started at the same
       moment always wins; the payment's start is swept from 0 to 580 ms (20 ms a round, 30 rounds) and the test requires both orders to happen: locally 9 rounds
       "pay first" (the void is `409 payments.void.order_closed`) and 21 "void first" (the 20.00 is a part payment, the order stays open); `paid_total` always equals the
       payments that are not voided, the water is sold at most once, no InnoDB deadlock. 4.6b adds it to `gate:p4`.
     - **Contract**: case 10 (the void) carries `contract:payments->orders` as well as `contract:payments->orders:refund`, so `mod-orders` runs it; the other refund cases
       stay red under their own group for 4.1b.
     - **Phase 1 to 4.3b tests that changed**: `Phase1StateMachinesTest` (`disputed -> verified|voided`), `OrdersSchemaTest` (two events), `RecordPaymentTest` (the
       `PaymentRecorded` payload has `has_receipt`), `ApplyPaymentTest` (one new case), `PaymentReadsTest` (one new case: the scope follows `payments.verify`),
       `FilesApiTest` (the delete rule), `NotificationTextTest` and `NotificationsSchemaTest` (the new type), `PaymentsLangTest` (25 keys), `PermissionRegistryTest`
       (one row). No test turns a shift rule off.
     - **Findings left as they are**: domain refusals (`PaymentError`, `OrderError`, `IllegalTransition`) are reported to the log at ERROR level app-wide (nothing
       marks them as not reportable); and `Idempotency` stores a sha256 of the request body as `request_hash`, which for a void (as for `POST /orders/{order}/void`
       since Phase 1) is computed over the PIN too: the PIN itself is never stored, logged or audited (`PaymentVoidTest`), but a 4 to 6 digit PIN under a hash of an
       otherwise known body is guessable offline by whoever reads the table. Both are cross-cutting and are for Ramin to decide.
     - The entry gate with `--no-tests` still fails "contract payments->orders first", as on main, because Payments has had code since Phase 1.
     - STATUS: phase 4's `payments` step stays `in_progress`; the top-level module stays `stable`.
   - **4.3d `phase-4/payments-refund`**: (M10 is already there) `Payments::refund` (the endpoint's use case): PIN, `Orders::refund` for the stock and order side, allocation LIFO over payments,
     `payment_refunds` rows, `RefundPaid`, the shift rule for the cash share, `Totals::cash` net of refunds and `Totals::byChannel` with `refunds` (so Shifts' code and its `cash_declarations`
     arithmetic need **no change**; `CashFiguresTest` gets a case proving expected cash falls by the refund), `Payments\Facts::refunds` filled. `contract:shifts->payments` case lands green here.
     Tests: `RefundTest` (full, line, dispositions, allocation across a split, cash share needs the shift), `RefundRaceTest` (two managers refund the same order: one wins, one `409
     orders.refund.line_refunded`), `RefundMoneyPropertyTest` (random split/void/refund sequences: `Σ payments - Σ voids - Σ refunds = Σ order totals - Σ refunded_total` and the
     ledger oracle holds), `RefundAuditTest` (every refund and void has its audit row, no PIN/reference/URL in the snapshot).
   - **4.3e `phase-4/receipts-reconciliation`**: M5; `composer require barryvdh/laravel-dompdf` (pinned, needs `dom`, `mbstring`, `gd`), the bundled `NotoSansEthiopic-Regular.ttf` (SIL OFL,
     registered into `storage/fonts` lazily and under a lock, `receipts:fonts` to warm it), `ReceiptRenderer`, the receipt routes, `receipt-settings` PUT, `GET /payments/reconciliation`,
     `GET /payments/export` (OpenSpout for xlsx, plain CSV; see 10.8 R-E), runbook "Receipts" (fonts folder, memory). Tests: `ReceiptRendererTest` (text extracted from the PDF contains the
     order number, the total as `12.50`, the Amharic channel name and no staff name; two pages at most for a 60-line order), `ReceiptLinkTest` (signature, expiry, tamper),
     `ReconciliationTest`, `PaymentsExportTest` (opens with the same reader library, row count, no photo URL in it). Payments `module-stable`.
   - **As built (step 4.3e, `phase-4/receipts-reconciliation`).** Order: the security fix of the Idempotency fingerprint (tests red, then the fix and its
     migration); the contract case for the new Orders read (red, then `Orders::forReceipt`); the tests and registries of the step (red: 84 of 91); the
     pinned packages and the font; `EthiopianDate`; M5 and the settings PUT; the receipts; the reconciliation and the export; OpenAPI, ERD, manifest,
     runbook and this note. Where the code disagreed with the plan or the plan left a choice:
     - **Security fix first (found by 4.3c).** `Idempotency` stored a sha256 of the whole body as `request_hash`; on the PIN voids (`POST /orders/{order}/void`,
       `POST /payments/{payment}/void`) and the People writes (`POST /users` with a password and a short code, `PATCH /users/{id}` with a password) the body
       held a secret a reader of `idempotency_keys` could recover offline. `Idempotency::SECRET_FIELDS` (`password`, `pin`, `short_code`, matched by name at any
       depth) is the one list: those fields are left out of the fingerprint, so the stored hash is the hash of the body without them. The same key with every
       other field equal is the same request: a success is replayed whatever PIN comes with it. A request that carried a secret keeps **only a success**: a
       refusal (a wrong PIN, a locked prompt, a malformed PIN, a refused void) wrote nothing and is not stored, so the same key with the right PIN is checked
       again (the web's `VoidDialog` takes a new key after a failure anyway). Refusals of other requests are stored and replayed as before; multipart (4.2b) is
       fingerprinted exactly as before. **Also found:** `POST /users/{id}/short-code/reset` answers the new short code, and the stored answer held it in clear;
       an answer whose JSON holds a secret field is now stored as `{kind: encrypted}` with the app key and replayed unchanged (one the key no longer opens is
       `422 idempotency.key_reused`, never a second run). When the app key cannot encrypt at all, that answer is not stored (never in clear) and a retry
       runs again; e2e-p2 found it, because the compose stack's `APP_KEY` was 35 bytes, which AES-256 refuses; `compose.ci.yml` now has a 32-byte one.
     - **The rows already on live.** A row is replayed for 24 hours (`bakery.idempotency.ttl_hours`) but **nothing purges one**: an expired row is deleted
       only when its own key comes back (the model's "swept" was never built), so every write since Phase 1 is still in the table. Migration
       `2026_11_03_100100_clear_secret_fingerprints_of_idempotency_keys` deletes no row; it sets `request_hash` to a value no sha256 can equal for every
       expired row (it never replays), every unexpired refusal (it wrote nothing) and every unexpired success that is the answer of a route with a secret (a
       voided order or payment, a user, a short-code reset, any answer holding a secret field), and sets a stored `short_code` to null. The table records no
       route, so a row is judged by what it holds; release.sh migrates in maintenance mode, so every row then was written by the old code. An unexpired
       success of any other route keeps its hash and still replays. A second run changes nothing.
     - **`Orders::forReceipt`** (new, `OrderReceiptView`, `ReceiptLineView`, contract case in `OrdersContractTest`, group `contract:payments->orders`): number,
       business date, type, table, status, sent/settled/paid/closed, the money, the charged lines with their modifiers; no person. The modifiers come in the
       order Orders writes them (the case first expected the client's order).
     - **M5** is `2026_11_03_100200_create_receipt_settings_table` (column sizes: names 120, address and footer 255, phone and TIN 32). No `client_uuid` (one
       row per branch, unique `branch_id`: a retry finds the same row) and no `deleted_at` (changed in place, never deleted: SoftDeletesTest allowlist). PUT
       replaces the whole form (a field left out is cleared); address and footer are pairs (both or neither); one AuditedAction `receipt_settings.update`; the
       first save is a no-op `insertOrIgnore` and then a lock, never a lock on a missing row.
     - **The receipt.** `ReceiptRenderer` uses `Dompdf\Dompdf` with its own `Options` (remote files, PHP and JavaScript off, HTML5 parser, subsetting on); the
       package's config is not used. A4. **Latin text and the Western digits print in Helvetica** (a PDF core font, not embedded), Amharic in Noto Sans
       Ethiopic embedded as a subset, DejaVu Sans only for a letter neither has: subsetting DejaVu Sans for every receipt took 3 to 5 s in PHP, the core font
       keeps a receipt near 0.2 s and a few kB. At most `payments.receipts.max_pages` (2): a compact layout, then the last lines folded into one row ("and N more
       items" with their sum); a 60-line order prints every line on two pages (an order is at most 50 lines through `POST /orders`). A line prints its unit
       price with the modifiers' deltas, the quantity without trailing zeros. A receipt is dated when the order was settled; a pro-forma (`?proforma=1`, any
       order not paid in full) is dated now, says so at the top and shows the balance. Cancelled and voided orders have neither (`409 payments.receipt.unpaid
       {status}`). The locale is `?locale=`, else both when `show_amharic`, else English. Voided payments are left off; refunds (none until 4.3d) print with
       the net paid. `EthiopianDate` lives in `Shared\Time` (4.5f's web converter is its twin).
     - **Fonts.** `NotoSansEthiopic-Regular.ttf` is the unhinted TTF of notofonts' release `NotoSansEthiopic-v2.102` (sha256 `86b9c07c...166d4`, pinned in
       `ReceiptFonts::SOURCE_SHA256`), with `OFL.txt`. `ReceiptFonts` registers it (TTF, `.ufm`, `installed-fonts.json` entry for normal and bold) into
       `storage/fonts` (`payments.receipts.fonts_dir` in tests) under the cache lock `receipts:fonts`, every file written to a temporary name and renamed.
       `receipts:fonts` warms it; `release.sh` runs it after `queue:restart`, never fatal. `storage/fonts` is ignored by git.
     - **The link.** `POST /orders/{order}/receipt-link` also takes `locale`, refuses an order not paid in full (409), and writes one audit row `receipt.link`
       (order, lifetime, expiry, locale; never the URL). The signature covers order, branch, locale and expiry; a bad or expired one is `403
       payments.receipt.link_invalid` (`bootstrap/app.php` picks the key by path; a file's stays `files.invalid_signature`). The public route sets the
       branch the link names as the current branch, so every read is scoped as for a signed-in user; it adds `X-Robots-Tag: noindex, nofollow`.
     - **Reconciliation.** One grouped query per table for the whole range (a CASE over the days' UTC bounds, built from literal pieces, every value a binding),
       so the `(branch_id, received_at)` and `(branch_id, refunded_at)` indexes serve it. Every day of the range is listed oldest first; a day lists the
       channels that had a payment or a refund (a channel that only paid money back shows a negative net), in the admin's order. The last 7 local days by
       default (a missing `to` is today, a missing `from` six days before it); more than `payments.reconciliation_max_days` (62) is `422
       payments.reconciliation.range_too_large {max_days}`. Polled: Payments' fingerprint plus the refunds' own (count, max id, settling).
     - **Export.** The filters of `GET /payments` (`PaymentList`) without paging (a cursor or a limit is 422), oldest first, 500 a query. CSV is UTF-8 with a
       byte-order mark; a typed text that a spreadsheet would run as a formula is prefixed with an apostrophe. XLSX goes through OpenSpout's streaming writer
       into a temporary file, sent and removed. **Money stays text in the xlsx**: a numeric cell needs a PHP float, which the money rule forbids, so the
       decimal string is written as stored (4.4d decides again for the analytics exports). `has_photo` is yes or no; no URL or object key anywhere. One audit
       row `payment.export` (format, locale, filters with a reference blanked, row count). `payments.export_max_rows` (20 000) is a config value.
     - **Packages.** `barryvdh/laravel-dompdf` 3.1.2 (dompdf 3.1.6), `openspout/openspout` 4.32.0 (5.x needs PHP 8.4), `smalot/pdfparser` 2.13.1 (dev: the
       tests read the PDF back), all exact pins.
     - **Tests that changed**: `PaymentsLangTest` (29 keys), `ReceiptSettingsTest` (extended), `SoftDeletesTest` (allowlist), `VoidOrderTest`, `PaymentVoidTest`
       and `UsersEndpointsTest` (two new cases each), `OrdersContractTest` (one case). No test turns a shift rule off.
     - **Not done here:** Payments `module-stable` is not run, because step 4.3d (refunds, on hold for R-C) still changes Payments; phase 4's `payments` step
       stays `in_progress`. Domain refusals are still logged at ERROR (for Ramin, as 4.3c said).
4. **4.4 analytics** (STATUS `analytics`), four PRs. Analytics `stable` after 4.4d.
   - **4.4a `phase-4/analytics-rebuild`**: M12 to M16; the `Analytics` module skeleton (`AnalyticsServiceProvider`, module boundaries); `Contracts\Rebuild::day(branch, localDate, trigger)`
     returning `RebuildResult {rows, version, changed, duration_ms}`; the computation split into small pure builders, one per table (`SalesBuilder`, `HourBuilder`, `ChannelBuilder`,
     `ItemBuilder`, `StationBuilder`, `UserBuilder`), each taking Facts DTOs and returning rows (unit tested with plain arrays, no database); **the clocks** (ADR 0014): revenue and orders by
     `settled_at` local day, hour sales by the same instant, payments by `received_at`, refunds by `refunded_at`, units sold by the order's settled day, prep times and `lines_served` by the
     line's `served_at`/`ready_at` day, production by `finished_at`, movements by `created_at`, `closing_*` at 23:59:59.999 local, shifts by every local day between opening and lock;
     `cogs_total` = `Σ units × cost of the recipe version the line snapshotted`, each ingredient at its `unitCosts(day)` (the moving average in force at the end of that local day, from
     `item_cost_history`), rounded **once** per item-day half-up, so the figure never changes just because a later purchase moved the average; a product without a recipe or an ingredient without a
     cost sets `cogs_complete = false` and counts 0, never an estimate. **Atomicity and idempotency:** `Rebuild::day` (1) upserts the `analytics_days` row with a no-op update (never lock a row
     that may not exist: the first-row race of traps.md), (2) `SELECT ... FOR UPDATE` it, (3) reads every Facts port inside that transaction (a consistent snapshot after the lock), (4) builds all rows
     in memory, (5) `DELETE`s that `(branch, day)` from each summary table and bulk `INSERT`s the rows in primary-key order (as built: upserts in primary-key order, then the
     day's stale rows deleted by their full key, never a range, see the As built note), (6) writes `version+1`, the fingerprint and `changed`. A failure rolls
     everything back, so a day is either the old rows or the new rows. Two rebuilds of unchanged facts produce **identical rows** (the fingerprint equals; only `rebuilt_at` and `version` move).
     The console `analytics:rebuild {--branch=} {--from=} {--to=} {--nightly} {--since-last} {--sync}`; the queued `RebuildDay` job (`ShouldBeUnique` by `branch:day`, `uniqueFor` 120, `tries` 3, a
     failure is logged with the day and never the facts). `CurrentBranch::runAs($branchId, ...)` is used for a queued or console rebuild (verify in the PR whether Phase 2's queued actor already
     provides it; if not, add the helper to Shared with its own test). Tests: `RebuildDayTest` (every column against a hand-built oracle for a fixture day), `RebuildIdempotentTest` (twice: same fingerprint, same rows),
     `RebuildBoundaryTest` (orders at 23:59 and 00:01 local, a Night shift, the day before and after), `RebuildRaceTest` (two forks, `concurrency` group, one wins, rows never doubled, never missing),
     `RebuildRollbackTest` (an exception in the middle leaves the old rows), `RebuildCogsTest`, `RebuildRefundDayTest` (a refund on D+1 of an order of D lands on D+1), ADR 0014 (proposed).
   - **As built (step 4.4a, `phase-4/analytics-rebuild`).** Order: the contract cases for what the tables need of the ports and the
     `CurrentBranch::runAs` test (red: 7 + 5), then the provider work with `app/Modules/Analytics` untouched; the module's tests (red: 64 on the
     missing classes, tables and command); M12 to M16 and the six builders; `Rebuild::day`, the job and the command; ERD, manifest, STATUS,
     ADR 0014 (proposed) and this note. Where the code disagreed with the plan or the plan left a choice:
     - **The ports did not carry everything the columns need**, so they grew first, contract-first (`contract:analytics->facts`, now 17 cases;
       every field added at the end of its row with a default): `Orders\Facts::voids(range)` (`VoidFact`: voided orders by `voided_at`,
       cancelled ones by `cancelled_at`, the total, the owner, who asked) and `Orders\Facts::stationLines(range)` (`StationLineFact`: a station
       line once per local day on which it was readied, served or rejected, whatever its order became, with all four instants and the
       preparer), because `lines()` is bucketed by the order's `settled_at` and leaves rejected lines out; `PaymentFact::duplicateReference`
       (Payments' own R-B rule, one indexed `EXISTS`, so no reference leaves Payments); `RefundFact::orderRefundId` (a refund over two
       payments is one refund); `StockOutFact::stationId` (the location's station); `ShiftFact::varianceLineCount` and `cashVariance` (the
       shift's counts that are approved or locked and not superseded); Catalog `ItemSummary::categoryId`. The manifest's `analytics.needs`
       and the contract's case list say so.
     - **`CurrentBranch::runAs($branchId, $callback)`** did not exist (Phase 2's queued work uses `ActorContext::runAs` and sets the branch by
       hand in `RestoresActor`), so it is in Shared with `CurrentBranchRunAsTest`: the branch for the callback, exactly the previous state
       afterwards (also on a throw), `BranchNotResolved::unknown` for a missing branch. An unresolved branch means no branch filter at all,
       so every rebuild resolves it.
     - **M12 to M16** are `2026_11_04_100100` to `100500` (`analytics_days`; `daily_sales` + `daily_hour_sales`; `daily_channel_totals`;
       `daily_item_metrics`; `daily_station_metrics` + `daily_user_metrics`): `branch_id` leads every primary key, FKs to the dimension's table,
       CHECKs `analytics_days_trigger_check` (the column `trigger` is a MySQL reserved word, quoted through the grammar), `daily_hour_sales_hour_check`,
       `daily_item_metrics_kind_check`, `daily_user_metrics_role_kind_check`; `kind` is VARCHAR(10) and `stockout_count` an INT (section 3
       corrected); INDEX `(branch_id, item_id, day)` for the product page. New and empty, safe on live. The manifest already listed the
       seven tables under `tables` (4.1a), so nothing moved. Analytics has no models (query builder only), so `SoftDeletesTest` and
       `BranchScopedTest` need no allowlist.
     - **A day is replaced by upserts, never by a range `DELETE`** (section 6 item (5) corrected). Each table's day is written as upserts in
       primary-key order and the rows it no longer has are deleted by their full key. A range `DELETE` takes a gap lock after the day, and a
       rebuild of the neighbouring day that inserts a row into that gap deadlocks with it: `RebuildRaceTest` found InnoDB deadlocks (1213) within
       30 rounds both when the delete ran on an empty day and, with the empty delete skipped, when rows appeared on both sides of the day
       border; with the upserts, none.
     - **The figures, where the plan left a choice** (ADR 0014 has the clock table): `daily_sales` has a row for every built day, zeros on a
       quiet one; the other tables only where there is a figure. Tickets are the orders whose lines the station readied or rejected that day;
       prep time is `accepted_at` to `ready_at` of the lines readied that day (a line readied straight from the queue has no sample), the
       mean half-up and the nearest-rank 90th percentile in whole seconds. A shift counts on every local day from its opening to its lock (to
       its cancellation for a cancelled one; from its opening on while it runs; never if it never opened); its variance lines and cash
       variance count on its `date` only. `without_reference_count` and `without_photo_count` count literally, as the 4.3e reconciliation does
       (cash has neither). `voids_count` is on the `waiter` row of the person who asked for the void; `refunds_count` on the `cashier` row of
       the person who paid it back. `consumed_qty` is `production_consume` (batches and station lines at READY); `sale`, `transfer`,
       `reservation*` and `return` movements are not item figures (units sold come from the lines). An item with stock but no cost has
       `closing_value_total` 0. `refunded_qty` is 0 until order refunds exist (4.1b; the money side of refunds is counted already).
     - **COGS** as R-K: the snapshotted version's required lines (optional ones left out, as `RecipeCost`), each at `unitCosts(day)`, exact,
       rounded half-up once per item-day; a line with no version is costed at the product's own cost. A modifier's recipe delta is not costed
       (`LineFact` does not carry it). **For Ramin:** display-case products made in the house (croissants, cakes) snapshot no recipe version
       (Orders records one only for made-to-order lines) and have no cost of their own (costs come from purchases and opening costs only), so
       their `cogs_complete` is false and their COGS 0 until Orders snapshots the version on such lines or Production values its output.
     - **`Rebuild::day`** refuses an unknown trigger, a malformed day, a day after today in the branch's zone (`InvalidArgumentException`)
       and a missing branch (`BranchNotResolved`) before writing anything. The placeholder row of the upsert (version 0, no fingerprint) only
       exists inside the transaction. `AnalyticsDayRebuilt` (`App\Shared\Events`: day, trigger, version, changed) is dispatched after the
       commit, never for a rollback.
     - **`RebuildDay`** (`Analytics\Jobs`): unique by `{branch}:{day}` for 120 s, 3 tries, `failed()` logs `analytics.rebuild.failed` with the
       branch, the day, the trigger and the class of the error only (a query error's message carries its bound values).
     - **`analytics:rebuild`**: exactly one of `--from[/--to]` (trigger `manual`, at most `analytics.rebuild_max_days` = 400, never after today),
       `--nightly` (`analytics.rebuild_days_back` days before today in each branch's zone) or `--since-last` (every local day in the life of a
       shift locked after that day's `rebuilt_at`, among the shifts of the last `analytics.since_last_days` = 7 days; trigger `shift_locked`);
       `--branch`, `--sync`. A bad call exits 2 and writes nothing; a failed day is logged as the job does and the command exits 1. No
       schedule yet (4.4b).
     - **Tests**: unit `SalesBuilderTest`, `HourBuilderTest`, `ChannelBuilderTest`, `ItemBuilderTest`, `StationBuilderTest`, `UserBuilderTest`,
       `FingerprintTest` (25, plain `Facts` rows from `Tests\Support\AnalyticsFacts`); feature `AnalyticsSchemaTest`, `RebuildDayTest` (a day made
       through the real actions with the three shift rules on, every column against figures worked out by hand), `RebuildIdempotentTest`,
       `RebuildBoundaryTest`, `RebuildRollbackTest` (the failure planted by a query listener half way through the writes), `RebuildCogsTest`,
       `RebuildRefundDayTest` (factory `payment_refunds` rows), `RebuildCommandTest` (Queue::fake and its sync twin); `tests/Gate/RebuildRaceTest`
       (forked, `concurrency`, 30 rounds each: the same day, and two neighbouring days from nothing, over the same rows and over rows that come
       and go at the border; `Innodb_deadlocks` unchanged). No test turns a shift rule off.
     - STATUS: phase 4's `analytics` step and the module are `in_progress`.
   - **4.4b `phase-4/analytics-triggers`**: the listeners of section 5 (`RebuildAfterShiftLocked`, `TouchToday` debounce), the scheduler entries, `POST /analytics/rebuild`, `GET /analytics/freshness`,
     `analytics.manage` and `analytics.view_own` use. Tests: `ShiftLockedTriggersRebuildTest` (a locked Night shift queues both days), `TouchTodayDebounceTest` (20 payments in a minute: one job),
     `NightlyScheduleTest` (the entries exist with `timezone('Africa/Addis_Ababa')`, `withoutOverlapping`; a `Carbon::setTestNow` run at 00:05 local rebuilds the two days), `RebuildEndpointTest`
     (403 matrix, 7 days inline, 31 queued, idempotent replay).
   - **As built (step 4.4b, `phase-4/analytics-triggers`).** Order: the tests and the registries (red: the new routes, classes, permission, lang file
     and schedule entries are missing; the changed tests of 4.4a and Orders say what the listeners change), then the code, then OpenAPI, ERD (no new
     table), the manifest, ADR 0014's triggers and this note. Where the code disagreed with the plan or the plan left a choice:
     - **The listeners are `ShouldQueueAfterCommit`.** Laravel queues a listener without looking at `ShouldHandleEventsAfterCommit` (that interface
       only defers a listener that runs in the request), and `PaymentRecorded` and `PaymentVoided` are dispatched inside the payment's transaction,
       so a rolled-back payment would otherwise still have touched the day (`TouchTodayDebounceTest` proves it does not). `ModuleBoundariesTest`
       requires it of `App\Modules\Analytics\Listeners`.
     - **`RebuildAfterShiftLocked`** asks for every local day from the event's `date` (the shift's local start date) to the local day of its
       `locked_at`, at most `analytics.rebuild_queue_max_days` (31, its date and the days just before the lock), never after today; a branch that no
       longer exists is skipped.
     - **`TouchToday`** listens to `PaymentRecorded`, `PaymentVerified`, `PaymentDisputed`, `PaymentVoided`, `OrderPaid` and `OrderVoided`
       (`AnalyticsServiceProvider::TOUCHES_TODAY`; `OrderRefunded` and `RefundPaid` join with 4.1b and 4.3d) and asks for the event's own local day
       (its `occurredAt` in the branch's zone, never after today), `analytics.touch_delay_seconds` (60) out. A verification, dispute or void of a
       payment of an earlier day changes that day's channel figures; the earlier day follows at the nightly run or by hand (ADR 0014).
     - **`RebuildDay` is `ShouldBeUniqueUntilProcessing`** (a `ShouldBeUnique`): unique per `branch:day` for `analytics.touch_unique_seconds` (120)
       at most, and the lock goes when the job starts. The job reads every fact after it started, so nothing asked for before is lost, and a payment
       taken while it runs asks for the next rebuild; with `ShouldBeUnique` that payment would wait for the nightly run. Two jobs of one day queue up
       on the day's `analytics_days` row lock (`RebuildRaceTest`, 4.4a).
     - **`Support\RebuildQueue`** is the one way onto the queue (the listeners, `analytics:rebuild` without `--sync`, the endpoint). It notes in the
       cache when a day was asked for (`analytics:asked:{branch}:{day}`, `analytics.asked_ttl_seconds` = 900); a day is `running` while it was asked
       for after its `rebuilt_at`, so nothing is cleared when a job ends, and a lost job stops counting after 15 minutes. A queued manual rebuild also
       notes its range per branch: a second request over the same days is `409 analytics.rebuild.running {from, to, asked_at}` until every day of the
       first was built after it was asked for. An inline (`sync`) rebuild of the same days is not refused by another inline one: they queue up on the
       row locks.
     - **Schedule** (`routes/console.php`): `analytics:rebuild --nightly` daily at 00:05 and `--since-last` daily at 05:30, `timezone` from
       `analytics.timezone` (`Africa/Addis_Ababa`), `withoutOverlapping(30)`; both only queue. `NightlyScheduleTest` runs the entry's own command line
       in the test process (`schedule:run` starts a process of its own).
     - **`POST /analytics/rebuild`**: `to` defaults to `from`; `422 analytics.range.future {today}` beside the plan's `422 analytics.range.too_large
       {max_days}` (7 with `sync`, 31 without: `analytics.rebuild_inline_max_days`, `analytics.rebuild_queue_max_days`). The audit row is
       `recordEvent('analytics.rebuild', 'analytics_day', null, {from, to, sync, days})`, written before the work, so it records the ask even when an
       inline rebuild fails; the rebuilds are not audited (ADR 0014). For it, Analytics now depends on Audit (`AuditedAction` only; 10.9 item 20).
     - **`GET /analytics/freshness`** reads the common range (`Http\Requests\RangeRequest`, for 4.4c too: default the last `analytics.range_default_days`
       (30) days, at most `analytics.range_max_days` (400), never after today) and answers `{from, to, timezone, as_of, days, stale_days, running}`:
       `days` the built days, `as_of` the oldest of their `rebuilt_at` (null when none is built). The ETag is the range, every built day's version and
       `rebuilt_at` (a day deleted and rebuilt starts again at version 1) and `running`; it needs no settling bucket. `FreshnessEndpointTest` still
       travels past the 5 s window before its `304`.
     - **`analytics.manage`** (admin) joins the registry with its own grant-only migration, `2026_11_05_100100_grant_analytics_manage_permission`
       (10.6 corrected). `analytics.view` and `analytics.view_own` were in the registry from Phase 0.
     - **Tests:** `ShiftLockedTriggersRebuildTest` (the Night shift locked through the real close and submit with the three shift rules on, after
       `ShiftsFixture` pinned them off for its own mechanics), `TouchTodayDebounceTest` (20 cash payments: one job, held in the database cache's
       `cache_locks`), `NightlyScheduleTest`, `RebuildEndpointTest`, `FreshnessEndpointTest`, `GrantAnalyticsManagePermissionMigrationTest`,
       `AnalyticsLangTest`. Changed: `RebuildDayTest` (its real payments touch the day first, so its manual rebuild is that version + 1),
       `ApplyPaymentTest` and `VoidOrderTest` (they name `TouchToday` among the queued listeners), `RebuildCommandTest`. No test turns a shift rule off.
     - CI runs `QUEUE_CONNECTION=sync`, so every payment, void and shift lock of the suite now also rebuilds its day in the test process.
     - STATUS: phase 4's `analytics` step and the module stay `in_progress`.
   - **4.4c `phase-4/analytics-dashboards`**: the read endpoints of 4.6, the scoping for `analytics.view_own`, `ConditionalPoll` ETags from the days' versions. Tests: one feature test per endpoint
     (happy path, range errors, 403 per role, `304`), `DashboardsReadSummariesOnlyTest` (the **query-log assertion**: every statement of every `/analytics/*` request touches only
     `daily_*`, `analytics_days`, and the name tables behind the Catalog/Payments/Identity contracts (`items`, `categories`, `payment_channels`, `users`, `stations`) plus the framework's own
     (`personal_access_tokens`, permission tables, `idempotency_keys`, `cache`); a statement on `orders`, `order_lines`, `payments`, `payment_refunds`, `stock_movements`, `stock_balances`,
     `production_*`, `shift_*`, `stock_counts*`, `audit_log` fails the test, and a planted violation proves the assertion bites), `DashboardNumbersTest` (each figure equals the oracle sum over the seeded day),
     `DashboardBudgetTest` (query count per endpoint <= 8, and 30 timed requests each; the substitute of 10.2 R-G).
   - **As built (step 4.4c, `phase-4/analytics-dashboards`).** Order: the contract cases for the names the dashboards need (red: 3), the provider
     work with `app/Modules/Analytics` untouched; the module's tests and the registries (red: the nine routes, the request and read classes, the
     prep columns); the code; OpenAPI and ERD; this note, the manifest, ADR 0014 and the runbook. A separate stock-alert fix rides along (below).
     Where the code disagreed with the plan or the plan left a choice:
     - **The answer.** Every dashboard read answers `{from, to, timezone, as_of, stale_days, data, previous?}`: the figures under `data`, the
       freshness of section 4.6 beside them, and with `?compare=previous` a `previous` of the same shape for the same number of local days just
       before the range (the 29 January to 28 February before March). `as_of` and `stale_days` are each period's own. Section 4.6's table lists
       what is under `data`; the plan's "`previous {...}?` inside the overview" became this one envelope for every read.
     - **Not built is never zero (R-U).** A sum over a range adds its built days; the days not built are in `stale_days`. A point of a series (a
       day, a week, a month) with no built day has null figures (`built_days` 0); a built day the dimension did not move is a true zero.
     - **The ETag** is the read's scope, the branch, today's local date (`is_new` turns at local midnight), both periods, the read's options and
       the caller's scope (the stations or the person of `analytics.view_own`), and every built day's version and `rebuilt_at` over the span
       read (with the product page's weeks since launch). No settling bucket, as the freshness feed. One query before a `304`. A name changed
       since the last rebuild of the range shows with the next rebuild.
     - **Names, contract-first** (`contract:analytics->facts`, 20 cases): `Payments\Contracts\Facts::channels(ids)` (`ChannelFact`, a removed
       channel too), `Catalog\Contracts\Items::labels(ids)` (`ItemLabel`: both names, kind, base unit, category with both names, `is_new` in the
       branch's calendar, live or not; one query with the units and categories joined, where `Items::summaries` makes one per unit) and a new
       `Identity\Contracts\ActorDirectory` (`names(ids)`, `stations(ids)` as `StationLabel`, `workStationIds(user)`), the class the manifest's
       `analytics.needs` and `ModuleBoundariesTest` already named; the arch rule now also allows its `StationLabel` view. Step 4.5a may bind
       its Shared seam for Audit to it.
     - **Which tables, defined (10.9 item 19).** `DashboardsReadSummariesOnlyTest` records every statement of every GET `/analytics/*` route
       (read from the router; `POST /analytics/rebuild` is the one other route and the documented exception of 10.1) in two phases split where
       the endpoint resolves its `RangeRequest`. The endpoint may name only `daily_*`, `analytics_days`, the name tables (`items`, `units`,
       `categories`, `payment_channels`, `users`, `stations`, `user_station_assignments`) and the framework's (`personal_access_tokens`, the
       permission tables, `idempotency_keys`, `cache`). The plumbing before it (auth, the `actor` middleware, the permission check) may name
       only what every route's plumbing reads: those, `branches`, `devices`, `acting_sessions` and the station's shift stamp
       (`shift_instances`), which the brief's list did not foresee and which is never allowed to an endpoint. A planted endpoint that reads
       `orders` and `shift_instances` is caught.
     - **Prep times over a range are exact.** A day's `avg_prep_seconds` is rounded and does not say how many lines it is the mean of, so
       `2026_11_06_100100_add_prep_totals_to_daily_metrics` adds `prep_samples` and `prep_seconds_sum` to `daily_station_metrics` and
       `daily_user_metrics` (the builders write them; the 4.4a tests that compare whole rows say so) and a range's mean is sum over sum,
       half-up. A 90th percentile of many days cannot be made from the days' percentiles: `p90_prep_seconds` is given for a one-day range
       only, and beside it `worst_day_p90_prep_seconds`. A day built before the migration counts no samples until it is rebuilt (runbook).
     - **The reads as built.** `/overview`: revenue, net revenue, orders, `avg_ticket` (revenue / orders, once, 0.00 without an order),
       items, refunds, voids, cancellations, `by_type`, channels with `share` (percent of the money taken, 2 dp, null when nothing was)
       most money first, the five products with the most revenue (then units, then id). `/sales?group=day|week|month`: points keyed by the
       day, the Monday or `YYYY-MM`, cut at the range's ends, with `days` and `built_days`. `/sales/hourly`: the 24 local hours. `/channels`:
       the totals and the checker's counts per channel and a series grouped as `/sales` (`group` too). `/items?kind=&category_id=&sort=&limit=`:
       per item (the category the item had on each day), largest first by revenue, units, margin or waste, ties by id, `limit` 1 to 200,
       `margin_pct` null without revenue, `cogs_incomplete_days`. `/items/{item}`: the totals, a point a day with every column of
       `daily_item_metrics`, and `since_launch` (the first sale in all the built history and its units week by week from that day,
       seven-day weeks, at most `analytics.launch_weeks` = 12, cut at today); 404 `http.not_found` for an item of another branch.
       `/stations` (shift-days: a Night shift counts on both days), `/staff` (by role kind, then person), `/shifts` (a point a day over the
       stations and the totals of the variances only, each belonging to one day). Money and quantities are decimal strings summed with
       brick/math or exact DECIMAL sums (`Read\Sum` refuses a float).
     - **`analytics.view_own`.** `/stations` and `/staff` take `permission:analytics.view|analytics.view_own` (every role). Whether the caller
       reads everything is the signed-in account's `analytics.view`, as the middleware checks; the own scope is the person at the device (the
       acting-as person of a shared pad, else the signed-in one): their stations (assignments, or every station with `stations.all`), or
       their own rows. A waiter, who works at no station, reads an empty list. No permission is new, so no grant migration.
     - **Known gaps, shown as they are.** `/items` keeps `cogs_complete` false (and its cost 0) for display-case products made in the house,
       whose lines snapshot no recipe version (4.4a's note); a modifier's recipe delta is not costed. Refund figures are the summaries' (zero
       until 4.1b and 4.3d). `DashboardNumbersTest` pins the croissant's `cogs_complete = false` beside the sandwich's exact cost.
     - **Budget (R-G).** `DashboardBudgetTest`, on 90 built days compared with the 90 before (60 products, 20 ingredients, eight channels, five
       stations, twelve people): every read with `compare=previous` makes at most 8 statements of its own (counted from its `RangeRequest`;
       2 to 7 as built, the chef's stations the most) and 30 timed in-process requests after 3 warm-ups have a p95 under 400 ms (locally 25
       to 70 ms); the figures are printed to the CI log. The query budget leaves out the shared plumbing (0 to 3 statements before it).
     - **Tests**: unit `DashboardWindowTest`; feature `OverviewEndpointTest`, `SalesEndpointTest`, `HourlySalesEndpointTest`,
       `ChannelsEndpointTest`, `ItemsEndpointTest`, `ItemPageEndpointTest`, `StationsEndpointTest`, `StaffEndpointTest`, `ShiftsEndpointTest`
       (each: the figures over rows written straight into the summaries by `Tests\Support\SummaryRows`, `compare`, the range errors, 403 or
       the scope per role, a `304` after the settling window and a new tag after a rebuild), `DashboardsReadSummariesOnlyTest`,
       `DashboardNumbersTest` (two days through the real actions with the three shift rules on, every figure against sums of the operational
       tables), `DashboardBudgetTest`. Changed: `AnalyticsSchemaTest`, `RebuildDayTest`, `StationBuilderTest`, `UserBuilderTest` (the prep
       columns), `ModuleBoundariesTest` (`StationLabel`), `FactsContractTest` (3 cases). No test turns a shift rule off.
     - **The stock-alert fix (Inventory, test first, its own commits).** On the live host the hourly sweep (`AlertSweeper`) queued the
       evaluation of every reorder rule and open episode, also those of the E2E branch's retired (soft-deleted) ingredients, and
       `EvaluateBalance` failed three times an hour for each (the Ledger locks live rows only): 3,529 rows in `failed_jobs` on 2026-10-26.
       The sweep now leaves out the rules and episodes of a deleted item or a removed place, a branch-wide rule is evaluated through a place
       still in use (`LedgerReader::lowestStockPlaces`), and `EvaluateBalance` of a balance whose item or place is no longer live returns at
       once and writes nothing, whoever queued it (the sweep, a rule change, a late movement event). An open episode of such an item stays as
       it is (ADR 0011: only an evaluation of a live balance opens or closes one), so nothing more is announced and a restored item is evaluated
       again. A switched-off item is still swept: it is still stocked, and the Ledger keeps its balances. Tests: `SweepAlertsCommandTest` (2
       cases), `EvaluateBalanceTest` (1 case, two datasets). The failed rows already on the host are left for Ramin.
     - STATUS: phase 4's `analytics` step and the module stay `in_progress`.
   - **4.4d `phase-4/analytics-exports-weekly`**: exports (`openspout/openspout` for xlsx, dompdf for the PDF summaries, CSV), `WeeklySummary` builder (previous Monday to Sunday: revenue and
     orders against the week before, best and worst products by margin, channel split, refunds and voids, variance lines, stock-outs; reads summaries only), `analytics:weekly-summary`,
     `WeeklySummaryReady`, `NotifyWeeklySummary` in Notifications with bilingual copy and the signed 7-day PDF link. Tests: `ExportOpensTest` (the xlsx is read back with the same library: sheet names,
     header row in the requested locale, row count, numbers stored as numbers with the decimal string exactly), `ExportPdfTest`, `ExportTooLargeTest`, `WeeklySummaryTest` (figures equal the
     summaries; dispatches once for a Monday; `Event::fake` plus a `sync` twin that creates the inbox line), `NotifyWeeklySummaryTest` (Telegram driver array in CI). `analytics` `module-stable`.
   - **As built (step 4.4d, `phase-4/analytics-exports-weekly`).** Order: the tests and the registries (red: the new classes, routes, lang
     files, type and schedule entry are missing); a fix found on the way in 4.3e's payments xlsx (its own commit); the shared PDF setup and the
     xlsx writer; the dashboards' figure rules moved into `Read`; the exports; the weekly summary with its event, link seam, Notifications
     listener and migration; OpenAPI and ERD; this note, the runbook and STATUS. Where the code disagreed with the plan or the plan left a choice:
     - **xlsx figures are numbers that hold their decimal strings exactly.** The plan's test wants "numbers stored as numbers with the decimal
       string exactly". The money rule forbids the PHP float that OpenSpout's numeric cell takes, which is why 4.3e kept money as text. Both
       hold here: `Shared\Exports\XlsxFile` writes a figure (`DecimalCell`: money and percents at scale 2 with the built-in format 0.00,
       quantities at scale 4 as they are) as a text cell with a private-use marker in front. Once OpenSpout has closed the file, each sheet's
       XML is streamed through once more, cut after a cell's end so memory stays flat, and every marked cell becomes a number cell holding the
       same characters (`<v>60.00</v>`). The marker is stripped from every other text, so typed text can never become a number. A count is an
       integer cell, and a figure that is not there (a day not built) is an empty cell, never a zero. The payments export of 4.3e keeps money
       as text.
     - **Found in 4.3e's payments xlsx:** OpenSpout's `Row::fromValues` makes a formula cell of any text that starts with `=`, so a typed
       reference or dispute reason could run as a formula. `PaymentsExport` and `XlsxFile` now build every text as a text cell; tests on both.
     - **The PDF setup is Shared's.** `Shared\Pdf\PdfFonts` (was Payments' `ReceiptFonts`; config `pdf.fonts_dir`, was
       `payments.receipts.fonts_dir`; lock `pdf:fonts`) and `PdfRenderer` (the 4.3e options, A4 portrait or landscape, bytes and pages).
       `ReceiptRenderer` prints through it. `receipts:fonts` keeps its name, so `release.sh` does not change. Analytics could not use Payments'
       class (ModuleBoundariesTest), and two copies of the font setup would drift.
     - **The exports** (`Export\Reports`, `Export\ReportFiles`; `ExportsController`). A report is the dashboard's own table, then its rows
       day by day: Sales and Hours; Channels and Channels by day; Items and Items by day (products and ingredients, most revenue first);
       Stations and Stations by day; Staff and Staff by day (by kind of work, then person); Shifts and Shifts by station. The figures come from
       the dashboards' own rules, which moved from the 4.4c controllers into `Read\SalesPoint`, `ChannelFigures`, `StationFigures`,
       `PersonFigures`, `ShiftDays` and `ItemFigures::ranked`, so a file and a dashboard cannot disagree. The day sheets read the summary table
       500 rows at a time (`Summaries::dayRows`, in primary-key order).
       - xlsx: every table, then About (the report, the range, the zone, the oldest rebuild as local time, the days not built, when the file
         was written). Sheet names, headers and values (yes/no, kinds, kinds of work, names) come from `lang/{en,am}/reports.php`.
       - csv: the first table, with the byte-order mark and the formula guard of 4.3e.
       - pdf: landscape A4 through `Shared\Pdf`, at most three pages: the title, the range, the freshness stamp, the report's totals and its
         first table, with only the columns a page has room for. Sales and shifts are printed a row a day up to 31 days, a week up to 92 and a
         month beyond. A table that still runs past three pages is laid out again shorter and ends with "N more rows are in the spreadsheet
         export".
       - The rows counted against `analytics.export_max_rows` (20 000) are the rows the file holds: every table of an xlsx, the first of a CSV
         or a PDF. Header rows and About do not count. They are counted from the summaries before anything is written.
       - The audit row is `analytics.export` {report, format, locale, from, to, rows}, written after the count. A download is named
         `analytics-{report}-{from}-{to}.{format}`, `Cache-Control: private, no-store`. An unknown report is 404 (the route knows the six).
       - `DashboardsReadSummariesOnlyTest` reads every report in every format once (files are not conditional). An export's one INSERT INTO
         `audit_log` is the only statement on that table allowed to an endpoint, and reading it is still caught.
     - **The weekly summary** (`Weekly\WeeklySummary`): the previous complete Monday-to-Sunday week in the branch's zone (on a Monday, the week
       that ended last night; on a Sunday, the week before), from the summaries and the names only (its test holds it to them with a query
       log).
       - Revenue and orders against the week before, with the change in percent (2 dp half-up, null when the week before had none), net
         revenue, the average ticket, refunds (on the day paid back), voids and cancellations.
       - Margins: three products with the best margin and three with the lowest, lowest first, never one in both lists; only products that
         sold, each with its units, revenue, margin, margin percent and `cogs_complete`.
       - The money per channel with its share, the count variances of the shifts dated in the week, the stock-outs (all items) and the five
         items that ran out most, and its freshness (as_of, the days not built).
     - **`analytics:weekly-summary {--branch=} {--week=}`**, scheduled `weeklyOn(1, '07:00')` in `analytics.timezone`, `withoutOverlapping(30)`,
       after the 00:05 and 05:30 rebuilds.
       - It announces a branch and week **once**: the cache key `analytics:weekly-summary:{branch}:{monday}` is taken atomically for eight
         days, and given back if the build fails. Run "scheduled".
       - A week that is not over is exit 2. A failed branch is logged as `analytics.weekly_summary.failed` (branch, week, error class) and
         makes the exit code 1.
     - **`POST /analytics/weekly-summary/send`** (analytics.manage, Idempotency-Key, `{week_of?}`: any date of the week; the last complete week
       by default).
       - It builds the same summary and announces it again every time, as run `manual-<uuid>`, so each send is a new message and a new try of
         the Telegram path. A replay of the same key answers the stored 202 and announces nothing.
       - `202 {week_start, week_end, run, summary}`, one audit row `analytics.weekly_summary.send` {week_start, week_end, run}.
       - A week that is not over is `422 analytics.weekly.week_open {week_start, week_end}`.
     - **No URL in the event** (section 5 corrected): the table listed "link" in `WeeklySummaryReady`'s payload, against the section's own rule
       (never a PIN, a key or a URL). The event carries `run`, `by_user_id` and the whole summary. `NotifyWeeklySummary` asks Shared
       `Reports\WeeklySummaryLinks` (bound by Analytics' `SignedWeeklySummaryLinks`; Shared's default answers null) for a seven-day signed link
       per language when it writes the line. The link is then in the inbox line's body and payload (`pdf_url_en`, `pdf_url_am`,
       `pdf_expires_at`) of the people it was sent to, and never in a queued job or a failed one.
     - **The PDF of the week** is the third public route of the API: `GET /weekly-summaries/{week}?branch&locale&expires&signature` (signed;
       `public_routes.php` and `check_invariants.py`).
       - It sets the branch the link names as the current branch, builds the summary when it is opened (so its stamp says as of when) and
         answers one A4 page through `Shared\Pdf`. It is not cached and not indexed.
       - A changed or expired link is `403 analytics.weekly.link_invalid` (`bootstrap/app.php` picks the key by path). A week that is not a
         Monday is 404.
     - **Notifications.**
       - `NotifyWeeklySummary` (queued after commit) tells the holders of `analytics.manage` (the admin, as the plan's "admins"); the person
         who sends one by hand is told too. Type `weekly_summary`: info, in-app, push and Telegram, inbox key `weekly_summary:{week_start}:{run}`,
         deep link `/admin/analytics?from=..&to=..`.
       - The text is written from the payload in both languages: revenue and its signed change, orders, the margins, the channel shares, the
         refunds and voids, the variances, the stock-outs and the link. A body without the link is used when none could be made. Names are cut
         at 40 characters.
       - Migration `2026_11_07_100100_extend_notifications_for_weekly_summary` adds the type to `notifications_type_check`, as 4.3c did, and
         widens `body_en` and `body_am` from 500 to 1000 characters (the figures, the names and the signed link, about 180 characters, do not
         fit in 500). It is DDL only and safe on live.
       - Push and Telegram stay off on the live host until Ramin creates their keys; CI's array driver stands in for them
         (`NotifyWeeklySummaryTest`).
     - **Not built here:** `php artisan analytics:bench` of 10.2 R-G (the runbook had named this step for it). It belongs with the gate's
       `DashboardBudgetGateTest` in 4.6b; the runbook says so. Refund figures stay the summaries' zeros until 4.1b and 4.3d. The display-case
       COGS gap of 4.4a shows in the exports and the summary's margins as it does on the dashboards.
     - **Tests:**
       - Unit: `XlsxFileTest`.
       - Feature: `PdfFontsTest` (moved from Payments), `PdfRendererTest`, `ExportOpensTest`, `ExportPdfTest`, `ExportTooLargeTest`,
         `ReportsLangTest`, `WeeklySummaryTest` (with the schedule entry), `NotifyWeeklySummaryTest`.
       - Changed: `PaymentsExportTest` (the xlsx formula case), `ReceiptFontsTest` (only `receipts:fonts`), `DashboardsReadSummariesOnlyTest`,
         `AnalyticsLangTest`, `NotificationTextTest`, `NotificationsSchemaTest`, `RoutePermissionMatrixTest` (`{report}`).
       - No test turns a shift rule off.
     - STATUS: phase 4's `analytics` step is `done` and the module `stable` (`phase_status.py module-stable analytics`, after CI's
       `mod-analytics` passed).
5. **4.5 web** (STATUS `web`), six PRs, Next 15 static export under `/app`, en and am, 48 px targets, tablets first; pure rules in `lib/*.ts` tested without a browser (the Phase 3
   `*-logic.spec.ts` pattern). No chart library is added (10.8 R-H): `components/charts/{Sparkline,Bars,Line}.tsx` are small inline SVG with `role="img"` and a table fallback.
   - **4.5a `phase-4/audit-read-api`**: API only: M17, Shared `ActorDirectory` (+ Identity binding), the three audit routes, `audit.export`, `Snapshot` redaction additions (`receipt_file_id`, signed URL
     shapes). Tests: `AuditLogListTest`, `AuditLogFiltersTest`, `AuditExportTest`, `AuditIsALeafTest` (arch: Audit imports only Shared), 403 matrix (manager reads, cashier 403).
   - **As built (step 4.5a, `phase-4/audit-read-api`).** Order: the tests and the registries (red, two commits: the list, filters, export,
     redaction on read and the leaf rule; then M17, the seam, the grant, the lang file and Snapshot's rules), then main merged (4.4d's
     `Shared\Exports\XlsxFile`), then the code in small commits (the seam, Snapshot, the migrations and the registry, the routes), OpenAPI and
     ERD, this note, the manifest, the runbook and STATUS. Where the code disagreed with the plan or the plan left a choice:
     - **The seam is `Shared\Contracts\ActorNames`** (`names(ids)`: id => name in id order), not a second class named `ActorDirectory`:
       Identity's `ActorDirectory` of 4.4c implements it and `IdentityServiceProvider` binds it, per use; Shared binds `NoActorNames` (nobody
       has a name) until then, as with `NoOpenShifts`. A list page asks it once for every actor and acting user on the page; an id nobody of the
       branch has is named null, a person who has left is still named. `tests/Arch/AuditIsALeafTest.php` reads the forbidden list from disk
       (every other module, every top-level namespace of `app/` but Shared, and the scaffold's `App\Models`, `App\Http`, `App\Providers`,
       `App\Console`); the older rule in `ModuleBoundariesTest` stays.
     - **M17** is `2026_11_08_100100_add_audit_log_read_indexes`: the plan's four and `(branch_id, acting_user_id, id)`, because the person's
       filter (`actor_id`) finds the person as the account signed in or as the person acting by short code at a shared pad (on a shared till
       the signed-in account is the till's and the waiter is the acting user). Indexes only; `AuditLogReadIndexesTest` drops and adds them over
       rows and finds the rows byte for byte and the triggers still refusing.
     - **The list.** Newest first by id, keyset paged (`cursor` the last id, `limit` 1 to 200, 50 by default, `next_cursor` a string as in
       `GET /payments`, null on the last page); `at` is UTC ISO 8601. Filters: `from`/`to` local dates of `at` (LocalDay), `action` a literal
       prefix (`LIKE ... ESCAPE '!'`, so `payment_` does not find `paymentXchannel`), the rest equal; they AND. `summary` is `{changed, more,
       reason}`: the fields whose value differs between before and after (missing on one side counts as null), without `id`, `branch_id`,
       `created_at` and `updated_at`, sorted, at most 10 with the count of the rest, and `context.reason` when it is a text. An event's payload
       (before null) lists its fields; a redacted field shows as changed only when it went from null to set or back. `GET /audit-log/{id}`
       adds `before`, `after` and `context`; another branch's row is 404 `http.not_found`.
     - **Redaction, on write and on read.** `Snapshot` now works at every depth of an array, and `AuditedAction` applies it to the context too
       (it was stored as given). New key rules: `receipt_file_id` and `proof_file_id` (the trail says a photo was attached; the payment reads show
       it in their own scope; 4.3b had kept the id, so `SnapshotTest` and `TakePaymentTest` changed), `key`, `object_key`, `*_object_key`, and
       `fingerprint` (a device's X-Device-Id, which a pad sends to be taken for that device; enforced from Phase 5). Value rules, in any text: a
       signed URL (`signature=`, `X-Amz-Signature`/`Credential`, `X-Goog-Signature`/`Credential` in a URL or a path) and an object key of the
       file store (`{purpose}/{branch}/{yyyy}/{mm}/{uuid}.{ext}`), each replaced where it stands; a text that holds JSON (a JSON column as the
       model stores it) is redacted inside and kept byte for byte when there is nothing to hide. Redacting twice changes nothing, so **every
       read** applies the rules to before, after and context, and the rows stay untouched. **What older rows can hold** (read from the writers'
       history, not from the live table): the `fingerprint` of every `device.enrol` and `device.revoke` since Phase 0, the `receipt_file_id` of
       every payment write since 4.3b, and contexts, which were never redacted (as built they carry reasons, notes, ids, channel codes and
       amounts: no PIN and no reference). No row holds a reference number, a PIN, a file key or a signed URL: `reference_no` was redacted from
       the day payments existed (step 1.4), `receipt_photo_key` and `reference_norm` from the step that first wrote them (4.3b), and no audited
       payload carries a URL. The next gap would have been the weekly summary's inbox line (4.4d): its `data` holds the signed PDF links and
       `notification.read` snapshots the line, so marking it read would have logged a link; the value rule redacts it on write and on read.
       `AuditRedactionOnReadTest` plants such rows raw and reads them through the list, the row, the CSV and the xlsx.
     - **The export** reuses `Shared\Exports\XlsxFile` (main merged with 4.4d before it was used): every text a text cell, never a formula;
       the ids number cells; a JSON longer than a cell holds (32 767 characters) is `audit.export.too_long` naming the row in the xlsx, and whole
       in the CSV. CSV: byte-order mark and the apostrophe guard on every text. Oldest first; the columns `id, at` (local), `actor_id, actor,
       acting_user_id, acting_user, action, subject_type, subject_id, station_id, shift_id, device_id, ip, changed` (every changed field),
       `reason, before, after, context` (redacted JSON), headers in `?locale=en|am` (`lang/{en,am}/audit.php`). At most `audit.export_max_rows`
       (50 000, `config/audit.php`), counted before anything is written. The file ends at the newest id there was when it was asked for, so the
       export's own audit row `audit.export` (format, locale, filters, rows) is never in it.
     - **Permissions.** `audit.view` (admin, manager) is in the registry since Phase 0; `audit.export` (admin) is new, with its own grant-only
       migration `2026_11_08_100200_grant_audit_export_permission` (10.6 corrected). The 403 matrix runs the three routes in `module:audit`
       (the `api-arch` job); its `{id}` there is a row of the caller's branch. `PermissionRegistryTest` gained `audit.export` and the missing
       `analytics.manage` row.
     - **Tests**: `AuditLogListTest`, `AuditLogFiltersTest`, `AuditExportTest`, `AuditRedactionOnReadTest`, `AuditLogReadIndexesTest`,
       `AuditLangTest` (`module:audit`), `tests/Arch/AuditIsALeafTest.php`, `ActorNamesBindingTest` and `GrantAuditExportPermissionMigrationTest`
       (Identity), `SnapshotTest` (five cases), `AuditedActionTest` (the context). Changed: `SnapshotTest`'s 4.3b case and `TakePaymentTest`
       (`receipt_file_id` is redacted), `PermissionRegistryTest`, `RoutePermissionMatrixTest`. No test turns a shift rule off. The summaries-only
       query-log test (4.4c) covers `/analytics` only; the audit routes read `audit_log` and the names, which is their job.
     - STATUS: phase 4's `web` step is `in_progress` (4.5a opens the web group, though it is API only).
   - **4.5b `phase-4/web-pay-sheet`**: `components/till/PaySheet.tsx` replaced by the full sheet. Behaviour: amount due and balance at the top; one **row per payment**: a channel picker (enabled
     channels in admin order, the `cash` row first), an amount (defaults to the remaining balance, decimal string arithmetic in `lib/money.ts`), for cash the tendered amount and the change due, for
     a channel with `requires_reference` a reference field with the channel's hint and pattern checked live, for `requires_photo` a **camera button** (`<input type="file" accept="image/*" capture="environment">`)
     that downsizes in the browser (`lib/images/downscale.ts`: `createImageBitmap(..., {imageOrientation:'from-image'})`, canvas, longest side 1600, JPEG 0.82, falls back to the original file if decoding
     fails), uploads with its own `client_uuid` and Idempotency-Key (a retry reuses both), shows the thumbnail and a replace button; **Pay stays disabled** until every row is complete (photo
     uploaded, reference valid, amounts sum to exactly the balance, a till shift running when `NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH` is on, which now covers every channel); Complete posts
     `payment-batches` once with one key; a network failure keeps the sheet and its uploaded photos (the photo ids are already on the server) and the retry is safe; offline says so (the outbox is Phase 5).
     Receipt after completion: preview, download PDF, copy link (`receipt-link`). Pure rules in `lib/paySheet.ts` (`rowsComplete`, `remaining`, `canComplete`, `blockReason`, `changeDue`) tested in
     `e2e/p4/pay-sheet-logic.spec.ts`. The `VoidDialog` gains the refund path for a closed order (lines to refund, disposition per line with the 10.2 R-C defaults, reason, manager PIN).
   - **As built (step 4.5b, `phase-4/web-pay-sheet`).** Order: the tests red (the pure rules failing on the missing `lib/paySheet.ts` and
     `lib/images/downscale.ts`, the three browser flows on the missing sheet), then the rules, the downscale and the client's calls, then the sheet,
     the receipt, the payment void and the strings; this note. Where the code disagreed with the plan or the plan left a choice:
     - **No `lib/money.ts`.** The arithmetic is `lib/decimal.ts` (scaled BigInt, Phase 1) with the keypad helpers of `lib/payment.ts`; `lib/paySheet.ts`
       adds `moneyOf` (typed text to a 2 dp string: at most ten whole digits and two decimals, the API's `MONEY` rule). Never a float.
     - **The rules** (`lib/paySheet.ts`): `sheetChannels` (enabled, by `sort`, cash first), `remaining`, `restOf` (the "Rest of the balance" button),
       `newRow` (the rest of the balance, on cash when no other row has it), `canUseChannel` (cash on one row only, one drawer count; any other channel on
       several), `changeDue`, `checkReference`, `rowIssue`, `rowsComplete`, `blockReason` and `canComplete` (in order: the till's shift, offline, nothing due,
       no rows, more than 6, the first incomplete row by number, then short or over by how much), `batchRows`, `keepsKey`. A cash row is complete once the cash
       handed over covers it (as the Phase 1 sheet; the API would take a missing tender as exact); "Exact amount" fills it.
     - **The reference check** compiles the pattern body as `^(?:body)$`, in the strict `u` mode first and the plain one when that fails (an escape such as
       `\-` that `u` refuses); a body neither compiles is not checked here (the API decides). Trimmed, case-sensitive, at most 64 characters, as
       `ReferencePattern` matches. Only a channel with `requires_reference` shows the field and sends `reference_no`; only one with `requires_photo` sends
       `receipt_file_id`.
     - **The photo** (`lib/images/downscale.ts`): `createImageBitmap(file, {imageOrientation: 'from-image'})`, an `OffscreenCanvas` (else a canvas) filled
       white, longest side 1600 never enlarged, JPEG 0.82; the original file when decoding or encoding fails. The camera button is a `<button>` that clicks a
       hidden `<input type="file" accept="image/*" capture="environment">` (named "Receipt photo file"), so a device without a camera opens the file chooser.
       The upload starts as soon as the photo is taken (`POST /files`, purpose `receipt`, its own `client_uuid` and key, both reused by "Try again"), so
       Complete never waits for it; the thumbnail is the local object URL of the shrunk picture, not a signed URL. A replaced photo is left unattached for
       the orphan purge (24 h): the till sends no `DELETE /files`.
     - **Idempotency after a failure.** The batch's `client_uuid` and each row's stay for the sheet's life. The Idempotency-Key is kept for a retry only when
       the outcome is unknown (no answer, a 5xx, `idempotency.in_flight`); after a refusal (4xx) the next Complete takes a new key, because the API stores a
       refusal and would replay it under the same key (a `payments.shift.required` would come back after the shift started). A changed body after a lost
       answer still carries the batch's `client_uuid`, which `RecordPaymentBatch` answers with the batch it recorded, so nothing is taken twice.
     - **The sheet is kept per order** while the page lives (`lib/payDrafts.ts`, zustand, memory only): closing it (Escape, a tap beside it) and opening it
       again shows the rows, the photos already uploaded and the same batch id. A reload starts afresh (the outbox is Phase 5). `payments.batch.row_invalid`
       marks the row it names with that row's own message, and a `payments.photo.invalid` row asks for a new photo. A network failure says the sheet is kept
       and a retry is safe; offline (`navigator.onLine`) blocks Complete and says so.
     - **The till passes the live order** (the queue's copy, so a line added meanwhile moves the balance and Complete waits for the rows to match) and the
       block of `payBlockReason` (the build flag or the API's first `payments.shift.required`), which covers every channel since 4.3b; the till's strings say
       "a payment", not "cash".
     - **The receipt**: a preview from the order the till holds, the payments the API answered and the references as typed, last 4 characters (the write
       answers do not echo a reference), under the business name of `GET /receipt-settings`; "Download receipt (PDF)" fetches
       `GET /orders/{order}/receipt.pdf` with the till's token (new `requestBlob` / `authedBlob`) and saves it; "Copy receipt link" posts `receipt-link`
       (one key per receipt, so a retry replays the same link) and copies it, or shows it to copy by hand when the clipboard is not available.
     - **The void of a payment is wired in** (4.3c's endpoint). A settled order (paid in full, not served) shows "Void a payment" in the queue; `VoidDialog`
       lists the order's payments that are not voided or refunded (`GET /orders/{order}/payments`), and for a part-paid order "The whole order" too. A settled
       order cannot be voided whole (`orders.order.paid`), so that choice is not offered. Reason and manager's PIN as for the order void, a new key after a failure.
     - **Refunds are not built** (4.1b and 4.3d wait for R-C). The refund path the plan puts in `VoidDialog` is a marked place (`RefundPlaceholder`, a
       `TODO(p4)` in the file's header): for a paid or closed order the dialog says refunds are not available yet. Nothing in the till reaches it today.
     - **Tests.** `pay-sheet-logic` (13 pure cases); `pay-photo-split` @live, the gate flow of 10.3 item 2 (cash 70.00 with 100 handed over plus the run's
       wallet 180.00 with a photo and a reference; Complete disabled with its reason until the photo is uploaded; one `payment-batches` 201; the order closed,
       two payments of one batch, the file attached to the wallet's and stored without EXIF at 1600 x 1200, and what left the browser already 1600 x 1200
       without EXIF; the PDF downloaded and the link fetched); `pay-camera-fallback` (CI only: the button opens the file chooser; with `createImageBitmap`
       failing, the original 2400 x 1800 with its GPS block goes up as it is and the server stores it without EXIF at 1600); `pay-void` @live (cash and the
       run's bank transfer on an unserved order, the transfer voided with a manager's PIN after a wrong one, the rest taken in cash, served and closed; a void
       of a closed order's payment is `409 payments.void.order_closed`). `pay-refund` stays a `test.fixme` stub for 4.3d; the four others 4.1a left for 4.6b
       are real now. A multipart body holding a Blob is not readable by Playwright (`postDataBuffer()` is null), so the flows read the uploaded file through a
       fetch wrapper the test installs (`recordUploads`, it only observes). The photo fixture is drawn by the page's canvas with an injected EXIF/GPS block
       (`e2e/support/photo.ts`); no binary is committed. `p1-order-flow`'s cash step presses "Complete payment"; its Phase 1 check that the digital channels
       were disabled is gone.
     - **Fixtures** (`e2e/support/payments.ts`): the till staff of 1.6c, a run-unique display-case product (ready when sent, served by the cashier through the
       API) and, per fixture, two run-unique channels made through `POST /payment-channels`: `e2e_wallet_<run>_<n>` (photo, reference, pattern
       `[A-Z]{2}[0-9]{6}`, a hint pair) and `e2e_bank_<run>_<n>` (reference only), each `client_uuid` derived from the code so a retried call gets the same
       channel. The number is there because the API keeps a removed channel's code taken (section 7 said `e2e_wallet_<runId>`). A flow removes its channels
       (soft) in its `finally` and voids or serves its order; `retireStaleChannels` first removes the `e2e_` channels earlier runs left. The default channels
       and the demo branch are never touched.
     - **Strings**: 65 new keys under `till` in `en.json` and `am.json`; `digital_reason` and `confirm` are gone.
     - **Local run notes**: the local dev API (`php artisan serve`, one worker, 3 to 5 s a request over the Windows bind mount) cannot carry a till screen, so
       the flows ran against a second `php artisan serve --no-reload` with `PHP_CLI_SERVER_WORKERS=8` and opcache timestamps off (a local container only, not
       a repo change): `p4-pay-photo-split` 16 passed (the refund stub skipped), `p1-order-flow` 6 passed.
     - STATUS: phase 4's `web` step stays `in_progress`.
   - **4.5c `phase-4/web-admin-payments`**: `/admin/payments` with tabs Channels (list, add, edit, reorder with up/down buttons not drag-only, switch, rules, hints, the cash row locked), Payments
     (the polled list with filters, photo thumbnail opens a viewer with a fresh signed URL, verify and dispute buttons, flagged chips), Reconciliation (per day and channel, with a "missing photo" drill),
     Receipts (the settings form). Export button. `lib/api/adminPayments.ts`, `lib/live/payments.ts` (poll 10 s visible, 30 s hidden, 304 keeps state).
   - **As built (step 4.5c, `phase-4/web-admin-payments`).** Order: the tests red (the pure rules failing on the missing `lib/adminPayments.ts`, the two
     browser flows on the missing pages), then the rules, the client's calls, the feeds and the deep-link pages, then the screens and the strings; the ADR 0009
     note and this note. Where the code disagreed with the plan or the plan left a choice:
     - **Four pages under one tab, not four tabs of one page.** `/admin/payments` (the list, where the `payment_disputed` link of 4.3c,
       `/admin/payments?payment={id}`, lands), `/admin/payments/reconciliation`, `/admin/payments/channels` and `/admin/payments/receipts`, under pills as
       Inventory and Shifts do (`PaymentsNav`, each pill shown to the holders of its permission: `payments.view`, `payments.verify`, `channels.manage`,
       `payments.take`). The admin console's "Payments" tab is its first tab shown only to someone who holds one of the section's permissions
       (`payments.view` or `channels.manage`); the overview has its card. The list comes first (the daily work and the link's target; the plan listed
       Channels first). `lib/deeplink.ts` knows the four pages, so the inbox links to them.
     - **The list's filters live in the address** (`from`, `to`, `channel_id`, `status`, `order_no`, `missing_photo`, `flagged`; `filtersFromParams` and
       `filterParams`), so a reload, the reconciliation's drill and the notification's link land on the same list; a malformed value is dropped, never
       sent. The API's `reference` and `shift_id` filters have no field here (the step did not ask for them).
     - **The polled list** (`lib/live/payments.ts`, `PAYMENTS_POLL` 10 s visible, 30 s hidden, a 304 keeps what is shown, one feed per filter set) polls
       the first page only. "Older payments" reads the next pages by cursor once (`olderPayments`, not polled); `foldHead` keeps the rows that new payments
       push out of the first page above the older pages, so nothing goes missing between the two. A verify or dispute answer replaces its row
       (`patchRows`) and asks the feed to look again.
     - **The photo**: a list thumbnail starts from the list's signed URL; the viewer always asks `GET /files/{file}/url` when it opens; a picture that
       fails to load asks for a fresh URL once more (`useSignedUrl`, at most two per picture, then "could not be loaded" with a retry). The dialog shows a
       larger thumbnail that opens the same viewer; Escape then closes the viewer only.
     - **Verify and dispute** are in the payment dialog, not inline in the list: verify with an optional note (the audit trail's only), dispute with a
       reason checked here for 3 to 255 characters. A refused reason (`422 validation.failed` on `reason`) shows the dictionary's twin of
       `payments.dispute.reason_required` (the API's field text is English only); every other refusal its own `message_en`/`message_am`. The keys follow
       4.5b: a new key after a 4xx, the same one when the outcome is unknown (`keepsKey`). The same rule holds for every channel write and the receipt
       settings.
     - **Chips** (`chipsOf`): disputed, duplicate reference, duplicate receipt photo. A disputed payment shows its chip in place of the status badge, so
       the word shows once.
     - **The export**: "Export CSV" and "Export Excel" for `payments.verify`, the list's filters and the page's language, read with the bearer token
       through `authedBlob` (4.5b's PDF read gained an `accept` option) and saved under the name the API gives the file (`exportFileName`, rebuilt in the
       browser: a cross-origin answer's `Content-Disposition` is not readable by script unless exposed). `422 payments.export.too_large` shows the API's
       bilingual message.
     - **Payment methods**: the live ones in the till's order (sort, then id) with Up and Down (`moveIds`: a swap with the neighbour, then
       `PUT /payment-channels/order` with every live id once), Edit, Switch off or on, Remove (asked once more); cash shows only Up and Down and "Locked"
       (`channelControls`). The removed ones are folded under "N removed payment methods" with the day they went (the E2E branch holds dozens). The form,
       below the list: the code (on a new method only), both names, the two rules, the pattern with a live check (`patternCheck`: none, read well, too
       long, unreadable in this browser; the server's `ReferencePattern` still judges it on save and its refusal shows its `reason` in words) and a sample
       reference tried against it (`sampleCheck`: the whole reference, trimmed, case-sensitive, as the API matches it), and the hint pairs. An edit sends
       only what changed, a hint pair together (`changesOf`); a new method keeps one `client_uuid` until it is made.
     - **Reconciliation**: the range is the screen's own state (the last 7 local days by default, at most 62 checked before asking), a feed
       (`RECONCILIATION_POLL` 30 s visible, 60 s hidden); the newest day first, a table per day with a day total (`dayTotals`, decimal strings), the days
       without a payment on one line, then the range's table from the API's `totals`. The refund columns render (zero until 4.3d). A "without photo" cell
       links to the list of that day, that method and `missing_photo=1` (`missingPhotoDrill`), except on cash (`canDrill`): the API counts cash payments
       as without a photo while the list's `missing_photo` leaves cash out, so that drill would open an empty list.
     - **Receipts**: the form over `GET /receipt-settings` (the till's query and shape), editable with `channels.manage` and read-only otherwise; the PUT
       replaces the whole form, an empty text is null, the pairs are both or neither; `is_default` says the settings were never saved.
     - **Query keys**, one per answer shape: `["payment-channels", "all"]` (the admin list with the removed ones; the till's `["payment-channels"]` keeps
       the live list, and a channel write refreshes both), `["payment", id]` (a payment's detail), `["receipt-settings"]` (the till's own key, the same
       shape). The list and the reconciliation are feeds, not queries.
     - **No refund** is issued anywhere: the dialog only shows a refunded amount when there is one, and the reconciliation's refund columns stay.
     - **UI**: `Button` gained `compact` (less side padding, still 48 px tall) for the row of actions of a method.
     - **Strings**: 246 keys under `adminPayments` plus `admin.payments` and `admin.payments_desc`, English and Amharic. The literal braces of the pattern
       hints are quoted for ICU (`'{10}'`).
     - **Tests**: `pay-admin-logic` (19 pure cases: the filters and the address, the drill, the inbox link, the export name, the order and its moves, the
       cash lock, the channel form, the pattern and sample checks, chips and decisions, the reason, the first page with the older pages, the row patch, the
       day totals, the range, the receipt form) and `pay-admin` (two flows, @live): a method added with its pattern checked live, edited (the PATCH holds
       only the change), moved up past the run's bank transfer and back (the PUT names every live id once), switched off and removed, with cash showing
       no edit, switch or remove; a 250.00 order paid through the API with the run's wallet (a photo and a reference) and bank transfer, the wallet
       payment found by its method, its photo opened from a fresh signed URL and loaded, a too-short reason refused before sending, disputed, verified,
       the reconciliation of today counting both run-unique methods exactly, the bank's missing photo drilled into, the deep link opening the payment and,
       in CI only (on the live host the queue waits for the cron), the manager's inbox line followed to it. The fixtures gained `Api.postForm`,
       `uploadReceipt`, `payBatch` and a background colour per run for `canvasJpeg` (`runTint`): two runs' photos drawn alike were the same stored bytes,
       which the API flags as a duplicate receipt.
     - **Local run notes**: the flows ran against a second local API container (eight CLI-server workers, opcache on without timestamp checks, a sync
       queue, port 8001; not a repo change): `pay-admin-logic` and `pay-admin` 21 passed.
     - STATUS: phase 4's `web` step stays `in_progress`.
   - **4.5d `phase-4/web-order-timeline`**: `/admin/orders/record` (as built; the plan said `/admin/orders/[id]`: a record page by `?id=`, export-safe) merges `GET /orders/{order}` (events) with `GET /orders/{order}/payments` (photo thumbnails, signed
     on read, re-signed when a viewer opens after 5 min), shows void/refund/payment events with who approved, and the refund action. The orders board links to it.
   - **As built (step 4.5d, `phase-4/web-order-timeline`).** Order: the tests red (the pure rules failing on the missing `lib/orderRecord.ts`,
     the two browser flows on the missing page), then the rules and the strings, then the page, the links into it and the reused pieces; this note.
     Where the code disagreed with the plan or the plan left a choice:
     - **The address.** `/admin/orders/record?id=` (the plan said `/admin/orders/[id]`: the static export has no dynamic segment, so a query, as 4.5e's
       product page), under the Orders tab; `lib/deeplink.ts` knows it. A missing or malformed id is said in words and never sent; another branch's order
       or none reads "This branch has no such order" (the API's 404).
     - **Who opens it: `payments.verify`** (the admin and the manager). The reads behind it ask for less (`GET /orders/{order}`: `orders.create`;
       `GET /orders/{order}/payments`: `payments.view`; the cashier holds both), so the gate is a choice: the record is the payment checkers' page (the
       voids, the decisions, the audit links), and a cashier reads "Your account cannot open this page". The links into it are shown to the same people.
     - **Two reads that exist, no API change.** `GET /orders/{order}` under the orders board's key `["order", id, version]` (the same shape; the page has
       no feed version, so `null`) and `GET /orders/{order}/payments` under the till void dialog's `["order-payments", id]` (the same shape). A void made
       on the page reads both again. **Not polled**: neither read answers an ETag (no `ConditionalPoll`), so a poll would read whole bodies and re-sign
       every photo each time, and an order on this page is mostly finished; the page has Refresh with a line saying when it was read and that it does not
       update by itself (the audit log's way). No feed was added, so ADR 0009's note is unchanged.
     - **The merge** (`recordEntries`): the timeline's events as written, plus for each payment what the timeline does not hold: its recording when no
       `payment.recorded` names it (a Phase 1 payment, before 4.3c wrote the event), its dispute and its verification (the payment row's `disputed_at` and
       `verified_at`: never on the timeline), and its void when no `payment.voided` names it. Oldest first by instant (parsed, so an offset does not
       matter; a time that cannot be read goes last); inside one second a payment known only by its row comes first (it is what settled the order), then
       the events by id, then the decisions by payment, in the order recorded, disputed, verified, voided. The kinds are one table (`ENTRY_KINDS`: the 20
       timeline events and the payment's two decisions, each with its tone and whether it is about a payment or names an approver); a kind not listed
       shows as the API wrote it. The orders board's timeline takes its words from the same table, so its payment events now read as words too.
     - **Who did it.** An event carries ids only: who did it is the person acting by short code at a shared screen, else the account (the account is
       named too when they differ); the approver is the row's `approved_by_user_id` (the order's void, each line it removed, a payment's void). Names: the
       people list (`GET /users` for `people.manage`, the People screen's `["users"]`, as 4.5f reads it), then the names the page's own rows carry (the
       order's owner, whoever took each payment, the viewer and the person acting at their screen), else `#id`; so a manager reads an approver's name
       only when it is one of those. A dispute and a verification name nobody: the payment row has no column for who decided (4.3c keeps it in the audit
       context), so the entry says the payment's audit history names them. No API change was needed.
     - **The header**: the number (dated when it is not today's), business day, type and table, waiter, where it was taken, when it was created, its
       status, total, paid and the balance as the API wrote them, with the balance's state (`balanceView`): still owed, paid in full, holding more than it
       costs (a part-paid order voided as a whole keeps its payment until that is voided too: said so, and the page offers that void), or nothing to pay. The two reads are two requests,
       so the page checks that the payments that are not voided add up to the order's paid and that the two balances agree, and asks for a Refresh when
       they do not. Below it the lines with their station, status and total.
     - **The payments**: channel, amount, the reference's last four characters only (`referenceTail`, moved from `ReceiptPanel` to `lib/paySheet.ts`),
       status and chips (4.5c's `PaymentStatusCell`), who took it and when, the cash handed over with the change, the dispute and the void with their
       reasons, the verification, the amount paid back when there is one, the photo; then "Open payment {id}" (`/admin/payments?payment={id}`, 4.5c's
       dialog), "Audit history" (each named "Audit history of payment {id}": `HistoryLink` gained `label`, so every link's name is unique in the page;
       the order's own stays "Audit history") and "Void payment {id}".
     - **The photo** (`photoOf`): a signed address, a file no longer stored (the read's URL is null: said in words, no viewer) or none. 4.5c's
       `PhotoThumb` and `PhotoViewer` as they are: the viewer always asks `GET /files/{file}/url` when it opens, and a picture that fails to load asks
       once more. `urlsStale`: a viewer opened 4 min 45 s or more after the payments were read (an address lives 300 s) also reads the payments again,
       so the thumbnails get fresh addresses as well.
     - **The existing writes only.** The void of the whole order (while it is sent and not paid in full, the board's rule) and of one payment (while the
       order is not paid or closed, the payment neither voided nor paid back; also the payment a part-paid order kept when it was voided whole, which
       4.3c allows and the board never reached), both through 4.5b's `VoidDialog`. It gained `payment` (it opens on the payment chosen; the others stay
       offered), a voided order counts as not closed there (the till and the board never open it on one), and the key rule: after a refusal (a wrong
       PIN, a void the order no longer allows) the next try carries a new Idempotency-Key, and the key is kept only when the outcome is unknown
       (`keepsKey`; before, every failure took a new key). The board and the till get the same rule.
     - **The receipt** once the order is paid in full (settled, paid or closed; never a cancelled or voided one, as `ReceiptBuilder` decides): 4.5b's
       two actions moved out of `ReceiptPanel` into `ReceiptActions`, each shown to the holders of its permission (the PDF: `payments.view`; the link:
       `payments.take`); the till shows both, as before.
     - **The refund is deferred to 4.3d.** The plan puts "the refund action" on this page; refunds (4.1b, 4.3d) wait for Ramin's answer on R-C. Its place
       is `RefundPlaceholder` in `components/admin/OrderRecord.tsx` (a `TODO(p4): 4.3d`), shown for an order paid in full: refunds are not available yet,
       and before the order closes a wrong payment is voided instead. The refund's timeline kinds (`line.refunded`, `order.refunded`) are one line each in
       `ENTRY_KINDS` (its own TODO) with their two strings; nothing else in the merge changes.
     - **Links in**: the orders board's timeline panel ("Open the full record"), the payment dialog ("Open the order's record") and an audit entry whose
       subject is an order ("Open the order's record"). The analytics pages show no order number, so they link nowhere.
     - **Dates** through `lib/dates.ts` (`addisStamp`, `dayText` with the person's format), so the Ethiopian preference reaches the page.
     - **Strings**: 94 keys under `orderRecord`, five under `adminOrders` (the four payment kinds and "Open the full record"), one each under
       `adminPayments.detail` and `audit.entry`, English and Amharic.
     - **Tests**: `pay-timeline-logic` (10 pure cases: the merge and its ties, the voided payment, the decisions, the names with the `#id` fallback, the
       photo and the reference's tail, the header's money and the reads in step, the re-sign timing, the voids and the receipt offered, the address, every
       kind worded in both languages) and `pay-timeline` (two flows, @live): a run-unique order split into cash and the run's wallet with a photo in a
       colour of its own, opened from the board; the header's money, the timeline in the API's order naming the cashier, the payments with the
       reference's tail only, the photo from a fresh address loaded at 1200 px, the receipt downloaded, the refund's place, the order's audit history; and
       a second order's bank transfer voided on the page, a wrong PIN refused and the next try under a new key, the manager's PIN approving it, the void
       naming who asked and who approved, 70.00 owed again; the cashier with no link on the board and refused on the page; then the order voided whole
       there (the header shows the 50.00 in cash it holds beyond its cost) and that payment voided too, nothing owed or held. `pay-refund` and
       `dashboards-audit` stay stubs. No test turns a shift rule off.
     - **Local run notes**: against a second local API container (eight CLI-server workers, opcache without timestamp checks, a sync queue, port
       8001; not a repo change): `pay-timeline-logic` with the project's other pure specs 42 passed; the two flows of `pay-timeline` passed, the first
       in 2.2 min once the API was warm (its first, cold run reached its last check when the 240 s ran out: the first receipt PDF took 29 s there) and
       the void flow in 1.2 min. The whole project runs in CI.
     - STATUS: phase 4's `web` step stays `in_progress` (the refund UI waits for 4.3d).
   - **4.5e `phase-4/web-analytics`**: `/admin/analytics` (Overview with period compare, Sales by day/week/month and by hour, Channels, Products with the product page `/admin/analytics/product?id=`,
     Stations, Staff, Shifts), a date-range control (today, 7, 30, 90 days, custom), `as_of` badge, stale-day notice with a Rebuild button for `analytics.manage`, export buttons, skeletons (first paint
     of data is what `p4-dashboards` times), role-limited view for `analytics.view_own` (one page `/admin/analytics/me`). `lib/analytics.ts` (range maths in the branch's local dates, week = Monday).
   - **As built (step 4.5e, `phase-4/web-analytics`).** Order: the tests red (the pure rules failing on the missing `lib/analytics.ts`, the browser
     flows on the missing pages), then the rules, the chart geometry, the client's reads and the freshness feed with the ESLint rule of ADR 0004, then the
     screens and the strings; this note and the ADR 0009 wording. Where the code disagreed with the plan or the plan left a choice:
     - **Pages under one tab.** `/admin/analytics` (Overview), `/sales`, `/channels` (named "Payment methods" on screen, as 4.5c names them), `/products`,
       `/product?id=` (the record page, export-safe), `/stations`, `/staff`, `/shifts` and `/me`, under pills that carry the range. The admin console's
       Analytics tab is shown to holders of `analytics.view` or `analytics.view_own`; for a person without `analytics.view` it leads to `/me`, and every
       `analytics.view` page sends such a person there too (`AnalyticsGate`), so the console's card and any link land somewhere they may read. The
       overview has its card. `lib/deeplink.ts` knows the nine pages: the weekly summary's line (`/admin/analytics?from={week_start}&to={week_end}`,
       4.4d) opens the overview on that week.
     - **The range lives in the address** (`lib/analytics.ts`): `?range=today|7|30|90` (30, the API's default, leaves the address clean) or
       `?from=&to=` (dates win over a preset; a missing `to` is today and a missing `from` the 30 days up to `to`, as `RangeRequest` reads them). A
       malformed value is dropped. A range past today or longer than 400 days is kept as it was asked for, and the API's `analytics.range.*` is shown in
       the page's language; the custom form checks the same rules first (`rangeIssue`). Each page's own options are there too: `compare=previous`
       (overview), `group` (sales, channels), `kind`, `category_id`, `sort` (products), `role_kind` (staff), `id` (the product page).
     - **Reads.** `lib/api/analytics.ts`, one query key per answer shape (`analyticsKeys`: `overview`, `sales`, `sales-hourly`, `channels`, `items`,
       `item`, `stations`, `staff`, `shifts`, each with the range and the options). Each read sends its last ETag and keeps its last answer per signed-in
       token and address (at most 60), so a read asked again for nothing answers 304. A refusal (4xx) is not retried.
     - **Freshness** (`lib/live/analytics.ts`): `GET /analytics/freshness` for the days the page reads (with `compare`, the period before too, unless
       the span is over 400 days), every 30 s visible and every 2 minutes hidden, a 304 keeps the state. The dashboards are not polled: the feed keeps
       its answer before the last, and the days whose version or `rebuilt_at` moved (a day built for the first time or gone included: `changedDays`)
       invalidate the queries that read them (`queryTouches`; the product page always, it also reads its weeks since launch). The overview starts the
       feed (and its sparkline's sales read) only after its own answer, so the first figure waits for nothing but the overview query. The badge is
       the feed's `as_of` ("Updated 2 min ago"; "Not built yet" when none is).
     - **Not built is never zero** (R-U, 4.4c): the days not built are named as runs of days (`dayRuns`); a range with no built day shows "—" on the
       tiles; a series point with no built day reads "Not built", a line breaks there and a bar's column is shaded.
     - **Rebuild** (`analytics.manage` only; others read "An admin can rebuild them"): `rebuildPlan` sends the days not built newest first, each request
       at most 31 days back from its newest day, inline (`sync`) only for 7 days or fewer; a built day between two that are not is rebuilt with them
       (a rebuild of unchanged facts writes the same figures). The requests go one after another, each with its own Idempotency-Key, kept only when
       the outcome is unknown (4.5b's `keepsKey`). A 200 invalidates the rebuilt days at once; a 202 shows `running` from the feed and the button
       waits for it. The result line sits outside the notice, which goes with the next look of the feed.
     - **Overview**: eight tiles (cancelled orders too: the API answers `cancelled_count`); with `compare=previous` each says its change in percent
       (one decimal, half up, `divideDecimal`) and the difference, or that the period before is not built or had none. Revenue by order type and
       the payment methods' shares are horizontal bars; the five best sellers open their product pages. The revenue tile's sparkline (sales by day)
       is drawn for a range of two days or more.
     - **Payment methods**: the counts that open the payments list (4.5c's `filterParams`, for a holder of `payments.view`, when above zero) are only
       those with an exact filter there: verified (`status=verified`), disputed (`status=disputed`) and without a photo (`missing_photo=1`, never
       for cash, whose payments the list's filter leaves out). A missing reference has no filter and the list's `flagged` also holds disputes and
       duplicate photos, so those two counts are not links. Above the table, the money of every method together by day, week or month.
     - **Products and the product page**: categories from `GET /categories` (the kind's live ones); "Cost incomplete" (a badge and a note) wherever
       `cogs_complete` is false, with no cost, margin or margin percent shown as a fact; the product page's tiles, its units a day (a line), since launch
       (the first sale, the weekly units as bars and a table), its days, the new flag (Catalog's `is_new`), a 404 said in words.
     - **Stations, Staff, Shifts**: a 90th percentile only for a one-day range (the worst day's otherwise, as 4.4c answers); staff by kind of work
       (`role_kind` choices); shifts a day per row with the variances' totals and the cash variance as bars (a shortage below the zero line).
     - **My figures**: the same two reads, which the API limits for `analytics.view_own`; a holder of `analytics.view` sees only their own row and
       stations there too (`me.user.id`, `me.work_stations`). No export (the exports need `analytics.view`).
     - **Exports**: Excel, CSV and PDF of each page's report (sales, channels, items, stations, staff, shifts) over the range in the page's language,
       read with the bearer token (`authedBlob` with `accept`, 120 s) and named in the browser `analytics-{report}-{from}-{to}.{format}`.
     - **Charts** (no library, 10.8 R-H): `lib/charts.ts` lays bars (vertical, or horizontal for categories) and lines out from the decimal strings with
       `decimalRatio` (decimal.ts, scaled integers): the only numbers made are pixel positions. `components/charts/{Bars,Line,Sparkline}.tsx` are inline
       SVG with `role="img"` and an accessible name; their table (`ChartTable`) is folded under a bar or line chart and visually hidden under a
       sparkline; a chart drawn beside a full table of the same figures has no fold.
     - **The ESLint rule ADR 0004 named did not exist**: `eslint.config.mjs` now refuses `parseFloat` and `Number()` of a name that says money, a
       quantity or a share of them, in `src` and `e2e` (the whole web app passed it).
     - **Labels in one place** for 4.5f: `dayLabel`, `seriesLabel`, `rangeLabel`, `spanLabel` in `lib/analytics.ts` (Intl, `en-GB` and `am-ET`).
     - **Strings**: 236 keys under `analytics` plus `admin.analytics` and `admin.analytics_desc`, English and Amharic.
     - **Tests**: `dashboards-logic` (14 pure cases; the Ethiopian labels a `test.fixme` for 4.5f) and `dashboards-live`: the `@live` flow (a
       run-unique product made new for two weeks and sold for 250.00 on the run's bank transfer, today rebuilt inline; the overview's first figure
       timed and logged against the soft budget of 2500 ms; the payment methods page exact for the run's channel with its drill; the product page with
       its units, the new flag, its first week and "Cost incomplete"; the cashier's own page and tab) and a CI-only flow (seven past days nobody built,
       chosen per process, named as not built and rebuilt inline by the admin's button). `dashboards.spec.ts` and `dashboards-audit.spec.ts` stay
       4.6b's stubs. No test turns a shift rule off.
     - **Local run notes**: against a second local API container (eight CLI-server workers, opcache without timestamp checks, a sync queue, port 8001;
       not a repo change) the `p4-dashboards` project passed 16 with 3 skipped; the first figure took 9 to 20 s there (the local API answers in
       seconds), a measure only. The time includes `GET /me`, which every signed-in page waits for before it renders; 4.6b's budget may start the
       overview read with the stored token.
     - STATUS: phase 4's `web` step stays `in_progress`.
   - **4.5f `phase-4/web-audit-ethiopian`**: `/admin/audit` (filters, the detail drawer with the before/after diff, export for admin), and the optional Ethiopian date display (`lib/ethiopianDate.ts`, a pure
     converter with a unit table for the new year, Pagume 5 and 6 and the leap rule, a per-user display preference; the receipt prints it when `ethiopian_dates` is on). Ethiopian dates are labels only: nothing stored changes.
   - **As built (step 4.5f, `phase-4/web-audit-ethiopian`).** Order: the tests red (the pure rules failing on the missing `lib/ethiopianDate.ts`,
     `lib/dates.ts` and `lib/audit.ts`, the browser flows on the missing page), then the calendar (the converter, the formatter, the preference and its
     switch, the labels routed through them), then the audit screen, the "Audit history" links and the strings; this note. Where the code disagreed with the
     plan or the plan left a choice:
     - **The page.** `/admin/audit` for `audit.view` (admin, manager): a tab after Analytics and a card on the overview, both shown only to holders of
       `audit.view` (the card is a small client piece, `PermittedCard`; the other cards are unchanged). Anyone else reads "Your account cannot open this
       page" (`RequireAuth`) and the API answers 403. `lib/deeplink.ts` knows the page, so a link with `?id=` and the filters opens there.
     - **The filters live in the address** (`lib/audit.ts` `auditFiltersFromParams` and `auditFilterParams`, 4.5c's pattern): `from`, `to`, `actor_id`,
       `action`, `subject_type`, `subject_id`, `station_id`, `shift_id`; a malformed value is dropped, never sent (the API's limits: an action of at most 100
       characters, a subject type of at most 191). The person list is the admin console's (`GET /users`, people.manage, the People screen's key and shape
       `["users"]`) together with every actor and acting person the rows on screen name, so a manager, who has no people list, still picks from the page;
       the chosen id stays offered. The action is a list of the 35 prefixes the API writes (read from the actions in the code: the counts are `count.`, not
       the `stock_count.` the step named), each named in both languages, or typed (a prefix or a whole action). The subject is a list of the known
       subjects by name, or typed; the station comes from `GET /me`'s work stations (every station for an admin); the shift is a number.
     - **The morph strings.** No morph map is registered, so the subject of an `AuditedAction` over a model is its class
       (`App\Modules\Payments\Models\Payment`, `App\Modules\Orders\Models\Order`, `App\Modules\Shifts\Models\StockCount`) and an event's is a short name
       (`stock_movement`, `stored_file`, `order` for `receipt.link`, `none`). `SUBJECT_TYPES` lists them; `historyQuery(kind, id)` builds the filtered
       address that the order timeline of 4.5d will use too.
     - **Not polled.** An audit trail needs no live refresh: the first page is read when the filters change and again on Refresh (a line under the button
       says when it was read and that the log does not update by itself), older pages by cursor ("Older entries"). 4.5c's `foldHead`, `olderCursor` and
       `visibleRows` are now generic over any row with an id (the payments list reads them as before), so rows a Refresh pushes out of the first page stay
       above the older pages. Query keys, one per answer shape: `["audit-log", "page", query]` (a first page) and `["audit-log", "entry", id]` (one row with
       before, after and context). These routes answer without an ETag, so the reads are plain `authed` reads.
     - **The drawer** (`?id=`; a `Drawer` primitive in `ui.tsx`, a side panel dialog that takes the focus, closed by Escape or the backdrop): the time, the
       action, who (and the person acting at a shared pad), the subject (a link to its history), station, shift, device and IP; then "Before and after"
       (`diffOf`): every field of either side, the changed, added and removed ones first with a badge and a tint, the unchanged after them, the bookkeeping
       fields (`id`, `branch_id`, `created_at`, `updated_at`) last and never highlighted; a field missing on one side counts as null there, as the API's
       summary counts it; two objects are diffed inside a fold that opens when something inside changed; a list is compared and shown whole on one line.
       `"[redacted]"` shows as a "Redacted" marker wherever it stands (a whole value, a piece of a text, an item of a list), never as data. A row with nothing
       before (an event, a create) reads as "Recorded values". The context reads as one column in the order it was written. A field that names a person
       (`*_user_id`, `user_id`, `actor_id`) is named from the people list or the row's own names: the void's approver reads as the manager. "Show the raw
       JSON" shows before, after and context as the API sent them (redacted too).
     - **The export** (`audit.export`, the admin only; the manager reads the log without it): Export CSV and Export Excel of the filters in the page's
       language, through `authedBlob` with `accept` (120 s) and `saveBlob`, named in the browser `audit-log-{from|all}-{to|today}.{format}` as the API names
       it. `422 audit.export.too_large` shows the API's message and, from `{max_rows, rows}`, how many entries the filters find and how many one file holds,
       in both languages.
     - **"Audit history" links** (for `audit.view`) on the payment dialog, the orders board's timeline and the count review page. They are named "Audit
       history", not "History": the Shifts pills already have a "History" that the p3 flow clicks by name.
     - **The Ethiopian converter** (`lib/ethiopianDate.ts`): the twin of `Shared\Time\EthiopianDate`, the same Julian day number arithmetic in integers,
       both ways (`fromEthiopian` refuses a day the calendar does not have, such as Pagume 6 of a common year). Its tests are the PHP unit table ported case
       for case, the days around 1 January, the leap year 2019 (Pagume 6 on 11 September 2027, the new year on the 12th), the month names checked against
       the PHP lists, and every day from 1900 to 2100 there and back.
     - **One formatter** (`lib/dates.ts`) for every date on screen: `dayText`, `monthText`, `rangeText`, `spanText`, `isoDayText` (the shift pages'
       dates, ISO as before in Gregorian), `stampText` (what `addisStamp` returns) and `whenText` (the bell, the production board and planner, the recipe
       history, the till's receipt preview). The Ethiopian labels read as the API's `EthiopianDate::format` ("Tikimt 17, 2019", "ጥቅምት 17 ቀን 2019 ዓ.ም.")
       with the month names of `messages/*.json` (`dates.ethiopian_months`); "both" writes "Gregorian (Ethiopian)"; the 24-hour clock of Addis Ababa stays
       in every calendar (the Ethiopian hours counted from dawn are not used). A series month is a Gregorian month (the API buckets by `YYYY-MM`), so in the
       Ethiopian calendar it reads as the days it covers ("Meskerem 21 – Tikimt 21, 2019"). 4.5e's `dayLabel`, `seriesLabel`, `rangeLabel` and
       `spanLabel`, `addisStamp` and `otherDayLabel` take the person's format (`useDateFormat()`); a bare locale still reads Gregorian, so 4.5e's
       assertions stand unchanged.
     - **The preference.** `GET /me` has no preference field, so the choice (Gregorian by default, Ethiopian, or both) is kept in the browser per signed-in
       user id (`bakery-ops.date-display.{id}`), every read and write in try/catch: blocked storage reads Gregorian, and a choice that cannot be kept lasts
       for the page. The switch is a select beside the language switch, shown when signed in. No migration and no API change; a server-side preference
       (a column on `users` set through the API) would follow the person to another device, and is left for a later step to decide.
     - **Where it reaches**: the dashboards, the payments list, dialog and methods, the freshness badge, the audit log, the shift pages (running shifts,
       the review queue and page, the count, notes, history, replay and the shift bar), the order labels, the bell, the production board and planner and
       the recipe history. The date pickers, the range controls and every address stay Gregorian ISO dates underneath. The roster's week grid stays
       Gregorian (it plans by Gregorian weeks). The till's receipt preview follows the receipt settings (the Gregorian date, and the Ethiopian one too when
       `ethiopian_dates` is on), as the printed receipt does, not the person's choice.
     - **The receipt.** 4.5c's Receipts form already showed and saved `ethiopian_dates`; it now says what today prints beside it ("Today it prints Tikimt
       17, 2019.", the page's language). The PDF is unchanged.
     - **Strings**: 168 keys under `audit` and 18 under `dates`, plus `admin.audit`, `admin.audit_desc` and `adminPayments.receipts.ethiopian_today`,
       English and Amharic.
     - **Tests**: `dashboards-logic` (five new cases: the converter as the twin, the leap rule with the round trip, the dashboards' labels, the timestamps
       and shift dates, the preference with blocked storage; the 4.5e `fixme` is gone), `dashboards-audit-logic` (nine pure cases: the filters and the
       address, the action prefixes, the morph strings, the diff with the redacted marker and the folding, the people offered, the export's name and
       refusal, the older pages), `dashboards-audit-live` (two flows @live: a payment voided with the manager's PIN found from the payment's "Audit history"
       link, narrowed by the action prefix, its before and after with the reference redacted (nowhere on the page or in the raw JSON) and the approver
       named, the address reopening it, the CSV export of the filter holding the void and not the reference, the Ethiopian labels in English and Amharic
       and both; the manager reading without the export and the cashier with no tab, refused on the page and 403 from the API; and one CI-only flow: the
       receipt switch saved), `pay-admin-logic` (the switch through the form). `dashboards-audit.spec.ts` stays the stub of 4.3d and 4.6b. No test turns a
       shift rule off.
     - **Local run notes**: against a second local API container (eight CLI-server workers, opcache without timestamp checks, a sync
       queue, port 8001; not a repo change) the three flows of `dashboards-audit-live` passed (the voided payment's flow in 40 s), and the pure specs of
       `p4-dashboards` and `pay-admin-logic` 60 passed; the whole projects ran in CI.
     - STATUS: phase 4's `web` step stays `in_progress` (4.5d, the order timeline, remains).
6. **4.6 docs and seeds** (STATUS `docs`), two PRs.
   - **4.6a `phase-4/seed-history`**: the real `Phase4Seeder` and `DemoClock` generalisation (section 8). It lands before 4.6b because the gate tests and `perf-dashboards` run on its data;
     it may be started as soon as 4.1 is done if the order needs it (`Phase3Seeder` and `DemoClock` exist since Phase 3 closed).
   - **As built (step 4.6a, `phase-4/seed-history`).** Order: the tests (red: `DemoClockTest` on the missing `daysBackFor`, `Phase4SeederTest` on the missing plan and
     message), then the clock and the extracted day loop with Phase 3 unchanged, then the Phase 4 seeder, then the CI wiring, the runbook and this note. Where the code
     disagreed with the plan or the plan left a choice:
     - **The clock.** `DemoClock::daysBackFor(int $phase)` is 3 below Phase 4 and `DayPlan::historyDays() + 2` from Phase 4 (`bakery.demo.history_days`, env
       `DEMO_HISTORY_DAYS`, default `DayPlan::HISTORY_DAYS` = 90, at least 1); `around()` anchors 05:30 local that many days back and remembers the phase
       (`anchoredPhase()`, `playsPhaseFourHistory()`). `wantsAnchor(4)` also needs no order (Phase 3's rule, movements and shifts, is unchanged). `DemoClockTest` proves
       Phase 3 still anchors three days back whatever `DEMO_HISTORY_DAYS` says. The loop plays **every day after the anchor to yesterday**, so with the default it plays
       91 days: the opening day D-91 (the first large purchase, transfers and production) and the 90 days D-90 to D-1 that the gate reads (section 8 said "90 days";
       corrected).
     - **The day loop.** `Demo\DemoDay` is Phase3Seeder's per-day play: 05:55 the Night shift of the evening before closes, 06:02 every station's Day shift starts and
       counts its opening, then the day's steps in time order (a stable sort), 14:05 the closings ("nothing changed" except the planned differences, co-signed and decided
       by the manager, a closing the plan leaves pending stays pending), 22:05 a planned Night shift starts; `closeAnchor()` locks the anchor day's carried shifts at 21:30;
       `today()` is Phase 3's today part. `Demo\DayPlan::phaseThree()` holds step 3.8's two days as data (`Demo\PlannedDay`), and Phase 3 writes the same rows as before:
       `Phase3SeederTest` and `ReplaySeededHistoryTest` are unchanged and green. With `SEED_PHASE` 4 and above on a fresh branch, `Phase3Seeder` writes only its templates
       and reasons and leaves the clock anchored; `Phase4Seeder` plays the history and then does today's part.
     - **The plan** (`DayPlan::phaseFour`, `mt_srand(4040)`, drawn once before anything is played; constants a test states): `WEEKDAY_ORDERS` 22 and `WEEKEND_ORDERS` 34
       (Saturday and Sunday) between 06:45 and 12:50; lines from a weighted menu (a drink 80 %, kitchen food 45 %, the display case 55 %); `CHANNEL_MIX` cash 45, Telebirr 30,
       CBE Birr 10, bank 15 spread over `BANK_CHANNELS` (CBE bank 7, Dashen 3, Awash 2, Abyssinia 2, Coop 1: the plan's "bank transfers" named); `SPLIT_PCT` 12 (the digital
       part 30 to 70 % of the bill in tens of birr, the rest in cash, one `payment-batch`); `PAYMENT_VOID_PER_MILLE` 15 (a digital part payment of half the bill, voided two
       minutes later by the cashier with the manager's approval, the bill then paid in cash); `ORDER_VOID_PCT` 1 (voided two minutes after it is sent, before anything is
       made); `VERIFIED_PCT` 40 and `DISPUTED_PCT` 3 of the digital payments (verified at 13:50, disputed at 13:52, one dispute in three verified at 06:44 the next morning;
       the last day's stay disputed); a voided payment's order gets no check. `REFUND_PCT` is 0 with `TODO(p4): 4.3d`; its roll is drawn on every order already, so turning
       it on changes no other draw, and `DemoTill` has the marked place where the refund becomes one more step after the payment. Every kind happens at least once
       however short the window (a second-day order is given it when the draw left it out).
     - **Stock** (`Demo\DemoStock`, from 06:05): what a day needs is its planned orders, a to-order product exploded through `Recipes::scaled` the way the ledger reserves
       it; what is there is `Stock::of(...)->available()`. A product made in the house below its day's need x 1.2 plus a margin is made up to the day's and the next day's
       need plus the margin (the manager plans it at 06:12, the baker accepts and starts it, finishes at 06:35, the manager confirms at 06:38); an ingredient or a bought-in
       drink below the same threshold is brought to (today + tomorrow) x 1.3 plus the margin in whole units of its own unit, asked by the station's worker and confirmed by
       the manager at the store; the store buys on Mondays and on the opening day for eight days of need x 1.3 (and on any day an item the transfers would empty). The
       margin is one unit of the item (two for a product) or twice a Phase 2 reorder rule's minimum. Two suppliers are added (Merkato Fresh Market, Abay Beverages; made-up
       numbers) besides Phase 2's three. Section 8's "daily production batches and weekly purchases" hold in effect; a product is made on the days it is short.
     - **Stock-outs**: `STOCK_OUTS` croissant, black forest slice and sweet bread at 25 %, 55 % and 82 % of the window. That morning the product is not made, the first order
       at or after 10:30 takes every unit left (read from the ledger), the reservation opens the stock-out and `CreateAutoRequest` its request (synchronous), and the baker
       makes it at 11:00 to 11:45; no other line of the product is sold before 11:50. No other place runs out (the test counts the `stock_out` requests).
     - **Differences**: `DIFFERENCES` at 12, 30, 50, 68 and 88 % of the window: the kitchen's 500 g of tomatoes (spoilage), the till 20.00 short (miscount), the bar's 250 ml of
       milk (spoilage), the till 10.00 over (miscount), the cake room's 200 ml of cream (spoilage), each approved by the manager. Every other count equals the ledger.
     - **Shifts**: one Day shift per tracked station per day, all locked; a Night shift at the bakery every Friday, planned at 08:30 with the bread baker, 22:05 to 05:55,
       dated Friday; **not when Friday is yesterday** (its night would end today). No test turns a shift rule off; every payment carries the till's shift.
     - **Payments** (`Demo\DemoTill`): each order over its own minutes (taken and sent at the pad, accepted 1 to 3 minutes later, the bar ready 2 to 5 and the kitchen 6 to 14
       minutes after that, served 1 to 3 minutes after the last ready line, paid 4 to 20 minutes later), so prep times and hours are real on the dashboards. Every digital
       payment's photo is drawn with GD (`Demo\DemoReceiptPhotos`: five designs cycled, each stamped with the channel, amount, reference and time, so no two have the same
       sha256 and no duplicate-receipt warning fires), stored through `Files::put` (purpose `receipt`, on the `files` disk) by the cashier at the till the same minute as its
       payment (the 12 h rule), with the `file.upload` audit row `POST /files` writes. References are `SEED-{yymmdd}-{n}`, n the order's number in the day. Client UUIDs are
       UUIDv5 of `demo-order:{date}:{n}`, `demo-pay:{date}:{n}[:digital|:cash|:void]`, `demo-batch:{date}:{n}`, `demo-photo:...`, `transfer:...`, `purchase:...`,
       `production:...` (`Demo\DemoIds`). A refusal anywhere in the history (a send the restock left short) stops the seed: nothing is skipped silently.
     - **Today**: Phase 3's today part, then, a second after today's opening counts (a count's snapshot has microseconds, a movement's `created_at` whole seconds, so a
       movement in the same second would read as before the snapshot: `DemoClock::nextSecond()`), a restock for today's samples in real time (`DemoStock` over the samples'
       plan, so nothing runs out under the live screens), then four sample orders (one on the rails, one being made, one served at the till, one paid split Telebirr plus
       cash), keyed by today's date; a send the stock cannot cover leaves the cart open, as the pad would.
     - **The queue.** From Phase 4 the whole anchored run is on the `sync` queue (`DemoClock::around`, put back afterwards), Phases 0 and 1 included: they do not set it
       themselves, and on a Redis queue (the dev and CI stacks) their `EvaluateBalance` jobs waited for a worker while holding their unique locks for ten minutes, which
       swallowed the history's own evaluations of the same balances, its stock-outs among them (found running the seed on the dev stack; the tests run `sync` and the
       `array` cache, whose locks expire in the seeded clock). `Phase4SeederTest` asserts the `jobs` table is empty after the seed.
     - **Held jobs** (`Demo\DemoAlerts`). Every queued listener runs (`queue.default=sync`), but two jobs are held on the bus while the history is played (`Bus::swap` with
       a `BusFake` that fakes those two only; every other job runs): the `RebuildDay` jobs the touch and shift-lock listeners ask for (a rebuild per payment over a growing
       ledger would cost minutes), and the `EvaluateBalance` jobs Inventory asks for on every movement. The held evaluations are run on the real bus, once per balance,
       after each day's closings, after the morning's batches and right after a planned stock-out order (so its automatic request is there that morning): the stock-outs,
       low-stock alerts, recoveries and their inbox lines happen as on the screens, at that granularity. Then `Rebuild::day` runs once for every day from the anchor to today,
       trigger `seed`. Side effect: a held job's unique lock is still taken when it is asked for; an evaluation lets its balance's lock go when it runs, and `RebuildDay`'s
       lock (120 s) means a payment in the demo branch in the two minutes after the seed asks for no rebuild (the nightly run heals it).
     - **Receipt settings**: `SaveReceiptSettings` is taken out of `ReceiptSettingsController` (same AuditedAction, same answer) and the seed saves the demo's settings through
       it when the branch has none (fresh and data paths).
     - **A branch with data** (the live demo): "history not generated: the demo branch already has data; load a new demo branch", nothing added to the ledger, the eight
       channels and the receipt settings made sure of, and `Rebuild::day` (trigger `seed`) for **every day from its first movement to today that has no `analytics_days`
       row yet** (section 8 said every day with data; a built day is left to its triggers and the nightly run, so a second run writes nothing), and a tracked station with no
       open shift named. The seeder never touches the E2E Test Branch (not a demo branch: refused by Phase 0, tested).
     - **Timing.** Measured in `e2e-p4` on `49d0130` (run 40571301269): Phases 0 to 2 in 15 s, the 91 days played in 938 s and rebuilt in 12 s, the whole seed step
       967 s (16 min, **over the 10-minute budget**); the job took 18 m 37 s (2 m 14 s before). That is about 0.4 s an order through the real actions (some 300 statements
       each, nearly all the modules' own), against the plan's 80 to 150 ms. Two answers: the held evaluations above and the helpers keeping what they read (stations,
       products, tables, people, unit factors, place types), about 15 % faster on the dev stack (8 days in 83 s instead of 96 to 104 s); and **the fallback**: `e2e-p4` seeds
       `DEMO_HISTORY_DAYS=60` (the reusable `e2e.yml` takes `history_days` and passes it only when set), the 90-day figure kept for `perf-dashboards` (4.6b). The 60-day
       seed's measured line is in the PR.
     - **CI.** `e2e-p4` seeds `SEED_PHASE=4` with `history_days: "60"` (the fallback above); `e2e-p0` to `e2e-p3` are unchanged. `Phase4SeederTest` (group `seeds:p4`,
       7 days, about 2.5 minutes here) runs in `mod-analytics` (the seed rebuilds through Analytics); `phpunit.xml` sets `DEMO_HISTORY_DAYS=1`, so the tests that seed every
       phase (`SeedDemoCommandTest`, `DemoSeederTest`'s `db:seed`) play two short days. The deploy pipeline (`release.sh`, `deploy.yml`) calls no seeder.
     - **For 4.6b.** The 90-day data exists only after `SEED_PHASE=4 php artisan migrate:fresh --force --seed --seeder=DemoSeeder` on the compose stack (e2e.yml's seed
       step); the "Demo Bakery" branch then holds the anchor day, the 91 played days and today, every one in `analytics_days` with trigger `seed`, version 1, and an empty
       `jobs` table; the E2E Test Branch is created after it and never touched. `gate-p4`'s seeded tests (`ChannelTotalsEqualPaymentsTest`, `RevenueEqualsOrdersTest`,
       `RebuildIdempotentGateTest`, `DashboardsReadSummariesOnlyGateTest`, `DashboardBudgetGateTest`) should run in a job that seeds once that way and then runs only their
       group in the app container, against that database and without `RefreshDatabase` or `DatabaseTruncation` (either would wipe the seed; `phpunit.xml`'s
       `DB_DATABASE=bakery_test` is not forced, so the container's `DB_DATABASE` wins, which is also why no other group may run in that process; *corrected in 4.6b:* phpunit.xml pins it with a `<server>` entry, which no environment variable overrides, so the seeded tests point their connection at the seeded database themselves, see 4.6b's note); they find the branch through
       `Phase0Seeder::existingBranch()` and should fail, not skip, when it has fewer than 90 built days. `dashboards.spec.ts` (`p4-dashboards`, in `e2e-p4` after the seed) sees the
       60-day fallback; `perf-dashboards` keeps the 90-day figure, so it seeds in a job of its own without `history_days` (the e2e job with `history_days` empty; about
       13 to 16 minutes, so give that job a timeout above e2e.yml's 30 minutes only if the Playwright part is long). Both can sign in as the demo admin
       (`DEMO_ADMIN_PHONE`/`DEMO_ADMIN_PASSWORD` in e2e.yml) and read the range they seeded; warm the API once before timing
       (the first request after the seed is cold), and start the timer after `GET /me` (4.5e's note). The seed's own line (`Phase 4: N days of history played ... in X s`) is in
       the e2e log of the seed step. Counts at 90 days (on `49d0130`): 2 325 orders, 2 612 payments (37 voided), 1 382 receipt photos and 26 971
       audit rows in the demo branch, under the 50 000 rows the audit export takes; at 60 days about two thirds of that.
     - **For 4.3d.** `DayPlan::REFUND_PCT` goes to 2 (and its `TODO(p4)` goes); the roll is already drawn on every order (`$refundRoll <= DayPlan::refundPct()`, never a voided
       order), so no other draw moves. `DemoTill::orderSteps` has the marked place, which throws today: the refund becomes one step after the payment (before 13:50: the latest
       payment is about 13:35), asked by the cashier and approved by the manager through the refund use case, display-case lines restocked (a `return` at the display case,
       inside the till's shift), station lines `waste`, a handful by line and a few lines `none`. The closings need nothing: `DemoShiftFlow` declares the drawer's expected
       cash, which `Totals::cash` will give net of cash refunds; the next morning's restock reads `available`, so restocked units are sold again. `Phase4SeederTest` already
       asserts `Σ payments − Σ payment_refunds = Σ order totals − Σ refunded_total` (a missing `orders.refunded_total` counts as zero until 4.1b adds it); add the planned refund
       count, `daily_channel_totals.refund_amount` and `daily_sales.refund_total` against the raw rows, and that every `return` points at its original.
     - **Also in this PR** (asked for separately, its own commit): the e2e API client (`apps/web/e2e/support/api.ts`) retries a request whose connection the busy live host
       drops (ECONNRESET and the like, or a body read that fails after the headers), three attempts, about 1 s then 3 s apart, with one Idempotency-Key per logical request;
       an HTTP answer is never retried. A pure check is in `pay-admin-logic.spec.ts`.
     - STATUS: phase 4's `docs` step is `in_progress`.
   - **4.6b `phase-4/gate`**: the `gate:p4` tests (section 7), the `gate-p4` and `perf-dashboards` jobs wired, ADR 0014/0015/0016 accepted and the dated notes on ADR 0006/0009/0013,
     `docs/openapi.json` and `docs/erd.dbml` final, `docs/runbook/server.md` (Files, Receipts, Analytics sections: cron timing, rebuild by hand, the quota, what to do when `analytics_days` says
     `stale`), `domain-model.md`, `dependency-graph.md`, `invariants.md`, `traps.md`, `testing-strategy.md`, `ci-cd.md`, `workflows-and-screens.md` and the phase reference corrected (10.9),
     the Playwright `fixme`s of 4.1a turned into tests, `phase-4-report.md`, STATUS `claim-done`.
   - **As built (step 4.6b, `phase-4/gate`).** Order: the `mod-inventory` timeout (its own commit), the tests (red: `AnalyticsBenchCommandTest` on the
     missing command, the seeded gate tests on the missing seed), `analytics:bench`, the CI jobs, `dashboards.spec.ts`, the ADRs, the runbook, the skill's
     references, the report and this note. **Phase 4 is not claimed done**: refunds (4.1b, 4.3d, their screens, the seed's refund roll, the refund
     Playwright flows) wait for R-C. STATUS keeps phase 4 `in_progress` with no `done_commit`; `shared.files` and `analytics` stay `done`, `contracts`,
     `payments`, `web` and `docs` stay `in_progress` (each still holds a refund part); the report is `docs/phases/phase-4-report.md`, the gate run is its
     evidence (not `gate_run_url`). Where the code disagreed with the plan or the plan left a choice:
     - **The seeded tests and the test database.** 4.6a's note said phpunit.xml's `DB_DATABASE=bakery_test` was not forced, so the container's database
       would win. It is pinned by a `<server>` entry, which PHPUnit writes into `$_SERVER` unconditionally and no environment variable overrides (traps.md).
       So the seeded tests open the seed themselves: `Tests\Support\SeededGate::open()` reads `GATE_SEED_DATABASE` and `GATE_SEED_DAYS`, points the default
       connection at that database (`config` and `DB::purge`), finds the demo branch through `Phase0Seeder::existingBranch()` and fails, never skips, when a
       variable is missing, the branch is missing, a day of the window (the `GATE_SEED_DAYS` local days before today) has no `analytics_days` row, or one sold
       nothing. No `RefreshDatabase` or `DatabaseTruncation`; they write only what a rebuild writes and the export audit rows. The other tests of the same
       process (the race tests, `DatabaseTruncation`) keep `bakery_test`, so the whole `gate:p4` group runs in one Pest process and nothing beside the
       seeded tests can wipe the seed.
     - **The `gate:p4` group**: `ChannelTotalsEqualPaymentsTest`, `RevenueEqualsOrdersTest`, `RebuildIdempotentGateTest`, `DashboardsReadSummariesOnlyGateTest`,
       `DashboardBudgetGateTest` (new, `tests/Gate`, on the seed), `SplitPaymentRaceTest` and `PaymentVoidRaceTest` (their groups gained `gate:p4`), and
       `PaymentStatusTest` (the payment machine's exhaustive table) and `FilesNoPublicAccessTest`, which stand for section 7's `Phase4StateMachinesTest` and
       `FilesNoPublicAccessGateTest`. Not built: `Phase4ActorGateTest` (the actor stamp of every new write is held by `ActorStampTest` and `AuditCoverageTest`
       in the `invariants` group, which `gate_check` runs as `arch,invariants`), `Phase4GateTest` (`gate_check` runs the group by name, and the `gate-p4` job
       fails on an empty group or any skipped test) and `RefundReversalGateTest` (with 4.1b).
     - **What each asserts.** Channel totals and revenue: every built day of the demo branch (the anchor, the opening day, the window, today), amounts compared
       with brick/math (`isEqualTo`), counts, the refund columns against `payment_refunds` (by `refunded_at`; `daily_sales.refund_count` is one per order
       refund, as `SalesBuilder` counts it, `daily_channel_totals.refund_count` one per row), the net figures, and the channels adding up to the day's takings.
       Rebuild: three days chosen by what they hold (the busiest, the day of the first voided payment, the last Friday of the window, whose Night shift runs
       into Saturday; the newest days fill in if two coincide), rebuilt twice with trigger `manual`: both write the seed's own rows (`AnalyticsDay::rows`) and
       fingerprint, `changed` false, the version up by one each time. Summaries only: the routes are read from the router (11 GET routes under
       `/analytics/`), each read over the window with `compare=previous` as the demo admin (the chef for `/stations`, the cashier for `/staff` too), each then
       answering 304 to its ETag, each export once (xlsx, CSV and PDF in turn); the rules moved from the module test into `Tests\Support\DashboardQueries`, so
       the two tests cannot drift. Budget: the reads of `App\Console\Support\DashboardBench` (the bench's own class) as the admin, plus the chef's stations and
       the cashier's staff, 3 warm-ups and 30 timed requests, each a 200 with a body, at most 8 statements of its own, p95 under 400 ms; then
       `analytics:bench --requests=3` on the seeded branch, exit 0, not one write statement, no audit row.
     - **`php artisan analytics:bench {--branch=} {--requests=30}`** (`App\Console\Commands\AnalyticsBenchCommand`) lives outside the modules, as
       `bakery:seed-demo` does: it signs in as a person of Identity (the branch's first active holder of `analytics.view`, `Recipients::byPermission`) and reads
       Analytics' routes through the HTTP kernel. The person is set on the sanctum guard for the process (no token: no `personal_access_tokens` row, no
       `last_used_at`; `Sanctum::actingAs` needs Mockery, which the release does not ship). It reads the 90 local days up to yesterday compared with the 90
       before and the product page of the product with the most revenue in them (left out when none sold), prints a table and the budget line, exits 1 over
       the budget or on a non-200, 2 for a bad call (`--branch` missing or not an id, an unknown branch, `--requests` outside 1 to 999, nobody who may read).
       The exports are not read (each writes its audit row). `AnalyticsBenchCommandTest` (`module:analytics`) proves it writes nothing (no INSERT, UPDATE or
       DELETE; on the database cache store only the permission cache, which any request fills) and the exit codes. The runbook says how to run it on the live
       host; nobody runs it there from CI.
     - **`gate-p4`** (`.github/workflows/ci.yml`): `needs` as the CI design (gate-p3, mod-analytics, e2e-p4, perf-dashboards) plus `files-s3` (4.2b); loads the
       API image, starts `app` (with the database and Redis), seeds `SEED_PHASE=4` with `DEMO_HISTORY_DAYS=$GATE_SEED_DAYS` (90: the gate's own figure, 10.3,
       not the 60-day fallback of `e2e-p4`), puts an empty `.env` in the container (see Measured), runs `vendor/bin/pest --group=gate:p4` there with
       `GATE_SEED_DATABASE=bakery`, fails on an empty group or a skipped or warning test, copies the gate's figure lines to the step summary, then `gate_check.py --mode done --phase 4 --ci`. Timeout 45 minutes.
     - **`perf-dashboards`**: after `mod-analytics` and `docker-build`, beside `e2e-p4` (the CI design's graph; it had needed `e2e-p4`), timeout 40 minutes:
       the 90-day seed (`DEMO_HISTORY_DAYS` unset), the demo admin signed in by `curl`, the range (the 90 days to yesterday in Addis Ababa) and the product
       (the most revenue, from `daily_item_metrics`), every read warmed once, then k6 (`grafana/k6:1.3.0` pinned by the digest of its image index,
       `scripts/perf/dashboards.k6.js`): two virtual users, 15 iterations each over the eleven reads with a second between pages, thresholds `p(95)<1000` per
       read and in all, no failed request, every check true; the per-read p50 and p95 go to the step summary and the summary JSON is an artifact.
     - **`dashboards.spec.ts`** (the last non-refund `fixme` of 4.1a) runs in **`e2e-p4`, on its 60-day seed**: the overview reads the last 30 days by default,
       which 60 days hold in full, so the 90-day seed would time the same page, and `perf-dashboards` stays a job without a browser. CI only (skipped on the
       live host, whose figure `dashboards-live.spec.ts` logs against the soft 2500 ms). The demo admin's token is put in the page's storage, the API warmed
       (`GET /me` and the overview's own read, which must be built and have orders), and the time runs from the page's `GET /me` answering (its resource
       timing `responseEnd`) to the revenue tile showing a figure (`performance.now()` in a requestAnimationFrame poll): both instants in the browser, so
       Playwright's round trips are not counted. Budget 1000 ms. `pay-refund.spec.ts` and `dashboards-audit.spec.ts` stay the refund flows' stubs.
     - **`mod-inventory`** got room, not a split: `module-tests.yml` takes `timeout_minutes` (default 20) and `mod-inventory` passes 30 (it took 18 m 34 s on
       PR #79 and 19 m 18 s on `main` at `76c25a0`). The skill's CI copy (`assets/ci/`) changed with every job above.
     - **ADRs.** 0015 and 0016 did not exist (4.2a and 4.1b were to write them): 0015 is written and accepted as built; 0016 is written as **proposed**, its
       refund part the proposal R-C decides, the void of 4.3c in a dated note. 0014 is accepted with the gate's evidence. Dated notes: 0006 (the Files row,
       this step); 0009 (4.5c and 4.5e, already there); 0013 (4.3b, already there). The manifest's `phases.4.adrs` lists the three, so `claim-done` records
       them.
     - **Docs.** `docs/openapi.json` and `docs/erd.dbml` need nothing (no route, no table). The runbook gained `analytics:bench`, a day that says not built
       (`stale_days`) and the line that the deploy never runs a seeder; the Files section's delete rule follows `payments.verify` (4.3c). The skill's
       references and the Phase 4 reference were corrected (10.9: the weekly summary goes to Telegram and the inbox, the audit read API, the three ADRs,
       the gate's wiring); the manifest names `rules-off-gates` (it still said `enforced-gates`). The plans' TODO wording that was not about refunds was
       reworded, so the markers left are the refund placeholders only.
     - **Measured** (CI run 40604303076 at `3e7114c`, every job green, 88 minutes). The 90-day seed: 672 s in `gate-p4` and 683 s in
       `perf-dashboards` (91 days played, 93 rebuilt in 12 s; 2 325 orders, 2 612 payments of which 37 voided, 1 383 receipt photos, 26 940 audit
       rows), the seed step 11 m 46 s; the 60-day seed of `e2e-p4`: 441 s. `gate:p4`: 15 tests in 46 s (channel totals: 93 days and 490
       day-channel rows; revenue: 2 298 settled orders; rebuilt twice: 2026-08-02, 2026-07-30 and 2026-10-23; the summaries-only test: 30
       requests, 140 statements, none outside the rules). In-process p95 on the seed: 4.4 ms (freshness) to 27.7 ms (items), 1 to 7 statements
       of a read's own. k6: 330 requests, p95 per read 30.7 to 68.7 ms, 56.5 ms over all. The browser: first data 278 ms after `GET /me`
       (`dashboards.spec.ts`); the live twin's figure in the same CI job 557 ms from navigation. `mod-inventory` 14 m 15 s in that run.
       **Found there:** Pest reported the `gate:p4` tests that boot Laravel as *warnings*, not passes: the API image has no `.env` (compose gives
       the variables) and PHPUnit reports phpdotenv's suppressed read of the missing file on every such test (the `files-s3` job has shown the
       same since 4.2b). `gate-p4` now gives the container an empty `.env` before the group and fails on any warning, as on a skip.
     - **Local run notes**: the seeded gate tests and the race tests ran here against a 3-day seed in a scratch database of the dev stack (`gate:p4`: 9 passed
       in 312 s; the seed of 4 played days took 46 s); `AnalyticsBenchCommandTest` 11 passed. The 90-day figures are CI's.
     - STATUS: unchanged (see above).

As-built notes are appended under each step as the build runs, as in Phases 1 to 3.

## 7. Test list

- **Unit** (no database): `PaymentStatusTest` (the exhaustive `from x to` table with the new `disputed` exits), `ImageProcessorTest`, `ExifOrientationFallbackTest`, `ChannelPatternTest`,
  `RefundAllocationTest` (LIFO over splits, remainder cents, never more than a payment's open amount), `RefundDispositionDefaultsTest`, `LocalDayTest` (23:59/00:01 Addis, `utcBounds`, week start Monday),
  one test per builder (`SalesBuilderTest` ... `UserBuilderTest`) with array facts, `FingerprintTest` (order-independent, scale-sensitive), `EthiopianDateTest` (the API's, 4.3e; the web's twin runs its table in `dashboards-logic.spec.ts`, 4.5f), `paySheet` logic (web).
- **Feature** (one per endpoint, 403 per role from the matrix, idempotent replay, validation): every route of section 4; `RoutePermissionMatrixTest` and `IdempotencyCoverageTest` pick the new routes
  up from the registries; the multipart idempotency cases; `OpenApiTest` for the multipart route.
- **Property**: `RefundMoneyPropertyTest`, `RefundLedgerPropertyTest` (50 random order/pay/refund sequences, seeded, the seed printed on failure: `item_stock_view` equals movements, no negative on_hand
  or reserved, every `return` points at exactly one original), `RebuildOracleProperty` (random fixture days: the summary figures equal an independent in-test aggregation of the raw tables).
- **Concurrency** (`DatabaseTruncation`, forks): `SplitPaymentRaceTest` (two tills pay the same last balance), `RefundRaceTest`, `PaymentVoidRaceTest`, `RebuildRaceTest`, `FileUploadRaceTest`
  (the same `client_uuid` from two processes: one row, one object).
- **Contract**: section 2.
- **Invariants/arch**: `AppendOnlyTest` (+4 tables), `NumericColumnsTest`, `BranchIdTest`, `BilingualColumnsTest` (the `reference_hint_*`, `photo_hint_*`, `business_name_*` pairs), `AuditCoverageTest`
  (every new write is audited; `analytics.rebuild` is, the rebuild job itself is not, ADR 0014), `ModuleBoundariesTest` (Analytics allowlist, Audit leaf), `OctaneSafetyTest`, `SoftDeletesTest` (allowlist
  of the derived tables), `DeployableCachesTest` (new configs have no closures; `config:cache` with the new variables unset).
- **Live-host realities in tests**: queue tests keep `Queue::fake` plus a `sync` twin; the touch debounce is tested with the database cache lock; every poll test asserts `304` on an unchanged second request and
  a changed tag inside the 5-second settling window of `Payments::fingerprint()`.
- **Playwright** (`apps/web/e2e/p4/`, projects exist in `playwright.config.ts`):
  - `p4-pay-photo-split` (`testMatch /pay/`): `pay-photo-split.spec.ts` @live (the gate), `pay-sheet-logic.spec.ts` (pure), `pay-refund.spec.ts` @live, `pay-void.spec.ts` @live, `pay-camera-fallback.spec.ts`, and from 4.5c `pay-admin.spec.ts` @live (the admin payments screens) and `pay-admin-logic.spec.ts` (pure), and from 4.5d `pay-timeline.spec.ts` @live (the order record page) and `pay-timeline-logic.spec.ts` (pure).
  - `p4-dashboards` (`testMatch /dashboards/`): `dashboards.spec.ts` (CI, the 90-day seed: first KPI visible under 1000 ms), `dashboards-live.spec.ts` @live (exact figures on a run-unique channel,
    soft budget), `dashboards-audit.spec.ts` @live (the audit screen shows this run's refund with its approver), `dashboards-logic.spec.ts` (range maths). From 4.5e
    `dashboards-logic.spec.ts` holds the range maths and the charts' scaling (pure; the Ethiopian labels wait for 4.5f) and `dashboards-live.spec.ts` the
    run's channel and product after an inline rebuild of today with the soft budget logged, the person's own page, and a CI-only flow of the Rebuild button.
    From 4.5f `dashboards-logic.spec.ts` also holds the Ethiopian converter (the PHP unit table of `EthiopianDateTest`, ported, so the web's converter is
    its twin), the labels and the display preference; `dashboards-audit-logic.spec.ts` (pure) the audit screen's rules; and `dashboards-audit-live.spec.ts`
    @live the audit screen over a voided payment (its diff, the redacted reference, the approver, the export, the Ethiopian labels) and the 403 matrix of
    the screen, with a CI-only flow of the receipt's Ethiopian switch. `dashboards-audit.spec.ts` (the refund's entry) stays for 4.3d and 4.6b.
  - Earlier projects keep running; `p1-order-flow` and `p3-handover` prove the cash path and the shift rules are not broken.
  - **How the live and shared-branch specs stay deterministic** (the lessons of 3.7a to 3.7e): they sign in as the E2E branch's admin through `Api.login` (per-run token file, a `429` waits for `Retry-After`);
    they start by `settleStation` for the till, kitchen and bar (a leftover opening or counting shift from an earlier failed run is closed and its count resolved) and end with `ensureOpenShift`; every
    name they create is **run-unique** (`e2e-${runId}`): the payment channel (`e2e_wallet_<runId>`, `requires_photo` + `requires_reference`, soft-deleted in the `finally`), the product, the
    order note; assertions on the dashboards are on the run's own channel and item, where the totals are exact however much the shared branch has accumulated; the default eight channels are never
    edited by a test; the photo fixture is a generated 2400x1800 JPEG with an injected GPS block (`e2e/support/photo.ts`, no binary in the repo) and the spec asserts through the API that the stored
    file has no EXIF and a longest edge of 1600; the dashboard figures are read after `POST /analytics/rebuild {sync:true}` for today (no wait for cron), the polled admin lists after the
    5-second settling window; the till's shift is a *carried* one started by `ensureTill` (the three shift rules are on). Refund specs refund only orders the run created.
- **Gate test files** (`apps/api/tests/Gate/`, group `gate:p4`): `ChannelTotalsEqualPaymentsTest.php`, `RevenueEqualsOrdersTest.php`, `RebuildIdempotentGateTest.php`, `DashboardsReadSummariesOnlyGateTest.php`
  (the 4.4c assertion run on the full seed), `DashboardBudgetGateTest.php`, `RefundReversalGateTest.php` (the 4.1b cases on a seeded refund), `FilesNoPublicAccessGateTest.php`, `Phase4StateMachinesTest.php`,
  `Phase4ActorGateTest.php` (every new write stamps user, acting-as, shift, station, device), and the three race tests above. `Phase4GateTest.php` is the single entry that `gate_check` names and
  asserts the suite above exists and is not skipped.

## 8. Seeds

`Phase4Seeder` extends the demo bakery and never rewrites `Phase0Seeder` to `Phase3Seeder`: same demo branch, same demo admin as actor, same idempotence (natural keys and deterministic UUIDv5 client
UUIDs such as `demo-pay:{date}:{n}`), every write through module code (`Orders`, `Payments::record` / `refund`, `Shifts`, `Counts`, `Locks`, the Ledger, `Files::put`) and never a raw insert into a
ledger, payment, count or file table. It runs the queued listeners synchronously for its duration (`queue.default=sync`, restored, as `Phase2Seeder` and `Phase3Seeder` do), then calls
`Rebuild::day` for every day it wrote (trigger `seed`).

**The clock.** Phase 3 anchors the demo at 05:30 local on the day three days ago and plays two days forward (`Demo\DemoClock::around()` computes `subDays(3)`; `anchor()` is only the getter; `wantsAnchor($phase)` is true from phase 3 on a branch with no movement and no shift). Phase 4 needs 90. The change is in the entry point only:
`DemoClock::around()` takes the days back from the phase (a `DemoClock::daysBackFor(int $phase)`); `SEED_PHASE` below 4 keeps 3, so `e2e-p0` to `e2e-p3` and every earlier seeder test see exactly what they saw. With `SEED_PHASE>=4` and a demo branch that has **no
shift, no order and no movement**, the Phase 0 to 2 seeders run with `Carbon::setTestNow` and `CarbonImmutable::setTestNow` at `today - 92 days, 05:30 local`, then one day loop plays 90 days forward to
yesterday (`Phase3Seeder`'s per-day play is extracted into `Demo\DemoDay` so the Phase 3 history is that loop run for two days with its planned differences; Phase 4 runs it with a richer `DayPlan`),
and `DemoClock` puts the real clock back in a `finally`. Today is left for the live demo: open shifts, a few sample orders. *As built (4.6a):* the loop plays every day after the anchor, D-91 to
D-1 (91 days: an opening day, then the 90-day window D-90..D-1 the gate reads); the whole Phase 4 run is on the `sync` queue from the anchor (`DemoClock::around`), and with `SEED_PHASE>=4` on a
fresh branch `Phase3Seeder` writes only its templates and reasons while `Phase4Seeder` plays the days and does today's part.

**The day plan** (seeded `mt_srand(4040)`, so the same 90 days every run): weekdays about 22 orders, weekends about 34 (about 2 000 orders in all, 6 000 lines); a channel mix of (cash 45%, Telebirr 30%,
CBE Birr 10%, bank transfers 15%); 12% of paid orders are **split** (a digital part with a photo and the rest in cash); every digital payment carries a generated receipt photo (five small GD-drawn
images, EXIF-free, cycled) and a reference `SEED-{yymmdd}-{n}`; about 1.5% of payments are **voided** before the order is paid (and the order re-paid in cash) and 2% of orders are **refunded** (display-case
lines restocked, station lines `waste`, a few lines `none`), a handful by line (*as built in 4.6a:* `DayPlan::REFUND_PCT` is 0 until step 4.3d, `TODO(p4): 4.3d`; the roll is drawn on every
order already); 40% of digital payments are verified, 3% disputed (a few resolved); 1% of sent orders are voided; the
display case and the bakery are kept stocked by daily production batches and weekly purchases so stock-outs are rare and planned (three days in the 90 have one, with the production request it
causes); one Day shift per tracked station per day, closed and locked with the closing count equal to the ledger except on five planned days (a kitchen spoilage, a till cash difference); a Night
shift at the bakery every Friday (not when Friday is yesterday: that night would end today). Dates and volumes are constants in `Demo\DayPlan` so a test can state them; the As built note of 4.6a
lists them and the choices the plan left open.

**What the seed guarantees**, asserted by `Phase4SeederTest`: all Phase 0 to 3 history assertions still hold (every place's chain verifies, `expected_qty` equals `balanceAt`); the ledger oracle holds
after every refund; for every day `SUM(payments.amount WHERE status <> 'voided')` per channel equals `daily_channel_totals.amount` and counts match; `daily_sales.revenue` equals `SUM(orders.total)`
of paid/closed orders settled that day; `Σ payments - Σ payment_refunds` equals `Σ order totals - Σ refunded_total`; a second run writes nothing, not even an audit row; a run into a branch that is not a demo
branch is refused. Estimated cost: about 2 000 orders through the real actions at 80 to 150 ms each is 3 to 5 minutes; the plan's budget is **under 10 minutes in `e2e-p4`/`gate-p4`**; if 4.6a measures
more, the fallback is `DEMO_HISTORY_DAYS` (60) in CI only, with the 90-day figure kept for `perf-dashboards` (decision recorded in the PR; 10.2 R-I). *As built (4.6a):* measured 967 s for the
90 days (2 325 orders at about 0.4 s each); the fallback is taken, `e2e-p4` seeds 60 days (see the As built note).

**What the earlier seeders must change.** Nothing beyond the clock anchor parameter (*as built in 4.6a:* `Phase3Seeder`'s day is played by the extracted `Demo\DemoDay` with the same rows,
and from Phase 4 the anchored run is on the `sync` queue from the start). `Phase1Seeder` already pays in the till's running shift (3.7e). Channels, `payment_channels` defaults and the
permission grants reach the database by migrations, not by the seeder.

**The live demo branch.** "Demo Bakery" on https://bakery-ops.orcamind.dev was loaded by hand at an earlier phase and has data of its own. **`bakery:seed-demo --phase=4` never writes history into it**: if the
branch has any order, shift or movement the seeder prints "history not generated: the demo branch already has data; load a new demo branch" (the Phase 3 rule), adds nothing to the ledger, and only (a) makes sure the
eight channels and the receipt settings exist, (b) runs `Rebuild::day` for every day that has data (read only on the ledger, so the dashboards show the live demo's real orders; *as built in 4.6a:* every day from its first
movement to today that has no summary yet, so a second run writes nothing), and (c) if the demo branch has no open
shift at a tracked station, says so (starting them is the owner's call, as in 3.7e). Nothing on the live host is ever backdated. A 90-day showcase on live would need a **second, new demo branch** created for it
(`--branch="Demo History"`); that is not done unless Ramin asks (10.2 R-I). Never run the seeder against the "E2E Test Branch".

## 9. ADRs this phase writes

| ADR | Decision | Written in |
|---|---|---|
| **0014 summary rebuild semantics** (manifest) | the clock each metric is bucketed by (table in 4.4a), cash basis for refunds, `Rebuild::day` is a pure function of the facts of one local day, delete-and-insert in one transaction under the `analytics_days` row lock, idempotent by fingerprint, derived tables exempt from soft delete and the actor stamp, triggers (`ShiftLocked`, debounced touch, nightly 00:05 Addis, on demand), Night shifts rebuild two days, dashboards read summaries only (the query-log test), what is never estimated (`cogs_complete`) | 4.4a (proposed), accepted at the gate |
| **0015 file storage portability** | `Files` over a Laravel disk named by `FILES_DISK`; local private disk on the live host, S3-compatible later; signed URL as the only read path; re-encode strips metadata; keys carry no names; the move to S3 by key-for-key copy; quota monitoring; PHP extension requirements and the doctor | 4.2a (proposed) |
| **0016 refunds, voids and the cash drawer** | void of a payment before the order is paid versus refund after; reversing movements point at originals, once, through `Inventory\Reversals`; dispositions and why a station line defaults to `waste`; the money side in `payment_refunds`, LIFO over the order's payments; `Totals::cash` net of cash refunds so expected cash falls when the drawer pays out; refund window and the discount/tax restriction; the order stays `closed` | 4.1b (proposed) |
| note on ADR 0013 | a payment on **every** channel carries the till's shift; one kill switch (10.1 R-A) | 4.3b |
| note on ADR 0006 | the Files row is concrete: `files` disk, `FILES_DISK`, the extensions and the doctor | 4.2b |
| note on ADR 0009 | Phase 4 stays on polling; the new feeds (payments, analytics freshness), their cadences and the settling window | 4.5c |

## 10. Assumptions and open questions

Only a question that changes the meaning of the ledger blocks; there is exactly one (R-C). Everything else takes the safer default below and
the build continues (standing approval).

### 10.1 Live-host facts that shape this plan, and the two rules that follow

Shared cPanel behind Cloudflare; CloudLinux `alt-php83`; MariaDB 10.11; `database` cache, session and queue, the queue drained once a minute by
`queue:work --stop-when-empty --max-time=50`; **no Docker, no MinIO, no S3, no Redis, no WebSocket server, no long-running process**;
`BROADCAST_CONNECTION=null` (polling, ADR 0009); `config:cache` at every release; `rsync --delete` protects `storage/` and `.env`; a deploy runs
migrations, never seeders. Consequences used above: receipt photos live on the local `private` disk under `storage/app/private/files`
(protected from the rsync, outside `public/`); every long job is one local day, never a range, because a cron-run job has about 50 s of
patience before the next tick; rebuilds, weekly summaries, notifications and the orphan purge are queue or scheduler work, and an
interactive action never waits for them (the one exception, `POST /analytics/rebuild {sync:true}` for up to 7 days, is admin-only and bounded);
PDF and Excel generation are bounded in rows and memory; nothing depends on a process surviving between requests.

**R-A. Every channel inside the till's shift.** The Phase 3 rule (`payments.require_shift_for_cash`, ON) refuses a *cash* payment without an open
shift because the money would fall outside the declared drawer. Phase 4 asks the same for **every** channel, because `Totals::byChannel(shift)`
and the cashier's per-channel receipt reconciliation are keyed on the payment's own shift: a Telebirr payment with no shift would be in no
reconciliation. Implementation without a fourth kill-switch line: `config/payments.php` gains `require_shift` = `env('PAYMENTS_REQUIRE_SHIFT')`, unset
(null) by default, and `Payments` `Support/ShiftRule` reads null as "follow `require_shift_for_cash`" on every call (as built in 4.3b; the draft's
`env('PAYMENTS_REQUIRE_SHIFT', env('PAYMENTS_REQUIRE_SHIFT_FOR_CASH', true))` froze the value when the config loaded, so a test or a tinker session that
turns the cash rule off in process would still have asked every channel for a shift); a payment needs the shift when
`(channel is cash && require_shift_for_cash) || every channel`. The existing line `PAYMENTS_REQUIRE_SHIFT_FOR_CASH=false` in the server `.env` (then
`php artisan config:cache`) therefore switches both off, so the runbook's "three lines" stay three. `NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH` keeps its
name and now gates Pay for every channel in the web build. A note on ADR 0013, not a new ADR. The refund of a cash share needs the shift for the same reason (4.4). The
`rules-off-gates` job already runs Phase 0 to 2 gates with the variables off; it gets `gate:p4`'s *payment* tests in both modes (a table: on and off).

**R-F. Files on the live host without MinIO.** The manifest and architecture name MinIO/R2. The live host cannot run MinIO or reach a bucket without an account (which is Ramin's
to open), so the live system stores files on the account's private disk behind `Files`, exactly as ADR 0006 already says in its table ("local `private` disk on the account, signed
URLs through Laravel"). The code is written against a disk name so the move is configuration plus a key-for-key copy (4.2a). MinIO stays in `compose.ci.yml` **only** to prove the S3 path
(`files-s3`), which keeps the manifest's entry criterion honest without needing it in production. The consequences accepted: the receipt photos are inside the account's disk quota and its
backup (10.2 R-M), reads go through PHP (a signed URL per view, no CDN cache: `Cache-Control: private, no-store`), and a signed URL is a bearer credential for its 5 minutes, so it is
kept out of logs by the application (the web server's own access log cannot be changed on shared hosting; the 5-minute life is the mitigation) and out of the audit snapshots.

### 10.2 Open questions, each with the default the build takes

| # | Question | Default taken | Who decides / when it bites |
|---|---|---|---|
| **R-C** | **PENDING RAMIN (asked 2026-10-24; default proposed: display-case lines restock with a reversing `return` movement, station lines waste with no movement). Step 4.1a builds no refund stock logic and step 4.1b waits for his answer.** **(the one that changes the ledger's meaning)** What does a refund reverse in stock? The phase reference says "reversing `sale` / `production_consume` movements". Reversing the *ingredients* of a sandwich that was cooked and served would put flour back on the shelf that is in the customer's hands. | Per line, a **disposition**: `restock` writes a reversing `return` movement pointing at the original; `waste` writes nothing (the original consumption stands, the loss is visible as the refunded money against consumed stock); `none` is for lines never made. **Defaults:** display-case lines (a croissant handed back) `restock`; station lines (made to order) `waste`. A station line can be `restock`ed by a manager (food never served, e.g. a rejected cake returned whole) but only when that location's station has an open shift (ADR 0013), else `409 inventory.shift.required`. The contract case "originals untouched, reversing movements reference them" is proved on `restock`. | Ramin (open); if he wants every refund to reverse everything, the default table in `RefundDispositionDefaults` flips and nothing else changes. The red contract cases of 4.1a pin the `restock` and `waste` paths only as options of `Orders::refund`, not the defaults. |
| R-D | Partial refunds when the order carries a discount or tax? | Not defined in Phase 4: a refund with `lines` on an order with `discount > 0` or `tax > 0` is `422 orders.refund.partial_not_allowed`; a whole-order refund returns the full `total`. (The pad has no discount or tax UI yet; the architecture lists VAT and waiter discounts as open questions of Ramin's.) | when discounts/VAT are decided |
| R-G | **k6 may not run against the shared host.** | The gate keeps k6 where Docker exists, **in CI** (`perf-dashboards` on the compose stack with the 90-day seed, as the manifest says: GitHub runners have Docker). For the live host, and as a second measure inside `gate:p4` that needs no k6, add: (1) `DashboardBudgetGateTest` (Pest, the gate seed): for each `/analytics/*` endpoint, 3 warm-ups then 30 sequential requests **without** `If-None-Match`, in process: p95 under **400 ms** and at most 8 queries, so the 1000 ms budget keeps 600 ms for transport; (2) `php artisan analytics:bench {--branch=} {--requests=30}` which makes the same timed calls in process on whatever host runs it and exits non-zero over budget, so Ramin can run it on the live demo branch; (3) `p4-dashboards` asserts first data under 1000 ms in the browser in CI, and its `@live` twin records the live figure with a soft budget of 2500 ms (it measures Cloudflare and a CPU-limited host with a small branch, so it can warn but not gate). k6 against the live URL is **not** planned: it would load the production host, trip Cloudflare, and measure a branch with almost no data. | Ramin: accept the substitute. The wording of manifest criterion 3 changes (10.9). |
| R-I | Seed volume and the live demo branch. | 90 days (about 2 000 orders) through module code, budget under 10 minutes in CI, `DEMO_HISTORY_DAYS` fallback (section 8). The existing live "Demo Bakery" gets no backdated rows, ever. No second showcase branch on live unless asked. | Ramin, only if he wants a 90-day showcase on live |
| R-J | Which clock does "a day's sales" use? Orders paid across midnight (sent 23:50, served 00:10) split the story. | Revenue and order counts by `settled_at` (the instant the balance reached zero, within seconds of the last payment), channel totals by `received_at`, refunds by `refunded_at`: so a day's revenue and a day's channel totals differ only by orders whose payments straddle midnight, and the gate checks each against its own clock. Not `business_date` (the order's numbering day) and not `paid_at` (which waits for the last line to be served). Written in ADR 0014. | Ramin, if the shop reads "the day" by when the order was taken |
| R-K | Gross margin when a purchase later moves a cost. | `cogs_total` uses the moving average in force **at the end of the sale's local day** and the recipe version the line snapshotted; a rebuild next month gives the same figure. An ingredient with no cost yet makes the product's `cogs_complete` false and counts 0; dashboards show "cost incomplete", never an estimate. | none unless cost policy changes |
| R-L | Weekly summary by email? | Telegram and the in-app inbox through Notifications, which exist. Email needs an SMTP account on the host (`config/mail.php` is the default `log`): left out, one more driver in Notifications when Ramin provides the credentials. | Ramin supplies SMTP |
| R-M | Receipt photo storage on a shared account. | About 180 KB each at 1600 px; 200 digital payments a day is about 1 GB a month. The default keeps them indefinitely (architecture 7) but `files:doctor` prints used size and free quota, and the runbook says to move to `files_s3` (R2) before the account passes 70% of its quota or the nightly backup gets slow. Orphans (uploaded, never attached) are purged after 24 h. | Ramin: cPanel quota and backup size; decide at the pilot |
| R-N | May a cashier refund? | The matrix says "request only": the cashier asks (`orders.void_request`) and a manager or admin approves with the PIN at the till, exactly like a void. A cashier may **view** payments (`payments.view`) only of their own shift or the last 24 hours (`GET /payments` scopes them), admin and manager see all. | none |
| R-O | Refund window. | `payments.refund_window_hours` = 72 (plain config); beyond it `409 orders.refund.window_closed`; an admin raises the number in the server `.env`, no admin screen. | Ramin |

Defaults told to Ramin on 2026-10-24 and taken unless he objects: R-A (the shift rule for every channel, `PAYMENTS_REQUIRE_SHIFT` defaulting to the cash flag), R-F (local private disk behind `FILES_DISK`), R-N (a cashier requests, a manager approves with the PIN), R-O (72 hour refund window), R-D (partial refunds refused with a discount or tax), the weekly summary to Telegram and the inbox, R-G (timed Pest and Playwright instead of k6 on live), R-I (no backdated rows on the live demo).

### 10.3 The gate in detail (`gate:p4`)

1. **Arithmetic.** `ChannelTotalsEqualPaymentsTest`: for each of the 90 seeded days and each channel, `daily_channel_totals.amount` equals `SUM(payments.amount)` over the day's non-voided payments
   (decimal strings compared with brick/math, never floats), `receipts_count` equals the row count, `net_amount = amount - refund_amount`, and the sum over channels equals the day's payment total.
   `RevenueEqualsOrdersTest`: `daily_sales.revenue` equals `SUM(orders.total)` of the day's paid/closed orders (by `settled_at`), `orders_count` equals the count, `net_revenue = revenue - refund_total`.
   `RebuildIdempotentGateTest`: rebuild three seeded days twice: same fingerprints, same rows.
2. **Browser.** `p4-pay-photo-split`: a channel with `requires_photo` and `requires_reference` plus cash; **Pay is disabled until the photo is attached**; after Complete the order is `closed`, two payments
   exist, the file is attached to the first and has no EXIF and a longest edge of 1600 px (read through the API).
3. **Speed and sources.** As R-G, plus `DashboardsReadSummariesOnlyGateTest` (the query-log assertion on the full seed).
4. **Not broken.** `gate-p0` to `gate-p3`, including `p1-order-flow` and `p3-handover` with the shift rules on, and `rules-off-gates` with them off.

### 10.4 Refund arithmetic

- `refund.amount` = `Σ order_lines.line_total` of the refunded lines (a whole-order refund: `orders.total`). `orders.refunded_total += amount` and must stay `<= orders.paid_total`; each line is refunded once.
- Allocation over payments: last in, first out by `(received_at, id)` descending, each taking `min(open, remaining)` where `open = payment.amount - payment.refunded_amount`, so a split bill of
  Telebirr 70.00 then cash 50.00 refunded 40.00 comes back as cash 40.00. A body field `allocation: [{payment_id, amount}]` overrides it (same validation: each within its open amount, the sum equals
  the refund; the sheet offers it only on split bills). A cash share is paid from the drawer, so `Totals::cash(shift)` falls by it and the cashier's closing declaration expects less.
- A **void** of a payment is for an order not yet paid: `paid_total -= amount`; a settled but unserved order loses `settled_at`; nothing was sold yet, so no stock moves. An order that is already
  `paid`/`closed` cannot have a payment voided (409): it is refunded, so history is never rewritten.
- Rounding: money is added and subtracted only as `Money` (brick/math); the only division in the phase is `avg_ticket` and `margin_pct`, rounded once, half-up, at the end.

### 10.5 Night shifts and the day boundary

Addis Ababa has no daylight saving; `LocalDay` is still written against the branch's timezone so that the code does not assume it. The Night shift (22:00 to 06:00) has `date` = its local start date
and works on two local days. Its payments are on the day they were received; its `ShiftLocked` fires after 06:00 of D+1 and rebuilds **both** D and D+1; the nightly job at 00:05 rebuilds D-1 and D-2
so a shift that locks late (a variance waiting for approval) is healed by its own event when it finally locks. Shift counts and variance lines are attributed to the shift's `date`; sales to their own
clocks (10.2 R-J). Tests sit at 23:59:59 and 00:00:00 local (20:59:59Z and 21:00:00Z) for every Facts port, the builders and the schedule.

### 10.6 New permissions and how they reach the live database

New to `PermissionRegistry`, granted by `grant_phase_4_permissions` (M2, the same thin caller as Phase 2 and 3, which never revokes and writes nothing the second time; the live database has them
as soon as 4.2b is deployed and before any route uses them):

| Permission | Default roles | Used by |
|---|---|---|
| `files.upload` | admin, manager, cashier | `POST /files` and one's own unattached upload |
| `payments.view` | admin, manager, cashier (cashier scoped, R-N) | order payments, `GET /payments`, a file's signed URL, receipts |
| `payments.verify` | admin, manager | verify, dispute, reconciliation, export, deleting an unattached file, the payment reads unscoped (as built in 4.3c through its own grant migration `2026_11_02_100100_grant_payments_verify_permission`) |
| `analytics.manage` | admin | rebuild, weekly-summary test (as built in 4.4b through its own grant migration `2026_11_05_100100_grant_analytics_manage_permission`: 4.2b's M2 had run on the live database without it) |
| `audit.export` | admin | audit export (as built in 4.5a through its own grant migration `2026_11_08_100200_grant_audit_export_permission`: M2 had run on the live database without it) |

Already in the registry and used as they are: `channels.manage` (admin), `payments.take` (admin, manager, cashier), `payments.void` (admin, manager: the approver's PIN for void and refund),
`orders.void_request` (admin, manager, cashier: asks for a void or a refund), `analytics.view` (admin, manager), `analytics.view_own` (everyone), `audit.view` (admin, manager), `stations.all`.
`GrantPhase4PermissionsMigrationTest` starts from a database "after Phase 3" with a hand-edited role and a direct permission, runs the migration and proves nothing was removed and a second run
changes nothing. The manager keeps "no configuration": `channels.manage` stays admin only, and the manager sees the Payments admin read-only (the buttons are hidden by permission and 403 by the API).

### 10.7 The live demo and E2E branches

"Demo Bakery" is never backdated and never touched by a test (section 8). The "E2E Test Branch" is shared and accumulates data across runs, so Phase 4 specs create their own run-unique channel,
product and notes, assert on those, settle stations first, honour the login limit, and clean up with soft deletes in a `finally` (section 7). Payments, refunds, photos and rebuilt days from E2E runs stay
(they are append-only by rule); a run adds about three small photos and a few hundred summary rows, which `files:doctor` makes visible if it ever matters.

### 10.8 Other assumptions (safer defaults)

- **R-B Duplicate reference or receipt.** A reference already used on the same channel (normalised: upper case, no spaces or dashes) or a receipt with the same `sha256` is a **warning** on the
  response and a `flagged` chip in the admin list and the channel dashboard (`duplicate_reference_count`), never a block: a customer can legitimately pay two bills with two transfers that share a
  prefix, and the cashier must not be stuck at the till. The admin disputes it.
- **R-E Excel library.** `openspout/openspout` (streaming writer, a few MB of memory whatever the rows) instead of `maatwebsite/excel` (PhpSpreadsheet, hundreds of MB for 20 000 rows, which
  CloudLinux's memory limit will kill). It needs `zip`, `xmlwriter`, `mbstring` (checked by the doctor). The manifest's "Excel/PDF exports" are met; the library name is not in the manifest.
- **R-H Charts.** No chart dependency: small inline SVG components (static export, no bundle weight, accessible with a table fallback). Revisit only if a dashboard needs interaction the SVG cannot give.
- **R-P Receipts.** The PDF and the signed link are Phase 4; ESC/POS thermal printing through a print bridge is Phase 5 (workflows-and-screens.md). VAT: the receipt prints the `tax` the order carries (zero
  today), computes nothing, and shows the TIN and business name from `receipt_settings`. The Amharic font is Noto Sans Ethiopic (SIL OFL) bundled as a TTF; Western digits throughout.
- **R-Q Disputed payments** still count in channel totals (the gate formula excludes only `voided`); they are listed separately (`disputed_count`) and can be resolved to `verified` or `voided`.
- **R-R Throttling.** `POST /files` is throttled 30 a minute per user; `POST /orders/{order}/refund` and `/void` count wrong PINs against the existing lockout.
- **R-S Timezones and currency.** Summaries are single-currency per branch (ETB); `day` is the branch-local date. A branch whose timezone is changed later is a rebuild of its history (documented in ADR 0014, not built).
- **R-T Offline.** The pay sheet is online-only in Phase 4 (a clear message when the network is down); queued photo upload and the outbox are Phase 5 (architecture 5: "payment pending sync").
- **R-U Nothing estimated.** Dashboards show `as_of` and `stale_days`; a day with no `analytics_days` row is shown as "not built", never as zero.

### 10.9 Where the phase reference and manifest conflict with the live host or with Phase 3 as built

Each is corrected in the PR named (the manifest, the skill copy under `.claude/skills/bakery-ops-builder/` and `references/` change together, as Phase 3 did in 3.1a).

1. **MinIO/S3** (entry criterion, step 4.2 "S3 disk (MinIO in CI)", architecture sections 2, 9, 10): the live host has none. Corrected to "a `files` disk, local on the live host, S3-compatible by configuration, MinIO in CI for the S3 proof only" (4.1a).
2. **k6 `perf-dashboards`** (gate criterion 3) cannot run on the shared host: R-G (4.1a, 4.6b).
3. **`e2e-p4` profiles** in `ci-cd.md` say `full,ws` ("until their phase plans settle the transport"): Phase 4 settles it, `e2e-p4` and `e2e-p5` run `full` with the polling transport like p1 to p3 (4.1a).
4. **`Facts` names**: the manifest says `Orders\Facts::sales(range)`, one method per port; the plan needs `sales` and `lines` (Orders), `payments` and `refunds` (Payments), four on Inventory, plus `LocalDay` in Shared. Same ports, more methods (4.1a corrects `modules.analytics.needs`).
5. **`analytics` tables**: the manifest lists four; the plan adds `daily_hour_sales` and `daily_user_metrics` (the hour and waiter/cashier dimensions of architecture section 8) and `analytics_days` (lock and freshness) (4.1a).
6. **Analytics -> Notifications**: the manifest's `analytics.depends_on` has no Notifications and `notifications.subscribes` has no weekly summary; the plan uses an event (`WeeklySummaryReady`) like every other producer, and `Notifications.subscribes` gains `WeeklySummaryReady`, `PaymentDisputed`, `OrderRefunded` (4.1a).
7. **Audit UI has no API** in the manifest (step 4.5 says "Audit log UI" only); `audit.view` exists but no endpoint does. Added as 4.5a with a Shared `ActorDirectory` so Audit stays a leaf.
8. **`Orders::refund(order, reason, pin)`**: the PIN is checked by the controller (as `VoidOrder` does, so a wrong PIN counts toward the lockout even when nothing is written), so the contract method takes the approving `User`, not a PIN; it also takes the line dispositions. Behaviour the manifest names ("reversing movements ... originals untouched") is kept, with R-C.
9. **Phase 1 `PaymentStatus` comment** promises "Phase 4 adds no column or state". It adds columns for the dispute (M4) and lets `disputed` be resolved; the exhaustive table and the CHECK change in 4.3b. Phase 1's `payments.amount.mismatch` also stops refusing a part payment.
10. **`receipt_photo_url`** (architecture section 3) is `receipt_photo_key` as built (an object key, never a URL); M4 adds `receipt_file_id` beside it.
11. **`PaymentVoided`** is listed in the manifest's payments events and Phase 1 never built it; it is built in 4.3c with its Orders listener, and `VoidOrder`'s note "no reversal in Phase 1" is superseded for paid orders by the refund.
12. **`payments.require_shift_for_cash`** says cash only; the owner's rule for this phase is every channel inside the till's shift. R-A widens it behind the same switch.
13. **Cash declaration arithmetic (Phase 3)** counts cash taken; refunds paid from the drawer must lower expected cash or every refund reads as a shortage. Handled inside `Totals::cash` (4.3d) with a new `shifts->payments` case; Shifts' own code does not change.
14. **`ShiftLocked` has no listener in Phase 3** (its docblock says Analytics will rebuild from it); a Night shift's `date` is its start date, so one event must rebuild two days (4.4b).
15. **Phase 3 `DemoClock` anchors three days back**; Phase 4 needs 90. The anchor becomes a parameter, default unchanged (section 8). `Phase4Seeder` builds on step 3.8's `Phase3Seeder` and `DemoClock`, which exist on `main` since the Phase 3 gate (`3b37a69`).
16. **Entry gate wants `Phase4Seeder` to exist**; the real one is the last step, so a skeleton lands first (4.1a), as Phase 3 did with placeholders.
17. **`order_events_event_check`** lists the events and has no payment or refund event, while the architecture wants payment photos on the order timeline: M9 extends it (DDL only).
18. **`daily_sales.revenue == SUM(orders.total)`** (gate) has no stated clock; ADR 0014 fixes it as `settled_at` (R-J).
19. **Dashboards "hit no raw tables"** needs a definition because names come from `items`, `categories`, `payment_channels`, `users`, `stations`: the allowlist of 4.4c. As built (4.4c): the endpoint's own statements may also name `units` (an item's base unit, joined by `Items::labels`) and `user_station_assignments` (the caller's stations), and the plumbing every route runs before the endpoint (auth, the `actor` middleware, the permission check) may name `branches`, `devices`, `acting_sessions` and the station's shift stamp on `shift_instances`; see the As built note of 4.4c.
20. **Analytics -> Audit**: the manifest's `analytics.depends_on` had no Audit, but `POST /analytics/rebuild` is a write and writes its audit row through
    `AuditedAction`. 4.4b adds `audit` to `analytics.depends_on` and `needs`, and `ModuleBoundariesTest` allows Analytics Audit's `AuditedAction` and
    nothing else of Audit.

## 11. Risks from `references/traps.md` that apply

| Area | Trap | What this plan does |
|---|---|---|
| MinIO (traps row) | signing host mismatch, missing bucket | only the `files-s3` job meets it; `AWS_ENDPOINT` (container) versus `AWS_URL` (browser), `mc` init container; the live host never sees it |
| Signed URLs | in logs, in a CDN cache, in a `Referer`, in an audit row | log processor scrubs `signature` and `expires`, 300 s ttl, `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer`, `Snapshot` redacts keys and URLs, test taps the log; Cloudflare must not cache `/api/*` (a page rule check is added to the runbook) |
| Float creeping into totals | `json_encode`, `SUM` through PDO, `parseFloat` in the dashboards | decimal strings end to end; `SUM` columns are read as strings and added with `Money`; assertion helpers compare strings; the ESLint rule from ADR 0004 covers the new web files; the charts take strings and convert to numbers only for pixel positions, never for displayed values |
| Summary rebuild idempotency across time zones | a day bucketed in UTC, a Night shift, a rebuild at 00:05 reading yesterday's half-written day | `LocalDay`, boundary tests at 23:59:59/00:00:00 local, two-day rebuild on `ShiftLocked`, delete-and-insert under the `analytics_days` lock, fingerprint equality test |
| First-row race | locking a row that may not exist | `analytics_days` is upserted (no-op update) before `FOR UPDATE`, as `stock_balances` and the chain are |
| k6 needs the big seed | the demo seed is too small to mean anything | `perf-dashboards` and `DashboardBudgetGateTest` run on the 90-day seed only |
| Queues on the shared host | cron-drained, a minute of latency, a long job killed | one job per day, `ShouldBeUnique`, debounce, `--max-time=50` respected, rebuilds that must be immediate are the bounded `sync` call, the scheduler heals the last two days |
| Idempotency | multipart bodies, concurrent duplicates | hash of fields plus file sha256, unique `(branch_id, client_uuid)` on `stored_files`, `payments`, `payment_refunds`, `order_refunds`; fork test for uploads |
| Hash of the first upload | the same photo reused for two bills | `sha256` stored, duplicate warning (R-B), `duplicate_reference_count` on the dashboard |
| Driver names | `=== 'mysql'` skips MariaDB | every new migration branch uses `in_array($driver, ['mysql','mariadb'], true)`; `AppendOnlyTest` runs under the live driver for the four new append-only tables |
| Live updates | auto-increment ids visible out of commit order; ETag over max id | `Payments::fingerprint()` adds the `settling` second; weak comparison of `If-None-Match` (Cloudflare turns strong ETags into `W/`); polls pause when hidden and back off |
| Ledger and shifts | a reversing movement at a station with no shift is refused | contract case 9; defaults avoid the case (R-C); the refund screen shows the refusal in the page's language |
| Seeds | later phases change seeds and break earlier gates | clock anchor is a parameter, default unchanged, Phase 3 and earlier seeds run exactly as before; `Phase3SeederTest` stays green; the live demo is never backdated |
| Windows host | CRLF, host PHP 8.0 | no new host-side PHP; the font and fixtures are generated in tests, not committed as binaries, except the one TTF (`.gitattributes` binary) |
| Config cache | closures in config files break `config:cache` | `files.php`, `analytics.php`, `payments.php` additions are plain values; `DeployableCachesTest` extended |
| PHP extension drift | web SAPI and CLI have different extensions on cPanel | `files:doctor` and `GET /files/doctor` side by side in the live check of 4.2b; `exif` optional, `gd` required |
| Memory on a shared account | a 12 MP photo decoded by GD is about 50 MB; dompdf and spreadsheets are hungry | the browser shrinks first; the server refuses over 24 MP and 8 MB before decoding; OpenSpout streams; receipts are two pages; dompdf `isRemoteEnabled` off; a memory check in the doctor |
| Disk quota | photos fill the account and its backup | R-M: doctor, 70% rule, orphan purge, S3 path ready |
| Playwright | dev server late hydration, storage shared, service workers | tests run the built app; `serviceWorkers: 'block'`; one context per role; waits on API state; file inputs via `setInputFiles` on the real `<input>`, never a timer |
| Shared E2E branch | accumulated data, a failed run leaves a shift or a count waiting, login limit | run-unique names, `settleStation`, `ensureOpenShift`, per-run token file, `Retry-After` (section 7) |
| Phase scope creep | building OCR, WhatsApp links, ESC/POS because the schema is there | out of scope and written down: OCR of receipts, direct bank verification, ESC/POS, outbox, email; no Phase 4 TODO markers may remain at the gate (`gate_check --mode done`) |
| Test debt | `->skip(` or `fixme` left in a gate project | the 4.1a Playwright `fixme`s are listed in the PR and removed by 4.6b; `gate_check --mode done` enforces |
| Contract before consumer | writing Payments or Analytics code before the contract is green | 4.1a commits the files red, 4.1b/4.1c turn them green, `app/Modules/Analytics` does not exist until 4.4a |
