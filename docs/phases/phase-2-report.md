# Phase 2 gate report — Inventory and production

Commit: the `done_commit` recorded in `docs/phases/STATUS.json` (set by `claim-done` on `main` after the merge) · CI
evidence: pull request #42 (`phase-2/gate`), run [39718390707](https://github.com/obssa/bakery-ops/actions/runs/39718390707)
at the STATUS commit, every job green including `gate-p0`, `gate-p1`, `gate-p2`, `e2e-p0`, `e2e-p1` and `e2e-p2`; the steps before it
ran green on `main` (last: step 2.8d, `51266a9`) · Date: 2026-10-22

## Verdict

PASS, pending the merge: every Phase 2 gate criterion is green in CI on the production engine (MariaDB 10.11), and both p2 flows
ran against https://bakery-ops.orcamind.dev after the 2.8c and 2.8d deploys; the five modules Phase 2 touches are stable; the four
required contracts are green; invariants (version 2), OpenAPI, ERD and both ADRs are clean. Three decisions are owed to Ramin
(below). The `@live` run after the 2.8d deploy had three red tests, all test-side selections that the live E2E branch's history
breaks; they are fixed in this PR and need the next deploy's `@live` run to show green.

## Gate criteria (from the manifest `phases.2.gate.criteria`)

| # | Criterion | Evidence (test name, CI job, numbers) | Result |
|---|---|---|---|
| 1 | `p2-stockout-production`: cake `on_hand 1` -> the pad sells it -> the request card appears on `/production` -> the baker accepts -> Done (6 produced, 0 waste) -> the overseer confirms where required -> the pad shows 6 available; the worker container is running | `apps/web/e2e/p2/stockout-production.spec.ts` (`@live`), CI job `e2e-p2` on the compose stack (polling, `worker` container on Redis): 7 passed (46.6 s) across `p2-stockout-production` and `p2-purchase-transfer`, among them the full flow (three contexts: pad, baker, overseer; the request awaited with `waitForQueue`, ledger `purchase_in, reservation, production_output` for the cake and `purchase_in, production_consume` for the ingredient read through the API, one `stock_out` and one `production_requested` line), the sandwich / shortfall / transfer-from-the-card test, the planner flow and the bell. The flow ran live after the 2.8c deploy (16 passed, 1 flaky: the pad idle-locked during the cron wait; fixed here with `ensureUnlocked`) | PASS |
| 2 | `p2-purchase-transfer`: admin purchase -> store balance; kitchen requests a transfer -> store confirms -> balances move | `apps/web/e2e/p2/purchase-transfer.spec.ts` and `purchase-admin.spec.ts` (`@live`), `e2e-p2` as above: the admin records and confirms a purchase in the browser (store +20 kg, average equal to the typed cost to 4 dp), a second purchase moves the average to the value the test computes, the chef requests 5 kg from the stock strip, the manager confirms (store -5 kg, kitchen +5 kg, two `transfer` rows with one ref), a second request is rejected with a reason and nothing moves. Pest path: `tests/Gate/PurchaseTransferGateTest.php` (`mod-inventory`) | PASS |
| 3a | `gate:p2` Pest: moving average to 4 dp | `tests/Unit/Inventory/MovingAverageTest.php` (16 worked cases incl. half-up at the 5th decimal both ways, unvalued stock, the largest values), `MovingAveragePropertyTest.php` (300 seeds against an exact rational oracle), the first case of `ConfirmPurchaseTest` (`costs.cost_after` `0.0800`, `0.1205`), and the chain in `PurchaseTransferGateTest` (0.0800 then 0.0850, with an independent decimal computation). All `gate:p2`; run in `api-arch` (unit) and `mod-inventory` (1877 passed, 166 780 assertions) | PASS |
| 3b | version publish keeps the old version readable | `tests/Gate/RecipeVersionGateTest.php` (`gate:p2`): version 1 readable by `version` after 2 is published, exactly one active, an order sent under version 1 consumes version 1's quantities; the race `RecipePublishRaceTest` (one new version, one 409). `mod-catalog` 297 passed (2349 assertions) and `mod-orders` 652 passed (82 577) | PASS |
| 3c | dedupe | `tests/Modules/Notifications/StockAlertDedupeTest.php` and `tests/Modules/Inventory/Feature/AlertEpisodePropertyTest.php` (`gate:p2`): low once, out once, nothing while inside an episode, again after every recovery, a recovery tells nobody; random availability walks (episodes alternate, never two open, `StockOut` count equals the falls); plus `LowAlertRaceTest` and the redelivery cases. `mod-notifications` 263 passed (1704) and `mod-inventory` | PASS |
| 3d | atomic batch (N consume + 1 output) | `BatchConfirmTest` (N consume rows and one output row), `BatchDoneTest` (the combined path, scaled to produced plus waste, four places) and the rollback case of `BatchesContractTest` (a failure on the last movement leaves no rows, no balances, the batch still started), all `gate:p2`; `ProductionLedgerPropertyTest` (`gate:p2`, random requests, batches, send-backs, confirms, transfers, sales: movements == balances == view, consume rows exactly the recipe at the recorded version scaled to produced plus waste, exactly one output); `ProductionBatchRaceTest` (15 rounds each: one set of N + 1 under double confirm, no deadlock in opposite order). `mod-production` 286 passed (9565 assertions) | PASS |
| 3e | AutoRequest only for produced items with a recipe | `AutoRequestTest` (`gate:p2`): one request for the batch size at the producing station; none for an ingredient, a bought-in or made-to-order product, a product without a recipe or a station; the same stock-out delivered twice makes one request; `ProductionRequestRaceTest` (two creations for one episode). `mod-production` | PASS |
| 3f | the stock-out to production path in Pest, on the seeded gate product | `tests/Gate/StockOutToProductionGateTest.php` (`gate:p2`): the demo seed at `SEED_PHASE=2`, "Honey cake (slice)" at `on_hand = 1`; a waiter sells it through `POST /orders` -> menu `available` 0 -> the automatic request for 6 -> the cake baker accepts, starts, Done (6, 0) -> pending (nothing written) -> the manager confirms -> seven consume rows at the exact scaled quantities and one output row -> the menu offers 6 and both episodes are closed. `Phase2ActorGateTest` pins that every row of the chain names the person who caused it. `mod-production` | PASS |
| 4 | the four state machines | `tests/Gate/Phase2StateMachinesTest.php` (`gate:p2`): hand-written from-to tables of `RequestStatus` (36 pairs), `BatchStatus` (9), `PurchaseStatus` (9), `TransferStatus` (9); terminal states are exactly those with no way out | PASS |
| 5 | Earlier gates, arch, invariants, OpenAPI and ERD stay green | `gate-p0` and `gate-p1` green in the same run; `api-arch` 2125 passed (31 376 assertions); `invariants` 0 violations; `openapi-check` "openapi in sync"; `erd-check` 55 tables and 1 view, 56 documented, 0 missing; `e2e-p0` 8 passed (7.5 s) with `SEED_PHASE=0`, `e2e-p1` 7 passed (57.2 s) with `SEED_PHASE=1`, so the Phase 2 seeder leaves both worlds alone | PASS |

