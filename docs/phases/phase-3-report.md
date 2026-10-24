# Phase 3 gate report — Shifts and handover

Commit: the `done_commit` recorded in `docs/phases/STATUS.json` (set by `claim-done` on `main` after the merge) · CI
evidence: pull request #59 (`phase-3/gate`), run [40051504109](https://github.com/obssa/bakery-ops/actions/runs/40051504109) (commit `76d938f`) at the last commit before this report was filled in, with the steps before it
green on `main` (last: step 3.7e, `4fad4bd`, run 40047040856) · Date: 2026-10-24

## Verdict

PASS, pending the merge: every Phase 3 gate criterion is green in CI on the production engine (MariaDB 10.11) with all three shift rules on, `rules-off-gates` proves the kill-switch state, and the demo seeds write two locked, hash-chained days. The six steps' modules are stable, the four required contracts are green, invariants (version 2), OpenAPI, ERD and both ADRs are clean. The `@live` Playwright flows were red after the 3.7e deploy for a fixture reason (a station left in its closing count by earlier live runs); the fix is in this PR and the next deploy's `@live` run is its proof. Four decisions are owed to Ramin (below).

## Gate criteria (from the manifest `phases.3.gate.criteria`)

| # | Criterion | Evidence (test name, CI job, numbers) | Result |
|---|---|---|---|
| 1 | `p3-handover`: kitchen closing count with one variance -> reason required -> submit -> admin side-by-side approve -> `count_adjustment` visible in the ledger -> lock -> hash shown -> next shift opening prefilled with the locked values | `apps/web/e2e/p3/handover.spec.ts` (`@live`), CI job `e2e-p3` on the compose stack (polling, worker, `SEED_PHASE=3`, all three shift rules on): 63 passed (45.8 s) across `p3-handover` and `p3-opening-prefill`, the flows and the logic specs (`handover-ensure-open-shift`, `handover-login-cache`, the cash, count, admin and shift-bar logic specs). Test 1 is the kitchen flow of the criterion (variance of -500 g, the inline refusal until a reason is chosen, approval with a note, ledger `purchase_in, count_adjustment` with the counted shift, a 64-hex hash, chain verify `ok`, the next opening prefilled with `9500`); test 2 is the till (cash declared by denomination, 10.00 short, channel table, cash block in the hash). Pest path: the same HTTP flow is `ApproveCountTest`, `LockOnDecisionTest`, `OpeningPrefillTest` and `CashEndpointsTest` in `mod-shifts` | PASS |
| 2 | `p3-opening-prefill` across midnight on the Night template | `apps/web/e2e/p3/opening-prefill.spec.ts` (`@live`), `e2e-p3`: a Night shift scheduled for yesterday and force-started, dated yesterday while opened today, the next shift prefilled from its closing, `previous_hash` equal to the earlier link, verify `ok`. The instants (23:59, 00:01, 05:59, 06:00) are `tests/Unit/Shifts/ShiftWindowTest.php` (`gate:p3`, the table of plan section 10.2) | PASS |
| 3a | `gate:p3` Pest: `HashChainTest` detects tampering | `tests/Gate/HashChainTest.php` (`gate:p3`): the tamper cases one per kind of change (a line figure, a line note, a decision note, who counted, a forged payload with and without its hash recomputed, a changed previous hash, a gap), plus `CashTamperTest` for the cash block and `LockChainRaceTest` (forks, 30 rounds, `Innodb_deadlocks` unchanged). `mod-shifts` 1104 passed (16 801 assertions), with the seeds group, the property and concurrency tests and `contract:shifts->*` | PASS |
| 3b | `ShiftAttributionTest` (movements during an open shift carry its id) | `tests/Modules/Shifts/ShiftAttributionTest.php` (`gate:p3`): an order, a payment and the movements they make carry the till shift and nothing before it; a production request; a person whose station has no running shift gets none. `mod-shifts` | PASS |
| 3c | `PresenceResolverSwapTest` (auto-request targets on-shift users first) | `tests/Modules/Shifts/PresenceResolverSwapTest.php` (`gate:p3`, also `contract:production->identity`, `contract:notifications->identity`, `contract:shifts->identity`): the Phase 2 presence assertions against the real `OnShiftResolver`, and a stock-out of a produced item goes to the people on the running shift first, to everyone assigned with nobody on it. `mod-shifts`, `mod-identity` | PASS |
| 3d | enforcement rejects a station-location movement without an open shift | `tests/Gate/EnforcementDefaultGateTest.php` (new, `gate:p3`: on the shipped defaults, through HTTP, `409 inventory.shift.required` with the station and location, allowed in a shift, refused while the shift is counting, the store never asked) and the full table `tests/Modules/Inventory/Feature/LedgerEnforcementTest.php` (`gate:p3`: 16 reasons by 6 places by 7 states of cover, flag off and on). `mod-shifts`, `mod-inventory` | PASS |
| 3e | replay equals state at time over seeded history | `tests/Gate/ReplaySeededHistoryTest.php` (new, `gate:p3`): the demo seeded on Phase 3, then `Replay::at` for every tracked station at the midpoint of each history day and at every count snapshot (and 30 s after it): balances against an independent fold over `stock_movements`, the held shift, the people on duty and the last locked link; the random-history half is `ReplayPropertyTest` (`gate:p3`, 10 seeds by 25 instants). `mod-shifts` | PASS |
| 4 | Phases 0 to 2 gates still green with the updated seeds | `gate-p0`, `gate-p1`, `gate-p2` in the same run; the seeds with the rules on (every CI job but one) and with all three off (`rules-off-gates`: `gate:p0..p2`, the kill-switch state); `e2e-p0`, `e2e-p1`, `e2e-p2` seed their own phase and the fresh-branch anchor never applies below Phase 3 (`DemoClock::wantsAnchor`). `api-arch` 2666 passed (39 088 assertions); `mod-inventory` 1993 passed (177 202), `mod-orders` 654 (82 604), `mod-payments` 52 (549), `mod-production` 286 (9 565), `mod-notifications` 292 (1 975), `mod-identity` 314 (1 743); `rules-off-gates` 814 passed (141 379); `e2e-p0` 8 passed (10.5 s), `e2e-p1` 7 (57.3 s), `e2e-p2` 7 (50.9 s); `gate-p0` 22 checks, `gate-p1` 17, `gate-p2` 18, all passed. | PASS |

