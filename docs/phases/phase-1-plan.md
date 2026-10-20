# Phase 1 plan — Ordering and live KDS

Status: **approved under Ramin's standing approval** (2026-10-19, "proceed to the next phase without waiting for my
permission"; `plan_approved_by` in `docs/phases/STATUS.json`). Written at the start of the phase from
`references/phases/phase-1-ordering-kds.md`, the manifest (`phases["1"]`, `contracts["orders->*"]`,
`contracts["payments->orders"]`), `docs/architecture.md` sections 4.1, 4.2, 4.5, 5 and 6, and the Phase 0 report. The
sections below are the plan; an "As built" note follows a section only where the build deviates from it.

Decisions written down as ADRs in this phase: ADR 0008 (reserve at send, consume at ready, sale at pay; one
reservation per order line) and ADR 0009 (live updates through a transport abstraction, polling first on the live
host). The ADRs of Phases 2-4 move up by one (0010-0014) so numbering stays sequential.

## 1. Entry check

`python scripts/gate_check.py --mode entry --phase 1`. Upstream modules this phase consumes, all `stable` since Phase 0:
shared (2026-10-19T09:57Z), audit (10:51Z), identity (15:17Z), catalog (16:55Z), inventory (22:10Z). Phase 0 is `done`
at `e57b791` (tag `phase-0-complete`). `payments->orders` is intra-phase (its provider, Orders, is built in this
phase): the gate skips it at entry and it is written at step 1.4, before any Payments code.

At plan approval (after the standing-approval commit, before step 1.1; `--no-tests`, so Pest groups are not run).
The three failures are the contract files step 1.1 writes, as expected at this point:

```
$ python scripts/gate_check.py --mode entry --phase 1 --no-tests   # at 494a9ac
== gate_check: entry phase 1 (Ordering and live KDS)
  [PASS] phase 0 done                     status=done done_commit=e57b791878396338f12158b6737df4950e1ba491
  [PASS] phase 0 commit in history        e57b79187839 is an ancestor of HEAD
  [SKIP] gate:p0 green                    pest --group=gate:p0 not run (--no-tests/--ci)
  [PASS] clean working tree               clean
  [PASS] phase plan exists                docs/phases/phase-1-plan.md
  [PASS] plan approved by Ramin           plan_approved=True by standing approval: Ramin, 2026-10-19
  [PASS] approval author                  obssa77@gmail.com
  [FAIL] contract orders->inventory file  missing apps/api/tests/Contract/Orders/InventoryContractTest.php
  [FAIL] contract orders->catalog file    missing apps/api/tests/Contract/Orders/CatalogContractTest.php
  [FAIL] contract orders->identity file   missing apps/api/tests/Contract/Orders/IdentityContractTest.php
  [SKIP] contract payments->orders        intra-phase: provider orders is built in phase 1; write it before step 'payments', checked by --mode module --module orders
  [PASS] dep orders->inventory stable     stable
  [PASS] dep orders->catalog stable       stable
  [PASS] dep orders->identity stable      stable
  [PASS] dep orders->shared stable        stable
  [PASS] dep orders->audit stable         stable
  [PASS] dep payments->identity stable    stable
  [PASS] dep payments->shared stable      stable
  [PASS] dep payments->audit stable       stable
  [SKIP] Phase1Seeder                     not yet (created during the phase)
== result: FAIL (3 failed, 0 warnings, 20 checks)
```

After step 1.1 (contract tests written, provider fixes in, tests run in Docker), the entry gate passes:

```
$ python scripts/gate_check.py --mode entry --phase 1   # at 33f64c5, Pest in the app container
== gate_check: entry phase 1 (Ordering and live KDS)
  [PASS] phase 0 done                      status=done done_commit=e57b791878396338f12158b6737df4950e1ba491
  [PASS] phase 0 commit in history         e57b79187839 is an ancestor of HEAD
  [PASS] gate:p0 green                     group gate:p0: 303 passed (57695 assertions)
  [PASS] clean working tree                clean
  [PASS] phase plan exists                 docs/phases/phase-1-plan.md
  [PASS] plan approved by Ramin            plan_approved=True by standing approval: Ramin, 2026-10-19
  [PASS] approval author                   obssa77@gmail.com
  [PASS] contract orders->inventory file   apps/api/tests/Contract/Orders/InventoryContractTest.php
  [PASS] contract orders->inventory first  consumer unchanged since previous gate
  [PASS] contract orders->inventory green  group contract:orders->inventory: 12 passed (263 assertions)
  [PASS] contract orders->catalog file     apps/api/tests/Contract/Orders/CatalogContractTest.php
  [PASS] contract orders->catalog first    consumer unchanged since previous gate
  [PASS] contract orders->catalog green    group contract:orders->catalog: 5 passed (50 assertions)
  [PASS] contract orders->identity file    apps/api/tests/Contract/Orders/IdentityContractTest.php
  [PASS] contract orders->identity first   consumer unchanged since previous gate
  [PASS] contract orders->identity green   group contract:orders->identity: 9 passed (89 assertions)
  [SKIP] contract payments->orders         intra-phase: provider orders is built in phase 1; write it before step 'payments', checked by --mode module --module orders
  [PASS] dep orders->inventory stable      stable
  [PASS] dep orders->catalog stable        stable
  [PASS] dep orders->identity stable       stable
  [PASS] dep orders->shared stable         stable
  [PASS] dep orders->audit stable          stable
  [PASS] dep payments->identity stable     stable
  [PASS] dep payments->shared stable       stable
  [PASS] dep payments->audit stable        stable
  [SKIP] Phase1Seeder                      not yet (created during the phase)
== result: PASS (0 failed, 0 warnings, 26 checks)
```

## 2. Contract tests to write first (green before any Orders code)

Written at step 1.1 from Orders' point of view, using only provider classes and factories; `apps/api/app/Modules/Orders`
stays exactly as Phase 0 left it (its `.gitkeep`). Each file runs in its provider's CI job for the life of the project
(`mod-inventory`, `mod-catalog`, `mod-identity`). Cases marked "added" were not in the manifest; this plan appends them
to `contracts[...].cases`.

| Contract | File | Cases |
|---|---|---|
| `orders->inventory` | `apps/api/tests/Contract/Orders/InventoryContractTest.php` | `reserve()` for two lines sharing an ingredient reserves the sum (one `reservation` row per ingredient, base units, waste included), and with one ref per line (`reserveMany`) each line holds its share while the balance holds the sum · `Shortfall` names the ingredient, `qty_short` in the base unit and the station, carries the short line's ref (lines are covered in the order sent), and nothing is written · `release(ref)` twice is a no-op, `releaseMany` skips settled and unknown refs · `consume(ref, actual)` writes exactly one `production_consume` per ingredient and reduces `reserved` by the reserved amount (as reserved, and with less actually used); a second consume writes nothing · lock order is deterministic: 50 shuffled orders of the same lines lock the balance rows in one identical ascending sequence (query log) · `Availability::bulk` matches `floor(min(available / qty_per_unit))` for `to_order`, the product's own available stock for `to_stock` / `bought_in`, held stock excluded, never below zero · added: a line's modifier deltas change what is reserved ("no onion" is not refused when onion is out, "extra chicken" reserves the extra, an optional line only when a modifier adds it) · added: display-case lines are sold at payment with `consumeMany(refs, sale)`, one `sale` per product carrying its ref · added: a void releases every open line of the order with one `releaseMany`; consumed lines stay consumed · added: `Locations::forProduct` names the stock location of a line (its station's location; the display case for counter products) · added: every movement carries the pad account as `user_id`, the waiter as `acting_user_id`, the station and the device |
| `orders->inventory` (concurrency) | `apps/api/tests/Contract/Orders/InventoryLockOrderConcurrencyTest.php` (groups `contract:orders->inventory`, `concurrency`; `CommitsForConcurrency`) | lock order is deterministic: 50 rounds of two orders whose lines share four ingredients, sent in opposite line orders by two real processes (`pcntl_fork`) inside Orders-style outer transactions with no retry, never deadlock (no error in either process, `Innodb_deadlocks` unchanged) and leave `reserved` equal to the sum of the reservations |
| `orders->catalog` | `apps/api/tests/Contract/Orders/CatalogContractTest.php` | `Prices::at(sent_at)` returns the price effective then, not now (history written through `PriceHistory`, as the admin endpoint does; null before the first price) · `Routing::stationFor` is null for display-case products (kitchen and bar for routed ones; `productionStationFor` still names the cake room) · `Modifiers::for` returns price deltas as money and recipe deltas in base units · added: `Recipes::activeFor` gives the version a line snapshots, `Recipes::version(product, n)` still answers an older version after a newer one became active · added: `Items::isNew` through `is_new_until` in the branch's local date; `Items::fulfilment` tells a station line from a counter line |
| `orders->identity` | `apps/api/tests/Contract/Orders/IdentityContractTest.php` | `verifyShortCode` rejects a code from another branch (and finds the right person when two branches share a code) · the acting-as session expires at 10 minutes: through the middleware stack every Orders write will carry, a write at 9:59 stamps the waiter as acting user, at 10:00 it is `401 identity.acting_session.expired` · `verifyPinFor('orders.void')` accepts a manager (and the admin) and returns the approver, not the asker; rejects a cashier's and another branch's manager's code (`403 identity.pin.rejected`); five wrong PINs lock it (`423`) · added: `can(user, 'orders.accept', station)` is true for the chef at the kitchen and the barista at the bar, false for the chef at the bar, true for admin and manager anywhere, false for waiter and cashier · added: the order permissions follow the section 7 matrix (`orders.create`, `orders.accept`, `orders.void`, `orders.void_request`, `tables.manage`) |
| `payments->orders` | `apps/api/tests/Contract/Payments/OrdersContractTest.php` (step 1.4, intra-phase) | `Orders::totals` returns total, paid, balance as decimal strings · `PaymentRecorded` with balance zero moves the order to paid and writes `sale` movements for its display-case lines (then closed) · added: a payment recorded before the last line is served settles the order, which becomes paid and closed when that line is served · Phase 4: `Orders::refund` |

Provider changes the contracts require (step 1.1, each with its own module tests extended; listed again in the PR):

- **Inventory** `Reservations::reserveMany(array<ref, list<ReserveLine>>)`, `releaseMany(list<ref>)`,
  `consumeMany(list<ref>, MovementReason)`: an order's lines in one ascending lock pass (ADR 0008, decision 3); the
  single-ref methods become the one-ref case of these. `ReserveLine` takes per-unit recipe deltas (modifiers);
  `Shortage` carries the ref of the line it belongs to. New `Contracts\Locations` (`forProduct`, `forStation`,
  `displayCase`): where a line's stock is, so Orders never reads Inventory models.
- **Identity** `PermissionRegistry` gains `tables.manage` (admin) for the dining tables; every other permission Orders
  needs (`orders.create`, `orders.accept`, `orders.void`, `orders.void_request`) exists since Phase 0.
- **Catalog**: none expected.

