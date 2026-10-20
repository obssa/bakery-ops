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

At plan approval (after the standing-approval commit, before step 1.1; `--no-tests`, so Pest groups are not run):

```
ENTRY_CHECK_NO_TESTS
```

After step 1.1 (contract tests written, provider fixes in, tests run in Docker):

```
ENTRY_CHECK_WITH_TESTS
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
3. **1.3 orders** (`module:orders`, `unit`, `feature`, `concurrency`), in five PRs:
   - 1.3a schema: the six Orders migrations, models, factories, `OrderStatus` / `LineStatus` with exhaustive
     transition tables, ERD, `AppendOnlyTest` for `order_events`;
   - 1.3b send: `POST /orders`, `PUT /orders/{id}/lines`, `POST /orders/{id}/send`, `POST /orders/{id}/cancel`;
     `SendOrder` (one transaction: number, price snapshot at `sent_at`, modifiers, `recipe_version`, routing,
     `reserveMany`, events); the shortfall body; the last-cake race;
   - 1.3c station actions: accept, ready (consume), reject (release), served; `OrderProgress` (order status from line
     statuses); station permission;
   - 1.3d void: `POST /orders/{id}/void` with `verifyPinFor('orders.void')`, `releaseMany`, both actors audited;
   - 1.3e provided capabilities: `Orders::totals`, `Tickets::since`, `Orders::openAt`; dining tables endpoints.
   Ends with `gate_check --mode module --module orders`, then `module-stable orders`.
4. **1.4 payments** (skeleton, final schema): `contract:payments->orders` first, then the two migrations, `Channels`,
   `Payments::record` (cash only passes), the two endpoints, `PaymentRecorded` -> `ApplyPayment`, `BranchCreated` ->
   default channels. Groups `module:payments`, `contract:payments->orders`. Ends `module-stable payments`.
5. **1.5 read models**: `GET /menu`, `GET /stations/{id}/tickets?since`, `GET /orders?mine|unpaid&since`, `GET
   /orders/{id}`, `GET /dining-tables` with states; ETag / 304; cursor with the overlap window. Group `feature`.
6. **1.6 web**: `/pad` (short-code lock screen, tables, menu with "3 left", New row, cart, Send, inline shortfall, my
   orders with the ready badge, 60 s idle lock), `/kds/kitchen` and `/kds/bar` (ticket rail, timers, accept / ready /
   reject, chime), `/till` (ordering surface, unpaid queue, cash pay sheet with change due), admin Orders live board;
   `LiveFeed` (`poll` default; `reverb` / `pusher` through Echo when configured), Workbox shell cache, minimal outbox
   (send, accept, ready) with client UUID + `Idempotency-Key`. Playwright `p1-order-flow`, `p1-shortfall` replace the
   step 1.1 placeholders.
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