`gate_check.py --mode done --phase 3 --ci` (job `gate-p3`): 14 checks, 0 failed, 1 warning at the commit above, the warning being the gate report, which this file ends. ADRs 0012 and 0013 present, `openapi.json` present, ERD covers the manifest's tables, 0 phase-3 to-do markers, 0 `->skip(` in Phase 3 module tests. The job does not run Pest itself (`--ci` trusts the module jobs): the `gate:p3` tests carry a `module:` tag and run in `mod-shifts`, `mod-inventory` and `api-arch` (the unit tests).

## Dependencies proven

| Contract | Cases | Status |
|---|---|---|
| `shifts->inventory` (`tests/Contract/Shifts/InventoryContractTest.php`, `InventoryConcurrencyTest.php`) | `balanceAt` at an instant, `Stock::at`, `netChangeSince`, `shiftSummary`, `Ledger::append` of `count_adjustment` with `ref_type = stock_count_line`, the enforcement cases 12 to 14 (skipped in 3.1a until the config existed, running since 3.1b), flag off never consults `OpenShifts` | in `mod-inventory` and `mod-shifts`; written red first in 3.1 (22 of 44 cases of the four files failed for the right reasons) |
| `shifts->identity` (`IdentityContractTest.php`) | `People::can` at a station, `isOverseer`, `workStationIds`, the presence resolver and attacher rebinding | in `mod-identity` |
| `shifts->payments` (`PaymentsContractTest.php`) | `Totals::cash(shift)` keyed on the payment's own shift (the manifest case text was corrected in 3.1a), `byChannel`, the cash arithmetic, `payments.require_shift_for_cash` guard | in `mod-payments` (10 cases) |
| `shifts->orders` (`OrdersContractTest.php`) | `Orders::openAt(station, t)` reads the timeline, never the current status | in `mod-orders` |

## Modules declared stable in this phase