As built (step 1.1): the contracts were committed red first (`10f8fd3`); `orders->catalog` was green against Catalog
as it is, `orders->inventory` and `orders->identity` failed exactly on the capabilities above. The provider fixes
are `53c3371` (Inventory: `reserveMany` / `releaseMany` / `consumeMany` with one ascending lock pass over the union of
the rows, `ReserveLine` per-unit deltas and `ReserveLine::withModifiers`, `Shortage::$ref`, `Shortfall::refs()` /
`for()`, `Contracts\Locations`; module tests extended in `ReservationsTest.php` and the new `StockLocationsTest.php`,
committed red first in `f37c51a`) and `2c74826` (Identity: `tables.manage`, `PermissionRegistryTest` row). Phase 0's
`reserve` / `release` / `consume` behave as before (module, property and lock tests unchanged and green). The
concurrency test was checked against a provider that locks line by line: it deadlocked in every one of the 50 rounds;
with the single ascending pass it passes with the engine's deadlock counter unchanged. `tests/Pest.php` now gives
`RefreshDatabase` to `Contract/*/*ContractTest.php`, so a `*ConcurrencyTest.php` contract file can commit its data.
`tables.manage` reaches existing databases (the live site) in step 1.3e through a migration that adds the registry's
missing permissions to spatie's tables and grants them to their default roles, never revoking anything; until then no
route uses it.

## 3. Migrations (in order)

All tables carry `branch_id` (FK, indexed); money is `DECIMAL(12,2)`, quantities `DECIMAL(14,4)` in base units;
enums are `VARCHAR` plus a named `CHECK` (MariaDB 10.11, portable to PostgreSQL); times are `DATETIME` in UTC; actor
stamp = `created_by_user_id` (or the row's own `*_by_user_id`), `acting_user_id?`, `shift_instance_id?` (no FK until
Phase 3), `station_id?`, `device_id?`. Orders, lines and payments are never deleted (cancelled / voided by status), so
they carry no `deleted_at` and are allow-listed in `tests/Arch/SoftDeletesTest.php` with that reason.

1. `2026_10_21_100100_create_dining_tables_table` (Orders): `id`, `branch_id`, `code` VARCHAR(16), `name_en`,
   `name_am`, `seats` TINYINT UNSIGNED NULL, `sort` SMALLINT, `is_active` BOOL, `created_by_user_id`, timestamps,
   `deleted_at` (soft delete). Unique `(branch_id, code)`.
2. `2026_10_21_100200_create_order_counters_table` (Orders): `id`, `branch_id`, `business_date` DATE (the branch's
   local date), `last_no` INT UNSIGNED, `updated_at`. Unique `(branch_id, business_date)`; the row is locked
   `FOR UPDATE` to number an order (short daily numbers the till and the kitchen call out).
3. `2026_10_21_100300_create_orders_table` (Orders): `id`, `branch_id`, `client_uuid` UUID, `order_no` INT UNSIGNED,
   `business_date` DATE, `type` (`dine_in | takeaway | delivery | advance`), `dining_table_id` FK NULL, `table_code`
   VARCHAR(16) NULL (snapshot), `status` (`open | sent | in_progress | ready | served | paid | closed | cancelled |
   voided`), `created_via` (`waiter_pad | till | admin`), `owner_user_id` FK (the acting waiter, else the creator: whose
   order it is), `created_by_user_id` FK, `acting_user_id` FK NULL, `shift_instance_id` NULL (stays null until Phase
   3), `station_id` FK NULL, `device_id` FK NULL, `subtotal`, `discount` (0 in Phase 1), `tax` (0, VAT off), `total`,
   `paid_total` DECIMAL(12,2) (kept by the `PaymentRecorded` listener), `currency` CHAR(3), `notes` TEXT NULL,
   `sent_at`, `settled_at`, `paid_at`, `closed_at`, `cancelled_at`, `cancel_reason` NULL, `voided_at`,
   `void_reason` NULL, `voided_by_user_id` FK NULL (who asked), `void_approved_by_user_id` FK NULL (whose PIN), `due_at`
   NULL (advance orders, Phase 2), `version` INT UNSIGNED, timestamps. Unique `(branch_id, client_uuid)`, unique
   `(branch_id, business_date, order_no)`; indexes `(branch_id, status)`, `(branch_id, owner_user_id, status)`,
   `(dining_table_id, status)`.