`gate_check.py --mode done --phase 2 --ci` (job `gate-p2`) in the PR run: 13 checks, 0 failed, 1 warning. ADRs 0010 and 0011
present, `openapi.json` present, ERD covers the manifest's tables, 0 phase-2 to-do markers, 0 `->skip(` in Phase 2 module tests;
the one warning was the gate report, which this file ends. The job does not run Pest itself (`--ci` trusts the module jobs): the
`gate:p2` tests carry a `module:` tag and run in `mod-inventory`, `mod-production`, `mod-notifications` and `mod-catalog`; nothing
in CI runs `--group=gate:p2` as a group of its own (the same gap as Phase 1).

## Dependencies proven

| Contract | Cases | Status |
|---|---|---|
| `production->inventory` (`tests/Contract/Production/InventoryContractTest.php`) | `appendMany` all-or-nothing with one actor stamp, `Stock::of` after a purchase_in, `Availability` where `Locations::forProduct` says, `StockOut` once per episode and `StockRecovered`, the queued-actor case | in `mod-inventory`; written red first in 2.1 (18 of 29 cases of the four files failed for the right reason) |
| `production->catalog` (`CatalogContractTest.php`) | scaling 40 to 60 with units, qty x target / yield once, the card's version / yield / steps, `batchQty`, `productionStationFor`; later the version-aware `scaled` | in `mod-catalog` |
| `production->identity` (`IdentityContractTest.php`) | the four new registry permissions, `assignedUsers`, `isOverseer` per station, both presence bindings, `ShiftAttacher` answering null; a roster double stands in for Shifts | in `mod-identity` |
| `notifications->identity` (`tests/Contract/Notifications/IdentityContractTest.php`) | `Recipients` by role, permission, station and ids, locale choice, inactive users out | in `mod-identity` |

## Modules declared stable in this phase