| Module | module:X | contract:*->X | arch boundary | route coverage |
|---|---|---|---|---|
| shifts | `module:shifts` (instances, counts, locks, cash, handover): 1104 passed (16 801 assertions), with the seeds group, the property and concurrency tests and `contract:shifts->*` | Shifts is consumed by nothing but Shared's `OpenShifts` and Identity's two interfaces, which it rebinds | green: Shifts uses Inventory, Identity, Payments, Orders, Catalog and Audit through Contracts (and three Ledger vocabulary classes), imports no Production or Notifications class, and only Analytics may import it | every operation (templates, shifts, roster, counts, lines, cash, submit, cosign, approve, reject, unlock, queue, notes, handover, replay, chain) in the registry and called as every role by the matrix test |
| inventory (extended) | `Locations::stockLocationsOf`, `cashDrawerOf`, `views`; `Stock::locationAt`, `shiftSummary`, `netChangeSince`; `Ledger::shiftCover`, `tailMovementId`; the enforcement rule | `shifts->inventory` green | green | unchanged routes plus the 409 |
| payments (extended) | `RecordPayment` asks `OpenShifts` for cash; `Totals`; the `payments.shift.required` key | `shifts->payments` green | green | unchanged |
| orders (extended) | `Orders::openAt`; orders and lines carry the shift | `shifts->orders` green | green | unchanged |
| identity, catalog (extended, additive) | `People::workStationIds`, `attachShift` answers, `work_stations` on `GET /me`, `Items::deleted`, `summaries` | their contracts green | green | `GET /me` grew |

Steps 3.1 contracts to 3.7 web and 3.8 docs are done in STATUS; Shifts became `stable` in 3.6.

## Earlier gates re-run

`gate-p0`, `gate-p1` and `gate-p2`: `api-arch` 2666 passed (39 088 assertions); `mod-inventory` 1993 passed (177 202), `mod-orders` 654 (82 604), `mod-payments` 52 (549), `mod-production` 286 (9 565), `mod-notifications` 292 (1 975), `mod-identity` 314 (1 743); `rules-off-gates` 814 passed (141 379); `e2e-p0` 8 passed (10.5 s), `e2e-p1` 7 (57.3 s), `e2e-p2` 7 (50.9 s); `gate-p0` 22 checks, `gate-p1` 17, `gate-p2` 18, all passed. Their Pest groups also ran with the three shift rules off in `rules-off-gates` and with them on in the module jobs.

## Invariants

`check_invariants.py --all`: 0 violations across numeric, branch-id, bilingual, append-only, idempotency and i18n (CI job `invariants`, and locally); `invariants_version`: 2, unchanged. New in
Phase 3 and enforced: every `shift_instance_id` column outside `audit_log` has a foreign key; one running shift per station is a unique key, not a SELECT; a locked count accepts no
UPDATE but its two supersede columns and no DELETE, and its lines and cash declaration are frozen with it (triggers on both engines, with a test that fails when a column is added and the
trigger is not); `handover_notes` is append-only; the canonical JSON is checked by a second implementation (`scripts/verify_chain.py`) in the `invariants` job; every new write route has an
Idempotency-Key, a permission and a registry row.

## Artifacts produced

- ADRs, both `Status: accepted` with an "As built" section: 0012 the hash chain and its canonical JSON (the file is named as the manifest names it), 0013 shift attribution rules. Dated
  notes on 0008 (shifts in the path), 0009 (Phase 3 stays on polling; the settling ETag) and 0010 (rebound to Shifts).
- OpenAPI `docs/openapi.json` and ERD `docs/erd.dbml`: in sync (`openapi-check`, `erd-check`); this PR changes no route and no table. Phase 3 added 9 tables (`shift_templates`,
  `shift_template_stations`, `shift_instances`, `shift_roster`, `stock_counts`, `stock_count_lines`, `variance_reasons`, `cash_declarations`, `handover_notes`) and the shift endpoints,
  counts, cash, chain, replay and notes routes.
- Seeds: `Phase3Seeder`, `Demo\DemoClock`, `Demo\DemoShiftFlow`, `Demo\DemoOrders`; `DemoSeederTest` and `Phase3SeederTest`. On a fresh demo branch: 21 shifts (15 locked, 1 pending approval, 5 open),
  every place's chain verified, planned differences approved by the manager. On the live demo branch: only today's part (see "What Ramin needs to do").
- Runbook: "Shift rules and the kill switch", "Nightly chain verification" (with "When the chain reports a break"), "Pilot day-one checklist", and the Phase 3 demo paragraph.
- `docs/phases/phase-3-pilot-feedback-template.md`; references corrected (domain model, dependency graph, invariants, traps, testing strategy, CI, workflows, the phase reference).
- Contracts and APIs added on the Shifts side: `Shifts`, `Counts`, `Locks`, `HashChain`, `Replay`; Shared: `OpenShifts`, `ShiftOpened`, `ShiftLocked`, `ChainBroken`.