4. `2026_10_21_100400_create_order_lines_table` (Orders): `id`, `branch_id`, `order_id` FK, `client_uuid` UUID,
   `item_id` FK, `qty` DECIMAL(14,4) (units of the product), `unit_price_snapshot` DECIMAL(12,2)
   (`Prices::at(sent_at)`), `modifier_price_snapshot` DECIMAL(12,2) (sum of the modifiers' price deltas per unit),
   `line_total` DECIMAL(12,2), `recipe_version` INT UNSIGNED NULL (the active recipe at send, `to_order` only),
   `station_id` FK NULL (null for display-case lines), `location_id` FK (where its stock is reserved),
   `reservation_ref` UUID (unique; UUIDv5 of the line's client UUID, ADR 0008), `status` (`queued | accepted | ready |
   served | rejected | voided`), `notes` VARCHAR(500) NULL, `accepted_by_user_id` / `accepted_at`,
   `prepared_by_user_id` / `ready_at`, `served_by_user_id` / `served_at`, `rejected_by_user_id` / `rejected_at` /
   `reject_reason` (`ingredient_spoiled | equipment_down | out_of_stock | other`) / `reject_note`, `created_by_user_id`,
   `acting_user_id` NULL, `shift_instance_id` NULL, `device_id` NULL, `version` INT UNSIGNED, timestamps. Unique
   `(branch_id, client_uuid)`, unique `reservation_ref`; indexes `(order_id)`, `(branch_id, station_id, status)`.
5. `2026_10_21_100500_create_order_line_modifiers_table` (Orders): `id`, `branch_id`, `order_line_id` FK,
   `modifier_id` FK, `name_en`, `name_am` (snapshot), `price_delta_snapshot` DECIMAL(12,2), `recipe_delta_snapshot`
   JSON (`[{ingredient_item_id, qty, unit}]`, base units, decimal strings), `created_at`. Written once with the line.
6. `2026_10_21_100600_create_order_events_table` (Orders), **append-only**: `id`, `branch_id`, `order_id` FK,
   `order_line_id` FK NULL, `station_id` FK NULL (the line's station: the KDS cursor), `owner_user_id` FK (the pad and
   till cursors), `event` (`order.created | order.lines_replaced | order.sent | order.send_refused | line.queued |
   line.accepted | line.ready | line.rejected | line.served | line.voided | order.in_progress | order.ready |
   order.served | order.settled | order.paid | order.closed | order.cancelled | order.voided`), `user_id` FK,
   `acting_user_id` FK NULL, `approved_by_user_id` FK NULL (the void PIN's owner), `actor_station_id` NULL,
   `device_id` NULL, `shift_instance_id` NULL, `payload` JSON (reason codes, totals, shortfall), `created_at`
   DATETIME(6). Indexes `(branch_id, station_id, id)`, `(branch_id, owner_user_id, id)`, `(order_id, id)`. Triggers
   `order_events_forbid_update_delete_update` / `_delete` (`SIGNAL SQLSTATE '45000'`) created when
   `in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)`, and `forbid_update_delete()` for `pgsql`, exactly the
   `audit_log` / `stock_movements` pattern; `tests/Invariants/AppendOnlyTest.php` gains the table.
7. `2026_10_21_110100_create_payment_channels_table` (Payments, final schema): `id`, `branch_id`, `code` VARCHAR(32),
   `name_en`, `name_am`, `is_enabled` BOOL, `requires_photo` BOOL, `requires_reference` BOOL, `sort` SMALLINT,
   `created_by_user_id` NULL, timestamps, `deleted_at` (soft delete: a removed channel stays in history). Unique
   `(branch_id, code)`. The migration inserts the eight defaults for every existing branch (cash: no photo, no
   reference; telebirr, cbe_birr, cbe_bank, awash, dashen, abyssinia, coop: photo and reference required; all enabled,
   sort 1-8, bilingual names); new branches get them from a listener on `BranchCreated` (section 5).
8. `2026_10_21_110200_create_payments_table` (Payments, final schema): `id`, `branch_id`, `client_uuid` UUID, `order_id`
   FK, `channel_id` FK, `amount` DECIMAL(12,2), `tendered_amount` DECIMAL(12,2) NULL (cash), `change_amount`
   DECIMAL(12,2) NULL, `currency` CHAR(3), `reference_no` VARCHAR(64) NULL (never logged), `receipt_photo_key`
   VARCHAR(255) NULL (Phase 4, object-store key, signed on read), `status` (`recorded | verified | disputed | voided`),
   `received_by_user_id` FK, `acting_user_id` NULL, `shift_instance_id` NULL, `station_id` NULL, `device_id` NULL,
   `received_at`, `verified_by_user_id` / `verified_at` NULL, `voided_by_user_id` / `voided_at` / `void_reason` NULL
   (Phase 4), timestamps. Unique `(branch_id, client_uuid)`; indexes `(order_id)`, `(branch_id, received_at)`.

`docs/erd.dbml` gains each table in the PR that migrates it (`erd-check` requires every migrated table).

## 4. Endpoints

All under `/api/v1`, bearer auth (`auth:sanctum`, `actor`), permission middleware, `Idempotency-Key` on every write
plus a `client_uuid` where a row is created (ADR 0003); the pad sends `X-Acting-Session` and `X-Device-Id`. Money and
quantities are decimal strings. Every error is `{message_key, message_en, message_am, ...}` with the key in
`lang/{en,am}/orders.php` or `payments.php` (or the existing shared, identity and inventory keys). State transitions
are actions, never PATCHes of `status`.

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| `POST /orders` | `orders.create` | key + order and line `client_uuid` (a replay with a lost key returns the same order) | `{client_uuid, type: dine_in\|takeaway, dining_table_id?, notes?, send?: true, lines: [{client_uuid, item_id, qty: "2", modifier_ids?: [..], notes?}]}` | `201` Order (status `sent`, or `open` when `send` is false) | `409 orders.send.shortfall` `{order (open), shortfall: [{line_client_uuid, line_id, item_id, ingredient_item_id, ingredient_name_en, ingredient_name_am, qty_short, unit, station_id, station_name_en, station_name_am}]}`; `422 validation.failed`, `orders.item.not_sellable`, `orders.item.no_price`, `orders.item.no_recipe`, `orders.item.no_location`, `orders.modifier.not_of_item`, `orders.table.unknown`; `401 identity.acting_session.expired`; `403 auth.forbidden` |
| `PUT /orders/{order}/lines` | `orders.create` (own order, or `stations.all`) | key | `{lines: [...]}` (replaces the open order's lines) | `200` Order | `409 orders.order.not_open`; the `422`s above |
| `POST /orders/{order}/send` | `orders.create` | key; a sent order answers `200` with itself | `{}` | `200` Order (`sent`) | `409 orders.send.shortfall`, `409 orders.order.not_open` (cancelled, voided) |
| `POST /orders/{order}/cancel` | `orders.create` | key | `{reason?}` | `200` Order (`cancelled`) | `409 orders.order.not_open` (only an unsent order is cancelled; a sent one is voided) |
| `POST /order-lines/{line}/accept` | `orders.accept` at the line's station | key | `{}` | `200` Line + order status | `403 orders.station.forbidden`; `409 state_machine.illegal_transition` |
| `POST /order-lines/{line}/ready` | `orders.accept` at the station | key | `{}` | `200` Line (consumes the reservation) | `403`, `409` as above |
| `POST /order-lines/{line}/reject` | `orders.accept` at the station | key | `{reason: ingredient_spoiled\|equipment_down\|out_of_stock\|other, note? (required for other)}` | `200` Line (releases the reservation) | `403`, `409`, `422 validation.failed` |
| `POST /order-lines/{line}/served` | `orders.create` | key | `{}` | `200` Line (+ order paid and closed when it was settled) | `409 state_machine.illegal_transition` |
| `POST /orders/{order}/void` | `orders.void_request` (asker) + the PIN of an `orders.void` holder | key | `{reason, pin}` | `200` Order (`voided`, `voided_by`, `void_approved_by`) | `403 identity.pin.rejected`, `423 identity.short_code.locked`, `409 orders.order.paid` (refunds: Phase 4), `409 orders.order.not_voidable` |
| `GET /orders` | `orders.create` | read | `?mine=1` (forced for roles without `stations.all` or `payments.take`), `?unpaid=1`, `?status=active\|...`, `?dining_table_id=`, `?since=<cursor>`; `If-None-Match` | `200 {data: [Order], cursor, server_time}` + `ETag`; `304` | `403` |
| `GET /orders/{order}` | `orders.create` (own order unless `stations.all` / `payments.take`) | read | | `200` Order with its `events` timeline | `404` (another branch, or not yours) |
| `GET /stations/{station}/tickets` | `orders.accept` at that station | read | `?since=<cursor>`; `If-None-Match` | `200 {data: [{order: {id, order_no, type, table_code, owner, sent_at, notes}, lines: [Line]}], cursor, server_time}` + `ETag`; `304` | `403 orders.station.forbidden` |
| `GET /menu` | `orders.create` | read | `?location=<id>` (all products at one location), `?include_unavailable=1`; `If-None-Match` | `200 {categories: [{id, name_en, name_am, sort}], items: [{item_id, name_en, name_am, category_id, unit, price, currency, available, low, is_new, station_id, location_id, modifiers: [{id, name_en, name_am, price_delta}], unavailable_reason?}], generated_at}` + `ETag` | `403` |
| `GET /dining-tables` | `orders.create` | read | | `200 {data: [{id, code, name_en, name_am, seats, state: free\|ordering\|waiting\|served\|bill}]}` | |
| `POST /dining-tables` | `tables.manage` | key | `{code, name_en, name_am, seats?, sort?}` | `201` | `422 validation.failed`, `orders.table.code_taken` |
| `PATCH /dining-tables/{table}` | `tables.manage` | key | `{name_en?, name_am?, seats?, sort?, is_active?}` | `200` | `422` |
| `GET /payment-channels` | `payments.take` | read | | `200 {data: [{id, code, name_en, name_am, is_enabled, requires_photo, requires_reference, sort, accepted_now}]}` (`accepted_now` false for photo channels until Phase 4) | |
| `POST /orders/{order}/payments` | `payments.take` | key + `client_uuid` | `{client_uuid, channel_code, amount, tendered_amount?, reference_no?}` | `201 {payment: {id, channel_code, amount, tendered_amount, change_amount, status, received_by, received_at}, order: {status, total, paid, balance}}` | `422 payments.channel.disabled`, `payments.channel.photo_required` (every digital channel until Phase 4), `payments.channel.reference_required`, `payments.amount.mismatch` (Phase 1: the amount is the whole balance; overpay refused), `payments.tendered.too_low`; `409 payments.order.not_payable` (open, cancelled, voided, closed), `payments.order.settled` |
| `POST /broadcasting/auth` (under `/api/v1`) | `identity.authenticated`; the channel rule decides | read-like (signs a subscription, writes nothing; listed as such in the idempotency coverage test) | `{socket_id, channel_name}` | `200` signature | `403` (foreign station, other user, other branch) |

Order resource: `{id, client_uuid, order_no, business_date, type, dining_table {id, code, name_en, name_am} | null,
status, created_via, owner {id, name}, created_by_user_id, acting_user_id, totals {subtotal, discount, tax, total,
paid, balance}, currency, sent_at, settled_at, paid_at, closed_at, voided {reason, by, approved_by} | null, version,
lines: [Line]}`. Line: `{id, client_uuid, item {id, name_en, name_am}, qty, unit_price, modifiers [{id, name_en,
name_am, price_delta}], line_total, station {id, code, name_en, name_am} | null, status, recipe_version, accepted_by,
accepted_at, ready_at, served_at, rejected {reason, note, message_en, message_am} | null, notes, version}`.

**Where a line's stock is (said explicitly; traps.md, "Pad location semantics").** Availability on the pad and the
reservation at send are computed at the location of the item's order station (`locations.station_id`: the kitchen
for a sandwich, the bar for a latte); display-case products (`stationFor` null: cakes, bread, bottled drinks) at the
branch's `display_case` location. Inventory answers this (`Locations::forProduct`); Orders never reads locations.
`GET /menu?location=` overrides it for an admin view of one location.

Every write endpoint is listed in `tests/Support/write_endpoints.php` and `route_permissions.php` in the step that adds
it; `check_invariants.py --idempotency` and the route matrix test keep that true.

## 5. Domain events and listeners

Event classes live in `App\Shared\Events` (array payloads of ids and decimal strings) so Payments, Notifications
(Phase 2) and Analytics (Phase 4) subscribe without importing Orders. All are dispatched after commit, except
`PaymentRecorded`, whose Orders listener must run inside the payment's transaction. Broadcast companions are
`ShouldBroadcast` with `broadcastWhen()` false while `broadcasting.default` is `null` (ADR 0009), so the live host
queues nothing.

| Event | Payload | Listeners (module) | Queued? |
|---|---|---|---|
| `OrderSent` | order_id, branch_id, owner_user_id, station_ids, line_ids | broadcast `station.{id}` per station (Shared) | no (broadcast only when a WS transport is on) |
| `LineAccepted` | line_id, order_id, station_id, by_user_id, owner_user_id | broadcast `station.{id}`, `user.{owner}` | no |
| `LineReady` | same | broadcast `user.{owner}` (pad badge), `station.{id}`; Phase 2: persisted notification | no |
| `LineRejected` | same + reason | broadcast `user.{owner}`, `station.{id}`; Phase 2: notification | no |
| `LineServed` | same | broadcast `station.{id}` (the ticket leaves the rail) | no |
| `OrderPaid` | order_id, total, paid_at | broadcast `branch.{id}.orders`; Phase 4: Analytics | no |
| `OrderVoided` | order_id, reason, by_user_id, approved_by_user_id, line_ids | broadcast `station.{id}` per station, `user.{owner}` | no |
| `PaymentRecorded` | payment_id, order_id, channel_code, amount, balance_after | Orders `ApplyPayment` (sync, inside the payment's transaction: `paid_total`, settled, paid -> `consumeMany(sale)` -> closed) | no |
| `BranchCreated` (new) | branch_id | Payments `EnsureDefaultChannels` | no |
| `StockChanged` (Phase 0) | item, location, on_hand, reserved, available | broadcast `branch.{id}.stock` (menu refresh) | no |

## 6. Module build order inside the phase

Small PRs, each green in CI before the next; a step that completes a module ends with `gate_check.py --mode module`.

1. **1.1 contracts** (this PR, `phase-1/plan-and-contracts`): the three contract files above plus the concurrency
   file; the provider fixes of section 2 with their module tests. Groups `contract:orders->inventory|catalog|identity`,
   `concurrency`. The entry gate passes with tests; STATUS `contracts: done`, phase `in_progress`.
2. **1.2 live updates** (`shared.broadcast`): broadcasting config (`null` default), the channel registry in Shared
   (`station.{id}`, `user.{id}`, `branch.{id}.stock`, `branch.{id}.orders`), `routes/channels.php` rules on Identity's
   `Access`, `POST /api/v1/broadcasting/auth`, the `BroadcastsDomainEvent` base with `broadcastWhen()`, the
   `ConditionalPoll` helper (ETag, `If-None-Match`, 304) and the overlap-window cursor helper. Groups `feature`,
   `module:shared`. CI: `e2e-p1` stops starting the `ws` service (polling).

   As built (step 1.2, `phase-1/live-updates`): Shared gains `App\Shared\Live`: `Channels` (the registry: builders,
   `parse`, `parseSubscription`, private channels only, ids positive without leading zeros) and `LiveChannel`;
   `LiveTransport` (on unless the default connection's driver is `null` or `log`: `log` only writes the log and
   would still queue jobs on the live host's cron-drained queue, which the runbook had set; env() reads
   `BROADCAST_CONNECTION=null` as PHP null, which Laravel and `LiveTransport` both take as the null driver);
   `BroadcastsDomainEvent` (`ShouldBroadcast` + `ShouldDispatchAfterCommit`, private channels of the registry only,
   `broadcastAs` = the domain event's name, `broadcastWith` = its array, `broadcastWhen` = `LiveTransport::isOn()`) and
   the first companion, `StockChangedBroadcast` on `branch.{id}.stock`, dispatched for every `StockChanged` by
   `SharedServiceProvider`; `ConditionalPoll` (`etag(scope, parts)` = scope plus a hash, weak `If-None-Match`
   comparison because Cloudflare weakens ETags it compresses, lists and `*`, 304 without building the body on GET and
   HEAD, `Cache-Control: no-cache, private` on every answer; CORS exposes `ETag` to the web origin); `OverlapCursor`
   (`after` = rows after the client's cursor in id order, `next` = the highest id at least 5 s old and never below the
   client's cursor, `fingerprint` = `{since, next, max, pending}` for the ETag, `merge` = the client's de-dup by id and
   version; window in `config('bakery.live.overlap_seconds')`). The ETag covers the unsettled tail, not only the
   highest id: a late commit below the maximum leaves the maximum unchanged and would otherwise be answered 304 until
   the next change (ADR 0009 decision 3 amended). Identity gains `Broadcasting\ChannelRules` (the rules of section 5
   and ADR 0009 on `Access`; `branch.{id}.orders` for `payments.take` or `stations.all`, those who see every order) and
   `POST /api/v1/broadcasting/auth` (`auth:sanctum`, `actor`, `can:identity.authenticated`; 403
   `identity.channel.forbidden`; `{channel_name, auth}` with `auth` null under `null`/`log` and the transport's
   signature under reverb/pusher). The rules are applied by the endpoint itself rather than registered in a
   `routes/channels.php`: the null broadcaster never consults `Broadcast::channel()` registrations, so the endpoint
   checks first and the transport only signs. The route writes nothing and takes no `Idempotency-Key`: it is the first
   entry of `tests/Support/read_like_writes.php` (mirrored by `READ_LIKE_WRITES` in `scripts/check_invariants.py`),
   which the idempotency coverage test honours and checks. Config: `config/broadcasting.php` (default `null`),
   `BROADCAST_CONNECTION=null` in `.env.example`, `.env.ci` and compose. CI: `ws` is its own compose profile; `e2e-p1`
   (profile `full`) starts the worker but no Reverb and runs `BROADCAST_CONNECTION=null`,
   `NEXT_PUBLIC_LIVE_TRANSPORT=poll`; `e2e-p2..p5` list `full,ws` so their stacks stay as they were until their
   phase plans settle the transport. No schema change (ERD unchanged); OpenAPI gains the endpoint.
3. **1.3 orders** (`module:orders`, `unit`, `feature`, `concurrency`), in five PRs:
   - 1.3a schema: the six Orders migrations, models, factories, `OrderStatus` / `LineStatus` with exhaustive
     transition tables, ERD, `AppendOnlyTest` for `order_events`;

     As built (step 1.3a, `phase-1/orders-schema`): the six migrations have the columns, scales, keys and indexes of
     section 3. Where section 3 left a detail open, the build settles it as follows:
     - `orders.order_no` and `business_date` are null while the order is an open cart. SendOrder (1.3b) sets both from
       `order_counters`. CHECK `orders_numbered_on_a_business_date` keeps them together, and the unique key keeps a
       number once per branch and day.
     - `order_lines.qty > 0` (CHECK `order_lines_qty_positive`).
     - The type, status, channel, reject reason and event lists are named CHECKs. They mirror the enums `OrderType`,
       `OrderStatus`, `CreatedVia`, `LineStatus`, `RejectReason` and `OrderEventType`, and the schema test keeps both
       sides identical. The bilingual reject messages come with the reject action (1.3c).
     - Defaults: `open` / `queued`, money `0.00`, `ETB`, `version` 1.
     - The station, device, approver and actor-station columns have foreign keys. `shift_instance_id` has none until
       Phase 3.
     - `order_events.created_at` is DATETIME(6). Its triggers are `order_events_forbid_update_delete_{update,delete}`
       on MySQL / MariaDB and `forbid_update_delete()` on PostgreSQL. The model guard is `OrderEventsAreAppendOnly`.

     The transition tables (`App\Modules\Orders\Enums`):

     | Order from | to | Line from | to |
     |---|---|---|---|
     | `open` | `sent`, `cancelled` | `queued` | `accepted`, `ready`, `rejected`, `voided` |
     | `sent` | `in_progress`, `ready`, `cancelled`, `voided` | `accepted` | `ready`, `rejected`, `voided` |
     | `in_progress` | `ready`, `served`, `cancelled`, `voided` | `ready` | `served`, `voided` |
     | `ready` | `served`, `voided` | `served` | `voided` |
     | `served` | `paid`, `voided` | `rejected` | (terminal) |
     | `paid` | `closed` | `voided` | (terminal) |
     | `closed`, `cancelled`, `voided` | (terminal) | | |

     The order's progress states follow its lines (OrderProgress, 1.3c). They only move forward, and two skips come from
     this plan's own assumptions:
     - `sent -> ready`: an order of display-case lines is ready at send.
     - `in_progress -> served`: a reject can leave only ready or served lines.

     The other edges, also from section 10:
     - `cancelled` is reached from `open` (cancel) and from `sent` / `in_progress` (every line rejected).
     - `voided` is reached from `sent` to `served` only. An open order is cancelled instead. Refunds of paid orders
       are Phase 4.
     - `paid` follows `served` only: a payment before the food sets `settled_at`, which is a timestamp, not a status.
     - Line `queued -> ready` is the display-case line at send. Whether a station may mark a line ready without
       accepting it first is decided by the station action (1.3c), not by the table.
     - A line is voided with its order from any state except `rejected`.

     Models and factories:
     - The six models are `BranchScoped` and cast money to `Money` and `qty` to `Quantity`.
     - Only `DiningTable` soft-deletes. `SoftDeletesTest` allow-lists the others with a reason each.
     - `OrderLine.location_id` is a plain id. A new arch rule says Orders never uses `Inventory\Models`, the `Ledger`,
       `StockBalance` or Payments.
     - Factories: `OrderFactory` (`sent()`, `atTable()`), `OrderLineFactory` (a display-case line by default,
       `atStation()`), and one each for tables, counters, modifier snapshots and events.

     Scope: no routes, actions or lang keys. The OpenAPI export is unchanged; the ERD gains the six tables. The
     `gate:p1` `Phase1StateMachinesTest` is still to write (step 1.7).
   - 1.3b send: `POST /orders`, `PUT /orders/{id}/lines`, `POST /orders/{id}/send`, `POST /orders/{id}/cancel`;
     `SendOrder` (one transaction: number, price snapshot at `sent_at`, modifiers, `recipe_version`, routing,
     `reserveMany`, events); the shortfall body; the last-cake race;

     As built (step 1.3b, `phase-1/orders-send`): the four endpoints of section 4, in `routes/modules/orders.php`
     (loaded by `OrdersServiceProvider`): `auth:sanctum`, `actor`, `permission:orders.create`, `idempotency`;
     `{order}` binds through the `BranchScoped` model, so another branch's order is 404. Where section 4 left a
     detail open, the build settles it as follows:
     - Who may act. The person acting by short code must hold `orders.create` too, not only the pad account the
       route checked (a chef's code on the waiters' pad: 403 `auth.forbidden`). An existing order is changed, sent
       or cancelled by its owner (the acting waiter, else the creator) or by a `stations.all` holder (admin,
       manager); anyone else gets 403 `orders.order.not_owner` (new key). `created_via` is `waiter_pad` for an
       acting session or a waiter's account, `admin` for `people.manage`, `till` for `payments.take`.
     - `POST /orders` writes the cart in its own AuditedAction (`order.create`, event `order.created`), then, with
       `send` true (the default), runs `SendOrder` in its own transaction. A refused send therefore answers 409
       with the cart open, as the body in section 4 says. An order `client_uuid` already used answers 200 with
       that order as it stands, under any key; it is not sent again (`POST /orders/{id}/send` does that). Client
       UUIDs are stored in lower case. A line `client_uuid` used anywhere else in the branch, or by a line removed
       from this order, is 422 `validation.failed` on `lines.N.client_uuid`.
     - Every line is checked when it is written and again at send: `orders.item.not_sellable` (not an active
       product of the branch), `orders.item.no_price`, `orders.item.no_recipe` (a to-order product without an
       active recipe), `orders.item.no_location` (a to-order product without a station, or `Locations::forProduct`
       null). When a line is written its modifiers must belong to its product (`orders.modifier.not_of_item`), and
       a table must be an active table of the branch (`orders.table.unknown`).
       The body names the line: `line_client_uuid`, `item_id`, `field` (`lines.N.item_id` or
       `lines.N.modifier_ids`) and, at send, `line_id`. Nothing is written until every line passes.
     - A cart line carries the price now, its station and stock location now, and its reservation ref (UUIDv5 of
       `order-line:{branch_id}:{client_uuid}`, `Support\ReservationRef`). `recipe_version` stays null until the
       send. Its modifiers are snapshotted once with the line (`order_line_modifiers`), and the send applies that
       snapshot's price and recipe deltas. The send takes the base price (`Prices::at(sent_at)`), the route and
       the recipe version again.
     - `PUT /orders/{order}/lines` knows lines by client UUID. A line sent again keeps its row, takes the new
       quantity and notes, and is priced and routed again now. Its product and modifiers are fixed: a change
       there is 422 `orders.line.changed` (new key), and the client adds a new line instead. A line left out is
       voided, never deleted, and stays in the resource with status `voided`. Event `order.lines_replaced`
       `{added, updated, removed, total}`.
     - `SendOrder` is one AuditedAction (`order.send`). It locks the order row and resolves every line at
       `sent_at`. It then reserves all lines in ONE `reserveMany` (refs in line order, modifier deltas from the
       snapshot). A `Shortfall` throws `SendRefused` and the transaction rolls back. Only then does it take the
       day's number (`DailyNumbers`: one upsert that inserts the row of the branch's local date at 1 or adds 1 to
       it, then a locking read), so the counter row is held only for the last writes. Then come the line
       snapshots and the order (`sent`). A display-case line is `ready` with `ready_at = sent_at`; an order of
       display-case lines only goes `sent -> ready` in the same write. Events: `order.sent`, `line.queued` per
       station line (with its station), `line.ready` per display-case line, `order.ready`. `OrderSent` is
       dispatched after the commit; its companion goes to `station.{id}` only when the order reaches a station.
       A sent order answers 200 with itself and writes nothing; a cancelled or voided one is 409
       `orders.order.not_open` with its `status`.
     - The shortfall body has one entry per short ingredient of a short line, in line order. Each entry has the
       line (`line_client_uuid`, `line_id`, `item_id`, `item_name_en|am`, `qty`), the ingredient
       (`ingredient_item_id`, `ingredient_name_en|am`), `needed`, `available` (what earlier lines left),
       `qty_short` and `unit` (base unit), `qty_short_display` ("120 g": the base quantity without trailing zeros
       and the unit code, in both languages), `location_id`, the station (`station_id`, `station_name_en|am`),
       and `message_en` / `message_am` ("Cannot add Chicken sandwich ×2: Chicken breast short by 120 g
       (Kitchen)."). A refused send writes nothing at all, not even an `order.send_refused` event. That event
       type stays in the schema for when a refusal is worth recording (Phase 2's "Notify chef?").
     - The idempotency middleware stores every answer below 500, so a refused send replayed with its key is the
       same 409; a new tap on Send is a new key.
     - Cancel takes an open order only. It voids the order's lines, so the total becomes 0.00, and writes
       `order.cancelled` `{reason, line_ids}`. It makes no Inventory call: nothing was reserved.
     - Money: a line is qty x (unit price + modifier price), half-up to 2 dp (`OrderTotals`). Voided and rejected
       lines are not charged; discount and tax are 0.

     Tests (groups `module:orders`, `feature`, `unit`): `CreateOrderTest`, `ReplaceLinesTest`, `SendOrderTest`,
     `CancelOrderTest`, `OrdersLangTest`, `Unit/Orders/{OrderTotals,ReservationRef}Test`. SendOrder is shown to be
     one transaction in two ways. Connection events show every write of the send in the same outermost
     transaction; under `RefreshDatabase` the idempotency middleware's key row has a savepoint of its own outside
     the send. A failing `order_events` insert leaves no number, reservation, event or audit row. The fixture
     menu is `Tests\Support\OrdersMenu`. Concurrency (`tests/Gate/SendOrderRaceTest.php`, groups `concurrency`,
     `module:orders`, `feature`; `CommitsForConcurrency`; real processes through `Tests\Support\Forks`):
     - the last cake: one 200, one 409, available never below zero;
     - two sends at once take the numbers 1 and 2;
     - one `POST /orders` sent twice at once under one key: one order (the database cache store shares the key
       lock between the processes, as it does on the live host).
     Registries: four rows in `route_permissions.php` and `write_endpoints.php`; the matrix test gains the
     `{order}` placeholder (an open cart of the caller's own). No schema change (ERD unchanged); the OpenAPI
     export gains the four operations.
   - 1.3c station actions: accept, ready (consume), reject (release), served; `OrderProgress` (order status from line
     statuses); station permission;

     As built (step 1.3c, `phase-1/orders-station`): the four routes of section 4 in `routes/modules/orders.php`, all
     with `auth:sanctum`, `actor`, `idempotency`; `{line}` binds through the `BranchScoped` model (another branch's line
     is 404). Where section 4 left a detail open, the build settles it as follows:
     - Who may act. accept, ready and reject: the route checks `orders.accept`; the controller then checks the person
       doing it (the waiter or chef acting by short code, else the account) holds `orders.accept` at the line's
       station, or `stations.all` (admin, manager). Anyone else gets 403 `orders.station.forbidden` (new key); a short
       code that lacks `orders.accept` altogether is 403 `auth.forbidden`. A display-case line is at no station: only
       `stations.all` reaches it, and its machine refuses accept and reject anyway. served: route `orders.create`, by
       the order's owner or `stations.all` (403 `orders.order.not_owner`).
     - One frame for all four (`Support\LineWork`): one `AuditedAction` (`order_line.accept|ready|reject|serve`); lock the
       order row, then the line row; refuse a line of an open cart (409 `orders.order.not_sent`, new key); move the
       line through its machine (an illegal move is 409 `state_machine.illegal_transition`, nothing written); the
       action's writes; line version +1 and a `line.*` timeline row; `OrderProgress::apply`; totals again (a rejected
       line is not charged); order version +1. Events are dispatched after the commit.
     - accept: `queued -> accepted`, stamps `accepted_by`, `accepted_at` and `prepared_by`. Writes no stock.
     - ready: `queued|accepted -> ready` (a station may ready a line it never accepted); stamps `prepared_by` and
       `ready_at`; in the same transaction `Reservations::consume(ref)` writes one `production_consume` per ingredient
       carrying the line's ref (what was reserved is what was used). Exactly once: the line is locked and `ready ->
       ready` is refused (a replay with the same key gets the stored answer; a second tap with a new key, or two at
       once, gets 200 then 409), and Inventory settles a ref once besides. The timeline row carries the movement ids.
     - reject: `queued|accepted -> rejected` with `reason` of the fixed list and a `note` (required for `other`,
       422 `validation.failed`); stamps `rejected_by`, `rejected_at`, reason and note; `Reservations::release(ref)`
       writes the `reservation_release` rows. The line resource shows `rejected {reason, note, message_en, message_am}`
       from `reject.reason.*` in `lang/{en,am}/orders.php`.
     - served: `ready -> served` only (a queued, accepted, rejected or served line is 409); stamps `served_by`,
       `served_at`. Writes no stock.
     - Response: `200` the line as in section 4 plus `order {id, order_no, status, version, totals}`.
     - `OrderProgress` (`derive` is a pure function, tested as a table in `Unit/Orders/OrderProgressTest.php`): no live
       line (every line rejected) -> `cancelled` (`order.cancelled` with cause `every_line_rejected`); every live line
       served -> `served`; every live line ready or served -> `ready`; work started on a station line -> `in_progress`;
       otherwise it stays. Forward only, and only along an edge the `OrderStatus` table allows. `paid`/`closed` arrive
       with Payments (1.4).
     - Events (`App\Shared\Events`): `LineAccepted`, `LineReady`, `LineRejected` (with the reason), `LineServed`, one
       base `OrderLineEvent`, one broadcast companion (`Live\Broadcasts\OrderLineBroadcast`) to `station.{id}` and
       `user.{owner}`; dispatched after commit, inert while `broadcasting.default` is `null`.
     - Test infrastructure. Inside one test, the scoped instances (`CurrentBranch`, `ActorContext`, the acting user from
       `ResolveActor`) now reset between requests as they do in production, and a controller built by an earlier request
       is not reused (`tests/TestCase.php`, `Feature/RequestScopeTest.php`). Tests that relied on the leak were fixed.
     - Tests (groups `module:orders`, `feature`, `unit`, `concurrency`): `AcceptLineTest`, `ReadyLineTest`,
       `RejectLineTest`, `ServeLineTest` (happy path, validation, 403 per role and station, idempotent replay, ledger
       asserted through the Inventory contracts, events after commit, rollback of a failed write),
       `Unit/Orders/OrderProgressTest`, `Gate/StationActionsRaceTest` (two readies at once and ready racing reject
       settle the reservation one way, once; the same ready twice at once under one key). Registries: four rows each in
       `route_permissions.php` and `write_endpoints.php`. No schema change; the OpenAPI export gains the four operations.
   - 1.3d void: `POST /orders/{id}/void` with `verifyPinFor('orders.void')`, `releaseMany`, both actors audited;

     As built (step 1.3d, `phase-1/orders-void`): `POST /orders/{order}/void` in `routes/modules/orders.php` with
     `auth:sanctum`, `actor`, `permission:orders.void_request` (admin, manager, cashier) and `idempotency`; `{order}` binds
     through the `BranchScoped` model (another branch's order is 404). Where section 4 left a detail open:
     - Who asks, who approves. The asker is the person acting (the waiter by short code, else the account) and must hold
       `orders.void_request`; any holder may ask for any order of the branch (a cashier voids a waiter's order). The
       approver is the owner of the PIN, verified by `Access::verifyPinFor('orders.void', pin)` (manager or admin, same
       branch; a cashier's or another branch's code is 403 `identity.pin.rejected`; five wrong PINs of the asking account
       lock the prompt, 423 `identity.short_code.locked`, even for the right PIN). A manager or the admin may approve their
       own request. The PIN is verified before the transaction opens so a failed attempt still counts, and it is never
       stored (not in the audit row, not in the timeline).
     - Order of checks: permission, then the order's state (so an order that cannot be voided does not spend a PIN
       attempt), then the PIN, then the transaction, which locks the order and checks the state again.
     - Body `{reason, pin}`: `reason` is free text, required, trimmed, up to 255 characters (the column's size); `pin` 4 to 6
       digits. 422 `validation.failed` with `errors.reason` / `errors.pin`, their required messages in
       `orders.void.reason_required|pin_required`. The wrong-PIN and locked messages are Identity's
       (`identity.pin.rejected`, `identity.short_code.locked`, both languages), not duplicated in `orders.php`.
     - Voidable: `sent`, `in_progress`, `ready`, `served` with no `settled_at`. `paid`, `closed` and a settled order
       (payment taken before the food) are 409 `orders.order.paid` (refunds: Phase 4); `open`, `cancelled`, `voided` are
       409 `orders.order.not_voidable`; both carry the order's `status`. A repeat with a new key on a voided order is
       the second.
     - One `AuditedAction` (`order.void`: actor = the asker, acting user as stamped, context `{reason,
       approved_by_user_id}`), one transaction: lock the order, lock its lines in id order, every line that is not
       `rejected` or `voided` becomes `voided` (including `ready` and `served` ones, which the line table allows; rejected
       lines stay rejected), `line.voided` per line, one `Reservations::releaseMany` over the refs of those lines, totals
       again (0.00), the order `voided` with `voided_at`, `void_reason`, `voided_by_user_id` (the asker),
       `void_approved_by_user_id`, version +1, then `order.voided` `{reason, by_user_id, approved_by_user_id, line_ids}`.
       `order_events.user_id` / `acting_user_id` are the asker as stamped, `approved_by_user_id` the PIN's owner, on
       `line.voided` and `order.voided` alike.
     - The release covers every voided line, not only queued and accepted ones: a display-case line is `ready` at send but
       still holds its reservation until its sale at payment, so it is released too. Lines made at a station were consumed
       at ready; `releaseMany` skips settled refs, so what was consumed stays consumed and nothing is reversed.
     - Event: `OrderVoided` (`App\Shared\Events`, after commit) with `order_id`, `reason`, `by_user_id`,
       `approved_by_user_id`, `line_ids`, plus `station_ids` and `owner_user_id` for its companion
       `Live\Broadcasts\OrderVoidedBroadcast` (`station.{id}` per station of the voided lines, then `user.{owner}`), inert
       while `broadcasting.default` is `null`. A refused void announces nothing.
     - Tests (groups `module:orders`, `feature`): `VoidOrderTest` (14 cases: happy path with consumed, reserved and display-case
       lines and the ledger oracle; manager, admin and cashier asking; cashier / unknown / other-branch PIN; five wrong
       PINs then locked; both actors audited and no PIN stored; idempotent replay; 403 per role; validation; every
       non-voidable state; no PIN attempt spent on them; partly rejected order; branch isolation; rollback on a failed
       write; event after commit). Registries: one row each in `route_permissions.php` and `write_endpoints.php`. No schema
       change; the OpenAPI export gains the operation.
   - 1.3e provided capabilities: `Orders::totals`, `Tickets::since`, `Orders::openAt`; dining tables endpoints; the
     migration that adds permissions new to the registry (`tables.manage`) to existing databases and grants them to
     their default roles (grant only, never revoke).

     As built (step 1.3e, `phase-1/orders-capabilities`): three capabilities in `App\Modules\Orders\Contracts`, the dining
     tables endpoints and one grant-only migration. Where the plan left a detail open:
     - Shape. Like Catalog's and Inventory's, the contracts are `final` classes (`Orders`, `Tickets`) that the container
       builds per use (they read the scoped branch, so no singleton and nothing to bind in `OrdersServiceProvider`), with
       readonly view objects: `OrderTotalsView`, `TicketsPage` / `TicketView`, `OpenOrderView` / `OpenLineView`. A new arch
       rule says Payments, Production, Shifts, Notifications and Analytics reach Orders through `Orders\Contracts` only.
     - `Orders::totals(orderId)`: `subtotal, discount, tax, total, paid, balance, currency` as decimal strings, from the
       order's own columns through `OrderTotals` (balance = total - `paid_total`, which Payments keeps in 1.4); another
       branch's or an unknown order throws `ModelNotFoundException` (the endpoint that uses it answers 404).
     - `Tickets::since(stationId, cursor)` returns a `TicketsPage` (`data`, `cursor` string, `serverTime`, `fingerprint`;
       `toArray()` is the body of `GET /stations/{station}/tickets`, `etag(stationId)` its ETag from `ConditionalPoll`).
       The feed is the station's `order_events` rows (`station_id`) through `OverlapCursor`. A first load (no cursor) is
       the orders with a line in queued, accepted or ready at the station; a poll is every order with an event at the
       station after the cursor, so a served, rejected or voided line comes back once with its new status and is gone on
       the next first load. Each ticket holds all the order's lines at that station. Tickets are oldest first by their
       first event at the station. The cursor handed back is the highest event id at least `overlap_seconds` old, never
       below the one sent; a late commit below an id already served stays after the cursor and is delivered. A cursor that
       is not a plain number counts as no cursor. `Tickets::fingerprint(stationId, cursor)` is the cheap read for a 304.
       There is no page size yet: a station's rail is small and the cursor bounds a poll; the endpoint (1.5) may add one.
     - `Orders::openAt(stationId, at)`: orders with a line at the station that were sent at or before `at` (their
       `order.sent` event) and had no `order.closed`, `order.cancelled` or `order.voided` event at or before `at`, with the
       station's lines and each line's status from its last `line.*` event at or before `at`. Read from the timeline only
       (a test rewrites the live rows and the answer does not move). Served lines stay in an order that is not closed; a
       paid but unclosed order is still open. Oldest sent first. Display-case lines (no station) are never listed.
     - Dining tables. `GET /dining-tables` (`orders.create`) returns `{data: [{id, code, name_en, name_am, seats, sort,
       is_active}]}` for the active tables in grid order (`sort`, `code`, `id`); the derived `state` of section 4 arrives
       with the read models in 1.5, and `?include_inactive=1` lists the switched-off tables for a `tables.manage` holder.
       `POST` (`tables.manage`, admin) takes `{code, name_en, name_am, seats?, sort?}` and answers 201; `PATCH` takes
       `{name_en?, name_am?, seats?, sort?, is_active?}` (the code is fixed because orders snapshot it: 422 when sent) and
       answers 200. A code is unique per branch, compared without regard to case and against inactive tables too: 422
       `orders.table.code_taken` (new key, both languages) with `field: code`. Each write is one AuditedAction
       (`dining_table.create`, `dining_table.update`). A table is switched off with `is_active`, not deleted.
     - Permissions migration `2026_10_21_120100_grant_new_permissions`: reads `PermissionRegistry`, creates the permissions
       the `web` guard lacks and attaches each registry permission to its default roles that exist and lack it. It never
       revokes, deletes, renames or creates a role, never touches user roles or direct permissions, writes nothing the
       second time and needs no seeder (on a fresh database it creates the permissions and no roles; RolesSeeder grants
       them later). It works on the tables, not the Spatie models, with `insertOrIgnore` for the grants, and clears the
       permission cache. `down()` does nothing: a permission or grant added here may be in use by then. A default grant
       removed by hand from a role is put back (no screen edits a role).
     - Tests (groups `module:orders`, `feature`): `OrdersTotalsTest`, `TicketsSinceTest`, `OrdersOpenAtTest`,
       `DiningTablesTest`, `GrantNewPermissionsMigrationTest`; `OrdersLangTest` gains `table.code_taken`; the route matrix
       gains the `{table}` placeholder. Registries: three rows in `route_permissions.php`, two in `write_endpoints.php`
       (the GET is a read). No schema change (ERD unchanged); the OpenAPI export gains the three operations.
   Ends with `gate_check --mode module --module orders`, then `module-stable orders`.
4. **1.4 payments** (skeleton, final schema): `contract:payments->orders` first, then the two migrations, `Channels`,
   `Payments::record` (cash only passes), the two endpoints, `PaymentRecorded` -> `ApplyPayment`, `BranchCreated` ->
   default channels. Groups `module:payments`, `contract:payments->orders`. Ends `module-stable payments`.
   As built (step 1.4, `phase-1/payments-skeleton`): contract first (red: the order stayed `served` and unsettled, no `sale`
   rows), then Orders' provider fix, then the Payments tests (red), then the module. Where the plan left a detail open:
   - Contract `payments->orders` (`tests/Contract/Payments/OrdersContractTest.php`): five cases, written against
     `Orders\Contracts` and `App\Shared\Events\PaymentRecorded` only: totals as decimal strings; a served order paid in
     full goes `paid` -> one `sale` row per display-case line -> `closed` with `OrderPaid` after the commit; a payment
     before the food only settles, and serving the last line then pays, sells and closes; a rollback of the payment's
     transaction leaves the order and the ledger untouched; a part payment adds to `paid_total` and settles nothing.
     `PaymentRecorded` (payment_id, order_id, channel_code, amount, balance_after) was added to Shared in the same
     commit so the contract fails on behaviour, not on a missing class. A `sale` movement is negative (stock leaves).
   - Orders. `Listeners\ApplyPayment` (registered by `OrdersServiceProvider`, synchronous) takes the order lock
     (`OrderLock`, free inside the payment's transaction), adds the amount to `paid_total`, sets `settled_at` and writes
     `order.settled` when the balance is zero, then hands to `Support\Settlement`, which is also called by `LineWork`
     right after `OrderProgress::apply`, so the two paths (payment after the food, food after the payment) share one
     implementation: `served` -> `paid` -> `Reservations::consumeMany(sale)` for the served lines without a station ->
     `closed`, `order.paid` and `order.closed` on the timeline, `OrderPaid` (order_id, branch_id, total, paid_at) after
     the commit with its inert `OrderPaidBroadcast` on `branch.{id}.orders`. The listener refuses what Payments should
     have refused (status not sent..served, already settled, amount not positive or above the balance) with a
     `LogicException`, which rolls the payment back: a bug, never a 4xx. Part payments are accepted by the listener
     (Phase 4); Payments does not send them yet. A line rejected after full payment would leave an overpayment: refunds
     are Phase 4.
   - New Orders capability `Orders::forPayment(orderId, lock = false)` -> `OrderPaymentView` (status, settled, totals,
     `isPayable()`): `totals` alone cannot say whether an order is payable, and Payments may not read Orders' models.
     With `lock` it is a `SELECT ... FOR UPDATE` of the order inside the caller's transaction, the lock `ApplyPayment`
     takes again, so two payments of one order (any keys, any client UUIDs) run one after the other and the second sees
     `settled`. Tests: `OrdersForPaymentTest`, `ApplyPaymentTest`.
   - Schema exactly as section 3. The channel migration inserts the eight defaults for every existing branch (soft-deleted
     ones too) with `INSERT IGNORE` on `(branch_id, code)`, through a public `backfill()` the test calls on a populated
     table: idempotent, and it never resurrects or edits a channel an admin changed or removed. Its list is a copy of
     `Support\DefaultChannels` (a migration must not depend on application classes); `DefaultChannelsTest` keeps them equal.
     Names: Cash, Telebirr, CBE Birr, CBE bank transfer, Awash Bank, Dashen Bank, Bank of Abyssinia, Cooperative Bank of
     Oromia, with Amharic twins. `PaymentStatus` is a state machine now (`recorded` -> `verified` | `disputed` | `voided`,
     `verified` -> `voided`, per domain-model.md) so the exhaustive test pins it; Phase 1 only writes `recorded`.
   - `BranchCreated` is dispatched from the `Branch` model's `created` hook (not from the creating code): the admin
     command, `Phase0Seeder` and every factory create branches through the model, so no path can forget it. It is
     synchronous (not after commit), so the channels exist in the same transaction as the branch. Cost: eight channel rows
     per branch in every test (one multi-row `INSERT IGNORE`). The listener writes with the explicit branch id because no
     branch is resolved yet.
   - `Payments\Contracts`: `Channels` (`enabled()`, `find(code)`, `ensureDefaults(Branch|int)`, `ChannelView` with
     `acceptedNow()`) and `Payments::record(...)` -> `RecordedPayment`, final classes built per use like the other
     modules' contracts. The endpoint is a thin caller of `record`.
   - `GET /payment-channels` lists the branch's non-removed channels, disabled ones included (`is_enabled` false,
     `accepted_now` false), in `sort` order. `accepted_now` = enabled and no photo required.
   - `POST /orders/{order}/payments`: the order id is a plain number (Payments cannot bind Orders' model); another branch's
     order is 404 through `forPayment`. Check order: a known `client_uuid` first (replay: 200 with the same payment and
     the order as it is now; `409 payments.client_uuid.reused` for another order's UUID, a new key), then the channel
     (`payments.channel.unknown` | `disabled` | `photo_required` | `reference_required`, all 422, in that order, so
     a switched-off digital channel says disabled), then under the order lock `payments.order.not_payable` (409: open,
     cancelled, voided, closed; `status` in the body), `payments.order.settled` (409), `payments.amount.mismatch` (422,
     carries the `balance`), `payments.tendered.too_low` (422). `reference_required` is reachable only for a channel an
     admin set to ask for a reference without a photo (no screen edits channels in Phase 1; the test sets it in the
     database). Cash: `tendered_amount` omitted means exactly the amount (change 0.00); other channels store neither.
     Money fields must be decimal strings (up to 10 integer digits, 2 decimals, above zero); a JSON number is 422.
   - Actor: `received_by_user_id` is the person who took the money (the cashier acting by short code on a shared till,
     else the account), `acting_user_id` repeats the short-code person, the audit row (`payment.record`) names the
     account; the person acting must hold `payments.take` too (`PaymentAccess`, 403 `auth.forbidden`), so a waiter's
     short code on the till is refused. The response carries `client_uuid`, `currency` and `order.id` in addition to
     the fields of section 4; `received_by` is the user id.
   - `reference_no` is stored but never logged: `Audit\Snapshot` now redacts a `reference_no` attribute (SnapshotTest
     case), so neither `before`/`after` of the audit row nor the response carries it.
   - Registries: two rows in `route_permissions.php`, one in `write_endpoints.php` (the GET is a read); `Payment` joins
     the `SoftDeletesTest` allowlist (never deleted: voided by status in Phase 4); `docs/erd.dbml` gains both tables;
     lang parity in `PaymentsLangTest` (nine keys); the OpenAPI export gains the two operations.
5. **1.5 read models**: `GET /menu`, `GET /stations/{id}/tickets?since`, `GET /orders?mine|unpaid&since`, `GET
   /orders/{id}`, `GET /dining-tables` with states; ETag / 304; cursor with the overlap window. Group `feature`.
   As built (step 1.5, `phase-1/read-models`): tests first (red: the four GET routes were 404/405, the `state` key and
   the bulk capabilities missing), then the capabilities, then the endpoints. Groups `module:orders`, `feature`; one test
   file per endpoint (`MenuEndpointTest`, `StationTicketsEndpointTest`, `OrdersIndexTest`, `OrderShowTest`,
   `DiningTableStateTest`) plus `MenuReadsContractTest` for the bulk reads. Where the plan left a detail open:
   - **Bulk reads, so the menu is a fixed number of queries.** The per-product capabilities cost a query or two per
     product (`Routing::stationFor`, `Locations::forProduct`, `Modifiers::for`, `Prices::at`, and `Recipes::activeFor` inside
     `Availability::bulk`), which on a shared host polled by every pad grows with the menu. Additive, each pinned equal to
     its single twin by `MenuReadsContractTest`: Catalog `Prices::atMany`, `Routing::stationsForMany`,
     `Modifiers::offeredFor` (with `ModifierOffer`: name and price delta, no recipe delta), `Recipes::activeForMany`,
     `Items::menuFingerprint`; Inventory `Locations::idsForProducts` (ids only: Orders never holds a `Location`),
     `Locations::has`, `Stock::fingerprint`, and `Exploder::explodeMany` behind the unchanged `Availability::bulk`. The
     menu test adds twelve made-to-order and twelve shelf products and asserts the query count does not move.
   - **`GET /menu`** (`MenuController`, `Support\MenuBuilder`): the live, active products of the branch with a price in
     force now (a product without one cannot be sold, so it is not listed), ingredients never. `available` is the
     `Availability::bulk` quantity as a 4 dp decimal string (`"4.0000"`, not `"4"`: ADR 0004's quantity format; the plan's
     `"0"` is shorthand), one `bulk` call per location. `low` = 1 to 3 units left; a product with 0 is `unavailable`, not
     `low`. A product with 0 is left out unless `include_unavailable=1`, which lists it with `available: "0.0000"` and
     `unavailable_reason` = `out_of_stock`, or `no_location` when its station has no stock location yet (codes, not
     sentences: the pad owns the wording). `?location=<id>` puts every product at that location and returns the products
     with stock there; an id that is not a live location of the branch is `422 validation.failed`. `categories` are the
     product categories the listed items use, by `sort` then id; items are ordered by English name. No lang keys were
     added: the only errors are the existing 403 and the shared 422.
   - **ETag of the menu**: branch, `location`, `include_unavailable`, `Items::menuFingerprint` (row count and latest
     `updated_at` of products, categories, modifiers, station assignments, recipes and recipe lines, deleted rows
     included; the prices in force now, so a price taking effect or ending changes the tag with no row written; the
     branch's calendar day for `is_new`) and `Stock::fingerprint` (highest ledger movement id, which every receipt,
     sale, waste and reservation moves, and the locations' state): nine small aggregate queries and no rows. `updated_at`
     is whole seconds, so two catalog edits in the same second after a poll can share a tag; the next change or the next
     day fixes it, and stock and prices, the fast-moving parts, are exact.
   - **`GET /stations/{station}/tickets`** (`StationTicketsController`): the route needs `orders.accept`, then
     `OrderAccess::assertAtStationRail` checks the person (the short-code person on a shared screen) at that station
     (`403 orders.station.forbidden`; admin and manager hold `stations.all`; a waiter or cashier is stopped by the route as
     `auth.forbidden`); another branch's station is 404 through the bound `Station`. The ETag is the new
     `Tickets::etag(station, Tickets::fingerprint(...))`, which `TicketsPage::etag` now calls too, so the 304 is answered
     before the page is built; the 200 carries that tag (never newer than its body, so a client can only be re-sent a page,
     never kept on a stale one).
   - **`GET /orders`** (`OrdersController::index`, `Support\OrderList`): the waiter's list is forced to the orders they own
     (`owner_user_id` = the waiter acting by short code, else the account) unless the person holds `stations.all` or
     `payments.take` (`OrderAccess::seesEveryOrder`); `?mine=1` narrows anyone to their own. No `status` means `active` =
     open, sent, in progress, ready, served and paid (not closed, cancelled, voided); `status=<value>` takes one status;
     `unpaid=1` = sent, in progress, ready, served (it overrides `status`). A first load returns at most `limit` (100,
     at most 200) orders, newest first. `?since=` is a poll over the branch's `order_events` (the person's own when
     mine) through `OverlapCursor`: every order with an event after the cursor, newest first, whatever its status, because
     an order that stopped matching (paid, cancelled, voided) must arrive once so the client can drop it; `status` and
     `unpaid` are therefore not applied to a poll (the client merges by id and version and filters itself), `mine` and
     `dining_table_id` are. A `since` that is not a plain number is a first load. The ETag is the feed fingerprint plus
     branch, person (when mine), status, unpaid, table and limit; a 304 reads three indexed aggregates and never the
     orders. The Order resource now exposes `relations()` so the list eager-loads in a fixed number of queries.
   - **`GET /orders/{order}`**: the Order resource plus `events` (id, event, order_line_id, station_id, user_id,
     acting_user_id, approved_by_user_id, payload, at), oldest first, one query. The owner and anyone who sees every order
     may read it; for any other waiter it is `404`, the same as another branch's order (`OrderAccess::assertMayRead`). It
     is not a conditional poll: the pad reads one order when it opens it.
   - **`GET /dining-tables` state** (`Support\TableStates`, one grouped query for the whole grid): `waiting` when the
     table has an order sent, in progress or ready; else `ordering` when it has an open order; else `served` when its
     only active orders are served and unpaid; else `free` (nothing, or only cancelled, voided, paid or closed orders).
     Precedence waiting, ordering, served. `bill` is in the enum but never produced in Phase 1: there is no "request the
     bill" action yet. `state` is the last key of the resource, so `POST` and `PATCH /dining-tables` answer with it too.
   - Registries: four rows in `route_permissions.php` (the matrix gains the `{station}` placeholder: a kitchen station
     the caller is assigned to); no write endpoints; no schema change (ERD unchanged); the OpenAPI export gains the four
     operations and the `state` property.
6. **1.6 web**: `/pad` (short-code lock screen, tables, menu with "3 left", New row, cart, Send, inline shortfall, my
   orders with the ready badge, 60 s idle lock), `/kds/kitchen` and `/kds/bar` (ticket rail, timers, accept / ready /
   reject, chime), `/till` (ordering surface, unpaid queue, cash pay sheet with change due), admin Orders live board;
   `LiveFeed` (`poll` default; `reverb` / `pusher` through Echo when configured), Workbox shell cache, minimal outbox
   (send, accept, ready) with client UUID + `Idempotency-Key`. Playwright `p1-order-flow`, `p1-shortfall` replace the
   step 1.1 placeholders.
   Step 1.6 is built as three web PRs: 1.6a (foundations and the kitchen and bar displays), 1.6b (pad and the
   `p1-shortfall` flow), 1.6c (till, the admin Orders board and the full `p1-order-flow`).
   As built (step 1.6a, `phase-1/web-kds`): the Playwright flow and its fixture helpers were committed red first, then the
   API gap below, then the web. Where the plan left a detail open:
   - **A chef could not find their rail.** `GET /stations` needs `people.manage` and a user's `stations` entries held
     only ids, so a chef or barista could not tell which assigned station is the kitchen and which the bar. The
     `stations` entries of `/me` and `/users` now also carry `code`, `name_en` and `name_am` (additive; the OpenAPI export
     and `UsersEndpointsTest` follow; eager-loaded in `GET /users`). `/kds/kitchen` and `/kds/bar` use the assigned
     stations of that code; admin and manager (`stations.all` and `people.manage`) also see every station of that code
     from `GET /stations`. One station is used directly, several give a "Station" picker, none gives a message.
   - **Types and decimals.** `src/lib/api/types.ts` gains Order, Line, Ticket and TicketsPage, Menu, DiningTable,
     PaymentChannel, Payment and the `orders.send.shortfall` body (`ShortfallBody`); `ApiError` keeps the whole error body
     so a refused send hands the shortfall to the pad. `src/lib/decimal.ts` compares, adds and trims decimal strings as
     scaled integers (no floats); a ticket shows `qty` without padding zeros ("2.0000" reads "2").
   - **`LiveFeed`** (`src/lib/live/`): `feed.ts` is transport-free (cursor, ETag, merge, never two requests in flight, a
     call during a look repeats once after it), `transport.ts` has the transports, `tickets.ts` the rail's merge. Poll
     transport: 2 s visible, 10 s hidden, paused offline and resumed on `online`, back-off 4 s, 8 s then 10 s after
     errors, an immediate look on focus and after the screen's own write. The first request has no cursor; every later one
     has `since=<cursor>` and the last `ETag` as `If-None-Match` (the second request therefore usually answers 200 with
     the same rows, because the ETag covers the cursor; from the third a quiet rail is 304). Rows merge by id, the higher
     `version` wins; served, rejected and voided lines stay in the state (an older copy must not bring one back) and are
     only left off the rail, as is a ticket with no active line. The rail is sorted client-side by `sent_at`, then id.
     `NEXT_PUBLIC_LIVE_TRANSPORT=reverb|pusher` selects an Echo adapter on `station.{id}` through
     `/api/v1/broadcasting/auth`; every event and every connect only kicks a look, and a 30 s poll runs underneath. It adds
     `laravel-echo` 2.5.0 and `pusher-js` 8.6.0 (exact), imported lazily so the default bundle does not carry them; if they
     cannot start the screen keeps the slow poll and logs why. It is typed and compiled but not exercised by CI (no `ws`
     e2e until a WebSocket host exists, ADR 0009 decision 6).
   - **Outbox** (`src/lib/outbox/`): a zustand store mirrored to `localStorage` (every access in try/catch) for send order,
     accept, ready and reject. Idempotency-Key fixed at enqueue (the order's `client_uuid` is the caller's), items sent
     one at a time in order by the account that queued them, retried with the same key after a network error or a 5xx
     (2 s, 4 s, 8 s, then 10 s), dropped on a 4xx and kept as a failure (the API's error whole) until dismissed. A 401
     ends the session and keeps the queue. `PendingIndicator` in the shell shows the count and the failures. The queue
     resumes on start, on `online` and on sign-in.
   - **Shell cache.** `scripts/build-sw.mjs` (`workbox-build` 7.4.1, exact, dev dependency) runs after `next build` only
     for the static export and writes `out/sw.js` (scope `/app/`) precaching the export's HTML, JS, CSS, fonts and images
     with query strings ignored; there are no runtime routes, so `/api/` is never cached. The deploy copies `out/` whole
     (`DEPLOY_WEB_MODE=static`), so the release and rsync scripts ship it unchanged. It is registered only when the build
     was an export (`NEXT_PUBLIC_SHELL_CACHE`), and a failed registration is ignored.
   - **KDS.** Tickets oldest first with order number, table or takeaway, waiter, a timer (`lib/kds.ts`:
     amber at 5 min, red at 10 min, one constant, with the words "Slow" and "Late" so colour is not the only signal),
     notes, and the lines at that station with modifiers and quantity. A new line gets Accept, Ready and Reject; an
     accepted one Ready and Reject; a ready one waits for the pad to serve it and then leaves. Reject opens an inline
     form with the four reasons (labels in both languages; the note is required for `other`). Accept, ready and reject
     go through the outbox and the rail looks again at once. The chime is two generated tones, played when a line that
     was not on the rail arrives after the first load; browsers need a tap first, so the first pointer or key event on
     the screen unlocks audio; the mute choice is remembered per device. Timers use the device clock against `sent_at`
     (never negative).
   - **Playwright.** `e2e/p1/order-flow-kds.spec.ts` (`@live`, in `p1-order-flow`) and `e2e/support/{env,api,fixture}.ts`.
     The fixture finds or creates the kitchen or bar station, its location, the chef or barista, the waiter (short code
     reset), the table `E2E-T1` and the cashier by fixed phone numbers (`+2519110002xx`; passwords derived from the
     admin's, so nothing is committed), and makes a run-unique ingredient with stock, a product routed to the station, its
     recipe and its price. The waiter sends through the API; the test asserts the ticket within 5 s, a 304 on the idle
     rail, that every request after the first has `since` and `If-None-Match`, accept, ready, the line leaving once served
     through the API, and the ledger `purchase_in`, `reservation`, `production_consume`. The `test.fixme` of
     `order-flow.spec.ts` stays until 1.6c.
   - The repository has no web unit-test runner, so the merge and cursor logic is covered by that flow and kept in pure
     functions (`mergeTickets`, `railOf`, `backoffDelay`, `decimal.ts`) for a runner to pick up.
7. **1.7 docs and seeds**: `Phase1Seeder`, ADR 0008 / 0009 accepted, OpenAPI, ERD, gate report.

## 7. Test list

- **Contract** (step 1.1): the four files of section 2; `contract:payments->orders` at step 1.4.
- **Unit**: `OrderStatus` and `LineStatus` exhaustive tables (every `from x to` pair; allowed transitions succeed,
  every other throws `IllegalTransition`; also picked up by `tests/Unit/StateMachineExhaustiveTest.php`);
  `OrderProgress` (line statuses -> order status, a table of cases incl. all lines rejected -> cancelled, settled +
  last served -> paid); `OrderTotals` (qty x (unit price + modifier deltas), half-up to 2 dp, decimal strings);
  reservation ref derivation (UUIDv5, stable); reject reasons have en and am messages.
- **Feature, one file per endpoint** (`module:orders` / `module:payments`): happy path; validation; `403` for every
  role without the permission (and the chef at the bar, the barista in the kitchen); idempotent replay (same key twice:
  same body, one row; same `client_uuid` with a new key: the same order); `SendOrder` is one transaction (one `BEGIN`
  in the query log); price snapshot at `sent_at` (a price changed after send does not move the line); shortfall `409`
  on the exact line, the order stays open, nothing reserved, resend after fixing; reject releases, ready consumes
  (movement rows asserted), served; void with a manager PIN releases the open lines, keeps consumed ones, audits both
  actors (`audit_log` actor + approver, `order_events.approved_by_user_id`), refuses a cashier PIN (`403`), locks after
  five (`423`), refuses a paid order (`409`); cancel only while open; cash payment closes a served order and writes
  `sale` for display-case lines; a payment before served closes the order when the last line is served; digital
  channels refused until Phase 4; amount mismatch and overpay refused; `GET /menu` hides availability 0, shows `low`
  at 3 or fewer ("3 left"), `is_new`, prices now, availability at the line's location; tickets and orders feeds: cursor
  monotonic, overlap window, `304` with a matching `If-None-Match`, foreign station `403`; dining table states.
- **Property** (`property`, `module:orders`, `gate:p1`): `OrderLedgerPropertyTest`, 100 seeded sequences (at most 50
  operations: send, accept, ready, reject, served, void, pay) over a small menu with shared ingredients: the ledger
  oracle agrees (movements == balances == view), `reserved` equals the sum of the open lines' reservations, `available`
  never below zero, every closed order's movements are `reservation -> production_consume` per station line and
  `reservation -> sale` per counter line.
- **Concurrency** (`concurrency`, `CommitsForConcurrency`): the step 1.1 lock-order test; two sends of the last cake
  on two connections (one sent, one `409` shortfall); two parallel identical `POST /orders` with one key (one order;
  the second gets `409 idempotency.in_flight` or the replay).
- **Arch / invariants**: `ModuleBoundariesTest` (Orders uses only Inventory, Catalog, Identity, Shared, Audit; never
  Payments); `AppendOnlyTest` + `order_events`; `SoftDeletesTest` allowlist for orders, lines, payments;
  `BranchIdTest`, `NumericColumnsTest`, `BilingualColumnsTest`, `ActorStampTest` cover the new tables;
  `RoutePermissionMatrixTest` and `IdempotencyCoverageTest` cover every new route.
- **Playwright** (`apps/web/e2e/p1/`; built app, `serviceWorkers: 'block'`, one context per role):
  - `p1-order-flow` (`order-flow.spec.ts`), four contexts (pad, kitchen KDS, bar KDS, till): the pad's waiter enters
    the short code -> table -> chicken sandwich + latte + one display-case croissant -> Send -> the kitchen ticket is
    visible within 5 s through the configured transport (polling: after the initial load the KDS only issues
    `tickets?since=` requests with `If-None-Match`) and the bar ticket likewise -> accept -> ready on both -> the pad's
    ready badge -> served -> till cash pay with change due -> closed. Post-conditions through the API: the movement
    sequence is `reservation, production_consume` for the sandwich's and the latte's ingredients and
    `reservation, sale` for the croissant; `available` equals what was stocked minus what was sold.
  - `p1-shortfall` (`shortfall.spec.ts`): selling more than is available shows the inline shortfall on the exact line
    (ingredient, quantity short, station, in English and in Amharic); reducing the line sends.
  - `@live`-safe: both flows build their own fixture through the admin API in the isolated "E2E Test Branch"
    (stations, locations, run-unique ingredients and products with recipes, prices, routing, stock via `purchase_in`,
    the fixed E2E staff accounts found or created by phone with short codes reset each run, an enrolled pad device),
    and read their post-conditions through the API, so they carry `@live` and run against
    https://bakery-ops.orcamind.dev after each deploy. Not `@live`: the check that the demo bakery's tables and menu show
    on the pad (the live demo bakery is loaded by hand), and any WebSocket-transport variant.
  - Until step 1.6 each project holds one `test.fixme` placeholder that names step 1.6 and carries a phase-1 TODO
    marker, so `e2e-p1` can require tests once the phase is `in_progress`, and `gate_check --mode done --phase 1`
    refuses to close while a placeholder remains.
- **Gate** (`tests/Gate/`, group `gate:p1`): `Phase1StateMachinesTest` (exhaustive tables), `SendOrderGateTest` (one
  transaction, idempotent replay returns the identical body and one order), `VoidGateTest` (releases, audits both
  actors), `LiveChannelsGateTest` (foreign-station channel auth `403` and tickets `403`), `OrderMovementsGateTest`
  (movement sequence, available = seeded minus sold), `OrderLedgerPropertyTest`.

## 8. Seeds

`Phase1Seeder` (step 1.7) extends the demo bakery and never rewrites `Phase0Seeder`: same branch, same actor, same
idempotence (natural keys and deterministic client UUIDs), every write through the module code the API uses.

- 12 dining tables (T1-T10 inside, Terrace 1-2; bilingual names, seats);
- the eight payment channels (`Channels::ensureDefaults`, the same code the `BranchCreated` listener runs);
- a shared pad: device "Front pad" enrolled, account "Pad 1" (role waiter) the tablet signs in with, the two waiters
  switching by short code;
- sample orders of today, sent through `SendOrder` and the station actions so the ledger holds what they did: one open
  (table T3, not sent), two sent (kitchen and bar lines queued), one in progress, one ready, one served and unpaid (the
  till queue), one paid in cash and closed (with a croissant sold from the display case).

Earlier gates stay green: `e2e-p0` seeds `SEED_PHASE=0` (no Phase 1 data), the Phase 0 seeder tests do not run
`Phase1Seeder`, and the sample orders draw a few units of plentiful demo stock; `DemoSeederTest` gains the Phase 1
composition and a second run that writes nothing.

## 9. ADRs this phase writes

- **0008** Reserve at send, consume at ready, sale at pay; one reservation per order line; one lock pass per order.
- **0009** Live updates through a transport abstraction, polling first on the live host; broadcasting behind
  `BROADCAST_CONNECTION`; channel auth written now.

Both are `proposed` now and become `accepted` in the gate report. The manifest and the phase references renumber the
later ADRs: Phase 2 0010 (presence resolver interface), 0011 (alert dedupe); Phase 3 0012 (hash chain canonical JSON),
0013 (shift attribution); Phase 4 0014 (summary rebuild semantics).

## 10. Assumptions and open questions

No question in this phase changes the ledger's meaning, so none blocks. Safer defaults taken:

- Negative stock stays refused (the Phase 0 open decision is Ramin's; nothing here depends on the answer).
- The price on a line is `Prices::at(sent_at)` (the server's send time); a price changed while the cart was open is
  the new price, shown in the response. No discounts in Phase 1 (`discount` 0; the default policy "cashier up to 10 %,
  manager unlimited with PIN" lands with the full pay sheet in Phase 4); VAT off (`tax` 0).
- Display-case lines are `ready` at send (no station step), `served` when handed over, sold at payment.
- Payment may come before the food (takeaway at the counter): the order is `settled`, and becomes `paid` and `closed`
  when its last line is served. Phase 1 takes one cash payment for the whole balance (tendered >= amount gives change);
  split and partial payments, photos and references are Phase 4.
- Void: unpaid orders only (refunds are Phase 4), reason required, the PIN of an `orders.void` holder (manager, admin),
  asked by anyone with `orders.void_request` (the cashier's "request only"); consumed ingredients stay consumed.
  Cancel: an unsent (`open`) order, by its owner, without a PIN (nothing was reserved or made).
- An order whose every line is rejected becomes `cancelled` (nothing to serve or pay).
- Order numbers restart every business day per branch (local date, Africa/Addis_Ababa).
- A waiter sees their own orders (owner = the acting waiter, else the creator); cashier, manager and admin see all.
- Reject reasons are a fixed bilingual list in code (`ingredient_spoiled`, `equipment_down`, `out_of_stock`, `other`
  with a note); a table of reasons can come with Phase 3's variance reasons if the pilot asks.
- `GET /menu` hides products with availability 0 unless `include_unavailable=1` (the pad's greyed tiles); "low" means
  3 or fewer.
- Order types offered on the screens: dine-in and takeaway; `delivery` and `advance` exist in the schema only.
- `orders.shift_instance_id` and every movement's `shift_instance_id` stay null until Phase 3; `LineReady` reaches the
  waiter through the live-update transport (the pad's own orders feed) and becomes a persisted notification in Phase 2.
- The gate flow adds one display-case product to the sandwich and the latte so the post-condition's `sale` step
  exists, and uses a bar KDS context for the latte (manifest gate criteria updated accordingly).

## 11. Risks from `references/traps.md` that apply

- **Polling cursor gaps** (auto-increment ids visible out of commit order): overlap window plus merge by `version`
  (ADR 0009); tested with a late-committed event.
- **Shared-host load**: polls are 304s from one indexed query; intervals back off when hidden or idle; measured in the
  pilot.
- **Idempotency race**: two identical sends in parallel; the middleware's lock plus the `(branch_id, client_uuid)`
  unique indexes; tested with two connections.
- **Deadlocks between orders**: one ascending lock pass per order (ADR 0008); the step 1.1 concurrency test.
- **Reason -> delta map in one class**: Orders never computes stock effects; `MovementReason` and `Reservations` do.
- **Pad location semantics**: section 4, answered by `Locations::forProduct`.
- **Playwright**: built app, service workers blocked, one context per role, waits on API state, never on timers;
  fixtures built through the API in the E2E Test Branch.
- **Time zone**: `business_date` and the daily order number use the branch's local date; boundary tests at 23:59 and
  00:01 Addis Ababa time.
- **Queues on the live host**: nothing in Phase 1 is queued (sync listeners, broadcasts off under `null`), so cron
  latency never delays an order.
- **Octane** is not on the live host; the `scoped()` bindings and the arch test on statics stay for the VPS path.