| Module | module:X | contract:*->X | arch boundary | route coverage |
|---|---|---|---|---|
| notifications | `module:notifications` 263 passed (1704 assertions) | none consume it (only events and Shared) | green: imports only Identity contracts, Audit and Shared; no module imports it except through Shared events; every listener `ShouldQueue` and after commit | every operation (inbox, read, read-all, channels, endpoints, Telegram link and webhook) in the registry and called as every role by the matrix test |
| production | `module:production` 286 passed (9565 assertions) | `shifts->production` is written in Phase 3 | green: imports Inventory, Catalog and Identity contracts only | every operation (requests, assign, accept, start, done, confirm, send back, cancel, board) covered by the matrix test |
| inventory (extended) | `module:inventory` 1877 passed (166 780 assertions, incl. property, concurrency and seeds) | `production->inventory` green | green | purchases, suppliers, costs, transfers, reorder rules, stock views, display case: all registered |
| catalog (extended) | `module:catalog` 297 passed (2349 assertions) | `production->catalog` green | green | recipe versions and recipe costs registered |
| identity (extended) | `module:identity` green | `production->identity`, `notifications->identity` green | green | `PATCH /stations/{station}` and the new permissions registered |

Inventory, Catalog and Identity stay `stable` (their contracts were extended additively); Notifications and Production became stable
in 2.5b and 2.7c after `gate_check.py --mode module`. Steps 2.1 contracts through 2.8 web and 2.9 docs are done in STATUS.

## Earlier gates re-run

`gate-p0` and `gate-p1`: green at this commit in the same run. Their Pest groups re-ran in `mod-inventory`, `mod-catalog`,
`mod-identity`, `mod-orders` (652 passed) and `api-arch`, and `e2e-p0` / `e2e-p1` seed `SEED_PHASE=0` / `1` and pass (8 and 7
tests).

## Invariants

`check_invariants.py --all`: 0 violations across numeric, branch-id, bilingual, append-only, idempotency and i18n (CI job
`invariants`, and locally). `invariants_version`: 2. New in Phase 2: cost rates (`unit_cost`, `moving_avg_cost`, `cost_before`,
`cost_after`) are `DECIMAL(14,4)` held as `UnitCost`, totals stay `(12,2)`, quantities `(14,4)`; `item_cost_history` is append-only
by trigger on both engines; every new table carries `branch_id`; title/body pairs are bilingual; every new write route has an
Idempotency-Key and a permission; `NoSecretsInEnvExampleTest` guards the push and Telegram keys.

## Artifacts produced

- ADRs, both `Status: accepted` with an "As built" section: 0010 the station presence resolver (interfaces in Identity, the
  assignment binding and the null shift attacher, what Phase 3 must keep true); 0011 stock alerts once per episode and the actor
  of queued work. Dated notes on 0004 (cost rates `DECIMAL(14,4)`) and 0009 (Phase 2 stays on polling).
- OpenAPI `docs/openapi.json`, ERD `docs/erd.dbml`: in sync (CI), every Phase 2 table documented (suppliers, purchases and lines,
  item costs and history, transfers, stock alert states, notifications with dedupe, endpoints and deliveries, reorder rules,
  production requests and batches; recipes extended). This PR changes no route, so neither file changed.
- Seeds: `Phase2Seeder` (data in `Demo\DemoPhaseTwo`) extends the demo bakery: three suppliers and 46 opening costs, two confirmed
  purchases and one draft, eight reorder rules, the gate product "Honey cake (slice)" at `on_hand = 1`, "Bread roll" version 2, three
  transfers (confirmed, requested, rejected) and four production requests (scheduled, accepted, waiting for the overseer, confirmed
  today). Composed by `DemoSeeder`, idempotent, run by `bakery:seed-demo` (runbook, "Demo bakery"); queued listeners run
  synchronously during the seed.
- Runbook: "Queued work (Phase 2)" and the Phase 2 demo paragraph; the push and Telegram procedure (2.5b) checked, nothing secret.
- Fixes found on the way: the bell's "Mark all as read" left lines that arrived since the last poll unread (the API was right;
  the web sent `before_id`); the pad idle-lock in the live stock-out flow; run-unique transfer notes and an id-selected planner
  assignee for the live E2E branch.
- Live site https://bakery-ops.orcamind.dev: the p2 flows ran there after the 2.8c deploy (16 passed, 1 flaky) and the 2.8d deploy
  (17 passed, 3 failed on test-side selections, fixed in this PR). This step's code ships with the merge; its `@live` run is the next
  deploy's.

## Deviations from the plan and assumptions taken

Each one is an "As built" note in `docs/phases/phase-2-plan.md` (section 6, per step; 2.9 last):