## The rollout of the three rules

The plan's riskiest move, in four steps, each its own PR with the earlier gates as the proof:

1. **Dark (3.1b, 3.2b, 3.5).** The three rules existed behind their own variables, off everywhere: `ledger.require_shift_for_station_locations` (the ledger refuses an `on_hand` change at a
   tracked station without an open shift), `shifts.open_ad_hoc_on_accept` (a baker accepting at a closed station opens a call-in shift) and `payments.require_shift_for_cash`.
2. **Rehearsal (3.2c, 3.7e).** CI ran the earlier gates and the e2e jobs with the ledger rule on before any default changed: the first commit of 3.2c was red on purpose (`e2e-p0..p3` and `enforced-gates`:
   276 of 814 failed) and the fixtures (`DemoShifts::ensureOpen`, `Tests\Support\Shifts`, `e2e/support/shifts.ts`) turned it green. 3.7e did the same for the cash rule (red in `e2e-p1..p3` and `enforced-gates`; 68
   failures, all one cash payment) before the flip.
3. **The flip (3.7e).** The three defaults are `true` in `config/ledger.php`, `config/shifts.php`, `config/payments.php`, `.env.example` and `.env.ci`; the web build sets `NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH`.
   `enforced-gates` became `rules-off-gates`, which runs the Phase 0-2 gates with all three variables `false`, so the switch is proven to leave a working system.
4. **The kill switch** is three lines in the server `.env` and `php artisan config:cache` (runbook). Nothing is lost by using it; shifts already open stay.

## Live deploy and `@live` results

- The deploy after 3.7c (run 39959438823) failed four p3 `@live` tests: a closing draft held lines of E2E ingredients the test cleanup had soft-deleted (a draft cannot be rejected), and `429` on `/login`.
- The deploy after 3.7d (run 40003438901) failed five: a switched-off ingredient with stock blocked the kitchen's count, the `/login` limit again, and the reorder-rules table did not show the run's rule.
- Step 3.7e fixed those three in the product and the fixtures (below). The deploy after it (run 40050659441), the first with all three rules on, failed 13 `@live` tests on both attempts, every one the same:
  the fixture's `POST /stock/movements` answered `409 inventory.shift.required`, because earlier live runs had left the E2E kitchen in its closing count (`counting`; the live branch holds 6 draft
  counts) and `ensureOpenShift` only handled "no shift" and "opening". CI never saw it (a fresh database). **Fixed in this PR**, as its own commit: `ensureOpenShift` ends with the station's shift `open`
  whatever state it finds (`opening`: finish the counts; `counting`: settle the station, resolve what waits for approval, start carried; `open`: nothing), re-reads the status and throws if it is
  not `open`, and `handover-ensure-open-shift.spec.ts` covers the five starting states against a local fake API. The next live run after this PR's merge is its proof.

## Deviations from the plan and assumptions taken

Collected from the "As built" notes of 3.1a to 3.8:

- **Contract cases skipped, not red (3.1a).** Cases 12 to 14 of `shifts->inventory` waited for `config/ledger.php`, because the entry gate tolerates a red contract only when its provider is built in the same phase.
- **Expected figures come from the locked balance rows, not `balanceAt` (3.3a).** `stock_movements.created_at` is whole seconds, so a count's expected figure is read under `Ledger::lock`; `Ledger::tailMovementId`
  (a shared locking read) closed a 3.3a race window (3.3b).
- **Three flags, one variable each (3.2b, 3.5, 3.7e).** The plan had two; cash got its own, and the call-in guard was an orchestrator decision. All three default to true since 3.7e; the plan said "four lines" and it
  was three config files, two env files, two workflows, compose, the runbook, STATUS and the tests that pinned the defaults.
- **Hash chain (3.4).** Eight claim attempts instead of three; `HashChainTest`, `VerifyChainCommandTest` and `LockApprovedBackfillTest` live in `tests/Gate/` (they drop triggers, DDL commits); a count with no
  difference locks at submit, so `CountSubmitted.status_after` says `locked`, never `approved`.
