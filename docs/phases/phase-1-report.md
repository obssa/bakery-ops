# Phase 1 gate report — Ordering and live KDS

Commit: the `done_commit` recorded in `docs/phases/STATUS.json` (set by `claim-done` on `main` after the merge) · CI
evidence: pull request #24 (`phase-1/gate`), run [39516311905](https://github.com/obssa/bakery-ops/actions/runs/39516311905),
every job green including `gate-p0` and `gate-p1`; the steps before it ran green on `main` (last: run
[39507435107](https://github.com/obssa/bakery-ops/actions/runs/39507435107) at `3758446`, 13 min 8 s) · Date: 2026-10-21

## Verdict

PASS: every Phase 1 gate criterion is green in CI on the production engine (MariaDB 10.11) and, tagged `@live`, on the
deployed site; both Phase 1 modules (orders, payments) are stable; the four required contracts are green; invariants,
OpenAPI, ERD and ADRs are clean. One decision is owed to Ramin (negative stock, below), and four known gaps are listed
under deviations.

## Gate criteria (from the manifest `phases.1.gate.criteria`)

| # | Criterion | Evidence (test name, CI job, numbers) | Result |
|---|---|---|---|
| 1 | `p1-order-flow`: four browser contexts (pad, kitchen KDS, bar KDS, till): short code -> chicken sandwich + latte + one display-case product -> Send -> kitchen ticket within 5 s through the configured transport (after the initial load only `tickets?since=` with `If-None-Match`, 304 while nothing changes) -> accept -> ready on both -> pad ready badge -> served -> till cash pay -> closed | `apps/web/e2e/p1/order-flow.spec.ts` (`@live`), CI job `e2e-p1` on the compose stack with the polling transport: 7 passed (47.2 s) across `p1-order-flow` and `p1-shortfall`, among them the full flow (8.5 s), the kitchen display (12.8 s), the till void, the admin board and the pad lock tests. The same flow ran against the deployed site after the 1.6c deploy ([39509058294](https://github.com/obssa/bakery-ops/actions/runs/39509058294), "Playwright @live against the site"): 13 passed (1.9 min), the full flow in 20.4 s | PASS |
| 2 | Post-conditions via API: movement sequence `reservation, production_consume` for the station lines' ingredients and `reservation, sale` for the display-case line; available = seeded - sold | The same spec reads the ledger through the API (`purchase_in, reservation, production_consume` for the sandwich and the latte, `purchase_in, reservation, sale` for the croissant; menu `available` equal to what was stocked minus what was sold). In Pest: `apps/api/tests/Gate/OrderMovementsGateTest.php` and `OrderLedgerPropertyTest.php` (below), run in `mod-orders` | PASS |
| 3 | `p1-shortfall`: selling more than available shows the inline shortfall on the exact line | `apps/web/e2e/p1/shortfall.spec.ts` (`@live`): 3 ordered of an ingredient stocked for 2, the shortfall "short by 1000 g at Kitchen" on that line in English and in Amharic, the reduced order sends, the ledger holds one reservation. `e2e-p1` (3.7 s) and the live site (9.1 s), green | PASS |
| 4 | `gate:p1` Pest: exhaustive state-machine tables | `apps/api/tests/Gate/Phase1StateMachinesTest.php` (`gate:p1`, `unit`, `module:orders`): hand-written from-to tables of `OrderStatus` (81 pairs), `LineStatus` (36) and `PaymentStatus` (16), each pair either succeeds or throws `IllegalTransition`; terminal states are exactly those with no way out. Runs in `mod-orders`; the generic walk over every machine is `tests/Unit/StateMachineExhaustiveTest.php` in `api-arch` | PASS |
| 5 | `gate:p1` Pest: `SendOrder` is one transaction; an idempotent replay returns the same body and one order | `apps/api/tests/Gate/SendOrderGateTest.php`: the send's writes (orders, lines, counters, events, movements, balances, audit) all sit under one `BEGIN`; the same `Idempotency-Key` and body answer the identical body with `Idempotency-Replayed: true`, one order and no new movement; the same client UUID under a new key answers the same order. Detail: `SendOrderTest` (21 cases) | PASS |
| 6 | `gate:p1` Pest: void audits both actors | `apps/api/tests/Gate/VoidGateTest.php`: a cashier's PIN is refused, a manager's PIN voids; every reservation is released, the audit row's actor is the asker and its context names the approver, the timeline row carries both, no PIN is stored. Detail: `VoidOrderTest` (14 cases) | PASS |
| 7 | `gate:p1` Pest: foreign-station channel auth and tickets feed are 403 | `apps/api/tests/Gate/LiveChannelsGateTest.php`: a chef is refused the bar's channel (`POST /broadcasting/auth`, `identity.channel.forbidden`, English and Amharic) and the bar's tickets (`orders.station.forbidden`), served their own. Detail: `BroadcastingAuthEndpointTest`, `StationTicketsEndpointTest` | PASS |
| 8 | Property test from plan section 7 | `apps/api/tests/Gate/OrderLedgerPropertyTest.php` (`gate:p1`, `property`, `module:orders`): 100 seeded sequences of at most 50 operations (send, accept, ready, reject, served, void, pay) over a menu with shared chicken, lettuce, butter and milk and scarce stock, through the Orders and Payments actions. After every operation: movements == balances == view, `reserved` == the reservation rows of the lines still open, `available` never below zero, a refused send writes nothing; at the end every closed order's lines show `reservation -> production_consume` (station), `reservation -> sale` (counter) or `reservation -> reservation_release` (rejected). 64 005 assertions, 200 s locally; the seed is in every failure message, `ORDER_LEDGER_PROPERTY_SEED=<n>` replays one. `mod-orders`: 651 passed (82 532 assertions, 190 s) | PASS |
| 9 | Earlier gates, arch, invariants, OpenAPI and ERD stay green | `gate-p0` green in the same run; `api-arch` 962 passed (19 346 assertions); `invariants` 0 violations; `openapi-check` "openapi in sync"; `erd-check` 0 missing; `e2e-p0` 8 passed (10.8 s) with `SEED_PHASE=0` | PASS |

`gate_check.py --mode done --phase 1 --ci` (job `gate-p1`) passed in the PR run: ADRs 0008 and 0009 present,
`openapi.json` present, ERD covers the manifest's tables, no phase-1 to-do markers, no `->skip(` in Phase 1 module
tests; the report check was the one warning before this file existed. The job does not run Pest itself (`--ci` trusts
the module jobs): the `gate:p1` tests are tagged `module:orders` as well and run in `mod-orders`, and nothing in CI
runs `--group=gate:p1` as a group of its own (see deviations).

## Dependencies proven

| Contract | Cases | Status |
|---|---|---|
| `orders->inventory` (`tests/Contract/Orders/InventoryContractTest.php`, `InventoryLockOrderConcurrencyTest.php`) | sum per shared ingredient, shortfall shape with the line's ref, release twice is a no-op, one `production_consume` per ingredient, per-unit modifier deltas, `consumeMany(sale)`; 50 rounds of two orders locking shared ingredients in opposite order, real parallel processes, no deadlock | 12 passed (263 assertions) in `mod-inventory`; written red first in step 1.1 (`10f8fd3`), provider fixes `53c3371` |
| `orders->catalog` (`tests/Contract/Orders/CatalogContractTest.php`) | `Prices::at`, `Recipes::activeFor`, `Routing::stationFor`, `Modifiers::for` | 5 cases, in the 9 passed (290 assertions) of `mod-catalog`'s contract run; green against Catalog as it was |
| `orders->identity` (`tests/Contract/Orders/IdentityContractTest.php`) | short-code verification, acting session, `can(user, 'orders.*', station)`, `verifyPinFor('orders.void')`, `tables.manage` | 5 cases, in the 59 passed (378 assertions) of `mod-identity`'s contract run; provider fix `2c74826` |
| `payments->orders` (`tests/Contract/Payments/OrdersContractTest.php`) | totals as decimal strings; a served order paid in full goes `paid`, writes one `sale` per display-case line and closes with `OrderPaid`; a payment before the food only settles; a rolled-back payment leaves order and ledger untouched; a part payment settles nothing | 5 passed (79 assertions) in `mod-orders`; committed before any Payments code |

## Modules declared stable in this phase

| Module | module:X | contract:*->X | arch boundary | route coverage |
|---|---|---|---|---|
| orders | `module:orders` 651 passed (82 532 assertions), `mod-orders`, 190 s | `payments->orders` 5 passed; `shifts->orders` and later consumers are written in their phases | `ModuleBoundariesTest` green (Orders uses only Inventory, Catalog, Identity, Shared, Audit; never Payments; Payments reach it through `Orders\Contracts` only) | 16 operations (orders, order lines, dining tables, menu, tickets), each in the registry; the matrix test calls every one as every role |
| payments | `module:payments` 42 passed (496 assertions), `mod-payments`, 9 s | `shifts->payments` is written in Phase 3 (allowed empty) | green | 2 operations (`GET /payment-channels`, `POST /orders/{order}/payments`), covered |

Stable since (STATUS.json): orders 2026-10-20T15:43Z, payments 2026-10-20T17:15Z, each after `gate_check.py --mode module`.
Steps 1.1 contracts, 1.2 shared.broadcast, 1.3 orders, 1.4 payments, 1.5 read-models, 1.6 web and 1.7 docs are done.

## Earlier gates re-run

`gate-p0`: green at this commit in the same run
([job 118952000772](https://github.com/obssa/bakery-ops/actions/runs/39516311905/job/118952000772)). Its Pest groups
re-ran in `mod-inventory` (596 passed), `mod-catalog` (226), `mod-identity` (251) and `api-arch`, and `e2e-p0` seeds
`SEED_PHASE=0` and still passes (8 tests), so the Phase 1 seeder leaves Phase 0's world alone.

## Invariants

`check_invariants.py --all --routes <artisan route:list --json>`: 0 violations across numeric, branch-id, bilingual,
append-only, idempotency and i18n (CI job `invariants`, run 39516311905, and locally). `invariants_version`: 1. New in
Phase 1: `order_events` is append-only by trigger (`AppendOnlyTest`), the order, line, event and payment tables carry
`branch_id`, NUMERIC money (12,2) and quantities (14,4), `_en`/`_am` pairs on the table and modifier names, the actor
stamp, and every new write route has `idempotency` and a permission; `POST /broadcasting/auth` is the one read-like
write (`tests/Support/read_like_writes.php`, mirrored in `check_invariants.py`).

## Artifacts produced

- ADRs, both now `Status: accepted` with an "As built" section: 0008 reserve at send, consume at ready, sale at pay, one
  reservation per line, one lock pass per order; 0009 live updates through a transport abstraction, polling first.
- OpenAPI `docs/openapi.json`: 43 paths, 58 operations (Phase 0: 26 and 39). Phase 1 added 19 operations: orders (create,
  list, show, replace lines, send, cancel, void), order lines (accept, ready, reject, served), dining tables (list,
  create, update), `GET /menu`, `GET /stations/{station}/tickets`, `GET /payment-channels`, `POST /orders/{order}/payments`
  and `POST /broadcasting/auth`; `stations` entries of `/me` and `/users` gained `code`, `name_en`, `name_am`. Additive
  only, so no breaking changes. Re-exported for this step against the migrated MariaDB: byte-identical to the file.
- ERD `docs/erd.dbml`: every migrated table (41 plus the `item_stock_view` view, 0 missing), including the eight Phase 1
  tables `dining_tables`, `order_counters`, `orders`, `order_lines`, `order_line_modifiers`, `order_events`,
  `payment_channels`, `payments`; `branches.catalog_version` added in this step.
- Seeds: `Phase1Seeder` extends the demo bakery (12 dining tables, the eight payment channels, the shared pad with its
  "Front pad" device and "Pad 1" account, and today's seven sample orders at different stages), composed by `DemoSeeder`
  and run by `bakery:seed-demo` (runbook, "Demo bakery"). Idempotent; a branch that holds Phase 0 gains only what Phase 1
  brings.
- Migrations: ten in `2026_10_21_*` (the Orders and Payments tables, the grant-only permissions migration, and
  `branches.catalog_version`), all additive.
- Live site https://bakery-ops.orcamind.dev: steps 1.6a, 1.6b and 1.6c were each deployed after green CI; the deploy of
  1.6c ([39509058294](https://github.com/obssa/bakery-ops/actions/runs/39509058294): build release, deploy, "Playwright @live
  against the site") passed 13 of 13 `@live` tests in 1.9 minutes: the six Phase 0 tests, the full pad -> kitchen and bar ->
  till flow, the kitchen display, the till void, the admin board, the pad lock and the shortfall flow. This step's code
  ships with the merge; its `@live` run is the next deploy's.

## Deviations from the plan and assumptions taken

Each one is an "As built" note in `docs/phases/phase-1-plan.md` (section 3 for schema, section 6 per step, 1.7 last):

- **Provider fixes inside Phase 1** (1.1): Inventory `reserveMany`/`releaseMany`/`consumeMany` with one ascending lock
  pass, per-unit modifier deltas, `Locations`; Identity `tables.manage`. Phase 0's single-ref methods behave as before.
  A grant-only migration brings new registry permissions to existing databases (a deploy runs no seeders).
- **Live updates** (1.2, ADR 0009): polling with ETag/304 is the transport; the ETag covers the unsettled tail, not only
  the highest id; `LiveTransport` treats `log` like `null`; channel rules live behind `POST /broadcasting/auth`, not
  `routes/channels.php`; `e2e-p1` starts no Reverb.
- **Schema** (1.3a): `orders.order_no` and `business_date` are null while the order is an open cart; the progress states
  only move forward and may skip (`sent -> ready` for display-case orders, `in_progress -> served` after a reject);
  all lists are named CHECKs mirroring the enums.
- **Send and cart** (1.3b): `POST /orders` writes the cart in one transaction and sends in a second, so a refused send
  answers 409 with the cart open; the day's number is taken after the reservation succeeds; an order is changed by its
  owner or a `stations.all` holder; `PUT /orders/{order}/lines` keeps a line's product and modifiers fixed.
- **Stations, void, payments** (1.3c-1.4): ready may follow queued without accept; an order whose lines are all
  rejected becomes `cancelled`; void releases display-case lines too and verifies the PIN before the transaction opens;
  payment before the food only settles the order, and `Settlement` closes it when the last line is served; the sale row
  is written at close; `BranchCreated` is dispatched from the `Branch` model hook so no path forgets the channels.
- **Read models** (1.5): bulk capabilities keep `GET /menu` at a fixed number of queries; `available` is a 4 dp string;
  `GET /orders?since=` returns every changed order whatever its status; the table state `bill` exists but is never
  produced.
- **Web** (1.6): the chef's rail needed `code` on `stations`; the outbox covers send, accept, ready and reject;
  the pad's acting session lives in memory only; a defect found by the lockout flow (`AttemptCounter` on Redis) was fixed.
- **Menu ETag** (1.7): the follow-up from step 1.5 is fixed exactly with `branches.catalog_version`, see the plan note.
- **Known gaps, not blockers:** the order timeline shows user ids only (names need an API change); the reverb/pusher
  transport is compiled but not exercised in CI (no WebSocket host); payments are online-only, not in the outbox; the
  10-minute acting-session expiry and the ready chime have no automated test; the ESLint rule that bans timers outside
  `src/lib/live/` (ADR 0009, enforcement) was never written, and `gate:p1` is not run as a group of its own in CI (the
  gate files ride in `mod-orders`); the locations' `updated_at` in the stock half of the menu tag still has whole-second
  precision, which only matters for two location edits inside one second.

## What Ramin needs to do

- [ ] Add `gate-p2` to the required status checks on `main` (the orchestrator does it with `gh api` after the merge).
- [ ] Review and approve `docs/phases/phase-2-plan.md` when it exists.
- [ ] Decide on negative stock (still open from Phase 0, and it changes what the ledger means). Today the Ledger refuses
  any movement that would take a location's `on_hand` below zero, so a sale or a ready that the system cannot cover is
  blocked until a purchase, production output or count is recorded first; on the floor that shows as the shortfall on
  the exact line, and the kitchen cannot make what the system believes it has no ingredients for. The alternative is to
  let `on_hand` go negative and flag it for an explanation at the station's shift count (Phase 3). Until you decide, the
  strict rule stays; nothing in Phase 1 depends on the answer.
- [ ] Optional: to show Phase 1 on the live demo bakery, keep `BAKERY_DEMO_PASSWORD` in the server `.env` and run
  `php artisan bakery:seed-demo --force` once after the deploy (`docs/runbook/server.md`, "Demo bakery"). It adds the
  tables, the "Pad 1" account and seven sample orders; it needs your server access.
- [ ] Optional: say whether the pilot should get a WebSocket host (a hosted Pusher-compatible service) now or after the
  polling load has been measured; the code switches by configuration (ADR 0009).