- **Contracts and events** (2.1): no per-user "withhold" of a permission exists (spatie grants are additive); `StockOut` / `StockLow`
  share `StockAlert` with `branch_id` on the envelope; `EvaluateBalance` does nothing for a balance no movement touched; every
  stock change queues an evaluation, so earlier tests that asserted "nothing queued" use `assertNothingQueuedButStockEvaluation`.
- **Costs and purchases** (2.2): cost rates are a word rule, not a suffix rule; a missing `item_costs` row is the unvalued state;
  `CostLedger` runs after the movements; `Ledger::lockItems` is new; a deadlock on a missing balance row (found by the race test)
  was fixed in `Ledger::lockRows` and `CostLedger::open`; no `DELETE /suppliers`.
- **Transfers** (2.3): a request does not look at stock, only the confirm does (409 with nothing written); repeats answer 200, the
  opposite decision 409; who may act is the person physically acting.
- **Recipe versions and costs** (2.4): publish is immediate (no draft), "unchanged" compares what was entered, a deadlock between
  two recipe writers was fixed by taking the branch lock first, `PUT` is refused once a version above 1 exists; cost roll-up is exact
  with rounding only for display, sub-recipes cost from their own recipe only when the item has no cost row.
- **Notifications** (2.5): the inbox key is per episode while the gate is per run-out; five types in the registry plus the transfer
  and production ones; the migration order differs (endpoints before deliveries); `Notifier::send` gained `$inAppOnly`; the Telegram
  webhook is public with a path secret; `minishlink/web-push` pinned at 11.0.0.
- **Reorder and views** (2.6): `low` and `out` are independent episodes; a rule change writes no episode, the next evaluation does;
  `StockLow` gained `unit`; an hourly sweep heals lost evaluations.
- **Production** (2.7): the sign-off CHECK was corrected (the confirmer differs from the finisher only where an overseer signs);
  `Identity\People` and `Recipients::overseers` were added for Production; the combined path (no overseer) finishes and confirms in
  one write; Done frees the stock-out key and the product's recovery closes the episode.
- **Web** (2.8): writes are online with an Idempotency-Key, not through the outbox; the bell, board and strips poll (15 s, 5 s);
  admin screens show base units; notification deep links are mapped to the screens that exist; `TICKET_VISIBLE_MS` is 5 s in CI and
  10 s live.
- **This step** (2.9): the demo's "bread rolls" rows are two requests (a second open manual request for one product is refused);
  the confirmed-today batch is chocolate cake; the gate product has a reorder rule, so the demo shows one low-stock line; the honey
  cake's recipe has no honey (it uses what the cake room holds); the automatic cause is the action name, not a `context.cause` key.
- **Known follow-ups, not blockers:** admin screens (ledger, stock, reorder) show base units (a kilogram item reads "20000 g"); the
  ledger API has no `reason` filter, so the screen filters the loaded page; CI's per-process unique-job locks differ from the live
  host's database lock (the live chain was observed to work); the planner reads its bakers from `GET /users` and `GET /stations`
  (`people.manage`), so a manager with `production.plan` but not `people.manage` cannot assign: a read-only station-staff endpoint
  would fix it; `gate:p2` is not run as a group of its own in CI.

## What Ramin needs to do

- [ ] Add `gate-p3` to the required status checks on `main` after Phase 3's plan is approved, and `gate-p2` now (the orchestrator
  does it with `gh api` after the merge).
- [ ] Review and approve `docs/phases/phase-3-plan.md` when it exists.
- [ ] Decide on negative stock (still open from Phase 0). Today the Ledger refuses any movement that would take `on_hand` below
  zero, so a transfer, a sale or a batch that the system cannot cover is blocked until stock is recorded; the production card shows
  the shortfall and offers "Request transfer". The alternative is to allow negative and flag it for explanation at the shift count
  (Phase 3). The strict rule stays until you decide.
- [ ] Decide on a solo admin confirming their own batch: by default a batch that needs an overseer cannot be confirmed by the person
  who pressed Done, even an admin, so a bakery with one admin and one baker at an overseer station needs a second person. The
  alternative is to let an admin sign off their own batch with the fact recorded.
- [ ] Push and Telegram keys: both drivers ship off. To switch them on, follow "Turning on web push and Telegram" in
  `docs/runbook/server.md` (the keys go only in the server `.env`); say whether the pilot wants them now.
- [ ] Optional: to show Phase 2 on the live demo bakery, run `php artisan bakery:seed-demo --force` once after the deploy
  (`docs/runbook/server.md`, "Demo bakery"); it adds the suppliers, purchases, rules, the honey cake at one slice, transfers and
  production rows, and needs your server access.