- **Co-signature and rejection (3.3c).** The cosign and reject routes ask for `shifts.view` and the action decides; the sole admin cannot approve their own count (open question kept at its default).
- **Cash (3.5).** The drawer is a count of its own, with a declaration required to submit; the first opening of a drawer is taken as counted; the cash rule has its own flag.
- **Replay (3.6).** Shifts keep no status history, so a shift sent back to counting is read as held for the whole time; a roster row keeps one `joined_at`; the replay carries no cash.
- **Web (3.7a to 3.7e).** `X-Station-Id` from the active station; the settling ETag (a tag over the highest movement id answered 304 to a reader below a late commit); count lines of deleted items are dropped at submit and
  the lines trigger allows DELETE of draft lines; a switched-off item with stock is countable; `/login` throttling is met by a per-run token file; `GET /reorder-rules` left out rules of deleted items after a 500.
- **Seeds (3.8).** The anchor applies to a fresh branch only (the plan's "no shift instance yet" would have backdated history under the live demo); Phase 1 keys its sample orders by the real day while anchored;
  today's shifts are planned on the Day template and started by the admin so the hour of the seed does not matter; `DemoOrders` duplicates the order walk instead of extracting it from `Phase1Seeder`; seeded
  reservations, transfer legs and purchases keep a null shift (rule E1); the plan's separate `Phase3StateMachinesTest`, `CrossMidnightGateTest`, `CashDeclarationGateTest`, `HandoverGateTest` and
  `Phase3ActorGateTest` are the unit and feature tests named above, tagged `gate:p3` in place. ADR 0012's file is named as the manifest names it.
- **Not built:** photos on variance lines (the column exists and stays null, Files is Phase 4); a cash rounding rule; the head hashes in a weekly summary outside the database (Phase 4).

Live findings of this phase (carry-over notes):

- *The kitchen strip crowded out the request button.* `p2-purchase-transfer` failed on live not for slowness: leftover out-of-stock E2E ingredients filled the strip's cut (`STRIP_ROWS`), so the run's sugar
  ranked past the limit. Fixed in the test (retire earlier runs' items in a `beforeAll`, four at a time; the second request independent of strip rank); passes live since the 3.7c deploy.
- *Deleted and switched-off items in counts.* A closing draft with lines of soft-deleted items answered `409 item_unavailable` and a draft cannot be rejected; a switched-off ingredient with stock was required by the
  submit but refused by the line editor. Product fixes in 3.7d (drop the lines of deleted items) and 3.7e (count what is on the shelf).
- *The login limit.* `429` on `/login` (10 a minute per address) when workers restarted; fixed with a per-run token file and `Retry-After`.
- *The reorder-rules 500.* One rule of a soft-deleted item broke the list for everyone; the list now leaves such rules out.
- *The pad menu that did not refresh (`p2-stockout-production` flake).* The cause was the ETag over the highest movement id; the settling marker fixed it (3.7b, ADR 0009 note).
- *The 3.7e deploy's 13 failures* (above): a station left in `counting` by earlier live runs.

## Known follow-ups

- Admin screens show quantities in the base unit (grams, millilitres) without a friendlier display unit.
- The ledger screen has no filter by movement reason (count adjustments are found by item).
- A manager cannot assign people to a shift in the planner without `people.manage`.
- Live E2E fixtures leave carried shifts and their drafts behind; a nightly cleanup of the E2E branch would stop the branch growing.

## What Ramin needs to do

- [ ] merge PR #59, then the orchestrator runs `claim-done` and tags `phase-3-complete`
- [ ] add `gate-p4` to the required status checks on `main` once its jobs exist
- [ ] review and approve `docs/phases/phase-4-plan.md` when it exists
- [ ] decide **negative stock**: an approval that would take stock below zero is refused whole (`shifts.adjustment.insufficient`); keep it that way or allow a correction to leave a negative balance
- [ ] decide the **push and Telegram keys** (VAPID, bot token): approvers hear about waiting counts in the app only until they exist
- [ ] decide whether a **solo admin** may confirm their own batch or count (refused by default; the pilot will say whether a second person is always there)
- [ ] decide whether to run `bakery:seed-demo --force` on the **live demo branch**: it would open carried shifts at the tracked stations, add the new day's seven sample orders and do only the "today" part of Phase 3
  (templates, reasons, roster, the kitchen note), printing "history not written"; for the two days of locked history load a new demo branch (`--branch="Demo Bakery 2"`). It was not run by this PR.
- [ ] run the pilot with `docs/phases/phase-3-pilot-feedback-template.md` and the day-one checklist in the runbook
