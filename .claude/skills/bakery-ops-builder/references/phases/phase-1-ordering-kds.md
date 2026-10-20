# Phase 1 — Ordering and live KDS (weeks 3–5)

The first phase that consumes another module's promises. Orders is built on top of an Inventory whose
reserve/release/consume behaviour was pinned down by contract tests *before* a line of Orders existed.

## Entry criteria (`gate_check.py --mode entry --phase 1`)
- `gate-p0` green on `main`; `done_commit` is an ancestor of HEAD.
- `docs/phases/phase-1-plan.md` committed with `plan_approved: true` in STATUS.json.
- `contract:orders->inventory`, `contract:orders->catalog`, `contract:orders->identity` exist and are
  green while `app/Modules/Orders` is still empty.
- Shared, Audit, Identity, Catalog, Inventory are `stable`.

## Scope in build order

| Step | Module | Builds | Consumes | Groups |
|---|---|---|---|---|
| 1.1 | contracts | `tests/Contract/Orders/{Inventory,Catalog,Identity}ContractTest.php` (+ `InventoryLockOrderConcurrencyTest.php`); provider fixes the contracts need (Inventory batch reservations, modifier deltas, `Locations`; Identity `tables.manage`) | providers only | `contract:orders->*`, `concurrency` |
| 1.2 | shared.broadcast | live updates (ADR 0009): polling cursor feeds with ETag / 304 on the live host and in CI; broadcast companions of the order events behind `BROADCAST_CONNECTION` (`null` on the live host, nothing queued); channel auth for `station.{id}`, `user.{id}`, `branch.{id}.stock`, `branch.{id}.orders`, so Reverb or a Pusher-compatible service is a configuration switch | Identity `can` | `feature` |
| 1.3 | orders | `dining_tables`, `order_counters`, `orders`, `order_lines` (`unit_price_snapshot`, `recipe_version`, `station_id`, `location_id`, `client_uuid`, `reservation_ref`), `order_line_modifiers`, `order_events`; both state machines; `SendOrder` (one transaction, `reserveMany`); accept / ready (consume) / reject (release) / served; void with manager PIN (unpaid orders only, `releaseMany`; refunds are Phase 4); `Tickets::since`; `Orders::totals` | Inventory, Catalog, Identity, Shared | `module:orders`, `unit`, `feature`, `concurrency` |
| 1.4 | payments (skeleton, final schema) | `payment_channels` seeded with all 8 channels and their `requires_photo` / `requires_reference`; `payments`; `POST /orders/{id}/payments` where only cash can pass validation yet; `PaymentRecorded` → Orders `paid` → `sale` movements for display-case lines → `closed` | Orders, Identity | `module:payments`, `contract:payments->orders` |
| 1.5 | read models | `GET /menu?location` (products with availability, price, isNew, modifiers), `GET /stations/{id}/tickets?since=<cursor>`, `GET /orders?mine|unpaid` | Catalog, Inventory, Orders | `feature` |
| 1.6 | web | `/pad`, `/kds/kitchen`, `/kds/bar`, `/till` (cash pay), `LiveFeed` client (polling by default, Echo when a WebSocket transport is configured), Workbox shell cache, minimal outbox (send order, accept, ready) with client UUID + `Idempotency-Key`, admin Orders live board | all above | Playwright `p1-order-flow`, `p1-shortfall` |
| 1.7 | docs | `Phase1Seeder` (dining tables, payment channels, shared pad, sample orders); ADR 0008 reserve at send / consume at ready / sale at pay; ADR 0009 live updates polling first; OpenAPI; ERD; report | | |

Forced stubs: Payments gets its final schema now (no throwaway); `orders.shift_instance_id` stays null
until Phase 3; `LineReady` reaches the waiter through the live-update transport directly (the pad's own orders
feed, polled on the live host; `user.{id}` once a WebSocket transport is on; persisted notification arrives in Phase 2). Pad availability is computed at the item's station location; display-case products
at the `display_case` location. Say this explicitly in the plan.

## Contract tests (consumer view, written first)
- Inventory: `reserve()` for two lines sharing an ingredient reserves the sum; `Shortfall` names
  ingredient, `qty_short` in base unit and station; `release(ref)` twice is a no-op; `consume(ref, actual)`
  writes exactly one `production_consume` per ingredient and reduces `reserved` by the reserved amount;
  deterministic lock order: 50 interleavings never deadlock; `Availability::bulk` matches
  `floor(min(available / qty_per_unit))`. Added by the Phase 1 plan: `reserveMany` (one ref per line, one
  lock pass, the shortfall names the short line), modifier deltas, `consumeMany(refs, sale)` at payment,
  `releaseMany` at void, `Locations::forProduct`, the actor stamp on every movement.
- Catalog: `Prices::at(sent_at)` returns the price effective then, not now; `stationFor` is null for
  display-case products; `Modifiers::for` returns deltas in base units. Added: `Recipes::activeFor` /
  `version(n)` for the line's `recipe_version`; `Items::isNew` and `fulfilment`.
- Identity: `verifyShortCode` rejects a code from another branch; acting-as session expires at 10 min;
  `verifyPinFor('orders.void')` accepts manager, rejects cashier. Added: `can(user, 'orders.accept', station)`
  per station; the order permissions follow the matrix (incl. `tables.manage`).

## Functional tests
Exhaustive state-machine tables for order and line; `SendOrder` is a single transaction (assert one
`BEGIN` via query log); idempotent replay returns the identical body and creates one order; reject
releases; ready consumes; void with PIN releases and audits both actors; cash payment closes the order
and writes `sale` for display-case lines; channel auth and the tickets feed are 403 for a foreign station;
`Tickets::since` cursor is monotonic and never skips a late-committed event (overlap window, ADR 0009); an
unchanged feed answers 304; `GET /menu` hides availability-0 products and shows "3 left" counts.

## Exit gate (`gate:p1`, CI job `gate-p1`)
1. Playwright `p1-order-flow` with four contexts (pad, kitchen KDS, bar KDS, till): short code → add
   chicken sandwich + latte + one display-case product → Send → kitchen ticket visible within 5 s through
   the configured live-update transport (polling on the live host and in CI: after the initial load the KDS
   issues only `tickets?since=<cursor>` requests with `If-None-Match`, answered 304 while nothing changes;
   ADR 0009) → accept → ready (kitchen and bar) → pad badge → served → till cash pay → closed.
   Post-conditions via API: movement sequence `reservation, production_consume` for the station lines'
   ingredients and `reservation, sale` for the display-case line; `available` equals seeded minus sold.
2. Playwright `p1-shortfall`: selling more than available shows the inline shortfall on the exact line.
3. `gate:p1` Pest covers the backend assertions above.
4. All Phase 0 gate checks still green.

## Artifacts
ADR 0008, ADR 0009 (ADR 0007 bearer tokens already written in Phase 0), OpenAPI diff reviewed, ERD, `phase-1-report.md`,
STATUS: orders, payments(skeleton) `stable`.

## Traps specific to this phase
Polling cursor gaps and 304s (ADR 0009) · shared-host load from polling · Reverb host split and private-channel
auth once a WebSocket transport is switched on · Octane `scoped()` bindings (VPS path) · deadlocks between orders
(one lock pass per order, ADR 0008) ·
Playwright against the built app with service workers blocked · idempotency race with two parallel
requests · reason → delta map in one class.
