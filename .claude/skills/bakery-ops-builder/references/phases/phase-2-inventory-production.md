# Phase 2 — Inventory and production (weeks 6–8)

Closes the loop the pilot needs most: a product runs out, the right baker is told, production is
recorded against the recipe, and the product is back on the pad. Also the first phase with queued
work and notifications, which is where "works locally, fails in compose" traps live.

## Entry criteria (`gate_check.py --mode entry --phase 2`)
- `gate-p1` green on `main`; plan approved.
- `contract:production->inventory|catalog|identity` and `contract:notifications->identity` green with
  no Production or Notifications code yet.

## Scope in build order

| Step | Module | Builds | Consumes | Groups |
|---|---|---|---|---|
| 2.1 | contracts | `tests/Contract/Production/*`, `tests/Contract/Notifications/IdentityContractTest.php` | | `contract:production->*`, `contract:notifications->identity` |
| 2.2 | inventory.purchases | `suppliers`, `purchases`, `purchase_lines`; confirm → `purchase_in`; `item_costs` moving average to 4 dp | Ledger, Catalog units | `module:inventory` |
| 2.3 | inventory.transfers | `transfers` requested → confirmed/rejected → `transfer` movements | Ledger, Identity perms | `module:inventory` |
| 2.4 | catalog.recipe-versions + inventory.recipe-cost | publish new version (swap `is_active` in one transaction, old kept readable by `version`); `RecipeCost::rollUp`; margin vs `Prices::at` | Costs | `module:catalog`, `module:inventory` |
| 2.5 | notifications | `notifications`, `Notifier`, drivers (in_app, push VAPID, telegram; `array` in CI), dedupe store, locale rendering | Identity | `module:notifications` |
| 2.6 | inventory.reorder | `reorder_rules`; `EvaluateThresholds` job on `StockChanged`; `StockLow` / `StockOut` with `stock_alert_states` for re-fire after recovery | Notifications (events) | `feature` |
| 2.7 | production | `production_requests`, `production_batches`, state machines, `stations.requires_overseer`, `Batches::confirm` atomic (N consume + 1 output), `AutoRequest` on `StockOut` for `kind=product` with a non-empty recipe, `AssignedUsersResolver`, `NullShiftAttacher`; `advance` order schema fields (due_at, deposit) land here, UI later | Inventory, Catalog, Identity, Notifications | `module:production`, `unit` |
| 2.8 | web | `/production` kanban; KDS stock strip + transfer request; till display-case panel + Request production; admin Inventory (stock by location, ledger, purchases, transfers, reorder) + recipe versions with cost + production planner | | Playwright `p2-stockout-production`, `p2-purchase-transfer` |
| 2.9 | docs | `Phase2Seeder`; ADR 0008 station presence resolver interface; ADR 0009 alert dedupe; OpenAPI; ERD; report | | |

## Contract tests (consumer view, written first)
- Inventory: `appendMany` is all-or-nothing (inject a failure on the last movement, assert zero rows);
  `Stock::of` reflects a `purchase_in` immediately; `StockOut` fires exactly once when `available`
  crosses to 0 and not again until it rises above 0.
- Catalog: a recipe with yield 40 scaled to 60 multiplies every line by 1.5 and converts to base units;
  `default_batch_qty` present for every produced product.
- Identity: `assignedUsers(station)` excludes inactive users; `isOverseer` is per station;
  `StationPresenceResolver::onDuty` returns assigned users under the Phase 2 binding.
- Notifications → Identity: recipients by role and station; locale picks `_en` / `_am`.

## Functional tests
Purchase confirm → `purchase_in` + moving average `(old_qty·old_cost + new_qty·new_cost)/(old_qty+new_qty)`
to 4 dp; transfer request/confirm moves balances between locations; version publish keeps the old
version; cost roll-up and margin; reorder evaluation runs on the queue (`Queue::fake` asserts dispatch,
a `sync` twin asserts the effect); dedupe; production state machine exhaustive; `Batches::confirm` writes
N consume + 1 output atomically; overseer gating per station; `AutoRequest` only for produced items.

## Exit gate (`gate:p2`, CI job `gate-p2`)
1. Playwright `p2-stockout-production`: seed cake `on_hand = 1`; pad sells it → request card appears on
   `/production` → baker accepts → Done (6 produced, 0 waste) → where the station requires an overseer,
   pending → overseer confirms → pad shows cake `6` available. The `worker` container must be running.
2. Playwright `p2-purchase-transfer`: admin purchase → store balance; kitchen requests transfer → store
   confirms → balances move.
3. `gate:p2` Pest covers the backend assertions above.
4. Phase 0 and 1 gates still green.

## Artifacts
ADR 0008, ADR 0009, OpenAPI, ERD, `phase-2-report.md`, STATUS: production, notifications `stable`,
inventory and catalog remain `stable` (their contracts extended).

## Traps specific to this phase
Queued listener vs `sync` · `worker` container in compose · dedupe reset semantics · moving-average
rounding · `interface_only` dependency on Shifts (never import it) · seeds must leave a product at
`on_hand = 1` for the gate.
