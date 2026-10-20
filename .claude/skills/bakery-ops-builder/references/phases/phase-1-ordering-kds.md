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
| 1.1 | contracts | `tests/Contract/Orders/{Inventory,Catalog,Identity}ContractTest.php` | providers only | `contract:orders->*` |
| 1.2 | shared.broadcast | Reverb config; private channel auth for `station.{id}`, `user.{id}`, `branch.{id}.stock` | Identity `can` | `feature` |
| 1.3 | orders | `orders`, `order_lines` (`unit_price_snapshot`, `recipe_version`, `station_id`, `client_uuid`, `reservation_ref`), `order_line_modifiers`, `order_events`; both state machines; `SendOrder` (one transaction); accept / ready (consume) / reject (release) / served; void with manager PIN (unpaid orders only; refunds are Phase 4); `Tickets::since` | Inventory, Catalog, Identity, Shared | `module:orders`, `unit`, `feature`, `concurrency` |
| 1.4 | payments (skeleton, final schema) | `payment_channels` seeded with all 8 channels and their `requires_photo` / `requires_reference`; `payments`; `POST /orders/{id}/payments` where only cash can pass validation yet; `PaymentRecorded` → Orders `paid` → `sale` movements for display-case lines → `closed` | Orders, Identity | `module:payments`, `contract:payments->orders` |
| 1.5 | read models | `GET /menu?location` (products with availability, price, isNew, modifiers), `GET /stations/{id}/tickets?since=<cursor>`, `GET /orders?mine|unpaid` | Catalog, Inventory, Orders | `feature` |
| 1.6 | web | `/pad`, `/kds/kitchen`, `/kds/bar`, `/till` (cash pay), Echo client, Workbox shell cache, minimal outbox (send order, accept, ready) with client UUID + `Idempotency-Key`, admin Orders live board | all above | Playwright `p1-order-flow`, `p1-shortfall` |
| 1.7 | docs | `Phase1Seeder` (tables, open orders); ADR 0008 reserve at send / consume at ready / sale at pay; OpenAPI; ERD; report | | |

Forced stubs: Payments gets its final schema now (no throwaway); `orders.shift_instance_id` stays null
until Phase 3; `LineReady` reaches the waiter over Reverb `user.{id}` directly (persisted notification
arrives in Phase 2). Pad availability is computed at the item's station location; display-case products
at the `display_case` location. Say this explicitly in the plan.

## Contract tests (consumer view, written first)
- Inventory: `reserve()` for two lines sharing an ingredient reserves the sum; `Shortfall` names
  ingredient, `qty_short` in base unit and station; `release(ref)` twice is a no-op; `consume(ref, actual)`
  writes exactly one `production_consume` per ingredient and reduces `reserved` by the reserved amount;
  deterministic lock order: 50 interleavings never deadlock; `Availability::bulk` matches
  `floor(min(available / qty_per_unit))`.
- Catalog: `Prices::at(sent_at)` returns the price effective then, not now; `stationFor` is null for
  display-case products; `Modifiers::for` returns deltas in base units.
- Identity: `verifyShortCode` rejects a code from another branch; acting-as session expires at 10 min;
  `verifyPinFor('orders.void')` accepts manager, rejects cashier.

## Functional tests
Exhaustive state-machine tables for order and line; `SendOrder` is a single transaction (assert one
`BEGIN` via query log); idempotent replay returns the identical body and creates one order; reject
releases; ready consumes; void with PIN releases and audits both actors; cash payment closes the order
and writes `sale` for display-case lines; Reverb channel auth 403 for a foreign station; `Tickets::since`
cursor is monotonic; `GET /menu` hides availability-0 products and shows "3 left" counts.

## Exit gate (`gate:p1`, CI job `gate-p1`)
1. Playwright `p1-order-flow` with three contexts (pad, kitchen KDS, till): short code → add sandwich
   + latte → Send → kitchen ticket visible within 5 s over WebSocket (assert no `/tickets` request after
   the initial load) → accept → ready → pad badge → served → till cash pay → closed. Post-conditions via
   API: movement sequence `reservation, production_consume, sale`; `available` equals seeded minus sold.
2. Playwright `p1-shortfall`: selling more than available shows the inline shortfall on the exact line.
3. `gate:p1` Pest covers the backend assertions above.
4. All Phase 0 gate checks still green.

## Artifacts
ADR 0008 (ADR 0007 bearer tokens already written in Phase 0), OpenAPI diff reviewed, ERD, `phase-1-report.md`, STATUS: orders, payments(skeleton)
`stable`.

## Traps specific to this phase
Reverb host split and private-channel auth · Octane `scoped()` bindings (two users on one worker) ·
Playwright against the built app with service workers blocked · idempotency race with two parallel
requests · reason → delta map in one class.
