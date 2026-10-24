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

Entry check: see "As built (step 4.1a)" under section 6, item 1, where its summary lines are pasted as run.

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
| Audit UI | Identity | **new** Shared `ActorDirectory::names(ids)` bound by Identity (Audit stays a leaf) | `ActorDirectoryTest` |

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
| M3 | `extend_payment_channels_for_rules` | Payments | `reference_pattern` VARCHAR(120) NULL (a regex body without delimiters, validated when saved), `reference_hint_en` / `reference_hint_am` VARCHAR(120) NULL (a bilingual pair), `photo_hint_en` / `photo_hint_am` NULL. No default pattern is seeded (nobody here knows each bank's current format; the admin sets one). |
| M4 | `extend_payments_for_phase_4` | Payments | `receipt_file_id` BIGINT UNSIGNED NULL FK `stored_files` (the existing `receipt_photo_key` is filled with the file's key too, so Phase 1 readers and the ERD keep meaning), `reference_norm` VARCHAR(64) NULL (upper case, no spaces/dashes; INDEX `(branch_id, channel_id, reference_norm)`, not unique: a repeated reference is a warning, 10.8 R-B), `batch_uuid` UUID NULL (payments recorded together), `disputed_by_user_id` FK users NULL, `disputed_at` DATETIME NULL, `dispute_reason` VARCHAR(255) NULL, `dispute_resolved_at` DATETIME NULL, `refunded_amount` DECIMAL(12,2) DEFAULT 0. CHECKs: `amount > 0`, `refunded_amount >= 0 AND refunded_amount <= amount`. `PaymentStatus::transitions()` becomes `recorded -> verified|disputed|voided`, `verified -> voided`, **`disputed -> verified|voided`** (a dispute can be resolved; before it was terminal). `tests/Gate/Phase1StateMachinesTest` and the exhaustive table are updated in the same PR. |
| M5 | `create_receipt_settings_table` | Payments | `receipt_settings`: `branch_id` UNIQUE, `business_name_en/am`, `address_en/am`, `phone`, `tin` VARCHAR(32), `footer_en/am` VARCHAR(255), `show_amharic` BOOL DEFAULT 1, `ethiopian_dates` BOOL DEFAULT 0, `created_by_user_id`, timestamps. One row per branch, created on first save; a read of a missing row returns the defaults (branch name, no TIN). |
| M6 | `create_stock_movement_reversals_table` | Inventory | `stock_movement_reversals`: `id`, `branch_id`, `movement_id` (the new `return` row) UNIQUE FK, `reverses_movement_id` UNIQUE FK `stock_movements`, `created_at`. **Append-only** (the update/delete triggers, as `order_events`). The unique `reverses_movement_id` is what makes "reversed once" a database fact, without a partial index. Written only by `Inventory\Contracts\Reversals`, inside the Ledger transaction. |
| M7 | `create_order_refunds_tables` | Orders | `order_refunds`: `id`, `branch_id`, `client_uuid` (UNIQUE with branch), `order_id`, `reason` VARCHAR(255), `amount` DECIMAL(12,2) CHECK `> 0`, `is_full` BOOL, `requested_by_user_id`, `approved_by_user_id`, actor stamp (`acting_user_id?`, `shift_instance_id?`, `station_id?`, `device_id?`), `created_at`; `order_refund_lines`: `id`, `branch_id`, `order_refund_id`, `order_line_id` UNIQUE (a line is refunded once), `qty` DECIMAL(14,4), `line_amount` DECIMAL(12,2), `disposition` CHECK in (`restock`, `waste`, `none`), `movement_ids` JSON (the reversing movements), `created_at`. Both **append-only**. |
| M8 | `add_refund_columns_to_orders` | Orders | `orders.refunded_total` DECIMAL(12,2) DEFAULT 0 CHECK `>= 0` (and `<= paid_total` as a trigger-free application rule, pinned by a test), `orders.refunded_at` DATETIME NULL; `order_lines.refunded_at` DATETIME NULL. |
| M9 | `extend_order_events_check` | Orders | drops and re-adds `order_events_event_check` with four more events: `payment.recorded`, `payment.voided`, `line.refunded`, `order.refunded`. DDL only; the append-only triggers are untouched (a test re-runs `AppendOnlyTest` after it). `ApplyPayment` now also writes `payment.recorded` (payload: `payment_id`, `channel_code`, `amount`, `receipt` bool; **never a URL or a key**). |
| M10 | `create_payment_refunds_table` | Payments | `payment_refunds`: `id`, `branch_id`, `client_uuid` (UNIQUE with branch), `order_id`, `order_refund_id` FK, `payment_id` FK, `channel_id` FK, `amount` DECIMAL(12,2) CHECK `> 0`, `reference_no` VARCHAR(64) NULL (of the outgoing transfer), `proof_file_id` FK `stored_files` NULL, `refunded_by_user_id`, `approved_by_user_id`, actor stamp, `refunded_at` DATETIME, `created_at`. INDEX `(branch_id, refunded_at)`, `(payment_id)`. **Append-only.** |
| M11 | `add_facts_indexes` | Orders, Payments, Production, Inventory | read indexes for the Facts queries, each added only if `EXPLAIN` in the provider's test shows the existing one is not used: `orders (branch_id, settled_at)`, `orders (branch_id, voided_at)`, `payments (branch_id, status, received_at)`, `production_batches (branch_id, finished_at)`, `stock_movements (branch_id, created_at, reason)`, `shift_instances (branch_id, date)`. DDL on tables of tens of thousands of rows, inside the release window (as 10.7 of the Phase 3 plan). |
| M12 | `create_analytics_days_table` | Analytics | `analytics_days`: PK `(branch_id, day)`, `day` DATE (local), `version` INT UNSIGNED, `rebuilt_at` DATETIME(6), `trigger` VARCHAR(16) CHECK in (`shift_locked`, `nightly`, `manual`, `touch`, `seed`), `duration_ms` INT UNSIGNED, `row_counts` JSON, `fingerprint` CHAR(64) (sha256 of the canonical sums of every summary row of the day, so two rebuilds can be compared with one string), `changed` BOOL (the fingerprint differs from the previous run). It is the lock target of `Rebuild::day` and the freshness feed. Derived data: physical delete allowed (ADR 0014). |
| M13 | `create_daily_sales_tables` | Analytics | `daily_sales` PK `(branch_id, day)`: `orders_count` INT, `items_qty` DECIMAL(14,4), `subtotal` / `discount_total` / `tax_total` / **`revenue`** DECIMAL(12,2), `avg_ticket` DECIMAL(12,2), `voided_count`, `voided_total`, `cancelled_count`, `refund_count`, `refund_total`, `net_revenue` (= revenue - refund_total, cash basis), `dine_in_revenue`, `takeaway_revenue`, `delivery_revenue`, `advance_revenue`, `rebuilt_at`. `daily_hour_sales` PK `(branch_id, day, hour)` `hour` TINYINT 0-23: `orders_count`, `items_qty`, `revenue`. |
| M14 | `create_daily_channel_totals_table` | Analytics | PK `(branch_id, day, channel_id)`: `receipts_count`, **`amount`** DECIMAL(12,2), `refund_count`, `refund_amount`, `net_amount`, `without_reference_count`, `without_photo_count`, `verified_count`, `disputed_count`, `duplicate_reference_count`, `rebuilt_at`. |
| M15 | `create_daily_item_metrics_table` | Analytics | PK `(branch_id, day, item_id)`: `category_id`, `kind` CHAR(10), `units_sold_qty`, `refunded_qty`, `produced_qty`, `consumed_qty`, `waste_qty`, `purchased_qty`, `adjusted_qty` (signed), `stockout_count`, `closing_on_hand_qty` (all DECIMAL(14,4)), `revenue`, `cogs_total`, `margin_total`, `closing_value_total` (DECIMAL(12,2)), `cogs_complete` BOOL (false when a used ingredient has no cost yet), `rebuilt_at`. |
| M16 | `create_daily_station_and_user_metrics_tables` | Analytics | `daily_station_metrics` PK `(branch_id, day, station_id)`: `tickets_count`, `lines_served`, `lines_rejected`, `avg_prep_seconds` INT NULL, `p90_prep_seconds` INT NULL, `batches_finished`, `produced_qty`, `waste_qty`, `shifts_count`, `shifts_locked_count`, `variance_line_count`, `cash_variance_total` DECIMAL(12,2), `stockout_count`. `daily_user_metrics` PK `(branch_id, day, user_id, role_kind)` `role_kind` in (`waiter`, `cashier`, `station`): `orders_count`, `items_qty`, `revenue`, `payments_count`, `payments_amount`, `voids_count`, `refunds_count`, `lines_prepared`, `avg_prep_seconds`. (The manifest names four tables; `daily_hour_sales` and `daily_user_metrics` are this plan's additions for the hour and waiter/cashier dimensions of architecture section 8, 10.9.) |
| M17 | `add_audit_log_read_indexes` | Audit | `(branch_id, id)`, `(branch_id, action, id)`, `(branch_id, subject_type, subject_id, id)`, `(branch_id, actor_id, id)` for the Audit UI's filters. Only indexes: the append-only triggers do not care. |

Invariant consequences, decided here so the checks do not surprise the build:

- The analytics tables are **derived and deleted physically by every rebuild**. `SoftDeletesTest` and `BranchScopedTest` get them on a
  documented allowlist with the reason "derived summary, rebuilt from facts (ADR 0014)"; `ActorStampTest` does not list them (they are not
  operational writes); `BranchIdTest` still demands `branch_id NOT NULL` + FK + index on all of them (it is part of every primary key).
  `NumericColumnsTest` stays satisfied: money DECIMAL(12,2), quantities DECIMAL(14,4), no column named `*cost*` outside the (14,4) rule
  (`cogs_total` is a total of money, 12,2).
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
| `GET /payment-channels` | `payments.take` (exists) | read | `?all=1` needs `channels.manage` (disabled channels too) | `200 {data: [Channel]}`, `Channel` = `{id, code, name_en, name_am, is_enabled, requires_photo, requires_reference, reference_pattern, reference_hint_en/am, photo_hint_en/am, sort, accepted_now, is_cash}`; `accepted_now` is now simply `is_enabled` (a photo no longer blocks) and is kept so the Phase 1 client still works | |
| `POST /payment-channels` | `channels.manage` | key + `client_uuid` | `{client_uuid, code, name_en, name_am, requires_photo, requires_reference, reference_pattern?, hints?, sort?}` | `201 Channel` | `422 payments.channel.code_taken`, `422 payments.channel.pattern_invalid` (does not compile, longer than 120, or a nested quantifier that can backtrack catastrophically), `422 validation.failed` |
| `PATCH /payment-channels/{channel}` | `channels.manage` | key | any of the above except `code` (immutable once a payment exists on it) | `200 Channel` | `409 payments.channel.cash_locked` (the `cash` channel cannot be switched off, removed, or given a photo/reference rule: the drawer count is built on it), `422` |
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
| `POST /orders/{order}/payments` | `payments.take` | key + `client_uuid` | `{client_uuid, channel_code, amount, tendered_amount?, reference_no?, receipt_file_id?}` | `201 {payment, order, warnings: []}` (`200` replay); `warnings` may hold `reference.duplicate` `{payment_ids}` and `receipt.duplicate` `{payment_ids}` (same `sha256` on another payment), never blocking | the list above, plus the Phase 1 keys (`payments.order.not_payable`, `.settled`, `.tendered.too_low`, `.client_uuid.reused`, `payments.shift.required`) |
| `POST /orders/{order}/payment-batches` | `payments.take` | key + `client_uuid` (the batch) | `{client_uuid, rows: [{client_uuid, channel_code, amount, tendered_amount?, reference_no?, receipt_file_id?}, ...]}` 1 to 6 rows | `201 {payments: [Payment], order, warnings}`; **the sum of the amounts must equal the balance**, all rows or none, one `AuditedAction`, one `batch_uuid` | `422 payments.batch.sum_mismatch` `{balance, sum}`, `422 payments.batch.row_invalid` `{row, ...the row's own key}`, `409` as above |
| `GET /orders/{order}/payments` | `payments.view` | read | | `200 {data: [Payment], balance}`; each `Payment` = `{id, channel {id, code, name_en, name_am}, amount, tendered_amount, change_amount, reference_no, status, received_at, received_by {id}, shift_id, receipt {file_id, url, url_expires_at} or null, verified_at, disputed_at, dispute_reason, refunded_amount, void_reason, voided_at}`; the signed URL is made on read | `404` |
| `GET /payments` | `payments.view` | read, **polled** (ETag from `Payments::fingerprint()`: count, max id, max `updated_at`, plus the `settling` second while the newest row is younger than 5 s, the lesson of `Stock::fingerprint`) | `?from=&to=` (local dates), `?channel_id=`, `?status=`, `?shift_id=`, `?order_no=`, `?reference=`, `?missing_photo=1`, `?flagged=1` (duplicate reference/receipt or disputed), `?cursor=`, `?limit=` (50, max 200) | `200 {data: [Payment + order {id, order_no, table_code, total}], next_cursor, server_time}` newest first | `422 validation.failed` |
| `GET /payments/{payment}` | `payments.view` | read | | `200 Payment` with `order`, `refunds [PaymentRefund]` | `404` |
| `GET /payments/reconciliation` | `payments.verify` | read, polled | `?from=&to=` (max 62 days), `?channel_id=` | `200 {days: [{day, channels: [{channel, receipts, amount, with_photo, without_photo, with_reference, verified, disputed, voided, refunds, refund_amount, net_amount}]}], totals}` computed from `payments` and `payment_refunds` (this is Payments reading its own tables, not a summary) | |
| `GET /payments/export` | `payments.verify` | read | the same filters + `?format=csv|xlsx` | the file, at most 20 000 rows (`422 payments.export.too_large`), signed photo URLs are **not** in it | |

### 4.4 Verify, dispute, void and refund (Payments, steps 4.3c and 4.3d)

PIN rules follow `VoidOrder`: the asker acts as themselves (or as the acting-as person of a shared till), the approver's PIN is checked by the
controller **before** the transaction through `verifyPinFor('payments.void')`, so a wrong PIN counts toward the lockout even though nothing is
written; the PIN is never stored, logged or audited.

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `POST /payments/{payment}/verify` | `payments.verify` | key | `{note?}` | `200 Payment` `recorded or disputed -> verified`; a repeat answers `200`; emits `PaymentVerified` | `409 state_machine.illegal_transition` |
| `POST /payments/{payment}/dispute` | `payments.verify` | key | `{reason}` (3 to 255) | `200 Payment` `recorded -> disputed`; emits `PaymentDisputed` | `422 payments.dispute.reason_required`, `409` |
| `POST /payments/{payment}/void` | `orders.void_request` + approver PIN of a `payments.void` holder | key | `{reason, pin}` | `200 Payment` `status voided`; one `AuditedAction` `payment.void`; emits `PaymentVoided` (Orders' `ReversePayment` takes it out of `paid_total` and unsettles the order in the same transaction) | `409 payments.void.order_closed` (the order is `paid`/`closed`: refund it instead), `409 payments.void.refunded` (money already went back), `403 auth.pin_invalid`, `423 auth.pin_locked`, `422 payments.void.reason_required` |
| `POST /orders/{order}/refund` | `orders.void_request` + approver PIN of a `payments.void` holder | key + `client_uuid` | `{client_uuid, reason, pin, lines?: [{line_id, disposition: restock|waste|none}], allocation?: [{payment_id, amount}], refund_reference?, refund_proof_file_id?}`; no `lines` = the whole order with the default dispositions of 10.2 R-C | `201 {refund {id, amount, is_full}, payments: [PaymentRefund], movements: [{id, item_id, qty, reverses}], order}` (`200` replay). One `AuditedAction` `order.refund` calling `Orders::refund` (stock and order side) then writing the `payment_refunds` rows, all in one transaction under the order's row lock | `409 orders.refund.not_refundable` `{status}`, `409 orders.refund.line_refunded` `{line_ids}`, `409 orders.refund.window_closed` `{hours}`, `422 orders.refund.partial_not_allowed` (the order carries a discount or tax: only a whole-order refund is defined, 10.2 R-D), `409 inventory.shift.required` `{location_id}`, `409 payments.shift.required` (a cash refund needs the till's open shift, the drawer must lose the money inside a declared shift), `422 orders.refund.reason_required`, `403`, `423` |

Allocation of the money: the refund amount is the sum of the refunded lines' `line_total` (the whole `total` for a full refund). It is returned
over the order's payments **last in, first out** up to each payment's `amount - refunded_amount`, on the channel it was taken on. A cash share
is handed out of the drawer; a digital share is a transfer made outside the app, recorded with `refund_reference` (and optionally a photo of
the outgoing transfer). No payment is edited except its `refunded_amount`.

### 4.5 Receipts (Payments, step 4.3e)

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `GET /orders/{order}/receipt.pdf` | `payments.view` | read | `?locale=en|am|both` (default: the receipt settings) | `200 application/pdf` (dompdf, Noto Sans Ethiopic embedded as a subset, Western digits), at most 2 pages: business name/TIN, order no and date (Addis time, and the Ethiopian date when enabled), lines with modifiers, subtotal/discount/tax/total, one line per payment (channel, reference tail only: last 4 characters), change, refunds if any, footer | `404`, `409 payments.receipt.unpaid` (a receipt of an unpaid order is a pro-forma, header says so: `?proforma=1` only) |
| `POST /orders/{order}/receipt-link` | `payments.take` | key | `{ttl_hours?: 72}` (max 168) | `200 {url, expires_at}`: a signed link to the public receipt page | `404` |
| `GET /receipts/{order}` | **signed**, no bearer | read | | `200` the same PDF; no names of staff on it; `Cache-Control: private, no-store` | `403`, `404` |

### 4.6 Analytics (Analytics, steps 4.4b to 4.4d)

Everything under `/analytics` reads **only** the summary tables (and names through Catalog/Payments/Identity contracts). Common query:
`?from=YYYY-MM-DD&to=YYYY-MM-DD` local dates, default the last 30 days, at most 400 days (`422 analytics.range.too_large`), `to` not after
today (`422 analytics.range.future`), `?compare=previous` adds the same-length period before. All answers carry `as_of` (the oldest
`rebuilt_at` of the days they used) and `stale_days` (days in range with no `analytics_days` row). All are conditional: the ETag is built from
the days' `version`s, so a dashboard that polls every 30 s gets `304` until a rebuild moves it.

| Method + path | Permission | Idempotent | Response (`data`) |
|---|---|---|---|
| `GET /analytics/overview` | `analytics.view` | read | `{revenue, net_revenue, orders_count, avg_ticket, items_qty, refund_total, voided_total, by_type {...}, channels [{channel, amount, share}], top_items [5], previous {...}?}` |
| `GET /analytics/sales` | `analytics.view` | read | `?group=day|week|month` (weeks start Monday, local) `{series: [{key, orders_count, revenue, net_revenue, avg_ticket}]}` |
| `GET /analytics/sales/hourly` | `analytics.view` | read | `{hours: [{hour, orders_count, revenue}]}` summed over the range |
| `GET /analytics/channels` | `analytics.view` | read | `{channels: [{channel {id, code, name_en, name_am}, receipts_count, amount, refund_amount, net_amount, share, without_reference_count, without_photo_count, verified_count, disputed_count, duplicate_reference_count}], series}` |
| `GET /analytics/items` | `analytics.view` | read | `?kind=product|ingredient&category_id=&sort=revenue|units|margin|waste&limit=50` `{items: [{item {id, name_en, name_am, is_new}, units_sold_qty, revenue, cogs_total, margin_total, margin_pct, cogs_complete, produced_qty, waste_qty, stockout_count}]}` |
| `GET /analytics/items/{item}` | `analytics.view` | read | the product page: `{item, totals, series: [{day, units_sold_qty, revenue, margin_total, produced_qty, waste_qty, stockout_count, closing_on_hand_qty}], since_launch: {first_sale_day, weekly_units: [...]}}` (new products flagged) |
| `GET /analytics/stations` | `analytics.view`; `analytics.view_own` limited to the caller's stations | read | `{stations: [{station, lines_served, lines_rejected, avg_prep_seconds, p90_prep_seconds, batches_finished, produced_qty, waste_qty, variance_line_count, shifts_count}]}` |
| `GET /analytics/staff` | `analytics.view`; `analytics.view_own` limited to the caller | read | `?role_kind=waiter|cashier|station` `{users: [{user {id, name}, orders_count, revenue, payments_count, payments_amount, voids_count, refunds_count, lines_prepared, avg_prep_seconds}]}` |
| `GET /analytics/shifts` | `analytics.view` | read | `{days: [{day, shifts_count, shifts_locked_count, variance_line_count, cash_variance_total}]}` |
| `GET /analytics/freshness` | `analytics.view_own` | read, polled 30 s | `{as_of, days: [{day, version, rebuilt_at, trigger, changed}], running: bool}` (the dashboard's "updated 2 min ago" and its refetch trigger) |
| `POST /analytics/rebuild` | `analytics.manage` | key | `{from, to, sync?: bool}`; `sync` only for at most 7 days and runs inline (what the live E2E uses, no cron wait); otherwise one `RebuildDay` job per day is queued | `202 {queued: n}` / `200 {days: [...]}`; an `AuditedAction` `analytics.rebuild` records the range | `422 analytics.range.too_large` (31 days queued, 7 inline), `409 analytics.rebuild.running` |
| `GET /analytics/exports/{report}` | `analytics.view` | read | `report` in `sales`, `channels`, `items`, `stations`, `staff`, `shifts`; `?format=xlsx|pdf|csv`, `?locale=en|am`, the common range | the file (xlsx via a streaming writer, 20 000 rows max; PDF is a 1 to 3 page summary with the same figures and the freshness stamp); `422 analytics.export.too_large` |
| `POST /analytics/weekly-summary/send` | `analytics.manage` | key | `{week_of?}` | `202`; builds the same summary the Monday job does and announces `WeeklySummaryReady` (a way to test the Telegram path) | |

### 4.7 Audit (Audit, step 4.5a; a gap in the manifest, 10.9)

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `GET /audit-log` | `audit.view` (admin, manager: read) | read | `?from=&to=` (local dates), `?actor_id=`, `?action=` (prefix match: `payment.`), `?subject_type=`, `?subject_id=`, `?station_id=`, `?shift_id=`, `?cursor=` (id), `?limit=` (50, max 200) | `200 {data: [{id, at, actor {id, name}, acting_user {id, name}?, action, subject_type, subject_id, station_id, shift_id, device_id, ip, summary}], next_cursor}` newest first; `name` comes from Shared `ActorDirectory` (Identity binds it; Audit imports nothing else) | `422` |
| `GET /audit-log/{id}` | `audit.view` | read | | `200` the row with `before` and `after` JSON as stored (already redacted by `Snapshot`: no PIN, no `reference_no`, no signed URL, no file key) | `404` |
| `GET /audit-log/export` | `audit.export` (admin) | read | the filters + `?format=csv|xlsx`, at most 50 000 rows | the file | `422 audit.export.too_large` |

## 5. Domain events and listeners

Cross-module classes live in `App\Shared\Events` with array payloads (ids, decimal strings, bilingual strings; never a PIN, a key or a URL),
so Notifications and Analytics never import a producer.

| Event | Payload | Listeners (module) | Queued? |
|---|---|---|---|
| `PaymentRecorded` (exists) | adds `has_receipt`, `reference_dup` (bool) | Orders `ApplyPayment` (sync, same transaction; now also writes `payment.recorded`); Analytics `TouchToday` | sync; Analytics queued |
| `PaymentVerified` (new) | payment_id, order_id, channel_code, amount, by_user_id | Analytics `TouchToday` | queued |
| `PaymentDisputed` (new) | payment_id, order_id, channel_code, amount, reason, by_user_id, order_no, channel names en/am | Notifications `NotifyPaymentDisputed` (admins and managers: in-app, push, Telegram, deduped per payment), Analytics `TouchToday` | queued |
| `PaymentVoided` (new, in the manifest's event list but never built) | payment_id, order_id, channel_code, amount, reason, by_user_id, approved_by_user_id | Orders `ReversePayment` (**sync, in the voiding transaction**), Analytics `TouchToday` | sync + queued |
| `OrderRefunded` (new, Orders, after commit) | order_id, refund_id, amount, is_full, line_ids, movement_ids, by_user_id, approved_by_user_id, reason | Analytics `TouchToday` (and the refund's own day), Notifications `NotifyRefund` (admins: "a refund of 120.00 on order 17 approved by ...") | queued |
| `RefundPaid` (new, Payments, after commit) | refund_id, order_id, payment_refunds [{channel_code, amount}] | Analytics `TouchToday`; Shifts: nothing (it reads `Totals::cash`) | queued |
| `OrderPaid`, `OrderVoided` (exist) | | Analytics `TouchToday` | queued |
| `ShiftLocked` (exists, "no listener in Phase 3") | shift_id, station_id, date, locked_at, ... | Analytics `RebuildAfterShiftLocked`: the local dates from the shift's opening to its lock (one or two: a Night shift touches `date` and `date+1`), one unique job each | **yes** |
| `AnalyticsDayRebuilt` (new, Analytics) | day, trigger, version, changed | none (the freshness feed reads `analytics_days`); useful to tests | n/a |
| `WeeklySummaryReady` (new, Analytics) | week_start, week_end, headline figures, top items (en/am names), channel split, link (signed, 7 days) | Notifications `NotifyWeeklySummary` (admins; in-app + Telegram; push optional) | queued |

`TouchToday` is **debounced**: it dispatches `RebuildDay(branch, today, trigger=touch)` as a `ShouldBeUnique` job with `uniqueFor` = 120 s
and `delay` = 60 s, using the database cache's atomic lock (supported by the live host's `database` cache). So during service the dashboard's
today row is at most about three minutes behind (one cron tick plus the delay), the rebuild runs at most once every two minutes, and nothing
ever aggregates raw tables in a request. A day that is not today is rebuilt by the shift lock, the nightly job or by hand.

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
   - **4.1b `phase-4/orders-refund`**: M6 and M7 to M9; Inventory `Reversals` (inside the Ledger, same lock order, writes `return` plus the
     reversal row); Orders `Refund` action behind `Orders::refund`, `ReversePayment` listener, `payment.recorded` / `payment.voided` /
     `line.refunded` / `order.refunded` events on the timeline, `OrderRefunded`, `PaymentVoided` events, `OrderResource` gains
     `refunded_total` and `refunds`. `OrderStatus` is **not** changed (an order stays `closed` after a refund; `refunded_total` says how much
     came back), so the exhaustive state-machine table of Phase 1 does not move. Contract cases 5 to 13 of `payments->orders` and 13 to 15 of
     `orders->inventory` go green with Payments untouched; `VoidOrderTest` still refuses a paid order (`orders.order.paid`).
   - **4.1c `phase-4/facts-ports`**: M11; Shared `Time\LocalDay` (a `from`/`to` pair of local dates, `utcBounds(tz)`, `dayOf(instant, tz)`,
     inclusive ends; the branch's timezone, default `Africa/Addis_Ababa`); the five `Facts` classes with their readonly DTOs under each
     provider's `Contracts/` (`Orders\Contracts\Facts::sales/lines`, `Payments\Contracts\Facts::payments/refunds` (refunds read `payment_refunds`: M10 lands in
     this PR as schema only, nothing writes to it until 4.3d, so the port returns `[]` and its contract case seeds rows through a factory), `Production\Contracts\Facts::batches`,
     `Inventory\Contracts\Facts::movements/closingBalances/stockOuts/unitCosts`, `Shifts\Contracts\Facts::instances`); each returns a
     generator over a chunked, ordered query. M10 and M11 are in this PR. `contract:analytics->facts` green with `app/Modules/Analytics` absent. Groups added to the
     provider jobs. ModuleBoundariesTest: Analytics may use `*\Contracts\Facts*`, `Catalog\Contracts\*`, `Identity\Contracts\ActorDirectory`
     and `Shared\*` only; the arch test fails on any model import from Analytics.
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
   - **4.2b `phase-4/files-api`**: `POST /files`, `GET /files/{file}/url`, `GET /files/{file}` (signed), `DELETE`, `GET /files/doctor`, `files:purge-orphans`; `files.upload` and
     `payments.view` permissions (M2); the multipart branch of `Idempotency`; log scrub (a Monolog processor removes `signature=` and `expires=` from any logged URL and
     never logs a key); `FilesNoPublicAccessTest` (the object path is not under `public/`, `public/storage` has no `files`, a plain GET of `/files/{id}` without signature is 403);
     the portability job `files-s3` (Pest group `files-s3`: `put`, `signedUrl` fetched over HTTP, `delete` against **MinIO** in the e2e compose stack, see 10.1 R-F; `compose.ci.yml`
     gains `minio` and an `mc` init container creating the bucket, `AWS_ENDPOINT=http://minio:9000` for the app container versus `AWS_URL=http://localhost:9000` for the browser,
     the two-hosts trap in traps.md); runbook "Files" section (where they live, quota, backup, the move to S3). `shared.files` goes `done`.
3. **4.3 payments (full)** (STATUS `payments`), five PRs. Payments is `stable` again after 4.3e.
   - **4.3a `phase-4/channels-admin`**: M3; channel create, rename, reorder, switch off, soft delete, pattern validation (a test table of good and bad patterns, including
     `(a+)+$` refused), `cash_locked`, `GET /receipt-settings` placeholder; bilingual `lang/*/payments.php` keys. Tests: `ChannelAdminTest`, `ChannelPatternTest`,
     `ChannelOrderTest`, 403 matrix. `Channels::enabled()` keeps its shape (additive fields).
   - **4.3b `phase-4/payments-record-v2`**: M4; the new `RecordPayment` rules of 4.3 above, `payment-batches`, `GET /orders/{order}/payments`, `GET /payments` with `fingerprint()`,
     `GET /payments/{payment}`, the shift rule for every channel (10.1 R-A), duplicate warnings, the file attach (`attached_*`). **Phase 1 tests that change, listed so the PR is honest:**
     `RecordPaymentTest` (a digital channel without a photo used to be `payments.channel.photo_required` for *every* digital payment, now only without a `receipt_file_id`), the part-payment case
     of `payments.amount.mismatch` (now accepted), `ChannelsEndpointTest` (`accepted_now`), and `Phase1StateMachinesTest` for the new `disputed` exits. The Playwright Phase 1 flows only
     use cash and are untouched.
   - **4.3c `phase-4/payments-verify-void`**: `verify`, `dispute`, `void` with the PIN path, `PaymentVerified/Disputed/Voided` events, `NotifyPaymentDisputed` (Notifications, with its
     bilingual copy), the `payments.verify` permission; tests `PaymentVerifyTest`, `PaymentDisputeTest`, `PaymentVoidTest` (void a part payment of a served order and pay again; void refused for
     `paid`; void of a refunded payment refused), `PaymentVoidRaceTest` (a void racing a last payment: the order lock serialises, `concurrency` group).
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
     in memory, (5) `DELETE`s that `(branch, day)` from each summary table and bulk `INSERT`s the rows in primary-key order, (6) writes `version+1`, the fingerprint and `changed`. A failure rolls
     everything back, so a day is either the old rows or the new rows. Two rebuilds of unchanged facts produce **identical rows** (the fingerprint equals; only `rebuilt_at` and `version` move).
     The console `analytics:rebuild {--branch=} {--from=} {--to=} {--nightly} {--since-last} {--sync}`; the queued `RebuildDay` job (`ShouldBeUnique` by `branch:day`, `uniqueFor` 120, `tries` 3, a
     failure is logged with the day and never the facts). `CurrentBranch::runAs($branchId, ...)` is used for a queued or console rebuild (verify in the PR whether Phase 2's queued actor already
     provides it; if not, add the helper to Shared with its own test). Tests: `RebuildDayTest` (every column against a hand-built oracle for a fixture day), `RebuildIdempotentTest` (twice: same fingerprint, same rows),
     `RebuildBoundaryTest` (orders at 23:59 and 00:01 local, a Night shift, the day before and after), `RebuildRaceTest` (two forks, `concurrency` group, one wins, rows never doubled, never missing),
     `RebuildRollbackTest` (an exception in the middle leaves the old rows), `RebuildCogsTest`, `RebuildRefundDayTest` (a refund on D+1 of an order of D lands on D+1), ADR 0014 (proposed).
   - **4.4b `phase-4/analytics-triggers`**: the listeners of section 5 (`RebuildAfterShiftLocked`, `TouchToday` debounce), the scheduler entries, `POST /analytics/rebuild`, `GET /analytics/freshness`,
     `analytics.manage` and `analytics.view_own` use. Tests: `ShiftLockedTriggersRebuildTest` (a locked Night shift queues both days), `TouchTodayDebounceTest` (20 payments in a minute: one job),
     `NightlyScheduleTest` (the entries exist with `timezone('Africa/Addis_Ababa')`, `withoutOverlapping`; a `Carbon::setTestNow` run at 00:05 local rebuilds the two days), `RebuildEndpointTest`
     (403 matrix, 7 days inline, 31 queued, idempotent replay).
   - **4.4c `phase-4/analytics-dashboards`**: the read endpoints of 4.6, the scoping for `analytics.view_own`, `ConditionalPoll` ETags from the days' versions. Tests: one feature test per endpoint
     (happy path, range errors, 403 per role, `304`), `DashboardsReadSummariesOnlyTest` (the **query-log assertion**: every statement of every `/analytics/*` request touches only
     `daily_*`, `analytics_days`, and the name tables behind the Catalog/Payments/Identity contracts (`items`, `categories`, `payment_channels`, `users`, `stations`) plus the framework's own
     (`personal_access_tokens`, permission tables, `idempotency_keys`, `cache`); a statement on `orders`, `order_lines`, `payments`, `payment_refunds`, `stock_movements`, `stock_balances`,
     `production_*`, `shift_*`, `stock_counts*`, `audit_log` fails the test, and a planted violation proves the assertion bites), `DashboardNumbersTest` (each figure equals the oracle sum over the seeded day),
     `DashboardBudgetTest` (query count per endpoint <= 8, and 30 timed requests each; the substitute of 10.2 R-G).
   - **4.4d `phase-4/analytics-exports-weekly`**: exports (`openspout/openspout` for xlsx, dompdf for the PDF summaries, CSV), `WeeklySummary` builder (previous Monday to Sunday: revenue and
     orders against the week before, best and worst products by margin, channel split, refunds and voids, variance lines, stock-outs; reads summaries only), `analytics:weekly-summary`,
     `WeeklySummaryReady`, `NotifyWeeklySummary` in Notifications with bilingual copy and the signed 7-day PDF link. Tests: `ExportOpensTest` (the xlsx is read back with the same library: sheet names,
     header row in the requested locale, row count, numbers stored as numbers with the decimal string exactly), `ExportPdfTest`, `ExportTooLargeTest`, `WeeklySummaryTest` (figures equal the
     summaries; dispatches once for a Monday; `Event::fake` plus a `sync` twin that creates the inbox line), `NotifyWeeklySummaryTest` (Telegram driver array in CI). `analytics` `module-stable`.
5. **4.5 web** (STATUS `web`), six PRs, Next 15 static export under `/app`, en and am, 48 px targets, tablets first; pure rules in `lib/*.ts` tested without a browser (the Phase 3
   `*-logic.spec.ts` pattern). No chart library is added (10.8 R-H): `components/charts/{Sparkline,Bars,Line}.tsx` are small inline SVG with `role="img"` and a table fallback.
   - **4.5a `phase-4/audit-read-api`**: API only: M17, Shared `ActorDirectory` (+ Identity binding), the three audit routes, `audit.export`, `Snapshot` redaction additions (`receipt_file_id`, signed URL
     shapes). Tests: `AuditLogListTest`, `AuditLogFiltersTest`, `AuditExportTest`, `AuditIsALeafTest` (arch: Audit imports only Shared), 403 matrix (manager reads, cashier 403).
   - **4.5b `phase-4/web-pay-sheet`**: `components/till/PaySheet.tsx` replaced by the full sheet. Behaviour: amount due and balance at the top; one **row per payment**: a channel picker (enabled
     channels in admin order, the `cash` row first), an amount (defaults to the remaining balance, decimal string arithmetic in `lib/money.ts`), for cash the tendered amount and the change due, for
     a channel with `requires_reference` a reference field with the channel's hint and pattern checked live, for `requires_photo` a **camera button** (`<input type="file" accept="image/*" capture="environment">`)
     that downsizes in the browser (`lib/images/downscale.ts`: `createImageBitmap(..., {imageOrientation:'from-image'})`, canvas, longest side 1600, JPEG 0.82, falls back to the original file if decoding
     fails), uploads with its own `client_uuid` and Idempotency-Key (a retry reuses both), shows the thumbnail and a replace button; **Pay stays disabled** until every row is complete (photo
     uploaded, reference valid, amounts sum to exactly the balance, a till shift running when `NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH` is on, which now covers every channel); Complete posts
     `payment-batches` once with one key; a network failure keeps the sheet and its uploaded photos (the photo ids are already on the server) and the retry is safe; offline says so (the outbox is Phase 5).
     Receipt after completion: preview, download PDF, copy link (`receipt-link`). Pure rules in `lib/paySheet.ts` (`rowsComplete`, `remaining`, `canComplete`, `blockReason`, `changeDue`) tested in
     `e2e/p4/pay-sheet-logic.spec.ts`. The `VoidDialog` gains the refund path for a closed order (lines to refund, disposition per line with the 10.2 R-C defaults, reason, manager PIN).
   - **4.5c `phase-4/web-admin-payments`**: `/admin/payments` with tabs Channels (list, add, edit, reorder with up/down buttons not drag-only, switch, rules, hints, the cash row locked), Payments
     (the polled list with filters, photo thumbnail opens a viewer with a fresh signed URL, verify and dispute buttons, flagged chips), Reconciliation (per day and channel, with a "missing photo" drill),
     Receipts (the settings form). Export button. `lib/api/adminPayments.ts`, `lib/live/payments.ts` (poll 10 s visible, 30 s hidden, 304 keeps state).
   - **4.5d `phase-4/web-order-timeline`**: `/admin/orders/[id]` (record page by `?id=`, export-safe) merges `GET /orders/{order}` (events) with `GET /orders/{order}/payments` (photo thumbnails, signed
     on read, re-signed when a viewer opens after 5 min), shows void/refund/payment events with who approved, and the refund action. The orders board links to it.
   - **4.5e `phase-4/web-analytics`**: `/admin/analytics` (Overview with period compare, Sales by day/week/month and by hour, Channels, Products with the product page `/admin/analytics/product?id=`,
     Stations, Staff, Shifts), a date-range control (today, 7, 30, 90 days, custom), `as_of` badge, stale-day notice with a Rebuild button for `analytics.manage`, export buttons, skeletons (first paint
     of data is what `p4-dashboards` times), role-limited view for `analytics.view_own` (one page `/admin/analytics/me`). `lib/analytics.ts` (range maths in the branch's local dates, week = Monday).
   - **4.5f `phase-4/web-audit-ethiopian`**: `/admin/audit` (filters, the detail drawer with the before/after diff, export for admin), and the optional Ethiopian date display (`lib/ethiopianDate.ts`, a pure
     converter with a unit table for the new year, Pagume 5 and 6 and the leap rule, a per-user display preference; the receipt prints it when `ethiopian_dates` is on). Ethiopian dates are labels only: nothing stored changes.
6. **4.6 docs and seeds** (STATUS `docs`), two PRs.
   - **4.6a `phase-4/seed-history`**: the real `Phase4Seeder` and `DemoClock` generalisation (section 8). It lands before 4.6b because the gate tests and `perf-dashboards` run on its data;
     it may be started as soon as 4.1 is done if the order needs it (`Phase3Seeder` and `DemoClock` exist since Phase 3 closed).
   - **4.6b `phase-4/gate`**: the `gate:p4` tests (section 7), the `gate-p4` and `perf-dashboards` jobs wired, ADR 0014/0015/0016 accepted and the dated notes on ADR 0006/0009/0013,
     `docs/openapi.json` and `docs/erd.dbml` final, `docs/runbook/server.md` (Files, Receipts, Analytics sections: cron timing, rebuild by hand, the quota, what to do when `analytics_days` says
     `stale`), `domain-model.md`, `dependency-graph.md`, `invariants.md`, `traps.md`, `testing-strategy.md`, `ci-cd.md`, `workflows-and-screens.md` and the phase reference corrected (10.9),
     the Playwright `fixme`s of 4.1a turned into tests, `phase-4-report.md`, STATUS `claim-done`.

As-built notes are appended under each step as the build runs, as in Phases 1 to 3.

## 7. Test list

- **Unit** (no database): `PaymentStatusTest` (the exhaustive `from x to` table with the new `disputed` exits), `ImageProcessorTest`, `ExifOrientationFallbackTest`, `ChannelPatternTest`,
  `RefundAllocationTest` (LIFO over splits, remainder cents, never more than a payment's open amount), `RefundDispositionDefaultsTest`, `LocalDayTest` (23:59/00:01 Addis, `utcBounds`, week start Monday),
  one test per builder (`SalesBuilderTest` ... `UserBuilderTest`) with array facts, `FingerprintTest` (order-independent, scale-sensitive), `EthiopianDateTest` (web), `paySheet` logic (web).
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
  - `p4-pay-photo-split` (`testMatch /pay/`): `pay-photo-split.spec.ts` @live (the gate), `pay-sheet-logic.spec.ts` (pure), `pay-refund.spec.ts` @live, `pay-void.spec.ts` @live, `pay-camera-fallback.spec.ts`.
  - `p4-dashboards` (`testMatch /dashboards/`): `dashboards.spec.ts` (CI, the 90-day seed: first KPI visible under 1000 ms), `dashboards-live.spec.ts` @live (exact figures on a run-unique channel,
    soft budget), `dashboards-audit.spec.ts` @live (the audit screen shows this run's refund with its approver), `dashboards-logic.spec.ts` (range maths).
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
and `DemoClock` puts the real clock back in a `finally`. Today is left for the live demo: open shifts, a few sample orders.

**The day plan** (seeded `mt_srand(4040)`, so the same 90 days every run): weekdays about 22 orders, weekends about 34 (about 2 000 orders in all, 6 000 lines); a channel mix of (cash 45%, Telebirr 30%,
CBE Birr 10%, bank transfers 15%); 12% of paid orders are **split** (a digital part with a photo and the rest in cash); every digital payment carries a generated receipt photo (five small GD-drawn
images, EXIF-free, cycled) and a reference `SEED-{yymmdd}-{n}`; about 1.5% of payments are **voided** before the order is paid (and the order re-paid in cash) and 2% of orders are **refunded** (display-case
lines restocked, station lines `waste`, a few lines `none`), a handful by line; 40% of digital payments are verified, 3% disputed (a few resolved); 1% of sent orders are voided; the
display case and the bakery are kept stocked by daily production batches and weekly purchases so stock-outs are rare and planned (three days in the 90 have one, with the production request it
causes); one Day shift per tracked station per day, closed and locked with the closing count equal to the ledger except on five planned days (a kitchen spoilage, a till cash difference); a Night
shift at the bakery every Friday. Dates and volumes are constants in `Demo\DayPlan` so a test can state them.

**What the seed guarantees**, asserted by `Phase4SeederTest`: all Phase 0 to 3 history assertions still hold (every place's chain verifies, `expected_qty` equals `balanceAt`); the ledger oracle holds
after every refund; for every day `SUM(payments.amount WHERE status <> 'voided')` per channel equals `daily_channel_totals.amount` and counts match; `daily_sales.revenue` equals `SUM(orders.total)`
of paid/closed orders settled that day; `Σ payments - Σ payment_refunds` equals `Σ order totals - Σ refunded_total`; a second run writes nothing, not even an audit row; a run into a branch that is not a demo
branch is refused. Estimated cost: about 2 000 orders through the real actions at 80 to 150 ms each is 3 to 5 minutes; the plan's budget is **under 10 minutes in `e2e-p4`/`gate-p4`**; if 4.6a measures
more, the fallback is `DEMO_HISTORY_DAYS` (60) in CI only, with the 90-day figure kept for `perf-dashboards` (decision recorded in the PR; 10.2 R-I).

**What the earlier seeders must change.** Nothing beyond the clock anchor parameter. `Phase1Seeder` already pays in the till's running shift (3.7e). Channels, `payment_channels` defaults and the
permission grants reach the database by migrations, not by the seeder.

**The live demo branch.** "Demo Bakery" on https://bakery-ops.orcamind.dev was loaded by hand at an earlier phase and has data of its own. **`bakery:seed-demo --phase=4` never writes history into it**: if the
branch has any order, shift or movement the seeder prints "history not generated: the demo branch already has data; load a new demo branch" (the Phase 3 rule), adds nothing to the ledger, and only (a) makes sure the
eight channels and the receipt settings exist, (b) runs `Rebuild::day` for every day that has data (read only on the ledger, so the dashboards show the live demo's real orders), and (c) if the demo branch has no open
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
reconciliation. Implementation without a fourth kill-switch line: `config/payments.php` gains `require_shift`, defined as
`env('PAYMENTS_REQUIRE_SHIFT', env('PAYMENTS_REQUIRE_SHIFT_FOR_CASH', true))`, and `RecordPayment::assertShift` applies for
`(channel is cash && require_shift_for_cash) || require_shift`. The existing line `PAYMENTS_REQUIRE_SHIFT_FOR_CASH=false` in the server `.env` (then
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
| `payments.verify` | admin, manager | verify, dispute, reconciliation, export, deleting an unattached file |
| `analytics.manage` | admin | rebuild, weekly-summary test |
| `audit.export` | admin | audit export |

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
19. **Dashboards "hit no raw tables"** needs a definition because names come from `items`, `categories`, `payment_channels`, `users`, `stations`: the allowlist of 4.4c.

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
| Phase scope creep | building OCR, WhatsApp links, ESC/POS because the schema is there | out of scope and written down: OCR of receipts, direct bank verification, ESC/POS, outbox, email; no `TODO(p4)` markers may remain at the gate |
| Test debt | `->skip(` or `fixme` left in a gate project | the 4.1a Playwright `fixme`s are listed in the PR and removed by 4.6b; `gate_check --mode done` enforces |
| Contract before consumer | writing Payments or Analytics code before the contract is green | 4.1a commits the files red, 4.1b/4.1c turn them green, `app/Modules/Analytics` does not exist until 4.4a |
