# Phase 3 plan — Shifts and handover

Status: **drafted for standing approval** (standing approval: Ramin, 2026-10-19, "proceed to the next phase without
waiting for my permission"; the building agent sets `plan_approved: true` and `plan_approved_by: "standing approval:
Ramin, 2026-10-19"` in `docs/phases/STATUS.json` in the commit that adds this file as `docs/phases/phase-3-plan.md`).
Drafted 2026-10-22 while Phase 2 was being closed (its gate report, `Phase2Seeder` and `claim-done` were still in
flight), from `references/phases/phase-3-shifts-handover.md`, the manifest (`phases["3"]`, `contracts["shifts->*"]`,
`modules.shifts`, `cycle_breaks` 1 and 3, `ci`), `references/{dependency-graph,invariants,traps,testing-strategy,ci-cd}.md`,
`docs/architecture.md` sections 2, 3, 4.4, 6 and 7, ADRs 0001-0011, `docs/runbook/server.md`, and the code as it stands
after Phase 2 step 2.8 (the "As built" notes of `docs/phases/phase-1-plan.md` and `phase-2-plan.md` section 6, and a
read of Identity, Inventory, Payments, Orders, Production, Shared, the migrations, the seeders, `tests/Support` and
`apps/web/e2e/support`). Nothing in the repository was changed to write it. The sections below are the plan; an "As
built" note follows a step only where the build deviates from it.

Decisions written down as ADRs in this phase: ADR 0012 (hash chain and its canonical JSON) and ADR 0013 (shift
attribution rules: which shift a row carries, what "on shift" means, when the ledger refuses a movement). Two smaller
decisions are dated notes on existing ADRs, not new ADRs: ADR 0010 (the presence resolver and the shift attacher are
rebound to Shifts; what was kept true) and ADR 0009 (Phase 3 stays on polling; the shift feeds). The six points the
owner asked to be settled are in sections 10.1 to 10.6, each with a recommended default: the enforcement flip and its PR
order (10.1), cross-midnight shifts (10.2), canonical JSON (10.3), negative stock (10.4), cash arithmetic (10.5), new
permissions and how they reach the live database (10.6).

What the phase delivers, in one paragraph: the shop's stations open and close shifts. The incoming person starts a
shift, sees the previous locked closing as the prefilled opening count, counts, explains every difference from a
bilingual list of reasons, and the shift is open. At the end the outgoing person closes: the station freezes, they count
against the ledger, differences go to an admin or manager who sees expected against counted side by side and approves
(a `count_adjustment` is written and the ledger matches the shelf) or rejects (a recount, both attempts kept). An
approved count is locked and hash-chained per location, so a changed record is detectable. The till adds a cash
declaration by denomination and a per-channel receipt reconciliation. Notes travel with the shift. The admin can replay
any station at any minute. Around that: the roster and templates, ad-hoc shifts for call-ins (Production already asks
Identity to attach one), "who is on duty" answered by the roster instead of by assignment, and, once the screens exist,
the ledger refusing a stock movement at a station that has no open shift. That last switch is the closing of the two soft
cycles left open since Phase 0 (dependency-graph.md, cycles 1 and 3).

## 1. Entry check

`python scripts/gate_check.py --mode entry --phase 3`. Upstream modules this phase consumes, all `stable` once Phase 2
closes: shared, audit, identity, catalog, inventory (since Phase 0), orders and payments (Phase 1). Shifts' dependencies
in the manifest are inventory, identity, payments, catalog, shared, audit. **Manifest correction (step 3.1a, in the
commit that adds this plan):** `modules.shifts.depends_on` gains `orders`, read through `Orders\Contracts` only, because
step 3.6 builds the replay on `Orders::openAt(station, time)` (Phase 1 provides it "for Phase 3") and the phase reference
already lists Orders as consumed by 3.6, while the manifest and `dependency-graph.md` omit it and the arch rule of step
3.2a would otherwise forbid the call. The same commit adds a fourth contract, `shifts->orders`, to `contracts`, to
`phases["3"].contracts_required` and to `docs/phases/STATUS.json` (section 2). Phase 2 is `done` when this runs (its
`done_commit` is an ancestor of HEAD). The two runs below are the ones step 3.1 will produce; counts marked `<n>` are
filled from the real run, the shape is what the gate checks.

At plan approval (after the standing-approval commit, before step 3.1; `--no-tests`, so Pest groups are not run). The four
failures are the contract files step 3.1a writes, as expected at this point:

```
$ python scripts/gate_check.py --mode entry --phase 3 --no-tests
== gate_check: entry phase 3 (Shifts and handover)
  [PASS] phase 2 done                           status=done done_commit=<phase-2 done_commit>
  [PASS] phase 2 commit in history              <sha12> is an ancestor of HEAD
  [SKIP] gate:p2 green                          pest --group=gate:p2 not run (--no-tests/--ci)
  [PASS] clean working tree                     clean
  [PASS] phase plan exists                      docs/phases/phase-3-plan.md
  [PASS] plan approved by Ramin                 plan_approved=True by standing approval: Ramin, 2026-10-19
  [PASS] approval author                        obssa77@gmail.com
  [FAIL] contract shifts->inventory file        missing apps/api/tests/Contract/Shifts/InventoryContractTest.php
  [FAIL] contract shifts->identity file         missing apps/api/tests/Contract/Shifts/IdentityContractTest.php
  [FAIL] contract shifts->payments file         missing apps/api/tests/Contract/Shifts/PaymentsContractTest.php
  [FAIL] contract shifts->orders file           missing apps/api/tests/Contract/Shifts/OrdersContractTest.php
  [PASS] dep shifts->inventory stable           stable
  [PASS] dep shifts->identity stable            stable
  [PASS] dep shifts->payments stable            stable
  [PASS] dep shifts->orders stable              stable
  [PASS] dep shifts->catalog stable             stable
  [PASS] dep shifts->shared stable              stable
  [PASS] dep shifts->audit stable               stable
  [SKIP] Phase3Seeder                           not yet (created during the phase)
== result: FAIL (4 failed, 0 warnings, 19 checks)
```

After step 3.1 (contract tests written red, provider fixes F1-F9 in, tests run in Docker) the entry gate passes. The
"first" lines are the ordering proof: `apps/api/app/Modules/Shifts` is still exactly as Phase 0 left it (a `.gitkeep`
and nothing else), which is what "contracts before consumers" means mechanically:

```
$ python scripts/gate_check.py --mode entry --phase 3
== gate_check: entry phase 3 (Shifts and handover)
  [PASS] phase 2 done                            status=done done_commit=<phase-2 done_commit>
  [PASS] phase 2 commit in history               <sha12> is an ancestor of HEAD
  [PASS] gate:p2 green                           group gate:p2: <n> passed (<n> assertions)
  [PASS] clean working tree                      clean
  [PASS] phase plan exists                       docs/phases/phase-3-plan.md
  [PASS] plan approved by Ramin                  plan_approved=True by standing approval: Ramin, 2026-10-19
  [PASS] approval author                         obssa77@gmail.com
  [PASS] contract shifts->inventory file         apps/api/tests/Contract/Shifts/InventoryContractTest.php
  [PASS] contract shifts->inventory first        consumer unchanged since previous gate
  [PASS] contract shifts->inventory green        group contract:shifts->inventory: 15 passed (<n> assertions)
  [PASS] contract shifts->identity file          apps/api/tests/Contract/Shifts/IdentityContractTest.php
  [PASS] contract shifts->identity first         consumer unchanged since previous gate
  [PASS] contract shifts->identity green         group contract:shifts->identity: 14 passed (<n> assertions)
  [PASS] contract shifts->payments file          apps/api/tests/Contract/Shifts/PaymentsContractTest.php
  [PASS] contract shifts->payments first         consumer unchanged since previous gate
  [PASS] contract shifts->payments green         group contract:shifts->payments: 7 passed (<n> assertions)
  [PASS] contract shifts->orders file            apps/api/tests/Contract/Shifts/OrdersContractTest.php
  [PASS] contract shifts->orders first           consumer unchanged since previous gate
  [PASS] contract shifts->orders green           group contract:shifts->orders: 5 passed (<n> assertions)
  [PASS] dep shifts->inventory stable            stable
  [PASS] dep shifts->identity stable             stable
  [PASS] dep shifts->payments stable             stable
  [PASS] dep shifts->orders stable               stable
  [PASS] dep shifts->catalog stable              stable
  [PASS] dep shifts->shared stable               stable
  [PASS] dep shifts->audit stable                stable
  [SKIP] Phase3Seeder                            not yet (created during the phase)
== result: PASS (0 failed, 0 warnings, 27 checks)
```

(The case counts 15 / 14 / 7 / 5 are the plan's, section 2; the real run prints what was written.) Notes on what the gate
does and does not see:

- One contract file is extended later in the phase, red first, before the code it proves: the two cash-declaration cases of
  `shifts->payments` at 3.5 (the file then has 9 cases). The extension is its own commit, written before the Shifts file that
  uses it; the "first" line stays true at the entry gate, and the commit order in the PR proves it for the extension.
- The entry gate cannot see the three things that matter most on the live host: the cron-drained queue behind the
  notifications (section 5), the config flag that is read from the server `.env` (section 10.1), and the live database's
  existing rows (section 10.6). The `@live` flows at the end of the phase prove the first two; the migration tests start
  from "the database as Phase 2 left it" for the third.
- `STATUS.json` for phase 3 already lists the module keys `contracts`, `shifts.instances`, `shifts.counts`, `shifts.locks`,
  `shifts.cash`, `shifts.handover`, `web`, `docs`; the PR split in section 6 maps onto them without changing the file's
  shape. `inventory`, `identity`, `payments`, `notifications`, `orders` and `production` stay `stable` throughout: every
  step that touches one of them ends with `gate_check --mode module --module <name>` green again and is not merged
  otherwise. `shifts` becomes `stable` at the end of 3.6 (all its code and tests exist; the screens and the flip are `web`).

**As run at step 3.1a** (`python scripts/gate_check.py --mode entry --phase 3 --no-tests`, on the branch after the status commit
`phase 3 in progress`; the Pest groups were run by hand, per contract, because `gate:p2` takes about 30 minutes locally and CI's
`gate-p2` is its evidence). The gate's intra-phase rule lets a contract be red only when its provider is built in the same phase
(`Payments->Orders` in Phase 1); the providers here are older, so a red contract fails the entry gate, which is why the four
ledger-enforcement cases (F2, step 3.1b) are `skip`ped by a condition, not left red:

```
== gate_check: entry phase 3 (Shifts and handover)
  [PASS] phase 2 done                      status=done done_commit=b981f3758241145afb2d483745538d6aa0944dcb
  [PASS] phase 2 commit in history         b981f3758241 is an ancestor of HEAD
  [SKIP] gate:p2 green                     pest --group=gate:p2 not run (--no-tests/--ci)
  [PASS] clean working tree                clean
  [PASS] phase plan exists                 docs/phases/phase-3-plan.md
  [PASS] plan approved by Ramin            plan_approved=True by standing approval: Ramin, 2026-10-19
  [PASS] approval author                   obssa77@gmail.com
  [PASS] contract shifts->inventory file   apps/api/tests/Contract/Shifts/InventoryContractTest.php
  [PASS] contract shifts->inventory first  consumer unchanged since previous gate
  [SKIP] contract shifts->inventory green  pest --group=contract:shifts->inventory not run (--no-tests/--ci)
  [PASS] contract shifts->identity file    apps/api/tests/Contract/Shifts/IdentityContractTest.php
  [PASS] contract shifts->identity first   consumer unchanged since previous gate
  [SKIP] contract shifts->identity green   pest --group=contract:shifts->identity not run (--no-tests/--ci)
  [PASS] contract shifts->payments file    apps/api/tests/Contract/Shifts/PaymentsContractTest.php
  [PASS] contract shifts->payments first   consumer unchanged since previous gate
  [SKIP] contract shifts->payments green   pest --group=contract:shifts->payments not run (--no-tests/--ci)
  [PASS] contract shifts->orders file      apps/api/tests/Contract/Shifts/OrdersContractTest.php
  [PASS] contract shifts->orders first     consumer unchanged since previous gate
  [SKIP] contract shifts->orders green     pest --group=contract:shifts->orders not run (--no-tests/--ci)
  [PASS] dep shifts->inventory stable      stable
  [PASS] dep shifts->identity stable       stable
  [PASS] dep shifts->payments stable       stable
  [PASS] dep shifts->orders stable         stable
  [PASS] dep shifts->catalog stable        stable
  [PASS] dep shifts->shared stable         stable
  [PASS] dep shifts->audit stable          stable
  [SKIP] Phase3Seeder                      not yet (created during the phase)
== result: PASS (0 failed, 0 warnings, 27 checks)
```

Contract groups, run by hand in Docker on the same commit: `contract:shifts->inventory` 13 passed + 3 skipped (231 assertions; the three
skipped are cases 12, 13 and 14, which turn green in 3.1b when `config/ledger.php` exists; the flag-off case 15 passes now),
`contract:shifts->identity` 16 passed (140), `contract:shifts->payments` 7 passed (46), `contract:shifts->orders` 5 passed (37).


## 2. Contract tests to write first (green before any Shifts code)

Written at step 3.1 from the consumer's point of view, using only provider classes and factories; `app/Modules/Shifts` is
not touched. Each file runs in its provider's CI job for the life of the project (`mod-inventory`, `mod-identity`,
`mod-payments`, and `mod-orders` for the new fourth file, whose `contracts:` string in `ci.yml` gains
`contract:shifts->orders`; the `shifts->inventory|identity|payments` strings are already in `ci.yml`). Cases marked
"added" are not in the manifest; this plan appends them to `contracts[...].cases`. "Red" and "green on arrival" are the
plan's expectation against the code as it is today; the PR says what actually happened, as 2.1 did.

| Contract | File | Cases |
|---|---|---|
| `shifts->inventory` | `apps/api/tests/Contract/Shifts/InventoryContractTest.php` | (1) `Stock::balanceAt(item, location, t)` equals the sum of the movements with `created_at <= t` (the manifest case), for a random mix of every reason · (2, added) the same at the whole-location level (`locationAt`) and the whole-item level (`itemAt`) · (3, added) a movement at exactly `t` counts, one a second later does not (`stock_movements.created_at` is `DATETIME`, whole seconds: **corrected in 3.1a**, the draft said a microsecond), and one written by a transaction that has not committed is invisible (second connection, `DatabaseTruncation`; this half and case 8 are in `InventoryConcurrencyTest.php` beside the contract file, same group) · (4) `Ledger::append` accepts `count_adjustment` with `ref_type = stock_count_line` and a `ref_id`, in both signs · (5, added) `appendMany` of N adjustments is all-or-nothing (the last one made to fail leaves none) · (6, added) a negative adjustment larger than `on_hand` is refused whole with the item and place named (`inventory.stock.insufficient`), nothing written; a positive one never is · (7, added) an adjustment written under `ActorContext::runAs` carries the shift of that stamp, not the caller's (the rule "a count adjustment carries the shift it corrects", ADR 0013) · (8, added) `Ledger::lock(pairs)` inside a transaction returns the `on_hand` that `balanceAt(now)` reports after it, and a concurrent `append` to a locked pair waits until the transaction ends (what the count's snapshot relies on) · (9, added) `Stock::shiftSummary(shiftId)` answers the movements stamped with that shift as `[{location_id, item_id, reason, qty, rows}]`, decimal strings, ordered by location, item, reason (the review before a closing count) · (10, added) `Stock::netChangeSince(location, afterMovementId, excludeMovementIds)` answers the signed `on_hand` change per item at the location since a movement id (what lets an opening count prove "previous closing plus what moved since equals the ledger") · (11, added) `Locations::stockLocationsOf(station)` answers the station's countable places (every live location of the station except the cash drawer) and `Locations::cashDrawerOf(station)` its cash drawer or null, as plain views (id, both names, type), never models · (12, added) with `ledger.require_shift_for_station_locations` on and a fake `OpenShifts` bound, a movement that changes `on_hand` at a location whose station tracks shifts is refused with `409 inventory.shift.required {station_id, location_id}` and nothing is written when neither the actor's shift nor the station's own shift is open · (13, added) the exemptions: `reservation` and `reservation_release` (they move `reserved`, not `on_hand`), a `count_adjustment` with `ref_type = stock_count_line`, a location with no station, a station that does not track shifts · (14, added) the two ways a movement is covered, and the stamp: an actor who carries an open shift is covered at any station; a station whose own shift is open covers an actor who carries none, and the row is stamped with that shift · (15, added) with the flag off the `OpenShifts` binding is never consulted (no query), whatever the state |
| `shifts->identity` | `apps/api/tests/Contract/Shifts/IdentityContractTest.php` | (1) `Stations::canCount` and `isOverseer` are per station (the cake baker flagged at the bakery is not an overseer at the cake room; an inactive or soft-deleted user is neither) · (2) `StationPresenceResolver` can be rebound to a roster-style implementation shaped like the Shifts one (on duty = joined and not left, at the running shift only, empty when no shift is open) and `Recipients::onDuty` keeps active users of the branch only, once each, in id order · (3) the same binding passes the presence assertions of the `production->identity` and `notifications->identity` contracts, including the fallback to the assigned people when the roster is empty (the "both bindings" rule of cycle 3; the real Shifts resolver joins the same assertions at 3.2b) · (4, added) `can(user, 'shifts.count', station)` is true for the chef at the kitchen and false at the bar, true for the cashier at the till, false for the waiter anywhere, true for the admin and manager anywhere (`stations.all`) · (5, added) `shifts.approve` is held by admin and manager only, `shifts.unlock` by admin only · (6, added) the overseer rule for co-signing: a user flagged `is_overseer` at the station may co-sign there without holding `shifts.cosign`, a holder (admin, manager) may anywhere, nobody else (the "not the person who counted" rule is the action's and is tested there) · (7, added) the new registry permissions `shifts.view` (the seven floor roles), `shifts.manage` (admin), `shifts.replay` (admin, manager) are held by those roles and no others · (8, added) `Stations::workStationFor(user, ?headerStationId)`: the station named by `X-Station-Id` when the user is assigned there or holds `stations.all`; else their only assignment; else null (two assignments and no header is null, never a guess; a header naming a station of another branch, or one the user may not work at, is ignored) · (9, added) with an `OpenShifts` double bound, `ResolveActor` stamps `ActorContext` with the resolved station and the double's shift (`stampFor`), and stamps no shift when there is none · (10, added) `ResolveActor` does not consult the double for an unauthenticated request · (11, added) `People::attachShift` puts the id the attacher answered into `ActorContext` (so the rest of that request is stamped) and leaves it alone when the attacher answers null · (12, added) `GET /me` carries `work_stations` (assigned stations, or every station for `stations.all`) with each station's `tracks_shifts`, `requires_overseer` and `current_shift` (`{id, status, date, opened_at}` or null) · (13) `ShiftAttacher` under its Phase 2 binding still answers null and writes nothing (pinned by binding `NullShiftAttacher` explicitly, because the container's default is Shifts' from 3.2b) · (14, added) a user of another branch or an inactive user is never returned by any presence answer, whatever the resolver says |
| `shifts->payments` | `apps/api/tests/Contract/Shifts/PaymentsContractTest.php` | (1) `Totals::cash(shift)` sums only the non-voided cash payments carrying that shift; the sum is a 2-dp decimal string and `'0.00'` for a shift with none · (2, **amended wording**) the manifest says "whose order carries that shift". The money enters the drawer when the payment is taken, so the key is the **payment's own** `shift_instance_id`: a payment taken in shift B for an order created in shift A (or at the waiter pad, which has no shift) counts for B, never for A (ADR 0013; the manifest case text is corrected in 3.1a) · (3, added) other channels, other shifts, voided payments (built with the factory, since no endpoint voids before Phase 4) and other branches' payments are left out · (4, added) `amount` is summed, not `tendered_amount` or `change_amount` · (5) `Totals::byChannel(shift)` answers `[{channel_id, code, name_en, name_am, receipts, sum}]` for non-voided payments of that shift, `receipts` an integer count, `sum` a 2-dp string, ordered by the channel's `sort` then id; a channel with no payment in the shift is not listed · (6, added) the total equals the sum of the same payments read through `Orders::totals` (no second arithmetic) · (7, added) a payment recorded through `Payments::record` while `ActorContext` carries a shift is stamped with it, and one recorded with none carries null and is in no shift's total · (8, added at 3.5) the cash-declaration arithmetic as the Shifts action will use it (opening float + `Totals::cash` = expected, with change given back not counted) on a worked table of three payments with tender and change · (9, added at 3.5) with `payments.require_shift_for_cash` on, `Payments::record` refuses (`409 payments.shift.required`) when the actor carries no open shift, and the refusal rolls the whole payment back |
| `shifts->orders` (added) | `apps/api/tests/Contract/Shifts/OrdersContractTest.php` | (1) `Orders::openAt(station, t)` returns the orders that had a line at the station, were sent at or before `t` and were not closed, cancelled or voided at or before `t`, read from the timeline and never from the current status (an order closed after `t` is still open at `t`) · (2) a paid but not yet closed order is open · (3) lines carry the status their last `line.*` event at or before `t` gave them, only for the station asked about · (4) oldest sent first, then by order id · (5) another branch's orders are never seen |

Provider changes the contracts require (step 3.1; each with its own module tests extended, committed red first as in
Phases 1 and 2; listed again in the PR). Expected red before the fixes: `shifts->inventory` cases 9 to 15 (7 of 15);
`shifts->identity` cases 7 to 12 (6 of 14); `shifts->payments` cases 1 to 7 (7 of 7: there is no `Totals`);
`shifts->orders` nothing (5 of 5 green on arrival, pinning behaviour that already holds and has its own module test).
The other cases are expected green against the code as it is, and the PR says so (a contract that is green on arrival is
still worth having: it pins the behaviour).

- **F1, Inventory (reads).** `Contracts\Stock::shiftSummary(int $shiftId)` and `Stock::netChangeSince(...)` (both through
  `LedgerReader`, so only the `Ledger` namespace names `stock_movements`), `Contracts\Locations::stockLocationsOf(station)` and
  `cashDrawerOf(station)` returning a small readonly `LocationView` (the technique of `StockLevel` and `ItemSummary`, so Shifts
  imports no `Location` model). No schema change.
- **F2, Inventory (enforcement, dark).** `config/ledger.php` (new, closure-free so `config:cache` keeps working:
  `DeployableCachesTest`) with `require_shift_for_station_locations` = `env('LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS', false)`;
  the check inside `Ledger::appendMany` (section 10.1 states the rule exactly); `InventoryError::shiftRequired(stationId,
  locationId)` with the key `inventory.shift.required` in `lang/{en,am}/inventory.php`; the stamp fallback. The default stays
  **false** until step 3.7e. With the flag off the code path is one config read.
- **F3, Shared.** `App\Shared\Shifts\OpenShifts` (interface: `stampFor(int $stationId): ?int`, the id of the running shift (open or
  counting) used to stamp; `openAt(int $stationId, bool $lock = false): ?int`, the id of the shift in status `open`;
  `isOpen(int $shiftId, bool $lock = false): bool`; `$lock` takes a shared row lock for the rest of the caller's transaction; and, **added in 3.1a** because `GET /me` needs the running shift's details and Identity may not read Shifts: `currentFor(int $stationId): ?array{id, status, date, opened_at}`) and its
  default binding `NoOpenShifts` (answers null and false). Bound `scoped()`, no static state (`OctaneSafetyTest`). This is the
  third use of the cycle-3 technique: the interface lives where both the writer (Inventory) and the reader (Identity's
  `ResolveActor`) may import it, Shifts rebinds it, and neither provider imports Shifts.
- **F4, Identity.** `PermissionRegistry` gains `shifts.view`, `shifts.manage`, `shifts.replay` and the grant-only migration
  `2026_10_24_100100_grant_phase_3_permissions` (section 10.6); `Stations::workStationFor`; `ResolveActor` reads `X-Station-Id`,
  resolves the station and asks `OpenShifts::stampFor` (`atStation` and `inShift`). `ResolveActor` is where every authenticated
  request's `ActorContext` is filled (the pad's short-code verify fills its own), and `atStation` and `inShift` are not called
  anywhere, so every `station_id` and `shift_instance_id` stamp written by a request is null today; this fills them. The existing tests that assert a null `station_id` do so on event payloads, not on stamps; the PR lists any
  that move. `People::attachShift` stamps `ActorContext`; `GET /me` gains `work_stations`; `PATCH /stations/{station}` accepts
  `tracks_shifts` and `Station` casts it; migration `2026_10_24_100200_add_tracks_shifts_to_stations` (section 3).
- **F5, Payments.** `Contracts\Totals` (`cash(int $shiftId): Money`, `byChannel(int $shiftId): list<ChannelTotal>`) with `ChannelTotal` (built in 3.1a; `cash` answers `Money`, whose `amount()` is the 2 dp string of the contract); the guard in `RecordPayment` behind
  `config('payments.require_shift_for_cash')` (default false until 3.7e, the same env switch as the ledger's: section 10.1)
  arrives at 3.5 with its contract case, red first. The
  `shift_instance_id` index on `payments` comes with the foreign-key migration (3.2a).
- **F6, Notifications.** Nothing before 3.3c, where the three Shifts notification types arrive (section 5). Listed here so the
  PR that touches `notifications` (stable) names its module gate.
- **F7, CI and docs.** `e2e-p3` drops the `ws` profile and runs the polling transport with `full`, as `e2e-p1` and `e2e-p2` do (the
  decision Phase 2 made for itself; ci-cd.md and `assets/ci/ci.yml` change the same way); the two Phase 3 Playwright projects get
  one `test.fixme` placeholder each in `apps/web/e2e/p3/` carrying a phase-3 TODO marker, because once the phase is `in_progress`
  `e2e-p3` requires a test per project and `gate_check --mode done` refuses to close while one remains; the manifest and skill
  copies get the corrections of section 1 and section 10 (shifts depends on orders; the `Totals::cash` wording; the
  `enforced-gates` job and the `seeds` group move of 3.2c).
- **F8, Orders.** None. `Orders::openAt` is already built and tested (`OrdersOpenAtTest`); the contract above only pins it from
  Shifts' side.
- **F9, Catalog.** None. The manifest lists "items held at a location type" under what Shifts needs from Catalog. This plan
  does not build it: the lines of a count come from the ledger (items with a non-zero balance at the place), the previous
  count of that place and an "add an item" action (section 10.8), which needs no Catalog capability. The manifest
  `needs.catalog` line is corrected in 3.1a.

## 3. Migrations (in order)

Conventions, as in Phases 1 and 2: every table carries `branch_id` (FK, indexed); money `DECIMAL(12,2)` and named `*_total`
or `*amount*`; quantities `DECIMAL(14,4)` in base units and named `*_qty`; enums are `VARCHAR` plus a named `CHECK`
(MariaDB 10.11, portable to PostgreSQL 16); times `DATETIME` in UTC, `DATETIME(6)` on snapshot, lock and cursor columns;
JSON columns use Laravel's `json()` (MariaDB `LONGTEXT` with `JSON_VALID`, PostgreSQL `json`, the way `notifications.payload`
is, so the manifest's "JSONB" is met by the portable type and nothing queries inside them). Actor stamp =
`created_by_user_id`, `acting_user_id?`, `shift_instance_id?`, `actor_station_id?` (where `station_id` already means
something else), `device_id?`. Rows that move through a status are never deleted (they carry a status, no `deleted_at`, and
are allow-listed in `tests/Arch/SoftDeletesTest.php` with that reason); rows edited as configuration are soft-deleted.
Triggers, generated columns and partial indexes are created inside
`in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)` / `pgsql` branches, through `DB::unprepared` (traps.md, "Driver
name checks"); `AppendOnlyTest` and the new `LockedRowsTest` run under the live driver, and MariaDB is the engine of record
(a compound trigger body goes through PDO as one statement, no `DELIMITER`). Migration file numbers continue after
`2026_10_23_100300`; exact timestamps are taken when each file is written. `docs/erd.dbml` gains each table in the PR that
migrates it (`erd-check`).

1. `2026_10_24_100100_grant_phase_3_permissions` (data only; step 3.1a): the grant-only pattern of
   `2026_10_22_100100_grant_phase_2_permissions`, section 10.6.
2. `..._add_tracks_shifts_to_stations` (Shared `stations`, step 3.1a): `tracks_shifts` BOOLEAN NOT NULL DEFAULT 1. "Does this
   station run shifts at all?" — the ledger check, the counts and the roster apply only where it is true. Backfill in the same
   migration: `UPDATE stations SET tracks_shifts = 0 WHERE code = 'store'` (a warehouse whose stock the admin and manager adjust
   by hand; section 10.8). A store created later gets false by the same rule in `StationsController::store`; every other code
   gets true. Reversible by `PATCH /stations/{station}` (people.manage). Existing rows are not rewritten otherwise.
3. `..._create_shift_templates_table` (Shifts, 3.2a): `id`, `branch_id`, `name_en` / `name_am` VARCHAR(80), `start_time` /
   `end_time` TIME (the branch's local wall-clock time; the window may cross midnight), `days_mask` TINYINT UNSIGNED (bit 0 =
   Monday .. bit 6 = Sunday, ISO weekday of the day the window **starts**; the API says `days_of_week: [1..7]`), `is_active` BOOL,
   `sort` SMALLINT UNSIGNED, the actor stamp, `version` INT UNSIGNED, timestamps, `deleted_at`. CHECKs: `days_mask BETWEEN 1
   AND 127`, `start_time <> end_time` (`end_time < start_time` is the cross-midnight case; equal is refused, a template is never
   exactly 24 hours). Index `(branch_id, is_active)`. Configuration, so soft-deleted and audited; no `client_uuid`.
4. `..._create_shift_template_stations_table` (Shifts, 3.2a): the stations a template applies to. `id`, `branch_id`,
   `template_id` FK, `station_id` FK, timestamps, `deleted_at`; unique `(template_id, station_id)` (a removed pair is restored,
   not duplicated, as `user_station_assignments` is). No actor stamp (the template's audit row carries it).
5. `..._create_shift_instances_table` (Shifts, 3.2a): a template on a date at a station, or an ad-hoc one. `id`, `branch_id`,
   `client_uuid` UUID, `station_id` FK, `template_id` FK NULL, `slot_key` BIGINT UNSIGNED NOT NULL DEFAULT 0 (the template id,
   or 0 when there is none; CHECK `slot_key = COALESCE(template_id, 0)`), `sequence` TINYINT UNSIGNED NOT NULL DEFAULT 1 (the
   2nd, 3rd .. instance of the same slot on one date: a station that closed early and started again, or two call-ins a night),
   **`date` DATE** (the **local start date** in the branch's timezone, section 10.2), `starts_at` / `ends_at` DATETIME NULL (UTC;
   the template window of that date, or null for an ad-hoc shift), `origin` (`scheduled | started | call_in`, CHECK), `status`
   (`scheduled | opening | open | counting | pending_approval | locked | cancelled`, CHECK; the architecture lists the middle
   five and the plan adds `opening`, a person counting in, and `cancelled`, a scheduled shift nobody will work), `open_key`
   TINYINT UNSIGNED NULL (1 while the status is `opening`, `open` or `counting`, else NULL; CHECK keeps the two in step),
   `opening_mode` (`counted | carried`, CHECK; null until the shift opens), `started_by_user_id` FK NULL, `started_at`,
   `opened_by_user_id` FK NULL, `opened_at`, `closed_by_user_id` FK NULL (who pressed Close), `closing_started_at`, `submitted_at`
   (every closing count submitted), `locked_at`, `cancelled_at` / `cancelled_by_user_id` / `cancel_reason` VARCHAR(255), the
   actor stamp without `shift_instance_id` (the row is the shift; `ActorStampTest` allow-lists the table with that reason),
   `version` INT UNSIGNED (the live feed's merge key), timestamps. No soft delete. Unique `(branch_id, client_uuid)`;
   **unique `(station_id, open_key)`: at most one running shift per station, enforced by the database on both engines with no
   partial index** (NULLs are distinct; ADR 0011's technique, and the answer to two people pressing Start at once); unique
   `(station_id, date, slot_key, sequence)`; indexes `(branch_id, date)`, `(branch_id, status)`, `(station_id, status)`. CHECKs
   beyond the lists: `(status = 'locked') = (locked_at IS NOT NULL)`; `(status = 'cancelled') = (cancelled_at IS NOT NULL)`;
   `opened_at` set when the status is `open`, `counting`, `pending_approval` or `locked`.
6. `..._create_shift_roster_table` (Shifts, 3.2a): who is on a shift. `id`, `branch_id`, `shift_instance_id` FK, `user_id` FK,
   `source` (`scheduled | started | call_in | joined`, CHECK), `joined_at` DATETIME NULL (null while someone is only scheduled),
   `left_at` DATETIME NULL, `created_by_user_id` FK, `acting_user_id?`, `device_id?`, timestamps, `deleted_at` (a scheduled
   person removed from a scheduled shift; once a shift has started the row is history and only `left_at` moves). Unique
   `(shift_instance_id, user_id)`; index `(user_id, joined_at)`. CHECK `left_at IS NULL OR (joined_at IS NOT NULL AND left_at
   >= joined_at)`. **On duty = a row with `joined_at` set and `left_at` null on the running shift** (ADR 0013).
7. `..._add_shift_instance_foreign_keys` (step 3.2a): the foreign keys the Phase 0-2 migrations promised ("shift_instance_id
   gets its FK with Shifts, Phase 3"): `shift_instance_id` -> `shift_instances.id` (nullable, `restrictOnDelete`) on `items`,
   `item_prices`, `stock_movements`, `orders`, `order_lines`, `order_events`, `payments`, `suppliers`, `purchases`, `transfers`,
   `reorder_rules`, `production_requests`, `production_batches` and `shift_templates`. **`audit_log` is left alone** (Audit is a
   leaf and keeps plain ids, ADR 0001). Every existing row is NULL (nothing is backfilled, cycle 1), so no row can violate it.
   MariaDB adds the missing index itself; the PostgreSQL branch adds one explicitly where none exists (`payments` had none; the
   `Totals` queries need `(shift_instance_id)`). The migration takes a short metadata lock per table on the shared host (tens of
   thousands of ledger rows at most); it runs inside the deploy's maintenance window (`release.sh`), as the append-only
   triggers did. New test `ShiftStampForeignKeysTest` reads `information_schema` and fails for any `shift_instance_id`
   column outside `audit_log` without that foreign key, so a later table cannot forget it.
8. `..._create_variance_reasons_table` (Shifts, 3.3a): `id`, `branch_id`, `code` VARCHAR(32), `name_en` / `name_am` VARCHAR(80),
   `requires_note` BOOL, `is_active` BOOL, `sort` SMALLINT UNSIGNED, the actor stamp, `version`, timestamps, `deleted_at`.
   Unique `(branch_id, code)`; index `(branch_id, is_active, sort)`. The five defaults of architecture section 4.4 point 2, as
   code `spoilage` ("Spoilage" / "መበላሸት"), `breakage` ("Breakage" / "መሰበር"), `miscount` ("Miscount" / "የቆጠራ ስህተት"),
   `theft_suspected` ("Theft suspected" / "የስርቆት ጥርጣሬ"), `other` ("Other" / "ሌላ", `requires_note` true). Given to every
   branch by `Shifts\Support\DefaultVarianceReasons::ensureFor(branchId)`, called by the `BranchCreated` listener, by this
   migration (for the branches that exist on the live host; idempotent, never touches an edited or removed reason, the way
   `Channels::ensureDefaults` does) and by `Phase0Seeder`'s branch creation path. The Amharic texts are a first draft for
   Phase 5's review with the staff.
9. `..._create_stock_counts_table` (Shifts, 3.3a; the lock columns are created now so 3.4 adds no column, "final schema, never
   throwaway"): a count of one location at one shift boundary. `id`, `branch_id`, `client_uuid` UUID (derived by the server, UUIDv5
   of `count:{shift}:{location}:{type}:{seq}`, so a retried start or close finds the same row), `shift_instance_id` FK,
   `location_id` FK, `type` (`opening | closing`, CHECK), `seq` TINYINT UNSIGNED (1 for the first count of this shift, location
   and type; a recount or an unlock revision is `max + 1`), `status` (`draft | awaiting_cosign | pending_approval | approved | locked
   | rejected`, CHECK; `approved` is the instant between the decision and the lock, which one transaction crosses once 3.4 lands),
   `counted_by_user_id` FK NULL (the person who submitted: acting-as, else the account), `started_at`, `snapshot_at` DATETIME(6)
   NULL (the instant the balances were read under the row locks at submit), `ledger_movement_id` BIGINT UNSIGNED NULL (FK
   `stock_movements.id`: the newest movement of the branch at that instant; what "since the count" is measured from),
   `submitted_at`, `cosigned_by_user_id` FK NULL, `cosigned_at`, `decision` (`approved | rejected`, CHECK, NULL), `decided_by_user_id`
   FK NULL, `decided_at`, `decision_note` VARCHAR(500) NULL, `supersede_cause` (`recount | unlock`, CHECK, NULL; on the **new** row),
   `unlock_reason` VARCHAR(255) NULL, `superseded_by_id` BIGINT UNSIGNED NULL (self FK; on the **old** row; the only column besides
   `superseded_at` that may change once the row is locked), `superseded_at`, **the lock**: `chain_seq` INT UNSIGNED NULL,
   `previous_hash` CHAR(64) NULL, `payload_hash` CHAR(64) NULL, `payload_version` TINYINT UNSIGNED NULL, `canonical_payload`
   MEDIUMTEXT NULL (the exact bytes that were hashed, ADR 0012), `locked_at` DATETIME(6) NULL, `locked_by_user_id` FK NULL; the
   actor stamp without `shift_instance_id`; `version` INT UNSIGNED; timestamps. No soft delete. CHECKs: `seq >= 1`; `(status =
   'locked') = (payload_hash IS NOT NULL AND previous_hash IS NOT NULL AND chain_seq IS NOT NULL AND canonical_payload IS NOT
   NULL AND locked_at IS NOT NULL)`; `chain_seq >= 1`; `decision` set exactly when `decided_at` is. Unique `(branch_id,
   client_uuid)`, unique `(shift_instance_id, location_id, type, seq)`, **unique `(branch_id, location_id, chain_seq)`** (NULL for
   unlocked rows: the chain's sequence is claimed by the unique key, section 5 and 10.3); indexes `(location_id, type, status)`,
   `(branch_id, status)` (the approval queue), `(superseded_by_id)`. The first rule of this table: **at most one unresolved
   count per location** (status `awaiting_cosign` or `pending_approval`): the action refuses a second submit
   (`409 shifts.count.blocked_by_pending`), because two unresolved counts at one place would each correct the same missing
   stock (section 10.8, "double counting"). That is code, not a database constraint, and the property test pins it.
10. `..._create_stock_count_lines_table` (Shifts, 3.3a): `id`, `branch_id`, `count_id` FK, `item_id` FK, `expected_qty`
    DECIMAL(14,4) NULL (the ledger's `on_hand` at the snapshot; null while draft), `carried_qty` DECIMAL(14,4) NULL (opening
    only: what the previous locked closing counted, the prefill), `counted_qty` DECIMAL(14,4) NULL (CHECK `>= 0`), `variance_qty`
    DECIMAL(14,4) NULL (counted - expected, signed, set at submit), `confirmed_at` DATETIME NULL (null until the counter touches
    the line; submit refuses an unconfirmed line, so a prefilled number is never accepted unlooked-at), `variance_reason_id` FK
    NULL, `variance_note` VARCHAR(255) NULL, `reason_status` (`none | pending | accepted | rejected`, CHECK, default `none`),
    `decision_note` VARCHAR(255) NULL, `adjustment_movement_id` BIGINT UNSIGNED NULL (FK `stock_movements.id`, set at approval),
    `photo_key` VARCHAR(255) NULL (reserved, always null in Phase 3: the Files capability is Phase 4), timestamps. No soft delete.
    Unique `(count_id, item_id)`; index `(item_id)`. No actor stamp: the count carries it (allow-listed in `ActorStampTest`).
    **Immutable after submit, by trigger** (the pattern of `stock_movements`, but conditional): `stock_count_lines_freeze_insert`
    refuses an INSERT when the parent count is not `draft`; `stock_count_lines_freeze_update` refuses an UPDATE of any column
    other than `reason_status`, `decision_note`, `adjustment_movement_id` and `updated_at` when the parent is not `draft`, and
    refuses even those once the parent is `locked`; `stock_count_lines_forbid_delete` refuses every DELETE. MariaDB sketch:

    ```sql
    CREATE TRIGGER stock_count_lines_freeze_update BEFORE UPDATE ON stock_count_lines FOR EACH ROW
    BEGIN
      DECLARE parent_status VARCHAR(20);
      SELECT status INTO parent_status FROM stock_counts WHERE id = OLD.count_id;
      IF parent_status = 'locked' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'a locked count is immutable (rule 5)';
      ELSEIF parent_status <> 'draft' AND NOT (
            NEW.count_id <=> OLD.count_id AND NEW.item_id <=> OLD.item_id AND NEW.expected_qty <=> OLD.expected_qty
        AND NEW.carried_qty <=> OLD.carried_qty AND NEW.counted_qty <=> OLD.counted_qty AND NEW.variance_qty <=> OLD.variance_qty
        AND NEW.confirmed_at <=> OLD.confirmed_at AND NEW.variance_reason_id <=> OLD.variance_reason_id
        AND NEW.variance_note <=> OLD.variance_note AND NEW.photo_key <=> OLD.photo_key) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'count lines are immutable after submit (rule 5)';
      END IF;
    END
    ```

    PostgreSQL: one function `stock_count_lines_guard()` per event with the same logic over `to_jsonb(NEW) - 'reason_status' -
    'decision_note' - 'adjustment_movement_id' - 'updated_at' IS DISTINCT FROM to_jsonb(OLD) - ...`, attached `BEFORE INSERT OR
    UPDATE OR DELETE`. `LinesFreezeTriggerTest` (a schema test on `information_schema.columns` and `triggers`) fails when a column
    is added to the table and the trigger text does not name it.
11. `..._extend_notification_types_for_shifts` (Notifications, step 3.3c): drops and re-adds the `notifications_type_check` CHECK
    with the ten Phase 2 types plus `count_submitted`, `variance_decided`, `chain_broken` (the migration Phase 2 promised: "Phase 3
    extends the list in its own migration"). Data untouched. The same `ALTER TABLE ... DROP CONSTRAINT` / `ADD CONSTRAINT` syntax
    works on MariaDB 10.11 and PostgreSQL.
12. `..._add_stock_counts_locked_rows_triggers` (Shifts, step 3.4): the rule of `invariants.md`, "locked `stock_counts` rows reject
    UPDATE except `superseded_by_id`". `stock_counts_locked_forbid_update` (BEFORE UPDATE): when `OLD.status = 'locked'`, any change
    to any column other than `superseded_by_id` and `superseded_at` is `SIGNAL SQLSTATE '45000'`; `superseded_by_id` may go from NULL
    to a value once and never back or elsewhere; `stock_counts_forbid_delete` (BEFORE DELETE, every row, locked or not: a count is
    history). MariaDB lists every column with the null-safe `<=>`; PostgreSQL compares `to_jsonb(NEW) - 'superseded_by_id' -
    'superseded_at'` with `to_jsonb(OLD) - ...`. The code that writes the two allowed columns is one query-builder `UPDATE` in
    `Locks::supersede` (no model save, so `updated_at` and `version` are not touched); `LockedRowsTest` proves every other column
    of a locked row is refused one by one (it reads the column list from `information_schema`, so a new column is covered
    automatically) and `StockCountsTriggerCoversEveryColumnTest` fails when the MariaDB trigger text omits a column.
13. `..._create_cash_declarations_table` (Shifts, 3.5): the cash of the till's drawer, one per cash-drawer count. `id`, `branch_id`,
    `count_id` FK **unique** (the `stock_counts` row at the station's cash-drawer location; so the declaration has the same
    lifecycle, approval, lock and chain as a stock count and needs no second workflow), `shift_instance_id` FK (denormalised for the
    shift screens), `denominations` JSON NOT NULL (object `{"200": 3, "100": 5, ...}`, counts as integers, keys the denominations
    of `config('shifts.denominations')`), `counted_total` DECIMAL(12,2) (computed by the server from the denominations, never
    sent), `expected_total` DECIMAL(12,2) NULL, `variance_total` DECIMAL(12,2) NULL, `carried_float_total` DECIMAL(12,2) NULL
    (opening: the previous closing's `float_left_total`), `cash_sales_total` DECIMAL(12,2) NULL (closing: `Totals::cash(shift)` at
    submit), `float_left_total` DECIMAL(12,2) NULL (closing: what stays in the drawer for the next shift), `channel_counts` JSON
    NULL (closing: the per-channel reconciliation, section 4), `variance_reason_id` FK NULL, `variance_note` VARCHAR(255) NULL,
    `currency` CHAR(3), timestamps. CHECKs: `counted_total >= 0`; `float_left_total IS NULL OR (float_left_total >= 0 AND
    float_left_total <= counted_total)`. Frozen by the same trigger pattern as the lines (parent `draft` only; `locked` fully).
    Amounts are `*_total`, so the money rule (12,2) applies; `*_total` and a JSON column are the only shapes.
    **As built (3.5), where the code differs from the text above:** one more column, `confirmed_at` DATETIME NULL (null until the cashier
    has declared; a submit refuses an undeclared drawer, as it refuses an unconfirmed line); `counted_total` defaults to `0.00` (a draft is a
    declaration of nothing yet); `channel_counts` holds the declared figures `[{code, receipts, sum}]` while the count is a draft and, from the
    submit, the whole reconciliation `[{code, expected_receipts, expected_sum, declared_receipts, declared_sum, difference_sum}]`; the
    triggers are `cash_declarations_freeze_insert` (parent not a draft), `cash_declarations_freeze_update` (parent not a draft and any column
    but `updated_at` changed, read from `information_schema` so it names every column, as migration 12 does; parent `locked`: always) and
    `cash_declarations_forbid_delete`; PostgreSQL has the one `cash_declarations_guard()` function. There is no decision column to leave
    writable (a count's decision lives on `stock_counts`), so unlike a line nothing may change after the submit.
14. `..._create_handover_notes_table` (Shifts, 3.6): `id`, `branch_id`, `client_uuid` UUID, `shift_instance_id` FK, `from_user_id`
    FK (the person who wrote it: acting-as, else the account), `to_user_id` FK NULL (a named colleague; null means "the next
    shift"), `body` VARCHAR(1000) NOT NULL (CHECK `CHAR_LENGTH(body) > 0` on MariaDB, `char_length` on PostgreSQL; free text in
    whichever language the writer used, so no `_en/_am` pair, like an order's `notes`), `created_by_user_id`, `acting_user_id?`,
    `actor_station_id?`, `device_id?`, `created_at` DATETIME(6). **Append-only**: triggers `handover_notes_forbid_update_delete_
    {update,delete}` on MariaDB, `forbid_update_delete()` on PostgreSQL, the `audit_log` / `order_events` pattern; `AppendOnlyTest`
    gains the table; a correction is a new note. Unique `(branch_id, client_uuid)`; indexes `(shift_instance_id, id)`, `(branch_id,
    id)` (the polled feed's cursor).

**What is deliberately not a table.** The hash chain has no table of its own: it is the `chain_seq` / `previous_hash` /
`payload_hash` columns of `stock_counts`, per `(branch, location)`, so the locked row **is** the chain link and the trigger of
migration 12 protects both. A "chain head" table would be a second mutable record to keep in step and a new lock to take
(section 10.3). Count adjustments are `stock_movements` rows (`reason = count_adjustment`, `ref_type = stock_count_line`,
`ref_id` = the line); the line carries `adjustment_movement_id` back to the row. Stock variances have no ledger table of their
own either: the lines are the variance.

**Models.** New models are `BranchScoped`; the `BranchIdTest` allowlist is unchanged (no table is global). Soft-deleted:
`ShiftTemplate`, `ShiftTemplateStation`, `ShiftRoster` (scheduled rows only), `VarianceReason`. Allow-listed in `SoftDeletesTest`
with their reasons: `ShiftInstance` (ends by status), `StockCount`, `StockCountLine`, `CashDeclaration`, `HandoverNote`.
Allow-listed in `ActorStampTest` with the reason "the parent carries the stamp": `StockCountLine`, `CashDeclaration`,
`ShiftTemplateStation`. `BilingualColumnsTest` configuration lists `shift_templates` and `variance_reasons`.

## 4. Endpoints

All under `/api/v1`, bearer auth (`auth:sanctum`, `actor`), permission middleware, `Idempotency-Key` on every write plus a
`client_uuid` where a row is created that an offline client could replay (ADR 0003: schedule, start, ad-hoc, handover notes;
counts are created by the server with a derived UUID). Money and quantities are decimal strings; quantities are in the
item's **base unit**, as on every screen since 2.8b. Every error is `{message_key, message_en, message_am, ...}` with the key
in `lang/{en,am}/shifts.php` (new) or `inventory.php` / `payments.php`. State transitions are actions, never PATCHes of a
status. Repeating an action that already took effect answers `200` with the row and writes nothing (not even an audit row);
asking for a different state is `409 state_machine.illegal_transition` or a specific key. Read endpoints that screens poll
are conditional (`ConditionalPoll`: ETag, `If-None-Match`, `304`, `Cache-Control: no-cache, private`). "At the station" below is
`Access::can(person, permission, station)`: assigned there, or holds `stations.all`; the person is the one who physically acts
(the short-code actor, else the account), as in Orders and Production. A station whose `tracks_shifts` is false has no shifts:
every action on it is `422 shifts.station.not_tracked`.

| Method + path | Permission | Idempotent | Request | Response | Errors |
|---|---|---|---|---|---|
| **Identity (3.1a)** | | | | | |
| `GET /me` (extended) | `identity.authenticated` | read | | adds `work_stations: [{station_id, code, name_en, name_am, tracks_shifts, requires_overseer, current_shift: {id, status, date, opened_at} or null}]` for the assigned stations, or every station for `stations.all` | |
| `PATCH /stations/{station}` (extended) | `people.manage` | key | adds `tracks_shifts?` | `200` Station (adds `tracks_shifts`) | `422`, `404` |
| **Templates and reasons (3.2b, 3.3a)** | | | | | |
| `GET /shift-templates` | `shifts.view` | read | `?active=1`, `?station_id=` | `200 {data: [Template]}` | `403` |
| `POST /shift-templates` | `shifts.manage` | key | `{name_en, name_am, start_time "HH:MM", end_time, days_of_week [1..7], station_ids [..], is_active?}` | `201` Template | `422 validation.failed`, `shifts.template.window_invalid` (start equals end), `shifts.template.station_unknown` |
| `PATCH /shift-templates/{template}` | `shifts.manage` | key | any of the above | `200` Template (instances already made keep their own window) | `422`, `404` |
| `DELETE /shift-templates/{template}` | `shifts.manage` | key | | `200` the Template as it was (soft-deleted) | `404` |
| `GET /variance-reasons` | `shifts.view` | read | `?active=1` | `200 {data: [{id, code, name_en, name_am, requires_note, is_active, sort}]}` | `403` |
| `POST /variance-reasons` | `shifts.manage` | key | `{code, name_en, name_am, requires_note?, sort?}` | `201` Reason | `422`, `409 shifts.reason.code_taken` |
| `PATCH /variance-reasons/{reason}` | `shifts.manage` | key | `{name_en?, name_am?, requires_note?, is_active?, sort?}` (the code never changes) | `200` Reason | `422`, `404` |
| **Shifts (3.2b)** | | | | | |
| `GET /shifts` | `shifts.view` (stations the caller works at, or all with `stations.all`) | read; `?running=1` is polled (ETag over count, `max(updated_at)`, `sum(version)`) | `?station_id=`, `?status=`, `?running=1` (opening, open, counting), `?from=` `?to=` (local dates), `?mine=1`, `?page=`, `?per_page=` | `200` paginated `{data: [ShiftSummary], meta}` newest first | `403`, `422` |
| `GET /shifts/{shift}` | `shifts.view` at the station | read | | `200` Shift | `404`, `403 shifts.station.forbidden` |
| `POST /shifts` | `shifts.manage` | key + `client_uuid` | `{client_uuid, station_id, template_id, date "YYYY-MM-DD" (local), user_ids [..]}` (schedules; the window is the template's on that date) | `201` Shift (`scheduled`, roster rows `source = scheduled`); a used `client_uuid` answers `200` | `422 validation.failed`, `shifts.template.not_for_station`, `shifts.roster.user_invalid` (inactive, other branch, not assigned to the station, or without `shifts.count` there), `shifts.station.not_tracked`, `409 shifts.shift.already_scheduled` `{shift_id}` (the same template, date and station is still waiting or running; added in 3.2b) |
| `PUT /shifts/{shift}/roster` | `shifts.manage` | key | `{user_ids [..]}` (replaces the roster of a `scheduled` shift; removed rows are soft-deleted) | `200` Shift | `409 shifts.shift.not_scheduled`, `422 shifts.roster.user_invalid` |
| `POST /shifts/{shift}/cancel` | `shifts.manage`, or the person who started it while `opening` | key | `{reason?}` | `200` Shift (`cancelled`, `open_key` freed) | `403 shifts.cancel.forbidden`, `409 shifts.shift.not_cancellable` `{status}` (only `scheduled` and `opening`) |
| `GET /stations/{station}/shifts/current` | `shifts.view` at the station | read (polled, conditional) | | `200 {data: Shift or null, server_time}`: the running shift (opening, open, counting), else `null` | `403 shifts.station.forbidden`, `422 shifts.station.not_tracked` |
| `POST /stations/{station}/shifts/start` | `shifts.count` at the station | key + `client_uuid` | `{client_uuid, shift_id? (a scheduled one), template_id?, carry? (open without counting, below), force? (outside the window; `shifts.manage` only)}` | `201 {shift, counts}`: the shift is `opening` with draft opening counts (prefilled) or, with `carry` or at a station with nothing to count, `open`; `200` for a repeat or for the same person resuming their own `opening` shift | `409 shifts.shift.already_open` `{shift_id, status}`, `409 shifts.shift.not_scheduled`, `422 shifts.shift.outside_window` `{starts_at, ends_at}`, `403 shifts.start.force_forbidden`, `403 shifts.station.forbidden`, `422 shifts.station.not_tracked` |
| `POST /stations/{station}/shifts/open-ad-hoc` | `shifts.count` at the station (for oneself) or `shifts.manage` (`user_id`) | key + `client_uuid` | `{client_uuid, user_id?}` (the call-in: opens `origin = call_in`, `opening_mode = carried`, no template, the person joins) | `201 {shift}`; `200` if one is already running (the person joins it) | `403`, `422 shifts.station.not_tracked` |
| `POST /shifts/{shift}/join` | `shifts.count` at the station | key | `{}` | `200` Shift (a roster row with `joined_at`, `source = joined`, or `scheduled -> joined`) | `409 shifts.shift.not_running` `{status}` |
| `POST /shifts/{shift}/leave` | `shifts.count` at the station | key | `{}` | `200` Shift (`left_at` set) | `409 shifts.shift.not_running` |
| `POST /shifts/{shift}/close` | `shifts.count` at the station, or `shifts.manage` | key | `{}` | `200 {shift, counts}`: `open -> counting` (the station is frozen: section 10.1), one draft closing count per countable place and one for the cash drawer, expected shown live from the ledger; a repeat answers `200` | `409 shifts.shift.not_open` `{status}` (a shift still `opening` is cancelled or opened, not closed) |
| `GET /shifts/{shift}/summary` | `shifts.view` at the station | read | | `200 {movements: [{location, item, reason, qty, rows}] (Stock::shiftSummary: production, sales, waste, transfers, adjustments, by reason), cash: {...} (till only), open_work: {queued_lines, accepted_lines} (from Orders::openAt; a warning, not a block)}` | `404`, `403` |
| **Counts (3.3)** | | | | | |
| `GET /shifts/{shift}/counts` | `shifts.view` at the station | read | | `200 {data: [Count]}` every revision, oldest first | `404`, `403` |
| `GET /stock-counts` | `shifts.view` | read (polled, conditional) | `?status=` (`awaiting_cosign`, `pending_approval`), `?station_id=`, `?mine=1`, `?limit=` (default 50, at most 200) | `200 {data: [CountSummary], counts: {awaiting_cosign, pending_approval}, server_time}` for the stations the caller works at or all with `stations.all`; this is the admin's approval queue. As built in 3.3c a row is the CountSummary plus `station {id, code, name_en, name_am, requires_overseer}`, `shift {id, date, status}` and `variance_lines` (the lines that differ, as in Count, with `moved_since`); oldest submitted first; `counts` ignore `status` and `limit`; `?mine=1` is the counts the caller handed in; `304` with a matching `If-None-Match` (the tag also moves with the ledger, which `moved_since` reads) | `403`, `422 validation.failed` |
| `GET /stock-counts/{count}` | `shifts.view` at the station | read | | `200` Count with its lines, side by side: expected, carried, counted, variance, reason, note, and `moved_since` (as built in 3.3b: while the count waits for a decision or is approved, the net change at the place since its snapshot without its own adjustments; an opening draft, the net change since the previous closing; null otherwise) | `404` |
| `PUT /stock-counts/{count}/lines` | `shifts.count` at the station | key | `{lines: [{item_id, counted_qty?, confirm? (true to mark it looked at), reason_id?, reason_note?}], confirm_all? (true: mark every line confirmed at its prefill, carried for an opening or the live ledger figure for a closing; the explicit "nothing changed" button)}` (draft only; an `item_id` not yet on the count adds a line: "found an item not on the list", expected 0) | `200` Count | `409 shifts.count.not_draft` `{status}`, `422 shifts.count.item_unknown` (not an item of the branch, or an ingredient or product that cannot be stocked), `422 shifts.count.qty_invalid` (negative, more than 4 dp, over ten integer digits), `422 shifts.reason.unknown` |
| `POST /stock-counts/{count}/submit` | `shifts.count` at the station | key | `{}` | `200` Count: `draft -> awaiting_cosign` when the station `requires_overseer`, else `-> pending_approval` when any line differs or the cash does, else straight to `locked` (as built in 3.4: `approved` and the lock are one step, in the submit's transaction; the first link of the place's chain, or the next). The shift moves on with it: an opening count submitted opens the shift, the last closing count submitted moves `counting -> pending_approval`, or `-> locked` when every count is locked. Emits `CountSubmitted` | `422 shifts.count.incomplete` `{item_ids}` (a line not confirmed, or an item with stock that is not on the count yet), `422 shifts.count.reason_required` `{item_ids}`, `422 shifts.count.note_required` `{item_ids}` (a reason that `requires_note`), `409 shifts.count.blocked_by_pending` `{count_id}` (another count at this place is unresolved), `409 shifts.count.not_draft` |
| `POST /stock-counts/{count}/cosign` | route `shifts.view`; the action: `shifts.cosign` at the station or the overseer flagged there, and never the person who counted | key | `{note?}` (kept in the audit row) | `200` Count (`awaiting_cosign -> pending_approval` when a line differs, else `locked` (3.4; it was `approved` in 3.3c); as built in 3.3c the last co-signature of a shift locks the shift like the last approval; a repeat by the same co-signer answers `200`). Emits `CountSubmitted` (the new `status_after`) | `403 shifts.station.forbidden`, `403 shifts.count.cosign_forbidden`, `409 shifts.count.cosign_self`, `409 shifts.count.not_awaiting_cosign` `{status}` |
| `POST /stock-counts/{count}/approve` | `shifts.approve` (admin, manager), and never the person who counted | key | `{note?}` | `200` Count (`locked`, a link of the chain: as built in 3.4 the approval and the lock are one transaction): writes one `count_adjustment` per line with a variance in one `Ledger::appendMany`, stamped with the counted shift; locks and chains (3.4); a repeat answers `200`. Emits `VarianceDecided` (and `ShiftLocked` when this was the last count of the shift) | `403`, `409 shifts.count.approve_self`, `409 shifts.count.not_pending` `{status}`, `409 shifts.adjustment.insufficient` `{item_id, location_id, on_hand, adjustment}` (stock moved away since the count; nothing written; reject and recount), `409 shifts.lock.chain_busy` (eight tries lost the chain's sequence; try again; as built in 3.4 the limit is `HashChain::MAX_ATTEMPTS` = 8) |
| `POST /stock-counts/{count}/reject` | route `shifts.view` (3.3c; it was `shifts.approve` in 3.3b); the action decides: a count in `awaiting_cosign` is rejected by whoever may co-sign it (the overseer flagged there, `shifts.cosign`), one in `pending_approval` by `shifts.approve` (`403 auth.forbidden` otherwise) | key | `{note, line_ids?}` (the note is required; `line_ids` marks the lines to look at again, default every line with a variance) | `200` Count (`rejected`) + the new draft revision `{recount: Count}` prefilled with the counted values (both attempts are kept, the first is `superseded_by_id` -> the second); the shift goes back to `counting` or stays `opening`. Emits `VarianceDecided` | `403`, `422 validation.failed`, `409 shifts.count.not_pending` |
| `POST /stock-counts/{count}/unlock` | `shifts.unlock` (admin) | key | `{reason}` (required) | `200 {unlocked: Count (still locked, `superseded_by_id` set), revision: Count (draft, `supersede_cause = unlock`, expected = the unlocked count's counted value)}` | `403`, `422 validation.failed`, `409 shifts.count.not_locked`, `409 shifts.count.already_superseded` |
| **Cash (3.5)** | | | | | |
| `PUT /stock-counts/{count}/cash` | `shifts.count` at the till | key | `{denominations: {"200": 3, "100": 5, ...}, float_left_total? (closing), channels: [{code, receipts, sum}]? (closing), reason_id?, reason_note?}` on the count of the cash-drawer place (draft only) | `200` Count with `cash` (below); `counted_total` is computed from the denominations | `409 shifts.count.not_draft`, `422 shifts.cash.no_drawer` (not a cash-drawer count), `422 shifts.cash.denomination_unknown` `{denomination}`, `422 shifts.cash.count_invalid` `{denomination}` or `{field}` (a count that is not a whole number, a sum with more than two decimals or not a number, a channel twice, a float left or channel figures on an opening count), `422 shifts.cash.float_too_large`, `422 shifts.cash.channel_unknown` `{code}` (not a channel of the branch, or switched off), `422 shifts.reason.unknown`, `422 validation.failed` (no `denominations`). A key that is absent leaves that part alone and `null` clears it; any `counted_total` sent is ignored. The submit adds `422 shifts.cash.declaration_missing`, `422 shifts.cash.float_required` (a closing that does not say what stays in the drawer), `422 shifts.cash.reason_required` and `422 shifts.cash.note_required` (a cash variance or any channel difference with no reason, or with a reason that asks for a note and has none) |
| `GET /shifts/{shift}/cash` | `shifts.view` at the till | read | | `200 {opening_total, carried_float_total, cash_sales_total, expected_total, by_channel: [{code, name_en, name_am, receipts, sum}], currency}`: the till widget's "cash in the drawer so far", live from `Totals` | `404`, `403` |
| **Chain (3.4)** | | | | | |
| `GET /locations/{location}/chain` | `shifts.replay` | read | `?limit=` (default 50, at most 200), `?before_seq=` | `200 {data: [{count_id, chain_seq, type, shift_id, shift_date, locked_at, previous_hash, payload_hash, superseded_by_id}], head: {chain_seq, payload_hash} or null}` newest first | `403`, `404` |
| `GET /locations/{location}/chain/verify` | `shifts.replay` | read | | `200 {ok, checked, head_hash, failures: [{chain_seq, count_id, kind}]}`, `kind` in `gap`, `previous_mismatch`, `hash_mismatch`, `payload_mismatch` | `403`, `404` |
| **Handover and replay (3.6)** | | | | | |
| `GET /shifts/{shift}/notes` | `shifts.view` at the station | read | `?after_id=` | `200 {data: [Note], last_id}` oldest first | `404` |
| `POST /shifts/{shift}/notes` | `shifts.count` at the station | key + `client_uuid` | `{client_uuid, body, to_user_id?}` | `201` Note; a used `client_uuid` answers `200` | `422 validation.failed`, `409 shifts.shift.not_running` (a scheduled, locked or cancelled shift takes no notes; as built in 3.6 one waiting for approval still does) |
| `GET /stations/{station}/handover` | `shifts.view` at the station | read | | `200 {previous: ShiftSummary or null, notes: [Note] (the previous shift's, and any addressed to the caller), previous_closing: [{location, count_id, chain_seq, payload_hash, locked_at, provisional: bool, float_left_total}], pending_counts: [CountSummary]}`: the "Start shift" screen's first panel (as built in 3.6: `float_left_total` is the money the drawer keeps for the next shift, null on every other place; a provisional row has no hash) | `403`, `422 shifts.station.not_tracked` |
| `GET /replay` | `shifts.replay` | read | `?station_id=` (required), `?at=` (required, ISO-8601 with an offset or `Z`; a bare time is the branch's local time), `?nonzero=0` (default only non-zero balances) | `200 {at, station, shift: ShiftSummary or null, on_duty: [{user, joined_at, left_at}], locations: [{location, balances: [{item, on_hand, reserved}], last_locked: {count_id, chain_seq, payload_hash, locked_at} or null}], open_orders: [{order_id, order_no, table_code, type, sent_at, owner, lines: [{item_id, qty, status}]}], counts: [{count_id, shift_id, location_id, type, status_at, submitted_at, decided_at, locked_at}]}` (as built in 3.6: no cash block, a timestamp after `at` is null, `on_duty[].left_at` is always null) | `422 shifts.replay.future`, `422 validation.failed`, `403` |

Resources.

- **Shift** (`ShiftSummary` is the same without `roster`, `counts` and `notes_count`): `{id, client_uuid, station {id, code, name_en, name_am, tracks_shifts, requires_overseer}, template {id, name_en, name_am, start_time, end_time} or null, date (local start date), status, origin, opening_mode, starts_at, ends_at, started_by {id, name}, started_at, opened_by, opened_at, closed_by, closing_started_at, submitted_at, locked_at, roster: [{user {id, name}, source, joined_at, left_at}], counts: [CountSummary], notes_count, me_on_roster: bool, version}`. Times are UTC ISO-8601; the screens show `Africa/Addis_Ababa`.
- **Count**: `{id, shift_id, location {id, name_en, name_am, type}, type, seq, status, counted_by {id, name} or null, started_at, snapshot_at, submitted_at, cosigned_by, decided_by, decision, decision_note, supersede_cause, superseded_by_id, lines: [Line], cash: Cash or null, chain: {chain_seq, previous_hash, payload_hash, locked_at} or null, blocked_by_pending: {count_id} or null, version}`. **Line**: `{id, item {id, name_en, name_am, unit}, expected_qty, carried_qty, counted_qty, variance_qty, confirmed, reason {id, code, name_en, name_am} or null, note, reason_status, decision_note, adjustment_movement_id, moved_since}` (decimal strings; null where not yet known). **Cash**: `{denominations: {"200": 3}, counted_total, expected_total, variance_total, carried_float_total, cash_sales_total, float_left_total, channels: [{code, name_en, name_am, expected_receipts, expected_sum, declared_receipts, declared_sum, difference_sum}], confirmed, reason {id, code, name_en, name_am} or null, note, currency}`; every configured denomination is listed (zero where none); for a draft the figures that depend on payments are live (`expected_total`, `variance_total`, `cash_sales_total`, the expected side of `channels`), from the submit they are the stored ones; a channel row has the declared figures and the difference null where nothing was declared for it; `channels` is `[]` for an opening count.
- **Note**: `{id, client_uuid, shift_id, from {id, name}, to {id, name} or null, body, created_at}`.

**How a start works (said exactly).** (1) The `client_uuid` of a repeat answers the shift already made. (2) A running shift
(`opening`, `open`, `counting`) at the station: the person who started the `opening` one resumes it (`200`); anyone else gets
`409 shifts.shift.already_open` and the screen offers Join (`POST /shifts/{shift}/join`). (3) The instance: the `shift_id`
asked for, which must be `scheduled` at this station; else the scheduled instance of this station whose window contains now
(from `starts_at - grace` to `ends_at`, `grace` = `config('shifts.start_grace_minutes')`, default 60); else a template of the
station whose window contains now (`ShiftWindow`, section 10.2); else an ad-hoc instance (`origin = started`, no template).
Outside every window an ordinary person has the ad-hoc path; an admin may start a scheduled instance with `force` (it keeps
its own `date`: section 10.2). (4) The row is written in one `AuditedAction` (`shift.start`), the person joins the roster, and
the unique `(station_id, open_key)` decides a race: the loser's insert fails inside a savepoint and answers
`409 shifts.shift.already_open`. A missing row is never locked (traps.md, "First-row race"); an existing scheduled instance is
locked `FOR UPDATE` by id. (5) The status: stations with countable places and no `carry` go to `opening` with one prefilled
opening draft per place (the cash drawer's draft asks for the float); a station with nothing to count, `carry`, and every
call-in go straight to `open` (`opening_mode = carried`). In step 3.2 there are no counts yet, so `start` opens at once, as
`carry`; step 3.3a changes the default as above and the e2e helper moves with it. (6) `ShiftOpened` is dispatched after the
commit when the status reaches `open`. `carry` is the screen's "Start without counting" for the morning the admin has not yet
approved last night's count: the shift opens, and its own closing count cannot be submitted until the pending one is resolved
(rule R-A, section 10.8).

**How a count works.** The draft's lines are the items with a non-zero `on_hand` at the place, plus the items of the previous
count of that place (an item counted to zero stays visible until a count after it leaves it at zero), plus anything the
counter adds. An opening draft is prefilled with `carried_qty` from the previous locked closing at that place (a provisional
`carried_qty` from a submitted, not yet approved one, flagged `provisional`). The counter touches every line (or presses
the explicit "nothing changed" button), enters a reason for each line whose counted figure differs from what is expected, and
submits. **Submit** is one `AuditedAction` (`count.submit`): the count row is locked `FOR UPDATE`; the shift row is locked
`FOR UPDATE` (always before any balance: the lock order of section 10.1); `Ledger::lock` takes the balance rows of every line
(and of any item with stock that is not on the count yet, which makes the submit refuse with `incomplete`) in ascending
`(item_id, location_id)` order; under those locks `expected_qty` is each balance's `on_hand`, `snapshot_at` is now,
`ledger_movement_id` the newest movement id, `variance_qty` counted minus expected; lines with a variance get
`reason_status = pending`. For an opening line the test `expected_qty = carried_qty + Stock::netChangeSince(...)` is asserted in
the same transaction and a difference is logged to the audit context as `opening_drift` (the property test of section 7 pins
that it never happens when the rules hold; it can only happen if someone writes to the table around the ledger). Nothing here
writes a movement. **Approve** is one `AuditedAction` (`count.approve`): count row, then shift row, then one
`Ledger::appendMany` of `count_adjustment` intents (`qty = counted - expected` per variance line, `ref_type = stock_count_line`,
`ref_id` = the line, `client_uuid` = UUIDv5 of `count-line:{branch}:{line}` so a replay cannot write twice) run as the counted
shift (`ActorContext::runAs` with the count's `shift_instance_id`), the lines' `adjustment_movement_id` and
`reason_status = accepted`, the count `approved`, then the lock (3.4), then the shift's own transition. **Why a delta and not
"set the stock to the counted figure":** the count measured a difference at an instant; stock that moved afterwards (an inbound
transfer, a baker's output) is real and must survive the correction. A delta that would take `on_hand` below zero (stock left
since the snapshot) is refused whole, `409 shifts.adjustment.insufficient`, and the admin rejects and asks for a recount
(section 10.4).

**An unlock** creates a new draft revision of the same shift, place and type whose `expected_qty` is the superseded count's
**counted** value (what the ledger was made to equal), so submitting and approving it writes only the correcting difference.
The old row stays locked and visible with `superseded_by_id`; it remains a link of the chain, and the new revision becomes a
new link when it locks.

**Who may act.** `shifts.count` at the station starts, joins, closes, enters, submits, declares cash and writes notes; the
cashier counts the till, the chef the kitchen, nobody the waiter. `shifts.cosign` (admin, manager) or the overseer flag at the
station co-signs, never the person who counted. `shifts.approve` approves and rejects, never the person who counted (a
one-person shop's admin who counted needs the manager; open question). `shifts.unlock` is the admin. `shifts.manage` is
configuration and acting for others. `shifts.view` reads at the stations one works at (everything for `stations.all`);
`shifts.replay` is the replay, the chain and the verification.

Every write endpoint is listed in `tests/Support/write_endpoints.php` and `route_permissions.php` in the step that adds it
(the route matrix gains the `{shift}`, `{template}`, `{reason}` and `{count}` placeholders; `{location}` and `{station}` exist);
`check_invariants.py --idempotency` and the route matrix test keep that true. About 36 routes in all, 21 of them writes (plus the extended `PATCH /stations/{station}`), each
with a feature test file. `docs/openapi.json` is re-exported in every PR that adds a route (`openapi-check`).

## 5. Domain events and listeners

Event classes live in `App\Shared\Events` (array payloads of ids, decimal strings and bilingual strings) so Notifications and,
in Phase 4, Analytics subscribe without importing Shifts. All are dispatched after commit (`ShouldDispatchAfterCommit`, as
Production's are). The `branch_id` is on the event envelope, not repeated in `payload()` (as built in 2.1).

| Event | Payload | Listeners (module) | Queued? |
|---|---|---|---|
| `ShiftOpened` (new, 3.2b) | shift_id, station_id, station_name_en/am, date, origin, opening_mode, template_id and names or null, starts_at, opened_by_user_id | none in Phase 3: the admin home polls `GET /shifts?running=1`; Phase 4's Analytics reads the tables | n/a |
| `CountSubmitted` (new, 3.3c) | count_id, shift_id, station_id and names, location_id and names, type, seq, status_after (`awaiting_cosign`, `pending_approval`, or `locked` for a count that needs nothing decided: as built in 3.4 `approved` is never announced because the lock is in the same transaction), lines (total), variance_lines, up to 5 variance items as `items` (biggest differences first: item names en/am, expected, counted, variance, unit), cash_variance_total or null, counted_by_user_id, cosigned_by_user_id or null; station_code and the place's names are in the payload too. Also announced by the co-signature, with the new `status_after` | Notifications `NotifyCountSubmitted` | **yes** |
| `VarianceDecided` (new, 3.3c) | count_id, shift_id, station and location ids, station_code and names, type, decision (`approved`, `rejected`), decided_by_user_id, note, counted_by_user_id, adjusted_lines, recount_count_id or null | Notifications `NotifyVarianceDecided` | **yes** |
| `ShiftLocked` (new, 3.4) | shift_id, station_id and names, date, locked_at, links: [{location_id, chain_seq, payload_hash}] | none in Phase 3 (Phase 4's Analytics `Rebuild::day`) | n/a |
| `ChainBroken` (new, 3.4) | location_id and names, first_bad_chain_seq, kind, count_id, caused_by_user_id (who made that link) | Notifications `NotifyChainBroken` | **yes** |
| `StockChanged` (exists) | as Phase 2 | Inventory `QueueBalanceEvaluation` (the count adjustments of an approval can open or close a `low` or `out` episode, as any movement does; the actor restored is the approving admin and the counted shift, from the movement) | the job: yes (exists) |
| `LineReady`, `LineRejected`, `StockOut`, `ProductionRequested`, `TransferRequested`, ... | unchanged | unchanged; `Recipients::onDuty` now returns the roster of the running shift and falls back to the assigned people when nobody is on it | unchanged |

**Listener behaviour.**

- `NotifyCountSubmitted` tells the right people for the state the count is in: `awaiting_cosign` -> the overseers of the
  station (`Recipients::overseers`) and the `shifts.cosign` holders (`Recipients::byPermission('shifts.cosign', station)`);
  `pending_approval` -> the `shifts.approve` holders; never the person who counted; a count that went straight to `locked`
  (no differences, no co-sign needed) tells nobody. Type `count_submitted`, severity `warning` when there are variance lines
  (`info` otherwise), channels in_app + push + telegram (when those drivers are on, section 10.1 of the Phase 2 plan), inbox key
  `count_submitted:{count_id}:{status_after}` (a count rejected and recounted is a new count, so a new line), deep link
  `/admin/shifts?count={count_id}`.
- `NotifyVarianceDecided` tells the person who counted (`Recipients::byIds`), type `variance_decided`, severity `info` for an
  approval and `warning` for a rejection (the note is in the body: they must recount), channels in_app + push, inbox key
  `variance_decided:{count_id}:{decision}`, deep link to the station's own screen (`/kds/kitchen?shift={id}`, `/till?shift=`,
  `/production?shift=`, chosen by the station code in `config/notifications.php`, the way the other deep links are).
- `NotifyChainBroken` tells the admins (`Recipients::byPermission('shifts.replay')` and the role `admin`), type `chain_broken`,
  severity `critical`, all enabled channels, inbox key `chain_broken:{location_id}:{first_bad_chain_seq}:{kind}` (one line per
  distinct break, however often the nightly check finds it again).
- Texts for the three types are in `NotificationText` in both languages (the `NotificationTextTest` rule: no type without en and
  am title and body, no placeholder left unfilled); the registry rows are in `config/notifications.php`; the migration of 3.3c
  extends the CHECK. The listeners are `ShouldQueue` and `ShouldHandleEventsAfterCommit` on the default queue, `$tries = 3`,
  backoff 30 s / 120 s, idempotent through the inbox key, and run as the person the event names (`EventScope`), exactly the
  Phase 2 pattern; the arch rule that every Notifications listener is queued still holds.
- `Shifts\Listeners\EnsureDefaultVarianceReasons` (on `BranchCreated`, synchronous, like `EnsureDefaultChannels`): gives a new
  branch its five reasons.

**How the live host drains it (said once, as in Phase 2 section 10.2).** The queue on https://bakery-ops.orcamind.dev is the
database queue, drained by a cron line every minute with `queue:work --stop-when-empty --max-time=50`; `QUEUE_CONNECTION=sync` in
Pest, `redis` plus a `worker` container in the compose stack. The three Shifts notifications can therefore be up to about a
minute late on the live host and a second or two in CI. Nothing a person must do depends on them: **the approval queue is a
polled read of `stock_counts`** (`GET /stock-counts?status=pending_approval`, ETag, 10 s visible), the counter's screen polls its
own count, and the shift widget polls `GET /stations/{station}/shifts/current`. The notification is the tap on the shoulder, not
the work list. Everything that must be atomic is not queued at all: the stamp, the adjustments, the lock and its hash are
written inside the request's transaction. No named queues, no `afterResponse` shortcut. The one scheduled job is
`shifts:verify-chain` (`routes/console.php`, daily at 03:00 `Africa/Addis_Ababa`, run by the existing `schedule:run` cron line, no new cron
line): it verifies every chain, prints the head hash per location to the log, and dispatches `ChainBroken` for each failure.
`@live` flows that wait for a notification use `waitForQueue` (30 s in CI, 150 s live, from `e2e/support/production.ts`).
**Live updates:** no new channel and no broadcast companion in Phase 3. The polled feeds above are the transport (ADR 0009);
a companion for `ShiftOpened` / `CountSubmitted` is additive when a WebSocket host exists (a dated note on ADR 0009).

## 6. Module build order inside the phase

Small PRs, each green in CI before the next; branches are `phase-3/<step>`; a step that completes a module ends with
`gate_check.py --mode module --module <name>` and, for Shifts, `phase_status.py module-stable shifts` at the end of 3.6. Inside
a PR the order is always: contract or module tests committed red, then the code, then registries (`write_endpoints.php`,
`route_permissions.php`), lang files in both languages, OpenAPI export and ERD. The order follows the manifest and
`references/phases/phase-3-shifts-handover.md`; the sub-steps are the plan's own split of the large steps, as 2.2a/b were.
**One deviation from the manifest's order, said here and argued in 10.1:** the manifest lists "flip
`ledger.require_shift_for_station_locations`" inside 3.2; this plan builds everything with the flag **off**, rehearses the flag **on**
in CI from 3.2c, and flips the default at 3.7e, after the screens that open and close shifts exist. A flip at 3.2 would put the
live ledger in a state where a shift can be opened only through the API.

1. **3.1 contracts and provider fixes** (STATUS `contracts`), two PRs. The entry gate passes after the second.
   - **3.1a `phase-3/plan-and-contracts`**: this plan (+ `plan_approved`); the manifest, STATUS and skill-copy corrections of
     section 1 and 2 (shifts depends on orders; `shifts->orders`; the `Totals::cash` wording; `needs.catalog`); the four contract
     files committed red; then F3 (Shared `OpenShifts` + `NoOpenShifts`), F4 (permissions + grant migration, `tracks_shifts`
     migration and `PATCH /stations`, `Stations::workStationFor`, `ResolveActor`, `People::attachShift`, `/me`), F5 (`Totals`),
     F1 (`Stock::shiftSummary`, `netChangeSince`, `Locations::stockLocationsOf`, `cashDrawerOf`), F7 (CI profile, placeholders).
     Module tests extended: `GrantPhase3PermissionsMigrationTest`, `TracksShiftsMigrationTest`, `StationsWorkStationTest`,
     `ResolveActorShiftStampTest`, `PeopleAttachShiftTest`, `MeWorkStationsTest`, `TotalsTest`, `ShiftSummaryTest`,
     `NetChangeSinceTest`, `LocationsStockLocationsTest`, `OpenShiftsDefaultTest`. Groups: `contract:shifts->*`,
     `module:identity|inventory|payments`, `unit`, `feature`. Phase `in_progress`.
   - **3.1b `phase-3/ledger-enforcement-dark`**: F2. Cases 12-15 of `shifts->inventory` go green; `LedgerEnforcementTest` (a
     table of every reason, every kind of location and station, both ways of being covered, flag on and off); `config/ledger.php`;
     `DeployableCachesTest` still passes. Ends with the full entry gate with tests and `gate_check --mode module --module
     inventory|identity|payments`.
   - **As built (step 3.1a, `phase-3/plan-and-contracts`).** Commits in the order the plan gave: plan; approval (STATUS by hand);
     manifest, STATUS and skill-copy corrections; the four contract files red (22 of 44 cases failed, for the right reasons: no
     `OpenShifts`, no `Totals`, no `work_stations`, no `shiftSummary`, no `tracks_shifts` column); F3; F4; F5; F1; ERD and OpenAPI;
     F7; plan corrections; STATUS `in_progress`. Where the code disagreed with the draft:
     - **Cases 12, 13, 14 of `shifts->inventory` (F2) are skipped, not red.** The entry gate only tolerates a red contract when its
       provider is built in the same phase; Inventory is not, so a red contract would fail the gate and CI's `mod-inventory`. The
       three cases are `->skip(fn () => ! file_exists(config_path('ledger.php')))`: they run, red then green, the moment 3.1b adds
       the file, and 3.1b removes the skips. Case 15 (flag off never consults `OpenShifts`) passes now.
     - **`stock_movements.created_at` is `DATETIME` (whole seconds), not microseconds.** Case 3 therefore pins "a movement at exactly
       t counts, one a second later does not". Consequence for 3.3a: `Stock::balanceAt(location, snapshot_at)` with a
       `DATETIME(6)` snapshot can include a movement written after the snapshot within the same second, so a count's expected
       figure must come from the balance rows read under `Ledger::lock` (case 8 proves they agree with `balanceAt(now)` while the
       lock is held), or 3.3a first widens `created_at` to `DATETIME(6)` (a migration on an append-only table with triggers).
     - **`OpenShifts` gained `currentFor(stationId)`** (`{id, status, date, opened_at}`), which `GET /me` needs and Identity cannot
       read from Shifts. `Tests\Support\FakeOpenShifts` is the double for it (and for 3.1b); HTTP tests bind it with `scoped()`,
       because the app forgets scoped instances at the start of a request and would drop `app()->instance()`.
     - **`Totals::cash` answers `Money`** (its `amount()` is the contract's 2 dp string); `ChannelTotal` carries `channelId, code,
       nameEn, nameAm, receipts, sum`. `Locations` answers `LocationView` and `Stock::shiftSummary` answers `ShiftMovementTotal`
       objects (arrays through `toArray()`); `netChangeSince` omits an item whose net change is zero.
     - Cases 3 and 8 of `shifts->inventory` need a second connection and a second process, so they sit in
       `tests/Contract/Shifts/InventoryConcurrencyTest.php` (same group, `CommitsForConcurrency`, `Tests\Support\Forks`). The
       contract groups hold 16 + 16 + 7 + 5 tests (the identity data-set case counts three).
     - Module tests written for the new code: `GrantPhase3PermissionsMigrationTest`, `TracksShiftsTest` (the migration adds the
       column only when it is missing, so its backfill can be tested without DDL inside the test transaction),
       `ResolveActorShiftStampTest` (real HTTP: the stamp reaches an audited write, an unusable header is ignored, `People::attachShift`,
       `work_stations`), `TotalsTest`, `ShiftReadsTest`, `OpenShiftsDefaultTest`; the plan's separate `StationsWorkStationTest`,
       `PeopleAttachShiftTest`, `MeWorkStationsTest`, `ShiftSummaryTest`, `NetChangeSinceTest` and `LocationsStockLocationsTest` are
       folded into these and into the contract files. `StationFactory::forCode(Store)` gives `tracks_shifts = false`, as the
       migration and `POST /stations` do. No existing test in the groups run locally (identity, shared, payments, arch, unit: 1497 passed) needed to change for the new stamps; CI ran the rest.
     - `GET /me` is the only response that grew; `ERD` and `docs/openapi.json` were re-exported against the test database.
       The `enforced-gates` job and the `seeds` group move stay in 3.2c, as section 6 says (the manifest `ci.module_jobs` are
       untouched here); `mod-orders` gained `contract:shifts->orders`.
   - **As built (step 3.1b, `phase-3/ledger-enforcement-dark`).** Commits: tests first (cases 12 to 14 unskipped, `LedgerEnforcementTest`;
     red: `contract:shifts->inventory` 3 failed + 13 passed, `InventoryError` not thrown; the new file 40 of 101 failed), then F2, then
     the planner e2e fix, then this note and STATUS. Where the code settled what the plan left open:
     - **`config/ledger.php`** reads `LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS` with default `false`; the variable is also written
       as `false` in `.env.example` and `.env.ci`, and documented in `docs/runbook/server.md` (not set on the host).
       `DeployableCachesTest` passes. `config/payments.php` and its rule stay in 3.5.
     - **The check** is `Ledger::shiftCover`, called inside the transaction before `lockRows`. It looks first without locks
       (`isOpen` for the actor's shift, `openAt` once per station), then re-reads every shift relied on with `isOpen($id, true)` in
       ascending id order and refuses if one closed meanwhile. `OpenShifts` is resolved lazily with `app()`, so with the flag off it is
       not even resolved. The error names the station and location of the first uncovered intent, in intent order.
     - **Stamp:** an actor whose shift is open keeps the actor's stamp; otherwise each enforced row carries its own station's open
       shift (a transfer across two stations stamps each side with its own). Exempt and untracked rows keep the actor's stamp as
       before, even if that shift is not open.
     - **A cash drawer location is enforced like any other** place of a tracking station (the rule looks at the station, not the type);
       `reservation` and `reservation_release` are exempt because their `on_hand` effect is zero, while a `production_consume` or `sale`
       settling a reservation is enforced.
     - `LedgerEnforcementTest` is one dataset of 16 reasons by 6 places, each run through 7 states of cover with the flag off and on
       (about 3 minutes in Docker), plus batch, lock order, close-in-between and config/lang cases.
     - Carry-over: `stockout-catalog-planning.spec.ts` now polls the API for the assignee instead of reading it right after the click.
       No other p2 spec asserted a select's own value as a saved result.
2. **3.2 shifts.instances** (groups `module:shifts`, `unit`; STATUS `shifts.instances`), three PRs:
   - **3.2a `phase-3/shifts-schema`**: ADR 0013 (proposed: the code cites it); migrations 3-7; models and factories;
     `ShiftStatus` with its exhaustive transition table (picked up by `StateMachineExhaustiveTest`):
     `scheduled -> opening | open | cancelled`, `opening -> open | cancelled`, `open -> counting`, `counting -> pending_approval |
     locked`, `pending_approval -> counting | locked`, `locked` and `cancelled` terminal; `ShiftWindow` (pure: which window of which
     template contains an instant, and the local start date: section 10.2) and `LocalClock`; `ShiftsServiceProvider` registered
     after Identity, Inventory and Payments in `bootstrap/providers.php` and doing nothing yet; the arch rules (Shifts uses
     `Inventory\Contracts`, `Inventory\Ledger\{Ledger, MovementIntent, MovementReason}`, `Identity\Contracts`,
     `Payments\Contracts`, `Orders\Contracts`, `Audit\Contracts`, `Catalog\Contracts` and Shared, never a foreign model, enum,
     resolver, support or HTTP class; no module imports Shifts, only Shared's `OpenShifts` is bound by it; the existing Production
     and Notifications rules stay). Tests: `ShiftWindowTest` (the table of section 10.2), `ShiftStatusTest`, `ShiftsSchemaTest`
     (CHECKs, the three unique keys: a second running shift at one station is refused by the database, a second row of one slot
     and sequence is refused), `ShiftStampForeignKeysTest`.
   - **3.2b `phase-3/shifts-api`**: `Contracts\Shifts` (final class built per use: `current(station)`, `start`, `openAdHoc(station,
     user)`, `join`, `leave`, `cancel`, `schedule`, `roster`), the actions (one `AuditedAction` each: `shift.schedule`,
     `shift.start`, `shift.open_ad_hoc`, ...), templates, roster and instance endpoints (section 4, rows "Templates" and "Shifts"
     except `close` and `summary`), `OpenShiftsReader` (rebinds `OpenShifts`, scoped, with the shared-lock variant),
     `OnShiftResolver` (rebinds `StationPresenceResolver`), `ShiftsAttacher` (rebinds `ShiftAttacher`; refuses with null a person
     who cannot count at the station), `EnsureDefaultVarianceReasons` is later (3.3a). In this PR `start` opens at once (carried):
     there are no counts yet. **The three Phase 2 tests that assert the default binding** (`AccessAndStationsServicesTest` "binds
     StationPresenceResolver to AssignedUsersResolver" and "binds ShiftAttacher to NullShiftAttacher", and
     `Contract/Production/IdentityContractTest` line "the default is AssignedUsersResolver") are changed to bind the Phase 2
     classes explicitly before asserting, because the container's default is now Shifts'; their assertions about what those
     classes do are untouched, and new tests assert the new defaults and run the `production->identity` and
     `notifications->identity` presence assertions against the real `OnShiftResolver`. Tests: `ShiftAttributionTest` (movements,
     orders, payments and requests made during an open shift carry its id; before it, null), `PresenceResolverSwapTest` (a
     stock-out of a produced item targets the people on the running shift first, falls back to everyone assigned), `CallInTest`
     (a baker accepting a request at a closed station opens an ad-hoc shift; the accept row, the batch and its movements carry it),
     the feature file per endpoint with 403 per role and idempotent replay, `StartRaceTest` (concurrency: two starts at one
     station, forked, 30 rounds, one `201` and one `409`, `Innodb_deadlocks` unchanged). Ends with `gate_check --mode module`
     for `identity`, `production`, `notifications` (their contracts ran against both bindings).
   - **3.2c `phase-3/enforcement-rehearsal`**: everything that lets the flag be on without a red gate (section 10.1 lists it
     path by path), with the default still false: `Tests\Support\Shifts::ensureOpen(...)` and its use in `OrderFloor::open`,
     `ProductionFixture`, `TransferFixture`, `PurchaseFixture`, `OrdersMenu` and the `stockIn()` helper (it opens the shift only
     when the flag is on, so the existing 200+ tests are unchanged with it off); `Database\Seeders\Demo\DemoShifts` and its calls
     in `Phase0Seeder`, `Phase1Seeder`, `Phase2Seeder` (idempotent: it opens a shift only where none is running, so a second run
     writes nothing); `apps/web/e2e/support/shifts.ts` (`ensureOpenShift(admin, station)`, `ensureOpenShifts`, and the till
     fixture: find or create the till station, assign the fixed cashier with `can_count`) called from every fixture builder
     (`buildStationFixture`, `buildFlowFixture`, `buildTillStaff`, `buildProductionFixture`, `buildTransferFixture`,
     `buildCatalogFixture`); CI: `compose.ci.yml` sets `LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS: "true"` on `app` and `worker`, so
     `e2e-p0`, `e2e-p1` and `e2e-p2` run with the flag **on**; the new `enforced-gates` job (reusable `module-tests.yml` gains an
     optional `env` input) runs `gate:p0,gate:p1,gate:p2` with the flag on; both are added to the manifest's `ci.module_jobs` /
     `gate_jobs.gate-p3.needs`; the `seeds` group moves from `mod-inventory` to `mod-shifts` (the seeders now compose Shifts). The
     commit order is the proof: first commit turns the flag on in `compose.ci.yml` and in `enforced-gates` and the earlier
     e2e and gate jobs go red on the PR (listed in the PR description), then the fixtures turn them green; the PR merges only
     when everything is green. Ends with `shifts.instances: done` in STATUS.
   - **As built (step 3.2a, `phase-3/shifts-schema`).** Commits: tests first (`ShiftWindowTest`, `ShiftStatusTest`, `ShiftsSchemaTest`,
     `ShiftStampForeignKeysTest` and the Shifts arch rules, red: the classes and tables did not exist), then the code (ADR 0013
     proposed, migrations 3 to 7, `ShiftStatus` and its siblings, `ShiftWindow`, `TemplateWindow`, `LocalClock`, models, factories,
     `ShiftsServiceProvider`), then ERD, STATUS (`shifts.instances` in progress) and this note. Where the build settled what the
     plan left open:
     - **No engine-specific DDL for the unique keys.** "At most one running shift per station" is the plain unique key
       `(station_id, open_key)` of section 3 (ADR 0011's technique), the same on MariaDB and PostgreSQL, so there is no generated
       column and no partial index in these migrations. The one engine branch is migration 7: on PostgreSQL it adds an index on
       `shift_instance_id` where none starts with the column (MariaDB creates it with the foreign key). It looks the existing
       keys up with `Schema::getForeignKeys`, so a rerun is a no-op.
     - **Names the plan did not fix.** Migration files are `2026_10_24_110100` to `110500`. The actor station column on
       `shift_templates` and `shift_instances` is `actor_station_id` (the plan's "where `station_id` already means something
       else"; `shift_instances.station_id` is the shift's own station). The unique keys are named `shift_instances_one_running_per_station`,
       `shift_instances_slot_unique`, `shift_instances_client_uuid_unique`, `shift_template_stations_pair_unique`,
       `shift_roster_person_unique`; the CHECKs are `shift_templates_days_mask_check`, `shift_templates_window_check`,
       `shift_instances_{status,origin,opening_mode,sequence,slot_key,open_key,locked_at,cancelled_at,opened_at}_check` and
       `shift_roster_{source,left}_check`.
     - **Two small CHECKs beyond the plan's list:** `sequence >= 1`, and `open_key` is NULL or 1 (not just "in step with the
       status"), so a stray key value cannot slip past the unique key.
     - **Migration 7 covers the 14 tables the plan names and `shift_roster.shift_instance_id` is its own parent key** (made with
       the table, NOT NULL), so `ShiftStampForeignKeysTest` skips it when it asks "nullable and has a key". It also asserts the
       key exists on every table found in `information_schema`, so a table of a later step cannot forget it.
     - **Pure time logic.** `TemplateWindow` (id, `start_time`, `end_time`, `days_mask`; refuses equal times, a mask outside 1 to
       127 and a malformed time; reads `HH:MM:SS` as the database gives it) is the value `ShiftWindow` works on, so the
       pure code never sees a model. `ShiftWindow::containing($instant, $templates)` answers the best window or null,
       `matching()` every window newest start first (ties: lower id), `forDate($template, $date)` the window of a scheduled
       instance, `adHocDate($instant)` the local date of an ad-hoc shift. `LocalClock` is `final readonly` and takes a timezone
       name (default `Africa/Addis_Ababa`, the branch's `timezone` once 3.2b reads it), so a branch in another zone needs no
       change. "A running shift wins" over a later start (the section 10.2 table, last overlap row) is not decided here: it
       needs the database, so 3.2b's `Shifts::start` picks among `matching()` with it.
     - **Enums.** `ShiftStatus` (with `isRunning`, `openKey`, `hasOpened`, which the CHECKs repeat), `ShiftOrigin`,
       `ShiftOpeningMode`, `RosterSource`. `ShiftInstance` and `ShiftRoster` have an empty `$fillable` (written by the actions,
       like `ProductionBatch`); `ShiftTemplate` and `ShiftTemplateStation` are fillable for the 3.2b endpoints.
     - **Arch.** The "downward only" matrix gained `Orders` for Shifts (the plan lists `Orders\Contracts` among its allowed
       uses). The new rules, group `module:shifts`: Shifts uses Inventory, Identity, Payments, Orders, Catalog and Audit through
       their Contracts (and the three Ledger vocabulary classes of Inventory) only, reaches no Production, Notifications or
       Analytics, and nothing but Analytics (and not Shared) imports Shifts. `ShiftInstance` is allow-listed in `SoftDeletesTest`.
     - **Earlier tests that stamped made-up shift ids now create the shift.** With the foreign keys in place a row cannot carry
       "shift 77" unless shift 77 exists, and 3.1a and 3.1b had written such stamps (`InventoryContractTest`, `PaymentsContractTest`,
       `TotalsTest`, `LedgerTest`, `LedgerEnforcementTest`). `shiftRow($id, $branchId)` in `tests/Support/helpers.php` inserts one
       scheduled ad-hoc row with that id (its date is unique per id, so any number can share a station) and is called where the
       id is used; no assertion about behaviour changed. `InventorySchemaTest` and `OrdersSchemaTest`, which asserted "no foreign
       key until Phase 3", now assert the key. Later steps that want a shift in a given state use `ShiftInstance::factory()->open()`
       and its siblings (3.2c's `Tests\Support\Shifts::ensureOpen` builds on them).
     - **Tests beyond the list:** `ShiftModelsTest` (every status from the factories satisfies the CHECKs, the on-duty
       reading, soft deletes). The unique-key tests start from `Schema::hasTable`, so a missing table cannot pass as a refusal.
       Local runs: `tests/Unit/Shifts`, `tests/Modules/Shifts`, `StateMachineExhaustiveTest`, groups `arch` and `invariants`; CI ran the rest.
     - ADR 0013 is `proposed`; STATUS keeps `adrs` empty until the gate report accepts it. The provider is registered and does
       nothing: no binding, no route, no listener. Every default of Phases 0 to 2 is unchanged.
   - **As built (step 3.2b, `phase-3/shifts-api`).** Commits: tests first (the endpoint files, `ShiftsBindingsTest`, `PresenceResolverSwapTest`,
     `CallInTest`, `ShiftAttributionTest`, `ShiftsContractTest`, `ShiftsLangTest`, `StartRaceTest`, `PeopleWorkStationIdsTest`, the registry rows and
     the matrix placeholders; red: the classes, routes and lang did not exist), then the code, then the docs. The three Phase 2 tests that
     assert the default binding bind the Phase 2 class explicitly before asserting, as planned; four more tests did the same and are named
     here: `Contract/Shifts/IdentityContractTest` (its `AssignedUsersResolver` line), `Modules/Shared/OpenShiftsDefaultTest` ("binds OpenShifts
     to NoOpenShifts" now builds `NoOpenShifts` itself) and `Modules/Identity/Feature/ResolveActorShiftStampTest` (binds `NoOpenShifts` for its
     "no shift" case). New defaults are asserted in `ShiftsBindingsTest`; the presence assertions of `production->identity` and
     `notifications->identity` run against the real `OnShiftResolver` in `PresenceResolverSwapTest` (tagged with both contract groups and
     `contract:shifts->identity`, so `mod-identity` runs it). Where the build settled what the plan left open:
     - **Rollout guard (orchestrator decision).** `config/shifts.php` has `open_ad_hoc_on_accept` (`SHIFTS_OPEN_AD_HOC_ON_ACCEPT`, default false
       in the code, `.env.example`, `.env.ci` and the live host) and `start_grace_minutes` (`SHIFTS_START_GRACE_MINUTES`, 60). With the flag off
       `ShiftsAttacher` puts the person on the shift that already runs at the station and answers its id, and otherwise answers null, exactly
       as `NullShiftAttacher` did; with it on a baker accepting at a closed station opens a `call_in` shift and the accept row, the batch and
       its movements carry it. `CallInTest` runs both settings. Written in the runbook ("Shifts and the call-in", next to the ledger flag) and
       in ADR 0013 rule 7; step 3.7e switches it on together with `ledger.require_shift_for_station_locations`.
     - **`ShiftsAttacher` resolves its collaborators when it runs.** Identity's `People` is built with the `ShiftAttacher` (cycle 3), and
       everything the attacher does is built on `People`, so injecting `People` made `People -> ShiftAttacher -> People` without end (a memory
       exhaustion at boot). It takes the container and resolves per call, the way `Recipients` resolves the presence resolver. Its signature
       is `attach(object $user, Station $station)`: the interface names Identity's `User`, which the Shifts arch rule forbids importing, and a
       parameter may be wider than the interface's. `OnShiftResolver` likewise leaves the element type to the interface (it filters
       `Stations::assignedUsers`, so it needs no `User` by name).
     - **On duty, exactly.** `OnShiftResolver` answers the active people assigned to the station who have joined the running shift and not left;
       with no running shift (or a station that tracks none) everyone assigned; a running shift with nobody on it answers nobody and
       `Recipients::onDuty` falls back to the assigned people, so an alert never reaches nobody (ADR 0013 rule 4). A person on the roster who
       is not assigned (the admin) is not on duty. `OpenShiftsReader` reads the unique running key `(station_id, open_key = 1)`; the shared
       lock variants are `sharedLock()` (`LOCK IN SHARE MODE`, `FOR SHARE` on PostgreSQL) on the shift row.
     - **Who may be on a roster.** "Not assigned to the station" (section 4) is read as `Access::can(person, 'shifts.count', station)`: an active
       person of the branch who is assigned there or works everywhere, and holds `shifts.count`. A waiter assigned to the till is therefore not
       rosterable (they could never join) and the admin and the manager are. The `can_count` flag of the assignment is not consulted in this
       step; section 4 defines "at the station" as `Access::can`.
     - **`People::workStationIds(userId)`** is a new additive capability of Identity (empty for anyone who cannot act): `GET /shifts` lists the
       stations a person works at, and Shifts may name no `User`. Tested by `PeopleWorkStationIdsTest`.
     - **Contract shape.** `Contracts\Shifts` takes stations, shifts and templates by model or id; `start`, `openAdHoc` and `schedule` answer a
       `ShiftOutcome {shift, created}` (the endpoints turn `created` into 201 or 200), `join`, `leave`, `cancel` and `roster` answer the
       shift. `roster` replaces the roster of a scheduled shift. The audit actions are `shift.schedule`, `shift.roster`, `shift.cancel`,
       `shift.start`, `shift.open_ad_hoc`, `shift.join`, `shift.leave`, `shift_template.create|update|delete`.
     - **Starting.** In this step every start opens at once, carried (`carry` is accepted and changes nothing until 3.3a). A repeat of the
       `client_uuid`, or of the `client_uuid` of the shift that already runs, answers 200; the starter of an `opening` shift resumes it; anyone
       else gets 409 `shifts.shift.already_open {shift_id, status}`. A scheduled instance started by id outside `starts_at - grace .. ends_at`
       is 422 `shifts.shift.outside_window {starts_at, ends_at}` unless `force` (admin only, 403 `shifts.start.force_forbidden`); `template_id`
       works the same way against the template's window of the local date. An automatic start takes the scheduled instance of the station
       whose window (with the grace) holds now, the one that started latest; else `ShiftWindow::matching` over the station's active templates
       (newest start first); else an ad-hoc instance dated by the local date. A scheduled instance started by `start` keeps its own
       `client_uuid` (the request's is not stored on it), so an offline replay of that start with a lost key meets 409 `already_open`, not 200.
       A race is decided by the unique key: the loser's insert fails and it answers 409 (or finds the winner's row by `client_uuid`);
       `StartRaceTest` runs two starts, one start twice and two starts of one scheduled instance, 30 rounds each, `Innodb_deadlocks` unchanged.
     - **Roster rows.** A person who starts or opens gets a `started` or `call_in` row; a person who joins gets `joined`; a scheduled person who
       joins or starts keeps `scheduled` and gains `joined_at`; a person who left and joins again keeps their row (`left_at` cleared,
       `joined_at` kept); a removed scheduled person who comes back gets the same row restored. Join, leave and roster changes move the
       shift's `version` and `updated_at`, the merge key of the polled feeds. Joining or leaving what already holds writes nothing, not even
       an audit row. Leaving a shift one never joined is a 200 no-op.
     - **Two errors the plan did not list.** `POST /shifts` for a template, date and station that still has a waiting or running instance is
       409 `shifts.shift.already_scheduled {shift_id}` (a cancelled or locked one does not count; the next takes the next `sequence`), and
       `cancel` by someone who may not is 403 `shifts.cancel.forbidden`. The cancel route asks for `shifts.view` only, like Production's
       cancel, and the action decides: the admin, or the starter of an `opening` shift (also for the repeat of their own cancel). An unknown,
       inactive, deleted, other-branch or unpaired template is `shifts.template.not_for_station`; an unknown station in the body of
       `POST /shifts` is 422 `validation.failed` on `station_id`.
     - **Reads.** `GET /shifts` pages `{data, meta {current_page, per_page, total, last_page}}` (25 by default, at most 100) newest first
       (`date`, then id) over the stations the caller works at; `?running=1` is conditional (ETag over the filters, the person, the count,
       `max(updated_at)` and `sum(version)`). `GET /stations/{station}/shifts/current` is conditional on the shift's id, version and
       `updated_at`. `counts` is `[]`, `notes_count` 0, and `cancelled_at`, `cancelled_by` and `cancel_reason` are added to the Shift. The
       endpoints that read a station check the person's station first (403 `shifts.station.forbidden`), then `tracks_shifts` (422).
     - **Tests beyond the list.** `ShiftsContractTest` (`Contracts\Shifts`), `ShiftsLangTest` (en/am parity for the 14 `shifts.*` keys),
       `ShiftsFixture` (`Tests\Support`), the `{shift}` and `{template}` placeholders of the route matrix (an `opening` shift the caller
       started), and 10 write-endpoint rows; `ShiftOpened` (Shared, no listener) is asserted in the start and call-in tests. `docs/openapi.json`
       was re-exported against `bakery_test` (the tables must exist: Scramble reads the columns); the ERD is unchanged (no table).
       Local runs: `tests/Modules/Shifts` (139), identity, shared, arch, invariants and the three identity contract files (154), the
       route matrix for `module:shifts` (112), `StartRaceTest` (3), `RequestAcceptTest` and `RecipientsTest` (19); CI ran the rest,
       including `mod-identity`, `mod-production` and `mod-notifications`, whose contracts ran against both bindings.
   - **As built (step 3.2c, `phase-3/enforcement-rehearsal`).** Commits in the planned order: (1) the flag on in `compose.ci.yml` (`app`
     and `worker`) and the new `enforced-gates` job, nothing else, pushed red on purpose: `e2e-p0`, `e2e-p1`, `e2e-p2` and `e2e-p3`
     (their seeding and fixtures hit `inventory.shift.required`) and `enforced-gates` (276 failed, 538 passed of 814) went red and
     `gate-p1` to `gate-p5` were skipped behind them; (2) `Tests\Support\Shifts` and `DemoShifts`; (3) the web `support/shifts.ts`
     and the live budget; (4) the `seeds` move, manifest and skill copies. Locally `gate:p0`, `gate:p1`, `gate:p2` with the flag on
     pass (814 tests). What the build settled:
     - **`Tests\Support\Shifts`** has `ensureOpen(Station|int)`, `ensureOpenAt(Location|int)` and `ensureOpenAll(...)`; all are no-ops
       with the flag off, skip stations that track no shift, and open nothing where a shift already holds the station (it is the factory's
       `open()` row, not a `Shifts::start`, so the fixtures stay independent of the Shifts actions). `stockIn()` opens the shift of its
       location's station first, which covers `OrdersMenu` (also called explicitly), `OrderFloor`, and every test that stocks a station;
       `ProductionFixture`, `TransferFixture` and `PurchaseFixture` open theirs at the end of the constructor.
     - **`DemoShifts::ensureOpen($branch)`** takes no station list: it walks the branch's tracked stations and, where `Shifts::current` is
       null, calls `Shifts::start(..., carry: true)` as the authenticated demo admin with a client UUID derived from branch and station
       code, so a second run, even after a close, answers the same row and writes nothing. It is called once at the top of each seeder's
       transaction (after the admin is the actor; in `Phase0Seeder` after the locations). The seeders do not set `ActorContext::inShift`:
       rule 4b (the station has an open shift, whoever acts) covers them, and the rows are stamped with that shift. `DemoSeederTest` gains
       the composition with the rule on (Phases 0 to 2: one open carried shift per tracked station, none at the store, stamped movements,
       a second run writes nothing).
     - **Plan error fixed in code: the demo store tracked shifts.** `Phase0Seeder` created stations without `tracks_shifts`, so a fresh demo
       branch got the column default (true) at the store; it now sets `tracks_shifts = (code !== store)` as `POST /stations` does (the
       migration had only corrected existing rows).
     - **Web.** `support/shifts.ts`: `ensureOpenShift(admin, station)` asks `GET /stations/{id}/shifts/current` and starts a carried shift
       when none runs (409 `already_open` and 422 `not_tracked` are accepted), `ensureOpenShifts`, and `ensureOpenShiftAt(admin, location)`
       for locations that may have no station. `fixture.ts` gains `ensureTill` (find or create the till station, assign the fixed cashier
       with `can_count`, open its shift) called by `buildTillStaff` and `buildFlowFixture`; the E2E branch's display case stays unlinked.
       The shifts the fixtures open are never closed (closing arrives in 3.3), so a live run finds the previous one running and writes no
       new shift; none of the helpers retries a start with a lost key, so the replay note of 3.2b does not bite.
     - **Live budget.** `MULTI_CONTEXT_TEST_MS` in `e2e/support/env.ts` (120 s live, 60 s otherwise) is the timeout of the three-context
       purchase-and-transfer flow.
     - **`GET /transfers` already pages** (`paginate`, default 25, `per_page` up to 100, newest first, test with `per_page=1` in
       `TransferRequestTest`), so no product change was made; the admin screen asks for the newest 100 of one status. A "load more" on
       that screen is a later screen decision, not needed for correctness.
     - **CI.** `module-tests.yml` takes an optional `env` input (KEY=VALUE lines appended to `GITHUB_ENV`). `enforced-gates` needs
       `mod-payments`, `mod-production`, `mod-notifications` and is a need of `gate-p3`; the `seeds` group runs in `mod-shifts`
       (`scripts/task.py` follows). `SHIFTS_OPEN_AD_HOC_ON_ACCEPT` stays off in CI. The skill's `assets/ci/ci.yml` copy was already behind the
       real one (MySQL, no static-export step); only the 3.2c changes were applied to it.
3. **3.3 shifts.counts** (groups `module:shifts`, `unit`, `feature`, `property`, `concurrency`; STATUS `shifts.counts`), three PRs:
   - **3.3a `phase-3/counts-core`**: migrations 8-10 (with the lines' freeze triggers and `DefaultVarianceReasons`, the backfill for
     existing branches), `CountStatus` and its exhaustive table (`draft -> awaiting_cosign | pending_approval | approved`,
     `awaiting_cosign -> pending_approval | approved | rejected`, `pending_approval -> approved | rejected`, `approved -> locked`,
     `locked` and `rejected` terminal), `Contracts\Counts` (`draftsFor(shift, type)`, `setLines`, `submit`), the draft builder (lines
     from `Stock::at(location)` plus the previous count; the `carried_qty` prefill from `Locks::previousClosing`, which in this PR
     is "the latest closing of the place that is `approved`"), the change of `start` to the `opening` path and `close` to the
     `counting` path with its draft closing counts, `summary`, the count read endpoints, variance reasons endpoints, rule R-A.
     No approval yet: a count with a variance waits in `pending_approval`, one without goes to `approved`. `e2e/support/shifts.ts`
     learns to finish an opening count (`confirm_all`, submit) so every fixture keeps working. Tests: `CountStatusTest`,
     `CountsSchemaTest` (the CHECKs, the three unique keys, the freeze triggers refuse every column but the decision ones),
     `LinesFreezeTriggerTest`, `DraftBuilderTest`, `SubmitCountTest` (expected from the locked ledger, snapshot, variance maths,
     incomplete, reason and note rules, R-A), `OpeningPrefillTest`, `VarianceReasonsEndpointsTest`, `CountsLangTest`,
     `CountsPropertyTest` (below).
   - **3.3b `phase-3/counts-decisions`**: `Counts::approve` (the adjustments, the shift's transition to `locked` when the last
     count resolves, `ActorContext::runAs` of the counted shift), `Counts::reject` (the recount draft, both attempts kept, the
     shift back to `counting`), the two endpoints and `GET /stock-counts/{count}`'s `moved_since`. The lock itself is 3.4: an
     approved count stays `approved` and `Locks::previousClosing` already treats `approved` as the carried value. Tests:
     `ApproveCountTest` (one `count_adjustment` per variance line, both signs, stamped with the counted shift, `adjustment_movement_id`
     set, ledger equals the counted figure when nothing else moved, equals counted plus what moved otherwise, a second approve writes
     nothing, `adjustment_insufficient` rolls back whole), `RejectCountTest` (the recount is prefilled, both rows kept, the shift
     is `counting` again, nothing written), `CountDecisionRolesTest` (403 per role, the counter cannot approve), `ApproveRaceTest`
     (concurrency: the same count approved twice at once, two counts of different places, a sale racing an approval).
   - **3.3c `phase-3/counts-cosign-notify`** (as built below): `cosign`, the admin queue endpoint, `CountSubmitted` / `VarianceDecided` events and
     their Notifications listeners, texts and registry rows, migration 11 (types), both-language `NotificationText`. Groups add
     `module:notifications`. Ends with `gate_check --mode module --module notifications` and `shifts.counts: done`.
   - **As built (step 3.3a, `phase-3/counts-core`).** Commits: tests first (red; 24 files), then four test corrections (below), then the migrations,
     models and factories, the counts core, the endpoints, and the e2e helper, ERD, STATUS and this note. Where the build settled what the plan left open:
     - **Expected figure (the 3.1a carry-over).** `SubmitCount` reads each line's expected figure from the balance rows locked by `Ledger::lock` (count
       row, then shift row, then balances ascending), so `stock_movements.created_at` stays DATETIME and no migration touches the append-only table.
       The property test proves `expected_qty == Stock::balanceAt(snapshot_at)` with the clock moving at least two seconds between operations. Two narrow
       windows are left, both to be pinned by 3.3b's `SubmitSnapshotRaceTest`: `ledger_movement_id` is read with a plain read (`Stock::fingerprint`), which under
       REPEATABLE READ can miss a movement committed at a locked pair between the transaction's first plain read and the lock; and an item whose first
       stock arrives in that same instant is not yet "on the count" and is judged by the next count.
     - **Test corrections (separate commits, each says why).** `DefaultVarianceReasonsTest` (`withoutGlobalScopes()` also lifts the soft-delete scope);
       `CountLinesTest` (Pest unpacks a bare `['1']` dataset row into the argument `'1'`); `OpeningPrefillTest` (two shifts of one station on one date);
       `CountsPropertyTest` (a fraction of `on_hand` needs a rounding mode). The freeze trigger refuses lines under a count that is not a draft, as the plan
       says, but several tests build history as a submitted count with lines: `StockCountLineFactory` holds the parent at `draft` for the insert and puts the
       status back (not possible for `locked`; make its lines first).
     - **`ReasonStatus` is a state machine** (`none -> pending -> accepted | rejected`), because `StateMachineExhaustiveTest` requires every `*Status` enum to be one.
     - **Triggers.** The MariaDB update trigger names every column (`id`, `branch_id`, `created_at` too; the plan's sketch listed ten) so `LinesFreezeTriggerTest`
       can check the text; PostgreSQL has one `stock_count_lines_guard()` function attached `BEFORE INSERT OR UPDATE OR DELETE`.
     - **Statuses.** A submit at a station that `requires_overseer` always goes to `awaiting_cosign` (3.3c co-signs); otherwise a variance gives `pending_approval`
       and none gives `approved`, with `decision` left null (nobody decided). A shift moves with its counts: the last opening count in opens it (`counted`, the
       submitter as opener, `ShiftOpened`; a difference does not hold it back), the last closing count in moves `counting -> pending_approval` and frees the station.
       `close` at a station with no stock place locks the shift at once. New key `shifts.count.shift_not_ready` (409, naming the status) for a count whose shift is
       not taking that kind of count.
     - **Carried figure.** `Locks::previousClosing` is the newest `approved` closing of the place; `carried_qty` is copied into the draft when it is made and not
       refreshed if an earlier closing is approved later. At submit an opening count is proved against the previous closing's counted figure plus
       `Stock::netChangeSince(previous.ledger_movement_id)`; a difference is audited as `count.opening_drift` and the count still goes against the ledger.
       `moved_since` on lines is left to 3.3b.
     - **Rule R-A** is checked after the shift lock with a plain read; no database lock is added: two counts can only meet at one place across shifts, and the
       shift machine already orders those (the next shift cannot start before the last closing count is in).
     - **Negative stock stays refused.** `VarianceMath::assertApplicable` implements the `409 shifts.adjustment.insufficient` rule for 3.3b's approval; nothing is
       written in this step. A count under the reserved quantity is allowed and listed as `below_reserved` in the answer.
     - **Additive contract changes.** `Locations::views(ids)` (Inventory, names a count's places), `Contracts\Shifts::close`, `Contracts\Counts`, `Contracts\Locks`.
       `start` has `carry` for the old behaviour; `DemoShifts`, the test helpers and the e2e helper keep starting carried, and the e2e helper also finishes the
       opening counts of a shift left `opening` (`finishOpeningCounts`: `confirm_all`, submit).
     - **Limits noted.** An item soft-deleted while it still has stock cannot be locked by the Ledger, so a count listing it is refused; the draft keeps an inactive item only while it has stock or was counted above zero.
   - **As built (step 3.3b, `phase-3/counts-decisions`).** Commits: tests first (red: `ApproveCountTest`, `RejectCountTest`, `CountDecisionRolesTest`,
     `CountMovedSinceTest`, `ApproveRaceTest`, `SubmitSnapshotRaceTest`, the two route registries and the message keys), then the actions, endpoints and
     presenter, then the OpenAPI export and this note. Where the build settled what the plan left open:
     - **Approve.** `ApproveCount` (`count.approve`): count row, shift row, then `Ledger::lock` of the variance pairs; each delta is checked with
       `VarianceMath::assertApplicable` (409 `shifts.adjustment.insufficient` with the pair, `on_hand` and the delta, before anything is written), then one
       `Ledger::appendMany` runs inside `ActorContext::runAs` with the counted shift and its station (the approver stays the user), `client_uuid` the UUIDv5 of
       `count-line:{branch}:{line}`. The lines get `adjustment_movement_id` and `reason_status = accepted`; the count stays `approved` with `decision`, `decided_by`,
       `decided_at` and the note (the lock is 3.4). Only `pending_approval` is decided: `awaiting_cosign` and a count that went straight to `approved` (no variance,
       `decision` null) are 409 `shifts.count.not_pending`. A count already approved by a decision answers itself for anyone who may approve, with no audit row.
       The counter is refused with 409 `shifts.count.approve_self`, a sole admin included (open question 4 keeps its default); rejecting one's own count is allowed.
     - **The shift locks when its last count resolves**: a `pending_approval` shift becomes `locked` (with `locked_at`, `open_key` null) when the newest
       revision of every count it has, opening and closing, is `approved` or `locked`. A shift whose counts were all auto-approved at submit stays
       `pending_approval` until 3.4's `lockApproved` and lock-at-submit (the 3.3a behaviour, unchanged).
     - **Reject.** `RejectCount` (`count.reject`): `note` required (at most 255), `line_ids` optional (an id not on the count is 422 `validation.failed`). The count becomes
       `rejected` with `superseded_by_id`; the recount is `seq + 1`, `supersede_cause = recount`, its lines copy counted figure, reason and note, the lines to look
       at again are unconfirmed, the rest stay confirmed; pending reasons of the rejected lines become `rejected`; the answer is the rejected Count plus
       `recount`. A closing recount sends a `pending_approval` shift back to `counting` (and `submitted_at` is cleared); if the next shift already holds the
       station (the `open_key` unique key decides, inside a savepoint) the old shift stays `pending_approval` and `SubmitCount` takes the recount there. An
       opening recount leaves the shift as it is (an `open` shift stays open) and `SubmitCount` takes an opening recount on an `open` shift. A count in
       `awaiting_cosign` can also be rejected by `shifts.approve`; the overseer's own reject is 3.3c's, with the co-sign.
     - **`moved_since`** on lines: net `on_hand` change per item at the place since the count's `ledger_movement_id`, without the count's own adjustments, while the
       count is `awaiting_cosign`, `pending_approval` or `approved`; for an opening draft, since the previous closing without that closing's adjustments; null
       otherwise. The count's ETag already moves with the ledger in exactly those statuses.
     - **Two 3.3a race windows.** (1) Closed: `SubmitCount` read `ledger_movement_id` with a plain read, which under REPEATABLE READ sees the snapshot of the
       transaction's first plain read, so a movement that committed on a locked pair between that snapshot and the lock was inside `expected_qty` but not below the id
       (`SubmitSnapshotRaceTest` held a pair from another process and failed with the old read). `Ledger::tailMovementId()` is a shared locking read of the newest
       movement, taken after `Ledger::lock`; it returns the true tail and waits for a movement still being inserted there (its writer holds every balance it needs). (2) Proved
       harmless, not closed: an item whose first stock commits during the submit is not on the count (the count cannot lock a pair it does not know); the ledger is right, no
       adjustment concerns it, and the next draft lists it with the live figure and no carried figure; the randomized rounds check `expected + net change since the id = ledger`
       for every line, whichever way the race falls. The worst case is a false `count.opening_drift` audit note, which is informational.
     - **`opening_drift` and adjustments.** The 3.3a proof "previous closing counted figure + movement since" now leaves out the closing's own adjustments (they are what made
       the ledger equal its counted figure); without that every approved difference would have been logged as drift.
     - **Open questions kept at their defaults**: negative stock stays refused (a refusal rolls the whole approval back); the overseer's reject is 3.3c's.
     - **Limit noted.** An item soft-deleted while it has a variance line cannot take its adjustment (the Ledger refuses a deleted item); the approval fails and the count is rejected and recounted without it.
   - **As built (step 3.3c, `phase-3/counts-cosign-notify`).** Commits: tests first (red: `CosignCountTest`, `CountQueueTest`, `CountEventsTest`,
     `DeletedItemCountTest`, `CountListenersTest`, the registries, the fixtures), then the actions, endpoints, events, listeners and migration 11, then the
     OpenAPI export and this note. Where the build settled what the plan left open:
     - **Co-sign.** `CosignCount` (`count.cosign`, the note in the audit context): count row, shift row, no balance. Route `shifts.view`; the action checks the station
       (`403 shifts.station.forbidden`), then the state (`409 shifts.count.not_awaiting_cosign` naming the status, before the permission, so any viewer of the count
       learns only what a `GET` shows), then `ShiftAccess::mayCosign` (the overseer flagged at that station, or `shifts.cosign` at it: `403 shifts.count.cosign_forbidden`; an
       overseer of another station only is refused), then the counter (`409 shifts.count.cosign_self`, even when the counter is the overseer or a manager). The same
       co-signer asking again answers `200` and writes nothing. `awaiting_cosign -> pending_approval` when a line differs, else `approved` with `decision` left null, like a
       count approved at submit. Left open by the plan: a co-signature that approves the last unresolved count locks the shift (`ShiftResolution`, shared with `ApproveCount`),
       so a station that requires an overseer does not leave a clean shift waiting for 3.4; a shift whose counts were all approved at submit still waits for 3.4.
     - **Reject moved to `shifts.view`.** The route asks for `shifts.view` and `RejectCount` decides: `awaiting_cosign` by whoever may co-sign (`403 shifts.count.cosign_forbidden`),
       `pending_approval` by `shifts.approve` (`403 auth.forbidden`, checked before the station so the 3.3b answer is unchanged), any other state `409 shifts.count.not_pending` for
       anyone who can see the count. An overseer who counted can reject their own count (a recount, as for an approver). The approve route stays on `shifts.approve`.
     - **Queue.** `GET /stock-counts` as in section 4 (`CountQueue`); `limit` default 50, at most 200; `status` outside the two waiting states is `422`.
     - **Events.** `CountSubmitted` is announced at submit (also for a count that goes straight to `approved`, which tells nobody) and again by the co-signature; the plan's `locked`
       is `approved` until 3.4. `VarianceDecided` at approve and reject (`adjusted_lines` is the number of adjustments written, 0 for a rejection; a repeat announces nothing). Both
       carry `station_code`, so the listener can choose the station's screen. Built by `CountAnnouncer` inside the transaction; `ShouldDispatchAfterCommit`.
     - **Listeners and texts.** `NotifyCountSubmitted`: `awaiting_cosign` -> `Recipients::overseers` plus `byPermission('shifts.cosign', station)`; `pending_approval` ->
       `byPermission('shifts.approve', station)`; never the counter; any other state nobody. Inbox key `count_submitted:{count}:{status_after}`, so the move to `pending_approval` is a
       new line for the approvers and a redelivery adds none. `NotifyVarianceDecided` -> the counter, key `variance_decided:{count}:{decision}`. The severity of `count_submitted` is
       `warning` when lines differ and `info` otherwise, of `variance_decided` `warning` for a rejection (`NotificationTypes::severity($type, $payload)`); the registry's
       `deep_link_by_station` picks `/kds/kitchen`, `/kds/bar`, `/till`, `/production` (cake room and bakery) or `/admin/inventory` (store) `?shift={id}`. Texts keep the wording per
       state (`awaiting_cosign`, `pending_approval`, `done`) and name at most five items with the signed variance and `and N more`.
     - **Migration 11** (`2026_10_24_130100_extend_notification_types_for_shifts`) drops and re-adds `notifications_type_check` with the ten Phase 2 types plus `count_submitted`,
       `variance_decided` and `chain_broken` (listed now so 3.4 needs no second change; its registry row, text and listener are 3.4's). `down()` deletes rows of the three types first.
     - **Deleted item (3.3b carry).** `Items::deleted(ids)` (Catalog, additive). Approve refuses a variance line for a soft-deleted item with `409 shifts.count.item_unavailable`
       `{item_ids}` before anything is written; submit refuses a line for one the same way and no longer asks for a deleted item that still has stock to be on the count; a recount
       is made without the deleted item. Chosen over excluding the item at draft time, which would hide stock that the ledger still holds.
     - **Open questions kept at their defaults**: negative stock stays refused; a sole admin who counted still cannot approve their own count.
4. **3.4 shifts.locks** (groups `invariants`, `unit`, `module:shifts`, `property`, `concurrency`; STATUS `shifts.locks`),
   `phase-3/locks`: ADR 0012 (proposed); migration 12; `Hash\CanonicalJson` (own encoder, section 10.3), `Hash\PayloadBuilder`,
   `Contracts\HashChain` (`lock(count)`, `verify(location)`, `export(location)`), `Contracts\Locks` (`previousClosing(location)`
   now "latest closing in `approved` or `locked`, not superseded, preferring the newest `submitted_at`", `supersede`), the lock
   inside submit-without-variance and approve, `unlock`, the chain endpoints, `shifts:verify-chain` (scheduled) and `ChainBroken`
   with its listener, the Python cross-check script; and the migration ends by locking any count left `approved` by the deploys between 3.3b and 3.4 (`Locks::lockApproved()`, each in its own transaction, idempotent). Tests: `CanonicalJsonTest` (pinned vectors, key order, scales, escapes,
   timestamps, negative zero), `PayloadBuilderTest`, `HashChainTest` (lock hash = sha256(previous_hash + canonical payload), the
   genesis link, verify ok, **tamper**: change a line, a note, a cash figure, `previous_hash`, a `payload_hash`, delete a link,
   swap two: each is found and named), `LockedRowsTest`, `StockCountsTriggerCoversEveryColumnTest`, `UnlockTest` (new revision,
   delta maths, the chain gets a new link, the old one stays and verifies), `LockChainRaceTest` (concurrency, forks: two counts of
   one place locking at once get sequence n and n+1, no deadlock, `Innodb_deadlocks` unchanged, 30 rounds), `VerifyChainCommandTest`.
   - **As built (step 3.4, `phase-3/locks`).** ADR 0012 (proposed); migration `2026_10_25_100100_add_stock_counts_locked_rows_triggers`.
     - **Where the lock is.** `Contracts\HashChain::lock(count, user)` is called inside the transaction of `SubmitCount` (a count that needs nothing decided
       goes `draft -> approved -> locked` in one step), `ApproveCount` and `CosignCount` (a co-signature that finds no difference), after the count row and
       the shift row are locked; `ShiftResolution::lockWhenResolved` then locks the shift when its last count is `locked` (also for a shift whose counts were
       all locked at submit, which used to wait for this step) and announces `ShiftLocked` (after commit; `Shared\Events\ShiftLocked`, links = the newest
       revision of each place and type with its `chain_seq` and hash). `CountSubmitted.status_after` therefore never says `approved`: it says `locked`
       for a count that needs nothing decided; the approve and cosign endpoints answer `locked` with `chain {chain_seq, previous_hash, payload_hash,
       locked_at}` (the presenter's `chain`, null before the lock). `locked_by_user_id` is whoever made the lock happen (the submitter, the approver or
       the co-signer).
     - **How the sequence is claimed** (plan section 10.3 said the unique key decides; this is the loop). The head is read without a lock; the link is one
       query-builder `UPDATE` (status, `chain_seq`, hashes, `canonical_payload`, `locked_at` with microseconds, `locked_by_user_id`, `version`) inside a
       savepoint; on a duplicate key (`UniqueConstraintViolationException`) it reads the row that now has that number with `sharedLock()` (a row that exists,
       so no gap lock: the first link of a place never takes a `FOR UPDATE` on a missing row), takes its hash as the previous hash and tries the next number.
       `HashChain::MAX_ATTEMPTS = 8` (the plan's "three retries" was a guess; eight costs nothing and three counts of a new place racing needs three);
       exhausted, it is `409 shifts.lock.chain_busy` and the transaction rolls back. `LockChainRaceTest` (forks, 30 rounds of two counts of one place, 30
       rounds of two counts of a new place, 10 rounds of three) saw sequences n and n+1 and `Innodb_deadlocks` unchanged. The first version saved through
       Eloquent and lost `locked_at` from the UPDATE after a lost claim (a dirty-state surprise); the query builder removes the question.
     - **Payload.** `Hash\PayloadBuilder` is exactly the version 1 list of section 10.3. `adjustment_qty` is read from the line's `variance_qty` when
       `adjustment_movement_id` is set (the approval writes the delta, by construction), so Shifts needs no view of movement rows; `supersedes_count_id` is
       the count whose `superseded_by_id` is this one; `cash` is null until 3.5 (the method that fills it is `PayloadBuilder::cash`). An empty list encodes
       as `[]`, and no empty map is in the schema. `CanonicalJson` also exposes `quantity()`, `money()`, `timestamp()` and `date()`, which refuse a figure that
       would need rounding (`LogicException`). Three vectors are pinned in `CanonicalJsonTest` and in `tests/Fixtures/chain/vectors.json`
       (`UPDATE_CHAIN_FIXTURES=1` rewrites it).
     - **Verify.** `HashChain::verify(location)` reports at most one failure per link, in the order `gap` (the named `chain_seq` is the missing number and
       `count_id` the link found in its place), `previous_mismatch`, `hash_mismatch`, `payload_mismatch`; after a failure it carries on with the stored hash of
       the link, so one tampered link names itself and the link after it, nothing more. A chain shortened at its newest end still verifies (only a head
       hash kept elsewhere can tell: ADR 0012, Consequences). `export(location)` is `{format: bakery-chain-export, version, branch_id, location_id,
       genesis_hash, head_hash, links: [{chain_seq, count_id, previous_hash, payload_hash, payload_version, canonical_payload}]}` for
       `scripts/verify_chain.py --export`.
     - **Triggers.** `stock_counts_locked_forbid_update` lists every column from `information_schema` when the migration runs (MariaDB), and lets
       `superseded_by_id` / `superseded_at` move only from a NULL `superseded_by_id` (and `superseded_at` only with a non-null `superseded_by_id`);
       `stock_counts_forbid_delete` refuses every DELETE. PostgreSQL: `stock_counts_guard()` over `to_jsonb(NEW) - the two columns`. `down()` drops them.
       `LockedRowsTest` tries every column of a locked row one by one (to a new value and to NULL).
     - **Unlock.** `Actions\UnlockCount` (`POST /stock-counts/{count}/unlock`, `shifts.unlock`): `409 shifts.count.not_locked {status}`,
       `409 shifts.count.already_superseded {superseded_by_id}`, `422` for a missing or over-long reason; response `{unlocked, revision}`. The revision's
       lines copy `counted_qty`, `carried_qty`, the reason and the note and are left **unconfirmed** (the counter looks at each again, `confirm_all` is the
       explicit "nothing changed"); a line for an item deleted since is not copied. `expected_qty` is not copied from anywhere: it is the ledger under the
       balance locks at submit, which equals the old counted figure plus anything that moved since, so approving writes only the difference. A locked
       shift takes an unlock revision (`SubmitCount::takesCount`); the shift stays `locked`. `Locks::supersede` is the one query-builder UPDATE the trigger
       allows. `Locks::previousClosing` is `approved` or `locked`, not superseded, newest `submitted_at` first.
     - **Backfill** (`Locks::lockApproved()`, last statement of the migration, also `php artisan shifts:lock-approved`). For every branch (the current
       branch is set and put back), every count with status `approved` is locked in its own transaction, within a place by `coalesce(decided_at,
       submitted_at)` then id, so the chain keeps the order things happened in; `locked_by_user_id` is the decider, else the counter, else the creator;
       the count row is locked `FOR UPDATE` before its shift row, and a shift left `pending_approval` whose counts are now all locked is locked too
       (`ShiftLocked` is dispatched). A count that cannot be locked is logged (`Log::warning`) and stays `approved` (still carried by `previousClosing`)
       for the command to retry; nothing aborts the migration. A second run finds nothing. No `audit_log` row is written (no actor in a migration).
       `LockApprovedBackfillTest` runs the migration's `up()` twice on a populated database.
     - **Nightly check.** `shifts:verify-chain [--branch=]` (routes/console.php: `dailyAt('03:00')`, `Africa/Addis_Ababa`, `withoutOverlapping(60)`) prints
       and logs (`Log::info`, context `branch_id, location_id, checked, head_hash, ok`) every head hash, dispatches one `ChainBroken` per failure and
       exits 1 (2 for an unknown branch). `Shared\Events\ChainBroken` carries `location_id`, both names, `first_bad_chain_seq` (the failing link of that
       failure), `kind`, `count_id` and `caused_by_user_id` (the link's `locked_by_user_id`). `Notifications\NotifyChainBroken` tells `byRoles(['admin'])`
       plus `byPermission('shifts.replay')`, critical, `in_app, push, telegram`, inbox key `chain_broken:{location_id}:{first_bad_chain_seq}:{kind}`, deep
       link `/admin/shifts?location={location_id}`; the texts say the kind in words (`chain_kind.*`).
     - **Chain endpoints.** `GET /locations/{location}/chain` (`limit` default 50, at most 200, `before_seq`) and `/chain/verify`, `shifts.replay`; a place of
       another branch is 404 through `Locations::has`.
     - **Python cross-check.** `scripts/verify_chain.py` (standard library only, its own encoder) recomputes the vectors and, with `--export`, any chain
       export; CI runs it in the `invariants` job.
     - **Tests moved and changed.** `HashChainTest`, `VerifyChainCommandTest` and `LockApprovedBackfillTest` live in `tests/Gate/` (not `Modules/`):
       they drop and recreate the protecting triggers (DDL commits) and so use `CommitsForConcurrency`, which cannot be combined with the `RefreshDatabase`
       the `Modules` directory gets (the run re-migrated per test). They still carry `module:shifts`. Every earlier test that expected a count to be
       `approved` after the API approved it, or a shift to wait in `pending_approval` after no-variance counts, now expects `locked`.
     - **Left open by the plan, decided here.** The unlock revision is not pre-confirmed. The backfill locks by `coalesce(decided_at, submitted_at)`. The
       lock is by the person who caused it. `ChainBroken` is one event per failure, not per chain. `adjustment_qty` is the variance.
5. **3.5 shifts.cash** (groups `feature`, `module:shifts`; STATUS `shifts.cash`), `phase-3/cash`: the two cash cases of
   `shifts->payments` appended red first; migration 13; `Denominations` (the configured list, the arithmetic in `Money`),
   `CashDeclarations` (the cash-drawer count's lines of money: opening float, closing expected/variance, float left, channel
   reconciliation from `Totals::byChannel`), the cash endpoints, the lock payload's `cash` block, `payments.require_shift_for_cash`
   (the `RecordPayment` guard, flag-gated, tested both ways), the cash-drawer count joining start/close/submit/approve/lock.
   Tests: `CashDeclarationTest` (the worked tables of section 10.5), `DenominationsTest`, `CashEndpointsTest`,
   `ChannelReconciliationTest`, `PaymentRequiresShiftTest`, `CashPropertyTest`. Ends with `gate_check --mode module --module payments`.
   - **As built (step 3.5, `phase-3/cash`).** STATUS `shifts.cash: done`; Payments stays `stable`.
     - **Order.** Red first (`5e71e38`: the tests of the list above plus `CashMathTest`, `CashDeclarationsSchemaTest`, `CashTamperTest`,
       `CashLangTest`, the two new cases of `PaymentsContractTest`; case 8 was green on arrival because `Totals` already existed, as the
       plan expected, case 9 and the rest red), then the code. `ShiftsFixture` gains `drawer`, `cashPut`, `cashRead`, `taken`, `openTill`, `closeTill`.
     - **Payments.** `config/payments.php` (`require_shift_for_cash`, env `PAYMENTS_REQUIRE_SHIFT_FOR_CASH`, **false** in the code,
       `.env.example`, `.env.ci` and the live host; its own variable, not the ledger's: section 10.1 corrected). `RecordPayment` asks Shared's
       `OpenShifts::isOpen(actor shift, lock)` inside its transaction, after the replay check and only for the `cash` channel, and refuses
       `409 payments.shift.required` (bilingual, `lang/{en,am}/payments.php`) when the actor carries no shift or one that is not `open`
       (counting, locked, cancelled): the shared lock keeps `close` (FOR UPDATE) from freezing the drawer between the check and the row.
       With the flag off Shifts is never asked (`PaymentsContractTest`). A payment already taken answers itself even after the till began
       counting. The `enforced-gates` rehearsal still turns only the ledger flag on (section 10.5, last bullet). Runbook: "Shift rule for cash".
     - **Shifts.** Migration `2026_10_26_100100_create_cash_declarations_table` (section 3, item 13, with the as-built differences written there);
       `Models\CashDeclaration`; `Cash\Denominations` (config `shifts.denominations`), `Cash\CashMath` (opening/closing expected, variance, all
       `Money`), `Cash\CashFigures`, `Cash\CashDeclarations` (live figures for a draft, `settle` at submit, `present` for the API, `payload` for the
       hash, `summary` for the widget, `copy` for a recount or revision), `Actions\SetCashDeclaration` (`PUT /stock-counts/{count}/cash`, audit
       `count.cash`, count version moves), `GET /shifts/{shift}/cash`. `DraftBuilder::buildCash` makes the drawer's draft and its empty declaration
       in start and close; `SubmitCount` settles the declaration before the status change (the trigger lets a draft parent be written) and a cash
       variance or channel difference sends the count to a decision like a line does; `CosignCount` counts it too; `RejectCount` and `UnlockCount`
       copy the declaration into the recount or revision, unconfirmed; `CountPresenter` fills `cash`; `CountAnnouncer` fills
       `cash_variance_total` of `CountSubmitted`; `PayloadBuilder::cash` is `CashDeclarations::payload`.
     - **A constructor cycle found and cut.** `Locks -> HashChain -> PayloadBuilder -> CashDeclarations -> Locks` overflowed the container
       (the migration of step 3.4 resolves `Locks`); `CashDeclarations` resolves `Locks` where it needs `previousClosing`, not in its constructor.
     - **The chain.** Payload version 1 is unchanged; the `cash` block and its channel row keys are written in section 10.3. A locked drawer count's
       declaration is rebuilt from its row on every verification, so a changed figure, reason, note or a deleted row is `payload_mismatch` at that
       link (`tests/Gate/CashTamperTest.php`, with the freeze trigger dropped as the other tamper cases do). The pinned till vector and
       `scripts/verify_chain.py` moved together (`UPDATE_CHAIN_FIXTURES=1` must be passed into the container with `docker compose exec -e`).
     - **Tests.** `tests/Unit/Shifts/DenominationsTest.php` is `tests/Modules/Shifts/DenominationsTest.php` (it reads the configuration, so it needs
       the application, which `Unit` does not boot). The route matrix and the write-endpoint matrix list the two new routes. `CashPropertyTest`:
       12 seeds, 4 shifts each, an independent `BigDecimal` sum of the denominations and of the payments.
     - **Left open by the plan, decided here:** section 10.5, "Decisions of 3.5" (declaration required, closing basis, live versus fixed
       figures, channel differences, denomination spelling, copies, the separate flag).
     - **For step 3.6 (handover and replay).** A till count has `lines: []` and a `cash` block: the handover panel's `previous_closing` row for the
       drawer has a `float_left_total` to show (`CashDeclarations::carriedFloat(location)`), and `provisional` is true for a drawer closing that
       is submitted but not locked, whose float is not carried yet (`Locks::previousClosing` ignores it until it is approved). `Replay::at` has no
       cash to replay (cash is not in the ledger); the payments of a shift are `Totals::cash(shift)` / `byChannel(shift)`. Nothing in 3.5 writes to
       `shift_instances`; `GET /shifts/{shift}/summary` still answers its own `cash` block (`cash_sales_total` and channels) and `GET /shifts/{shift}/cash`
       is the widget's. The only new statuses a screen meets are the existing ones: a drawer count waits in `pending_approval` like any count.
     - **CI** (run on `b73e84a`, PR 52). `mod-payments` (the module gate evidence, Payments stays `stable`): `module:payments` 52 passed (549
       assertions), `contract:shifts->payments` 10 passed (66); `mod-shifts` 980 passed (11971); `enforced-gates` (the ledger flag on), `gate-p0`
       to `gate-p3`, `e2e-p0` to `e2e-p3`, `api-arch`, `invariants`, `openapi-check`, `erd-check`, `i18n-check` green. Local: `tests/Modules/Shifts`,
       `Payments`, `Invariants`, `Arch` 685 tests with the one stamp-column test fixed afterwards, the Gate tests of the chain 54 passed.
6. **3.6 shifts.handover** (groups `feature`, `module:shifts`, `property`; STATUS `shifts.handover`), `phase-3/handover`:
   migration 14; notes and handover endpoints; `Contracts\Replay::at(station, time)`; `GET /replay`; `ReplayPropertyTest`;
   then `gate_check --mode module --module shifts` and `phase_status.py module-stable shifts`. Playwright projects stay
   placeholders. `shifts.handover: done`.

   - **As built (step 3.6, `phase-3/handover`).** Migration 14 is `2026_10_26_100200_create_handover_notes_table`; `HandoverNote` is append-only by
     trigger (`handover_notes_forbid_update_delete_update` / `_delete` on MariaDB, `forbid_update_delete()` on PostgreSQL), has no factory (the tests
     write through the API or raw rows) and is on the `SoftDeletesTest` list. `check_invariants.py` and `AppendOnlyTest` know the table; ERD and OpenAPI
     are re-exported. Where the build settled what the plan left open:
     - **Notes.** `WriteHandoverNote` (`handover_note.create`, one AuditedAction under the shift row's lock): `shifts.count` at the station, `client_uuid`
       and `body` (1 to 1000 characters, trimmed) required. A used `client_uuid` answers `200` with the note already written, even when the body
       differs, and writes nothing (no audit row). The shift takes notes while it is `opening`, `open`, `counting` or `pending_approval` (the outgoing
       baker may write after handing the closing in); `scheduled`, `locked` and `cancelled` are `409 shifts.shift.not_running` naming the status.
       `to_user_id` must be an active person of the branch who holds `shifts.view` at the station, else `422 validation.failed` on `to_user_id`.
       `from` is the person acting (short-code actor, else the account); `created_by_user_id` is always the account. `GET /shifts/{shift}/notes` is
       a polled feed through `OverlapCursor` (ETag, 304, `last_id` held back by the overlap window, `?after_id=`), `shifts.view` at the station.
       `notes_count` in the full Shift resource is a grouped count.
     - **Handover panel.** `Handover\HandoverPanel`. `previous` is the newest shift of the station in `pending_approval` or `locked` (by `started_at`,
       then id); the shift that runs now is never it. `notes` are the previous shift's (any age) plus the ones addressed to the caller that were written
       on a shift of this station within `config('shifts.handover_note_days')` (7) days, oldest first, at most 200; there is no read receipt. One
       `previous_closing` row per stock place and for the drawer: an unresolved closing is the row and is `provisional` (no hash, no `locked_at`),
       otherwise `Locks::previousClosing`. `float_left_total` is `CashDeclarations::carriedFloat` for a locked drawer closing and the declared figure
       for a provisional one (a decision: the screen can show what is proposed, flagged). `pending_counts` are the unresolved counts of those places.
       A drawer-only station (the till) works with no stock place. Not conditional (not polled).
     - **Replay.** `Contracts\Replay::at(station, time, nonzero)` returns a readonly `ReplayState` (`ReplayDuty`, `ReplayPlace`, `ReplayLink`,
       `ReplayCount`); `Handover\ReplayPresenter` makes the JSON. It depends on contracts only (`Stock`, `Orders`, `Locations`), so it closes no cycle.
       The shift that held the station is the one with `started_at <= t` and no end by `t`, the end being `submitted_at`, else `cancelled_at`, else
       `locked_at` (the station is free once the closing counts are handed in): that is a summary of the row **as it is now**; shifts keep no status
       history, so a shift sent back to counting and later is read as held for the whole time. A roster row keeps one `joined_at`, so someone who left
       and came back shows from the first join; `on_duty` therefore holds only people not gone by `t` and `left_at` is always null. Balances are
       `Stock::locationAt` (non-zero only unless `nonzero=0`); the cash drawer is not a place and **the replay carries no cash** (cash is not in the
       ledger; a shift's money is `Totals::cash` through the summary and cash endpoints), but a drawer count is in `counts`. `counts` are the counts
       of the shift that held the station, of the shift before it, and any not yet locked, started by `t`, each with `status_at` worked out from its
       timestamps (locked, else the decision, else submitted as `awaiting_cosign` at a station that requires an overseer and has no co-signature yet
       or `pending_approval`, else `draft`); a rejected count of an older shift is left out. `at` accepts `Z`, an offset, or a bare time read as
       the branch's local time; a moment after now is `422 shifts.replay.future`; an unknown or foreign `station_id` is `422 validation.failed`.
       The replay is not limited to stations that track shifts (the store replays with `shift: null`).
     - **Tests.** `ReplayPropertyTest` runs random histories only: the `Phase3Seeder` it also names does not exist yet (the seeds step adds the
       seeded-history half), and it is tagged `property`, `module:shifts` like `CountsPropertyTest`, not `gate:p3` (the gate re-tags come with the
       gate steps). The oracles are written in the test from `stock_movements`, `order_events`, `shift_roster` and the count timestamps.
       `HandoverNotesTest`, `HandoverPanelTest`, `ReplayEndpointTest` are the feature files with 403 per role; `HandoverNotesSchemaTest`,
       `HandoverLangTest` the schema and the message key. Matrix and write registries list the four routes.
     - **CI** (run 39901423445, PR 53, on `c5fb4dc`). `mod-shifts` (the module gate evidence): 1083 passed (14773 assertions); `enforced-gates`, `gate-p0` to
       `gate-p3`, `e2e-p0` to `e2e-p3`, `api-arch`, `api-lint`, `invariants`, `openapi-check`, `erd-check`, `i18n-check`, web checks green. STATUS
       `shifts.handover: done`, module `shifts: stable`. Local: the new feature, schema, lang and property files, Arch, Invariants and the route matrix.
     - **For the web steps (3.7).** Notes: `POST /shifts/{id}/notes {client_uuid, body, to_user_id?}`, poll `GET .../notes?after_id=<last_id>` and
       merge by id. The waiter has no `shifts.view`, so no handover panel and no notes for the pad. The panel's `previous_closing[].location.type ==
       'cash_drawer'` row carries `float_left_total`; show `provisional` rows as "waiting for approval". The first opening count of a drawer is a
       chain link too, so a till's chain has one link per count (closing `chain_seq` 2 after one opening). Replay is admin and manager only.
7. **3.7 web** (STATUS `web`; Playwright `p3-handover`, `p3-opening-prefill`), five PRs. Each flow is committed red first with its
   fixture helpers (`e2e/support/shifts.ts`), then the screens; the placeholders and their TODO markers go as their flow lands.
   Strings are added to `messages/en.json` and `messages/am.json` together (the `i18n-check` key sets must match); money and
   quantities stay strings (`decimal.ts`); base units on screens.
   - **3.7a `phase-3/web-shift-bar`**: `lib/api/client.ts` sends `X-Station-Id` from the active station (set by each station
     screen), the `Me` type gains `work_stations`, a `ShiftBar` component (status, who is on, Start / Join / Close, "Start without
     counting", the `409 inventory.shift.required` and `payments.shift.required` messages in both languages with a Start button)
     on `/kds/kitchen`, `/kds/bar`, `/production` and `/till`, a `LiveFeed` over `GET /stations/{station}/shifts/current` (5 s
     visible, 30 s hidden, ETag), the till's Pay button disabled with the reason when no shift is running, and the stations-list
     for managers from `work_stations`.
     - **As built (step 3.7a, `phase-3/web-shift-bar`).** Web only (no API change). `lib/api/client.ts` sends `X-Station-Id` from `lib/station.ts`
       (`useActiveStation`, set by each screen through `useShiftStation`; the kitchen and bar displays pass the station they picked, production
       and the till pass their station kinds), `Me.work_stations` is typed and `signIn` reads `/me` once more because the login answer has none.
       `components/shifts/ShiftBar.tsx` renders four states over `lib/live/shifts.ts` (`createShiftFeed`, `SHIFT_POLL` 5 s / 30 s / 30 s, no
       cursor, the answer replaces the state): **no shift** (badge "No shift", "Start shift" = count first, "Start without counting" =
       `carry`), **opening** (count in progress, who started it, "Open the count", "Cancel start" for the starter or `shifts.manage`), **open**
       (since when, the people on duty as chips, the notes count, "Join shift" or "Leave shift", "Close shift" behind a confirmation) and
       **counting** (closing count in progress, "Open the count"). A station that tracks no shifts, or a person without `shifts.view` there,
       gets no bar. The pure rules are in `lib/shifts.ts` (`barActions`, `onDuty`, `payBlockReason`, `shiftRequiredNotice`, `stationsFor`,
       `addisClock`) and tested without a browser in `e2e/p3/handover-shift-bar-logic.spec.ts`. Any 409 `inventory.shift.required` or
       `payments.shift.required` that the client sees (a write or a poll) is kept in `useShiftNotice` and shown in the bar in the page's
       language, using the API's own `message_en` / `message_am`, above the same Start buttons; a running shift clears it. Start,
       join, leave, close and cancel each keep one Idempotency-Key (the start's `client_uuid` is the same value) until they succeed or the
       API answers definitely. `/shifts/count?shift=<id>` is a placeholder page that 3.7b replaces; the admin overview (`/admin`) gains a
       "Stations and shifts" panel from `/me` work_stations (`stations.all` only, refreshed every 15 s). The kitchen and bar displays now
       also list the stations of `work_stations`, so a manager (stations.all, no assignment) can open them.
       **Decisions the plan left open.** (1) *The till's Pay button.* The plan says it is disabled with the reason when no shift is running;
       with `payments.require_shift_for_cash` off that would stop cash on the live site. The web cannot read the flag, so Pay is blocked
       only after the API has answered `payments.shift.required` once in the session, or when the build sets
       `NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH=true`; otherwise the bar shows "No shift is running" and Pay stays enabled. 3.7e sets the build
       variable together with the flag. (2) *Close needs no roster membership:* any holder of `shifts.count` at the station may close, as the
       endpoint allows. (3) *The `opening` dead end:* "Start shift" counts first, but the count screen is 3.7b, so for now an `opening` shift is
       left through "Cancel start" and a fresh "Start without counting". (4) `notes_count` in the bar can lag: the `current` ETag moves with the
       shift version, which a handover note does not change; the count refreshes at the next roster or status change (3.7b adds the notes
       panel and may add the notes count to the ETag).
       **The pad menu flake (carry from 3.4, `stockout-production.spec.ts:100`).** The run's Playwright report was not among its artifacts
       (only the release), so the trace could not be read. What the code shows: the menu ETag is the highest ledger movement id plus the
       locations' state, so a production output moves it; the pad's menu feed starts with the screen and is not touched by the idle lock or the
       unlock (it is a separate feed from the waiter's orders); the 304 path keeps the state, and the poll cadence (5 s visible, 20 s hidden,
       back-off to 20 s) leaves room in 30 s. The one way a feed stops for good is a request that never answers: a look is awaited before the
       next one is scheduled and `fetch` had no time limit, so a connection dropped behind the proxy froze the menu until a reload. Requests
       now give up (`READ_TIMEOUT_MS` 20 s for reads and polls, `WRITE_TIMEOUT_MS` 30 s for writes, reported as the usual network failure so
       the feed backs off and looks again), with a test that a hung `conditionalGet` fails inside its limit. This is the likely cause, not a
       proven one. A second, theoretical gap is left for a later step: the menu ETag uses `max(id)` of the ledger, so a transaction that
       takes a lower id but commits after a higher one would not move it.
       **For 3.7b to 3.7e.** The flow in `e2e/p3/handover-shift-bar.spec.ts` settles the kitchen station first (`settleStation`: cancel an
       `opening` shift, close and submit an `open` or `counting` one) and ends with `ensureOpenShift`; 3.7b should reuse `finishClosingCounts`
       until its own screens finish counts. `useShiftStation` and `ShiftBar` take a station and are ready for the count and handover links
       (the handover panel belongs in the bar's "opening" state). 3.7d adds the declaration to the till's opening state next to "Open the
       count". 3.7e: set `NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH` with the cash flag.
       CI evidence: see the PR.
   - **3.7b `phase-3/web-counts`**: the count screen (one component for opening and closing: lines with expected, carried and
     counted in base units, the touched/untouched mark, "nothing changed", the reason picker, the note, add an item, the review
     summary before confirming), the admin approval queue and side-by-side approval, the chain hash shown on a locked count, and
     `p3-handover` (first test) green.
     - **As built (step 3.7b, `phase-3/web-counts`).** Web plus one small API fix (Orders and Inventory stay `stable`).
       **Count screen** `/shifts/count?shift=<id>` (`components/shifts/CountScreen.tsx`, `CountPanel`, `CountLines`, `ReviewDialog`, `ChainCard`;
       pure rules in `lib/counts.ts`, tested without a browser in `e2e/p3/handover-count-logic.spec.ts`): one component for opening and closing. It polls
       the shift and its counts every 6 s, shows the counts of the phase the shift is in (`phaseOf`), one place at a time (a button per place with
       its stage), and sets `X-Station-Id` to the shift's station. A draft count is a table: item, expected (live ledger), carried (opening only),
       counted (a field saved when left: `counted_qty` with `confirm`), difference (counted minus expected, string arithmetic), reason and note (shown
       only for a touched line that differs; the note placeholder says whether the reason requires it), and the touched mark ("Counted" / "Not
       counted" with an "As expected" button). "Nothing changed" is `confirm_all`. "Add an item" lists active, stockable items not on the count
       (`GET /items`). "Review and submit" opens a dialog with the totals, each differing line with its reason, and what blocks the submit (untouched
       lines, differences with no reason, reasons that need a note); Submit stays disabled until none remain. After the submit the panel says what
       happens next: waiting for co-signature, "Waiting for a manager to approve", or "Approved and locked" with the chain card (link, full
       fingerprint, previous fingerprint). A rejected count's recount (`seq + 1`) opens with a banner naming who sent it back and their note. An unlocked
       count's revision says it was reopened. A drawer count (`cash` set) shows "the cash count comes in the next update" and no lines: the declaration
       is 3.7d's. Handover notes sit below (list, and a form with an optional addressee from the roster).
       **Handover panel** (`HandoverPanel.tsx`, `GET /stations/{station}/handover`, refreshed every 15 s): the previous shift, its notes and notes to me,
       one row per place (a `provisional` closing reads "Waiting for approval", a locked one shows its link and short fingerprint, the drawer its float),
       and the number of unresolved counts. It is a "Show handover" disclosure in the shift bar's no-shift and opening states and the first panel of an
       opening count. The bar also gains "Add a note" (a dialog over `POST /shifts/{id}/notes`; the `client_uuid` is the Idempotency-Key).
       **Approval screens.** The queue (`ApprovalQueue.tsx`, feed `lib/live/counts.ts` over `GET /stock-counts`, ETag, 5 s / 30 s) is mounted at
       `/admin/shifts` (a new "Shifts" tab and overview card; 3.7c builds the rest of `/admin/shifts` around it). Each row: count number, status, station,
       place, shift date, who handed in, the differing lines, and "Review". The side-by-side page `/admin/shifts/review?count=<id>` shows expected,
       counted, difference, moved since, reason and note per line (differing lines first), the chain card of a locked count, and the actions the person may
       try (`approvalActions`: approve and reject by `shifts.approve` and never for the counter; co-sign by `shifts.cosign` or an overseer at a station that
       asks for one; unlock by `shifts.unlock`): Approve and Co-sign with an optional note, Send back (a required note and the lines to look at again,
       default the differing ones), Unlock (a required reason). The API still decides and answers in both languages.
       **p3-handover test 1** is real (`e2e/p3/handover.spec.ts`, `@live`): the chef closes the kitchen shift from the bar and counts; one ingredient is 500 g
       short; the review refuses until a reason is chosen; a note is written and the count handed in. The admin finds it in the queue, sees the line
       side by side, approves; the page shows the locked count's fingerprint; the API shows one `count_adjustment` equal to the variance on that line and
       `chain/verify` ok with the head equal to the count's hash. The chef starts the next shift: the handover panel shows the note and the fingerprint, the
       opening line carries the locked figure, "Nothing changed", submit, the shift is open. Test 2 stays a `test.fixme` with its phase-3 to-do marker for 3.7d.
       The support helpers got sturdier (`finishDrafts`: a draft made before an item was stocked gets that item added, a line left unexplained goes back to
       the ledger figure; `resolvePendingCounts` approves what an earlier run left waiting; `settleStation` calls it first), because one count waiting at a
       place blocks the next count there and a failed live run would otherwise poison the E2E branch.
       **The ETag fix (the gap 3.7a left open).** `Stock::fingerprint()` used the highest movement id, so a transaction that took a lower id but committed
       after a higher one was served left the tag unchanged. It now adds `settling`: while the newest movement is younger than the overlap window
       (`bakery.live.overlap_seconds`, 5 s) it is the current second, so the tag moves on every poll of the window; after it the tag is stable again and
       different from every tag the window handed out, so a pad that looked inside the window looks once more. Feeds that read it: the menu, the stock strip,
       the display case, the stock views, and now the approval queue and the count read (their `moved_since` reads the ledger; both used only the id).
       Checked and left alone: the tickets and orders-list feeds (`OverlapCursor::fingerprint` already counts the rows after the cursor), the notes feed
       (same), and the queue's own part (count, `max(updated_at)`, `sum(version)` change when a row arrives whatever its id). The `current` shift tag gains the
       handover-note count and the shift's count rows (total, `sum(version)`, newest id): a note changes `notes_count` without moving the shift's version,
       and a recount after a rejection changes `counts` the same way. Tests: `MenuReadsContractTest`, `MenuEndpointTest`, `CountQueueTest`,
       `ShiftReadEndpointsTest`.
       **Decisions the plan left open.** (1) The queue lives at `/admin/shifts` and the detail at `/admin/shifts/review?count=<id>` (a static export reads
       the id on the client). (2) The count screen needs only `shifts.view`; the buttons that write appear for everyone and the API answers 403 for those
       without `shifts.count`. (3) A count is saved line by line as it is edited, not on a "Save" button: the draft is the server's. (4) "Add an item" lists
       any active, non-made-to-order item (the API refuses the rest). (5) Unlock stays on the review page; the revision is counted on the count screen.
       **For 3.7c to 3.7e.** 3.7c mounts its sections around `ApprovalQueue` on `/admin/shifts` (the page is a server component with a `PageHeader` and the queue)
       and can link `/admin/shifts/review?count=<id>` from the locked history. 3.7d fills the drawer case in `CountPanel` (the `drawer` branch) and the
       cash block in `ApprovalReview`; both show "the cash count comes in the next update" until then. Quantities are base units as the API sends them
       (the kitchen's ingredients count in `g`). `ensureOpenShift` still opens carried shifts; with a drawer, 3.7e must declare it.
       **purchase-transfer on live (from the 3.7a deploy).** Its second request clicked `Request transfer of <sugar>` in the kitchen strip, which is cut to `STRIP_ROWS` (8) rows, out first, then low, then by available over minimum. The E2E branch holds out-of-stock "E2E kitchen ingredient" items and older runs' sugars at 6000 g over a minimum of 2000, so after the transfer this run's sugar tied with them and fell past the cut (run tags are base36 timestamps, so each new run sorts later). It was not slowness. Fixes: `retireStaleIngredients` (`e2e/support/fixture.ts`) soft-deletes the E2E ingredients of earlier runs, or switches them off when a recipe still uses them, before the flow; the strip now leaves out switched-off ingredients (a deleted one was already out; test in `StockStripEndpointTest`); and the second request no longer depends on the row being ranked inside the cut: when it is absent the request goes through the API.
       CI evidence: see the PR.
   - **3.7c `phase-3/web-admin-shifts`**: `/admin/shifts` (templates, roster calendar with schedule and "Start now" for an admin,
     running shifts, approvals, locked history with the hash and a "Verify" button, notes, replay at a point in time, variance
     reasons), the setup check (stock places with no station, stations without templates), and `p3-opening-prefill` green.
     - **As built (step 3.7c, `phase-3/web-admin-shifts`).** Web only (no API change, no new route, flags untouched). `/admin/shifts` is now a section with its own tabs
       (`AdminShiftsNav`, like Inventory): **Overview** (`/admin/shifts`), **Templates**, **Roster**, **History**, **Handover notes**, **Replay**, **Variance reasons**
       (`/admin/shifts/<name>`; static pages, the screens read their own state on the client). A person sees the tabs their permissions give (`adminSections`): everyone with
       `shifts.view` reads all of them except Replay (`shifts.replay`: admin and manager); every write button and form is for `shifts.manage` (the admin) and is hidden
       from the others, and the API still decides each call. The pure rules are in `lib/shiftsAdmin.ts` and are tested without a browser in
       `e2e/p3/handover-admin-logic.spec.ts` (local dates and ISO weekdays, the templates that run on a date, the template and reason forms, the setup check, the roster
       cells, who may be offered for a station, the point-in-time input, the chain summary, the sections); the calls are in `lib/api/adminShifts.ts`.
       **Overview**: the *setup check* (`GET /me` work stations, `GET /locations`, `GET /shift-templates`: every stock place with no station, every station that tracks
       shifts and has no active template, each with the link that fixes it: "Add a template" opens Templates with the station ticked, "Open inventory" for a place), *shifts
       running now* (a new `LiveFeed` over `GET /shifts?running=1`, ETag, 5 s visible and 30 s hidden like the queue, `lib/live/running.ts`; a row says station, template and
       date, status, who started it, links to the count and "Cancel start" for an `opening` shift to its starter or `shifts.manage`), and 3.7b's `ApprovalQueue` unchanged below.
       **Templates**: list with window, days, stations and status; the admin makes, edits and retires (`DELETE` is a soft delete: a retired template leaves the calendar and
       shifts already made keep their window); the form checks what the API would refuse before it asks (both names, different start and end times, a day, a station) and says
       when a window runs through midnight. **Roster**: a Monday-to-Sunday week (a date field "Show the week of", previous, this and next week), one table per tracked station,
       a row per active template, a cell per date the template runs on, placed by station, template and local date (`GET /shifts?from&to`). A cell shows each shift's status and,
       for a scheduled one, the people on it (`GET /shifts/{id}`; the list leaves the roster out); the admin schedules people into an empty cell (the people offered are the
       active ones assigned to the station with `can_count`, from `GET /users`), changes the people of a scheduled shift (`PUT .../roster`), cancels it, or presses **Start now**.
       Start now opens a confirmation ("Start without counting" is a tick box) and calls `POST /stations/{id}/shifts/start {shift_id, force: true, carry}` with the key as the
       `client_uuid`: the admin's way into a window that is over; the shift keeps the date it was scheduled for. **History**: for admin and manager the locked counts of a place
       (`GET /locations/{id}/chain`: link, count, shift date, locked time, short fingerprint with the full one in the tooltip, a "Review" link to 3.7b's side-by-side page) and
       a **Verify** button that walks the chain (`GET .../chain/verify`) and says "intact, N links checked" or lists each failure by link and kind in plain words; below it every
       shift, newest first, with a station and status filter and paging. **Handover notes**: shifts newest first with a "Show notes" toggle per shift (`GET /shifts/{id}/notes`:
       who wrote it, when, for whom). **Replay**: a station and a date-time typed on the branch clock (Africa/Addis_Ababa, sent with its `+03:00` offset), then the shift that was
       running, who was on, the balances per place with the last locked link, the orders still open and the counts with the status each had then (`GET /replay`; `422
       shifts.replay.future` is shown in the page language). **Variance reasons**: list (retired ones included) with add, edit (names, "needs a note") and retire or bring back;
       the code is typed once and never changes. The **shift bar** now names the shift by template and local start date ("E2E Night · 2026-10-22", "Ad hoc shift · date"), which
       is what makes a Night shift worked after midnight readable.
       **`p3-opening-prefill` is green** (`e2e/p3/opening-prefill.spec.ts`, fixture in `e2e/support/nightshift.ts`, `@live`): the bakery gets a run-unique ingredient of 5 kg and
       is settled (closed and its closing count submitted as it stands, so a locked closing exists); the admin schedules the "E2E Night" template (22:00 to 06:00, every day,
       found or made) for **yesterday's local date** with the bread baker, then presses Start now on the roster in the browser; the baker's bar names the Night shift of
       yesterday's date, the opening count shows 5000 prefilled from the locked closing, is submitted, and the baker writes a note, closes, counts "nothing changed" and the
       count locks and shows its hash; "Start shift" then opens the next shift with the Night closing values and the note in the handover panel. The API checks: the Night
       shift's `date` is yesterday while its `opened_at` is today and its `starts_at` is yesterday 22:00 local; `GET /shifts?station_id&from&to` lists both; each opening line's
       `carried_qty` is the previous closing's `counted_qty`; the closing's `previous_hash` is the `payload_hash` of the link before it; verify is ok.
       **Midnight and `@live`.** The test does not control the clock and does not need to: yesterday and today come from the Addis Ababa date at run time (`addisDate`), and the
       dates are asserted from the shifts themselves. It is therefore `@live` and runs after every deploy. The one place the clock leaks in is the *next* shift: started from
       "Start shift" between 22:00 and 06:00 local it matches the Night window and is dated by the day that window started (yesterday after midnight), at any other time it is the
       ad-hoc shift dated today; the test reads the rule from the shift (`origin`, `template`, `starts_at`, `opened_at`) and accepts exactly those two outcomes. The instant-level
       boundaries (23:59, 00:01, 05:59, 06:00) stay in Pest (`CrossMidnightGateTest`). A second flow, `e2e/p3/handover-admin.spec.ts` (`@live`, project `p3-handover`), makes,
       edits and retires a run-unique template in the browser, then checks the overview, the chain verification and the replay for the bakery.
       **Decisions the plan left open.** (1) The tabs are separate static pages under `/admin/shifts/` (the queue stays on the overview, so 3.7b's test is unchanged), not one page
       with client-side tabs. (2) Stations for the pickers come from `GET /me` (`work_stations`: every station for `stations.all`), so a manager needs no `people.manage`; only the
       roster's people list (`GET /users`) is admin-only, which matches scheduling being `shifts.manage`. (3) "Stock places with no station" lists every such place, the display
       case included (the E2E branch's has none by design); the fix link goes to Inventory because there is no location editor in the web yet. (4) The roster cell offers
       "Schedule" whenever no shift of that template and date is waiting or running; the API's `409 shifts.shift.already_scheduled` stays the rule. (5) The history's chain view is
       per place and skips display cases (they have no count). (6) Retiring a variance reason is `is_active: false`, never a delete.
       **For 3.7d and 3.7e.** The admin screens never touch cash: the drawer's declaration is still 3.7d's (the review page and the count screen keep the "next update" lines).
       The roster's "Start now" and the ad-hoc starts from the bar both leave an `opening` shift when "Start without counting" is off, so with a cash drawer 3.7e's fixtures must
       still declare it. The running-shifts feed uses the API's own ETag (count, `max(updated_at)`, `sum(version)`), no ledger part is involved. Local note: the Docker stack on this PC answers in 2 to 9 seconds per request, so the browser flows only finish there with
       longer waits (a copy of the specs with larger timeouts passed up to the last submit); CI and the live host are the evidence.
       **purchase-transfer on live (from the 3.7b deploy, run 39939310713).** The trace showed both attempts spending the whole 120 s before the first browser action: `retireStaleIngredients` deleted the earlier runs' E2E ingredients one request at a time (about 400 of them at 0.6 s) inside the test's own budget. It now asks the server only for active ingredients (`is_active=1`), retires four at a time (each request keeps its own Idempotency-Key, a refused delete still falls back to the PATCH), and runs in a `test.beforeAll` with its own 300 s budget. The next live deploy proves it.
       CI evidence: see the PR.
   - **3.7d `phase-3/web-cash`**: the till's shift widget (opening float by denomination, cash so far by channel from
     `GET /shifts/{shift}/cash`), the cash declaration and channel reconciliation inside the closing count, the second test of
     `p3-handover`.
     - **As built (step 3.7d, `phase-3/web-cash`).** Web plus one small API change (`GET /shifts/{shift}/cash` now polls: an ETag made from its own figures, 304 while nothing is taken; `CashEndpointsTest`). Payments and Shifts stay `stable`; no flag changed (all three stay false everywhere).
       **Till widget** (`CashWidget`, mounted under the shift bar on `/till`, feed `lib/live/cash.ts` over `GET /shifts/{shift}/cash`, 5 s / 30 s, ETag): four figures (the opening float with its notes and coins when the shift has an opening count, "carried from the last closing" when it started carried; cash taken so far; what the drawer should hold; the float the last closing left) and the cash taken by channel (receipts and sum). It shows while a till shift runs; a station with no drawer (422) shows nothing.
       **Declaration** (`CashDeclaration`, pure rules in `lib/cash.ts`, tested without a browser in `e2e/p3/handover-cash-logic.spec.ts`): fills the `drawer` case of `CountPanel`. Every configured denomination is a row (largest first, an empty field is none, digits only, amount per row), saved when a field is left (`PUT /stock-counts/{count}/cash` always carries the whole tally plus only the part that changed, one Idempotency-Key per payload), with the counted total worked out as it is typed (`decimal.ts`, never a float) and the server's expected and variance beside it; "Confirm this count" declares an empty drawer (`{}` is every count zero). A closing adds the float left (typed amount, at most the counted total, "to take to the bank" worked out), the channel reconciliation (a row per channel that took money: taken by the till, receipts and sum counted, difference; both fields or neither, and a channel not counted never differs) and, when the cash or any channel differs, one reason and a note where the reason asks for one. "Review and submit" opens `CashReviewDialog` (the figures as stored, and what blocks the submit: not declared, no float, float above the count, no reason, no note; the same four things the API refuses). A handed-in or locked drawer count reads as a record (`CashSummaryView`).
       **Shift bar.** At a till in the `opening` state, "Declare the drawer" sits next to "Open the count" and opens the same declaration in a dialog (`DrawerDialog`); the count itself is handed in from the count screen. **Approval review.** The drawer count shows "Cash, side by side" (opening float and cash taken at a closing, expected, counted, difference, float left, to the bank, the notes and coins, the channels declared against taken, reason and note), and the chain card of a locked count as before; a drawer count has no lines table.
       **p3-handover test 2** is real (`@live`-capable, run-unique through the fixed cashier, a kitchen product and the E2E branch's till): it makes or finds the till's cash drawer (`ensureDrawer`), settles the till, the cashier starts a shift and declares the drawer by denomination (what the last closing left, 500.00 the first time), the submit locks it, a 60.00 cash payment shows in the widget, the closing is 20.00 short with the cash slips 10.00 short, the review refuses without a reason, the admin sees the cash side by side and approves, the drawer's chain verifies to the count's hash, and the next opening shows "Float left in the drawer: 10.00", expects exactly that and is declared. `settleStation` and `finishDrafts` (`e2e/support/shifts.ts`) now declare a draft drawer count as expected (greedy notes and coins, float 0.00) before submitting it.
       **Decisions the plan left open.** (1) The widget lives under the bar on the till page, not inside the bar, so the bar stays the same component for every station. (2) The declaration saves per field on blur rather than with one "Save" button, like the stock lines; the whole tally is always sent because the API takes it as the whole count. (3) A channel row needs both receipts and sum or neither (the API takes both); only channels the till took money in are listed, so there is no row to declare a digital channel nothing was taken in until Phase 4. (4) The first opening of a drawer ever has no expected figure and is taken as counted (the API answers expected equal to counted). (5) `GET /shifts/{shift}/cash` has no cheap fingerprint of its own (payments are not a cursor feed), so its ETag is a hash of the answer it would give: two small reads per poll, 304 instead of the body.
       **For 3.7e.** `ensureOpenShift` starts carried, which opens a till shift without any declaration, so the fixtures that only need a till shift (cash payments with `PAYMENTS_REQUIRE_SHIFT_FOR_CASH` on) work unchanged; only a counted start ("Start shift", roster "Start now", ad hoc) at a till with a drawer needs the declaration, and `finishOpeningCounts` / `finishClosingCounts` now make it. A till has a drawer only where one was made: the E2E branch gets one when `p3-handover` test 2 first runs (`ensureDrawer`), the live demo branch has one from the demo seeder. `Phase3Seeder` and `DemoShifts` must still declare the drawer when they open the till shift. Set `NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH=true` in the web build together with the three flags. Local note: the Docker API on this PC answers in 3 to 15 seconds, so the till page's 20 s read limit cancels its own polls and the browser flow cannot finish there; the logic specs, `tsc`, lint, the static build and `CashEndpointsTest` ran locally, the browser flow is CI's.
       **Two fixes that came with the step (from the 3.7c deploy, run 39959438823).** (A) *A draft could never be closed once an item on it was deleted.* `retireStaleIngredients` soft-deleted earlier runs' ingredients that were lines on the kitchen's closing draft, so every submit answered 409 `shifts.count.item_unavailable` ("reject the count and count again"), which a draft cannot do; a shop that deletes an ingredient while a count is in draft hit the same dead end. `SubmitCount` now drops, inside its transaction and before `assertComplete`, the lines of the draft whose items were deleted from the catalog (as `assertComplete` already leaves out a deleted item that is not on the count), writes an audit event `count.lines_dropped` with the item ids and each line's figure, and answers `dropped_item_ids` next to the count; the count screen shows a short bilingual notice. No movement is written and nothing about the ledger changes. This needed a migration (`2026_10_27_100100_allow_deleting_draft_count_lines`, both engines): the `stock_count_lines` DELETE trigger refuses a delete only when the parent is not a draft. `ApproveCount` keeps its 409 for a pending count with a variance line of a deleted item (reject is possible there). Tests: `DeletedItemCountTest` (dropped, reported, audited, rest unchanged), `LinesFreezeTriggerTest`. (B) *The live run hit `429 /login`.* `Api.login` (`e2e/support/api.ts`) caches the login per origin, phone and branch for the whole worker and logs in again only on a 401 (then retries the request once); `e2e/p3/handover-login-cache.spec.ts` proves it against a local fake API. The server's rate limit is unchanged.
       CI evidence: see the PR.
   - **3.7e `phase-3/enforcement-on`**: the flip. `config/ledger.php`, `config/shifts.php` and `config/payments.php` default to `true`;
     `.env.example` and `.env.ci` carry the variables; `compose.ci.yml` drops its now-redundant override (the default is what it runs) and the `enforced-gates` job becomes the flags-off `rules-off-gates`; the runbook gets the kill switch (section 10.1); `DeployableCachesTest`. The PR
     changes four lines and no behaviour the rehearsal did not already exercise. After the merge the deploy runs every `@live`
     flow of phases 1 to 3 against https://bakery-ops.orcamind.dev with enforcement on, which is the last evidence. Ends with
     `web: done`.
     - **As built (step 3.7e, `phase-3/enforcement-on`).** The flip, plus three fixes the live run after the 3.7d merge asked for. Shifts, Payments and Inventory stay `stable`; `web: done`.
       **Order.** (1) The rehearsal went first, in the same PR: `compose.ci.yml` (app and worker), the `enforced-gates` job and the CI web build (`NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH=true`) turned on all three rules. That commit was red in four jobs: `e2e-p1`, `e2e-p2` and `e2e-p3` (the stack could not even seed: `Phase1Seeder` takes demo cash as the cashier with no shift, `payments.shift.required`) and `enforced-gates` (68 failed: 66 rounds of `OrderLedgerPropertyTest`, `OrderMovementsGateTest` and `StockOutToProductionGateTest`, all the same cash payment). The module jobs were green because the flags were still off there. (2) Fixtures: `Phase1Seeder` pays in the till's running shift (`ActorContext::inShift(Shifts::current(till))`; `DemoShifts` already opens it carried, so no drawer declaration is needed); `TestsSupportOrderFloor` assigns the cashier to the till (the floor already opened the till's shift); `TestsSupportShifts::enforced()` is true when either the ledger or the cash flag is on, so `ensureOpen` runs for the cash rule too; the property test sets the actor's shift to the till's before paying. The Playwright fixtures needed no change: `ensureTill` already starts the till's shift (carried), as 3.7d noted. (3) Green at 044204f with the rehearsal still in place: every module job, `enforced-gates`, `e2e-p0` to `e2e-p3`, gate-p0 to gate-p3. (4) The flip: the three defaults are `true` (`config/ledger.php`, `config/shifts.php`, `config/payments.php`), `.env.example` and `.env.ci` say `true`, the compose overrides are gone, `deploy.yml` builds the static export with `NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH=true`. The tests that pinned the defaults off now pin them on and set the flag off in-process where they test the off behaviour (`LedgerEnforcementTest`, `CallInTest`, `PaymentRequiresShiftTest`).
       **Decisions the plan left open.** (1) `enforced-gates` is kept but turned round and renamed `rules-off-gates`: it runs the Phase 0-2 gate tests with all three variables `false`, which is the state of the live host after the kill switch, so the switch is proven to leave a working system; everything else runs with the rules on. (2) The kill switch is three lines in the server `.env` and `php artisan config:cache` (runbook, "Shift rules and the kill switch", one section replacing the three per-flag ones). The web variable is a build-time convenience: with the cash rule off on the server the till's Pay button still waits for a shift until the next build sets it `false`. (3) `DeployableCachesTest` gained one test: `config:cache` succeeds with the variables unset (all three true), all `false` and all `true`, and the cached file carries those values. (4) The plan's "four lines" estimate was wrong: the flip touches three config files, two env files, two workflows, compose, the runbook, STATUS and the tests above, because the defaults were pinned in tests.
       **What staff see.** At a tracked station with no shift the shift bar offers Start shift and orders, stock moves and batch confirmations are refused until one runs; the till needs its shift for cash. The E2E branch's tracked stations already carry carried shifts from the fixtures. The demo branch (Demo Bakery) has no open shifts, so a first-time demo user must start a shift from the bar at the till, kitchen and bar before taking orders; rerunning `bakery:seed-demo` on live would open carried shifts at every tracked demo station (it is not run by this PR; it is the owner's call).
       **Three fixes asked for after the 3.7d deploy (run 40003438901), each its own commit, test first.** (A) *A switched-off ingredient with stock blocked the count.* `SubmitCount` requires every item with stock at the place, `SetCountLines` refused a switched-off item with `422 shifts.count.item_unknown`, so an ingredient switched off while it still had stock made the station's count impossible. Rule: count what is on the shelf. The draft builder already listed every item with stock; `SetCountLines` now accepts a switched-off item that has stock at the counted place, and still refuses a switched-off item with no stock there, a deleted item and a made-to-order product. Nothing is written to the ledger. `SwitchedOffItemCountTest`. (B) *`429 /login` on live.* The per-worker cache was lost whenever a failed test restarted its worker. `e2e/support/global-setup.ts` names a per-run file in the system temp folder (`E2E_TOKEN_FILE`; never under `test-results`, which CI uploads) that `Api.login` reads and writes, and a 429 now waits for `Retry-After` (60 s when absent) and retries once. The server's limit is unchanged. (C) *The reorder rules table never showed this run's rule.* The cause was in the API: `GET /reorder-rules` answered 500 once any rule belonged to a soft-deleted item (`ReorderRuleResource` reads `$rule->item->...` on null; 3.7b's `retireStaleIngredients` deletes items that have rules), so the table did not render at all. The list now leaves out rules of deleted items (the reorder list already did); rules of switched-off items stay. `ReorderRulesEndpointsTest`.
       CI evidence: see the PR.
8. **3.8 docs and seeds** (STATUS `docs`), `phase-3/gate`: `Phase3Seeder` and the `DemoSeeder` clock anchor (section 8), ADR 0012
   and 0013 accepted and the ADR 0009 and 0010 notes, `docs/openapi.json` and `docs/erd.dbml` final, `docs/runbook/server.md` (the
   Phase 3 section: the kill switch, the nightly verification, what to do when the chain reports a break, the pilot day-one
   checklist), `domain-model.md`, `dependency-graph.md`, `invariants.md`, `traps.md`, `testing-strategy.md`, `ci-cd.md`,
   `workflows-and-screens.md` and the phase reference corrected where this plan departs, the `gate:p3` tests, the pilot feedback
   template (`docs/phases/phase-3-pilot-feedback-template.md`), the gate report, STATUS `claim-done`.
   - **As built (step 3.8, `phase-3/gate`).** Commits: `Phase3SeederTest` red first, then the seeder, the gate tests, the ADRs and references, the runbook and the pilot
     template, the report and STATUS (`docs` done, phase 3 `gate_pending`; `claim-done` is the orchestrator's). No route, table or permission changed, so `docs/openapi.json` and
     `docs/erd.dbml` are unchanged and in sync. Where the build settled what section 8 left open or got wrong:
     - **The anchor decides on the branch, not on shifts alone.** Section 8 asked "does the demo branch already have any shift instance"; the live demo has none and holds Phase 0-2 movements, so
       that test would have anchored it and backdated history under rows the ledger has shown. `Demo\DemoClock::wantsAnchor` anchors only when Phase 3 is requested **and** the demo branch does not
       exist yet or holds neither a stock movement nor a shift. `DemoSeeder::run` and `SeedDemoCommand` both run the seeders inside `DemoClock::around`. On any other branch the clock is never touched
       and `Phase3Seeder` writes no history, does the "today" part and prints `history not written` with the reason. Tested: seed Phases 0-2, travel a day, `bakery:seed-demo --phase=3`, nothing
       older than the newest movement, no closing count, no `count_adjustment`.
     - **Sample orders are keyed by the real day.** Phase 1 keyed its orders by the local date of "now", which on an anchored branch is three days ago, so a second run on the same real day would have
       added seven new orders and "a second run writes nothing" would have failed. `Phase1Seeder` now takes the key date from `DemoClock::dayKey` (the real day while anchored, else today as before); a run
       on a later day still adds that day's orders.
     - **The history as written.** Anchor day D-3 05:30 local (Phases 0-2), closed and locked 21:30; D-2 and D-1: Day shifts started 06:02 (inside the template window), waste 07:30, a transfer from
       the store 08:30 (oil to the kitchen on D-2, sugar to the bar on D-1), three cash orders (09:40, 10:50, 12:10) through the new `Demo\DemoOrders` (pad, stations, serve, pay with tender and change), the
       kitchen note 13:30 on D-1, closings 14:05; the bakery Night shift is scheduled on D-2 by the admin with the bread baker on its roster, started 22:05 and closed 05:55. Planned differences as in
       section 8 (500 g of flour, +3 of the first product with stock at the bakery, 20.00 short in the drawer, 250 ml of milk pending). 21 shifts in all (15 locked, 1 pending approval, 5 open). A decision is
       always at least two minutes after the snapshot it decides on (`DemoClock::tick`), so `balanceAt(snapshot_at)` never includes the count's own adjustment.
     - **Today's shifts are planned on the Day template and started by the admin** (`start` with the scheduled instance and `force`), whatever hour the seed runs: starting by the window would pick
       the Night template and yesterday's date for the bakery before 06:00. The station's worker makes the opening count and declares the drawer (the float the last closing left); the bar starts
       carried while its closing waits. Every station worker joins the roster.
     - **`DemoOrders` and `DemoShiftFlow` are new; `Phase1Seeder` was not refactored to share code with them** (a pure extraction would touch a seeder whose behaviour two gates depend on; the duplication
       is the order walk of about 40 lines).
     - **Not every seeded row carries a shift id.** The assertion "every seeded movement is inside a shift" holds for what people do at a station (waste, sales, consumption, count adjustments); a purchase
       into the store, a transfer leg and a reservation are exempt by rule E1 or sit at an untracked place, and keep a null shift (ADR 0013, as built).
     - **Gate tests.** Existing tests tagged `gate:p3` in place: `HashChainTest` (the tamper cases), `ShiftAttributionTest`, `PresenceResolverSwapTest`, `ReplayPropertyTest` (random histories),
       `LedgerEnforcementTest`'s matrix, `CashDeclarationTest`, `ReplayEndpointTest`, `CountStatusTest`, `ShiftStatusTest`, `ShiftWindowTest` (the cross-midnight table) and `CashMathTest`. New in `tests/Gate/`:
       `EnforcementDefaultGateTest` (the shipped defaults through HTTP: refused without a shift, allowed in one, refused while counting, the store never asked) and `ReplaySeededHistoryTest` (the seeded
       half of the replay property: every tracked station at the midpoints and at every closing's snapshot, balances against an independent fold, the held shift, the people on duty and the last link).
       The plan's `Phase3StateMachinesTest`, `CrossMidnightGateTest`, `CashDeclarationGateTest`, `HandoverGateTest`, `Phase3ActorGateTest` are not separate files: the state-machine, window and cash tables
       exist as the unit and feature tests above, and the handover path is `p3-handover` itself.
     - **ADRs 0012 and 0013 accepted**, each with an "As built" section; ADR 0012's file is `0012-hash-chain-canonical-json.md` (the manifest's name; it was written as `...-and-canonical-json`).
       Dated notes on 0008, 0009 and 0010. The Phase 3 to-do marker of the 3.7b note is reworded (the gate forbids the literal).
     - **Docs.** Runbook: the Phase 3 demo paragraph (fresh branch versus the live one), "Nightly chain verification" with what to do on a break, the pilot day-one checklist; references corrected
       (domain-model tables and state machines, dependency-graph, invariants, traps, testing-strategy, ci-cd, workflows-and-screens, the phase reference with an as-built list); the pilot feedback template.

As-built notes are appended under each step as the build runs, as in Phases 1 and 2.

## 7. Test list

- **Contract** (step 3.1, extended at 3.5): the four files of section 2 (15 + 14 + 9 + 5 cases once 3.5 lands). They run in
  `mod-inventory`, `mod-identity`, `mod-payments` and `mod-orders` for the life of the project.
- **Unit**: `ShiftStatus` and `CountStatus` exhaustive tables (every `from x to` pair, picked up by
  `tests/Unit/StateMachineExhaustiveTest.php`); `ShiftWindow` (the table of section 10.2: Night 22:00-06:00 at 21:59, 22:00,
  23:59, 00:00, 00:01, 05:59, 06:00; Day 06:00-14:00 at 13:59 and 14:00; a Friday-only Night on Saturday 01:00 and on Saturday
  23:00; two overlapping templates at one station and the tie-break; the local date when UTC and local dates differ; a custom
  template that crosses midnight and ends exactly at midnight; start equal to end refused); `LocalClock`; `CanonicalJson`
  (section 10.3: key order, list order, scale, negative zero, escapes, UTF-8, timestamps, the pinned vectors); `PayloadBuilder`
  (a count and a cash count to the exact canonical string); `Denominations` (the totals in `Money`, unknown denominations,
  counts as integers only); `VarianceMath` (counted minus expected at 4 dp, signed, the adjustment of an unlock revision is
  against the counted value of the superseded one); `DraftBuilder` (which items get a line); `DefaultVarianceReasons`
  (idempotent, leaves an edited reason alone); `NotificationText` rows for the three types; `Recipients` ordering for the
  new answers.
- **Feature, one file per endpoint** (`module:shifts|identity|payments|inventory`): happy path; validation; `403` for every role
  without the permission (the waiter on every Phase 3 route, the cashier on a kitchen shift, a baker at the wrong station,
  the chef on `approve`, the manager on `unlock`, the cashier on `replay`); idempotent replay (same key twice: same body,
  one row; same `client_uuid` with a new key: the same row); branch isolation on every resource (another branch's shift, count,
  template, reason is `404`). Specifics: templates (cross-midnight window accepted, equal times refused, soft delete keeps old
  instances); scheduling and roster replace; `start` for every path of the "how a start works" paragraph (replay, resume, already
  open `409`, scheduled one, window, grace, force by admin and refused for a manager's `force`, ad-hoc, carry, a station that
  does not track shifts `422`); `open-ad-hoc` and the join of a second person; `close` (freezes: a sale and a transfer into the
  station after it are `409 inventory.shift.required` with the flag on; the drafts exist; a repeat is `200`); the summary (movements
  by shift, cash at the till, open work as a warning); counts: draft lines (prefill from a locked closing; a provisional one; an added item),
  `lines` validation (negative, scale, unknown item), `confirm_all`, submit (incomplete, reason required, note required, the
  snapshot equals the ledger under lock, unconfirmed line, an item that got stock while counting, R-A blocked), cosign (the
  counter refused, an overseer without `shifts.cosign` accepted, a flagged overseer of another station refused), approve (the
  adjustments, the stamp, both signs, `adjustment_insufficient`), reject (the recount), unlock (the new revision, the delta), cash
  (denominations, no drawer, float too large, unknown denomination, the expected figure), the chain read and verify, notes
  (append-only, `to_user_id`, a locked shift takes none), handover, replay (future refused, a bare time read as local), `GET /me`.
  `GET /stock-counts` and `GET /shifts?running=1` are polled: `304` with a matching `If-None-Match`, the tag changes on a late
  commit below the highest id (the `OverlapCursor` rule applies to `handover_notes`).
- **Queue pairs** (traps.md, "Queues in e2e"): for every queued listener one test with `Queue::fake()` asserting dispatch (class,
  `afterCommit`, default queue) and a `sync` twin asserting the effect: submit -> `CountSubmitted` -> `NotifyCountSubmitted` ->
  inbox lines for exactly the right people with the actor of the submit; approve -> `VarianceDecided` -> the counter's line; a
  duplicate delivery creates nothing; a tampered chain -> `ChainBroken` -> the admins' lines; `EnsureDefaultVarianceReasons`
  (a new branch has five reasons, a second event adds none).
- **Property** (`property`, `module:shifts`, `gate:p3`; seeded generators, at most 50 operations, seed printed on failure, a
  `SHIFTS_SEED=<n>` environment variable reruns one):
  - `CountsLedgerPropertyTest`: random purchases, sales, consumption, waste, transfers and reservations at a small bakery with
    two stations, interleaved with random opening and closing counts (random variances, some rejected and recounted, some
    unlocked and corrected) and approvals. After every step: movements == balances == view (the Phase 0 oracle); no `on_hand` or
    `reserved` below zero; for every count `expected_qty == Stock::balanceAt(location, snapshot_at)` for the line's item; for every
    approved count with nothing moving since, the ledger equals the counted figure, and with movements since it equals the counted
    figure plus exactly those movements; for every opening count `expected_qty == carried_qty + netChangeSince(previous closing,
    excluding its adjustments)`; the sum of a count's adjustments equals the sum of its accepted variances; at most one unresolved
    count per place; a rejected count wrote nothing; the chain of every place verifies.
  - `ReplayPropertyTest` (3.6): over the `Phase3Seeder` history and over random histories, 200 random `(station, instant)` samples:
    `Replay::at` balances equal an independent oracle written in raw SQL from `stock_movements` (not the code under test); equal the
    `expected_qty` of any count whose `snapshot_at` is that instant; the open orders equal an oracle read from `order_events`; the
    people on duty equal an oracle read from the roster rows; counts' `status_at` follow their timestamps.
  - `HashChainPropertyTest`: random chains of random payloads verify; flipping any one byte of any stored `canonical_payload`,
    `payload_hash` or `previous_hash`, dropping any link, or swapping any two is detected and the first bad `chain_seq` is named.
  - `CashPropertyTest`: random payments with tender and change at the till: expected = opening + the sum of `amount` (never
    tendered, never change); the denominations total equals the sum of denomination x count; the variance sign is counted minus
    expected; the channel table's sums equal `Totals::byChannel`.
  - `ShiftWindowPropertyTest`: random templates (including ones that cross midnight) and random instants: the matched window
    contains the instant (start inclusive, end exclusive), the date is the local start date of that window, the same instant maps
    to the same window twice, and instants in one window never get two dates.
- **Concurrency** (`concurrency`, `CommitsForConcurrency`, real processes through `Tests\Support\Forks`, `Innodb_deadlocks`
  compared before and after every round, 30 rounds each, as `TransferRaceTest` does):
  `StartRaceTest` (two starts at one station: one `201`, one `409`, one row, the loser can then join); `JoinRaceTest` (one roster
  row); `CloseVersusMovementRaceTest` (a close racing a sale at the same station: either the sale committed first and the count's
  expected figure includes it, or it is refused `409 inventory.shift.required`; never a movement stamped after the freeze and
  never one missing from the expected figure; this proves the shared lock on the shift row against the exclusive one of close);
  `SubmitSnapshotRaceTest` (a purchase racing a submit: `expected_qty` equals `balanceAt(snapshot_at)` for every counted line);
  `ApproveRaceTest` (one count approved twice at once: one set of adjustments, the second `200`; two counts of different places
  approving at once in opposite order of items: no deadlock); `LockChainRaceTest` (two counts of one place locking at once get
  `chain_seq` n and n+1 in order, both verify; the first lock of a place racing another is decided by the unique key, with no
  `FOR UPDATE` on a missing row); `ApprovalAgainstSaleRaceTest` (a sale racing an approval's adjustment for the same stock: the
  ledger lock order holds). The lock order these prove: domain row (order, purchase, transfer, count) -> `shift_instances` ->
  `stock_balances` ascending -> `item_costs` ascending.
- **Arch / invariants**: `ModuleBoundariesTest` (Shifts per step 3.2a; no module imports Shifts; Inventory and Identity import
  Shared's `OpenShifts` only; Shifts imports no Production or Notifications class); the existing arch rule that every Notifications
  listener is queued; `AppendOnlyTest` + `handover_notes`; `LockedRowsTest` and `StockCountsTriggerCoversEveryColumnTest` (migration
  12); `LinesFreezeTriggerTest`; `ShiftStampForeignKeysTest`; `NumericColumnsTest` (the new `*_qty` and `*_total` columns);
  `BilingualColumnsTest` (`shift_templates`, `variance_reasons`); `BranchIdTest`; `SoftDeletesTest` and `ActorStampTest`
  allowlists with their reasons; `OctaneSafetyTest` (`OpenShiftsReader` is `scoped()`, no static state in the new namespaces);
  `RoutePermissionMatrixTest` and `IdempotencyCoverageTest` over every new route; `DeployableCachesTest` with `config/ledger.php`,
  `config/shifts.php` and `config/payments.php`; `EnforcementConfigTest` (the three keys read one environment variable);
  `NoStaticStateTest` for `Hash\CanonicalJson`; `MiddlewareOrderTest` (`ResolveActor` still runs before binding and fills the
  shift before any controller).
- **Seeds**: `Phase3SeederTest`, `DemoSeederTest` extended, `Phase0SeederTest` and friends extended, section 8.
- **Playwright** (`apps/web/e2e/p3/`; built app, `serviceWorkers: 'block'`, one context per role, waits on API state and never on
  timers). Both flows are `@live`-safe: they build their own fixture through the admin API of the isolated "E2E Test Branch" with
  the helpers in `e2e/support/shifts.ts` (new, built on `api.ts`, `env.ts`, `fixture.ts`) and read their post-conditions through
  the API, so they run on the CI compose stack and against https://bakery-ops.orcamind.dev after each deploy. Fixed staff
  accounts are the existing ones (chef `+251911000201`, cashier `+251911000204`, manager `+251911000205`, bread baker
  `+251911000207`) found or created by phone; no new account is needed. Every fixture first runs `idleOrOpen(admin, station)`:
  it finds the station's running shift, resolves any count a failed earlier run left pending (as the admin approving the chef's
  count, which the rule allows), and leaves the station with an **open** shift and nothing unresolved, so each flow starts from
  a known state on a database that has run for weeks.
  - `p3-handover` (`handover.spec.ts`), two tests, three contexts (chef, admin, and the API sessions):
    1. *"kitchen closing count with a variance, approval, lock, hash and the next opening prefilled @live"*. Fixture: the kitchen
       station and location (found or created), the chef assigned with `can_count`, `requires_overseer` false (asserted), a
       run-unique ingredient (kg item) bought in at 10 kg (base units: 10000.0000 g) through `POST /stock/movements` after
       `ensureOpenShift(kitchen)`. Flow: the chef opens `/kds/kitchen`, the shift bar says the shift is open, **Close shift** -> the
       closing count lists the fixture's ingredient with expected `10000` -> "Nothing changed" for every line, then the ingredient
       edited to `9500` -> **Submit is refused until a reason is chosen** (asserted: the inline message, in the page language)
       -> reason "Spoilage" -> Submit -> "Waiting for approval". API: the count is `pending_approval`, the line's variance is
       `-500.0000`, no movement yet. The admin opens `/admin/shifts`, the approvals tab shows the count; the side-by-side row reads
       expected `10000`, counted `9500`, variance `-500`, reason Spoilage -> **Approve** with a note. API: the ledger of the
       ingredient at the kitchen is `purchase_in`, `count_adjustment` with qty `-500.0000`, `ref_type = stock_count_line` and the
       counted shift's id; the count is `locked` with a 64-hex `payload_hash`; the screen shows the hash; `GET
       /locations/{kitchen location}/chain/verify` answers `ok` and the chain is one link longer; the shift is `locked`. The
       chef presses **Start shift** -> the opening count shows the ingredient **prefilled with `9500`** (`carried_qty`) and every
       other line with its locked value -> "Nothing changed" -> Submit -> the shift is open; API: the new shift's `date` is
       today's local date, `opening_mode = counted`, every opening line's `carried_qty` equals the `counted_qty` of the locked
       closing line. After the chain of events, `waitForQueue` finds the admin's `count_submitted` and the chef's
       `variance_decided` inbox lines (30 s in CI, 150 s live).
    2. *"the till's cash declaration by denomination with a variance and the channel reconciliation @live"*. Fixture: the till
       station and a `cash_drawer` location (found or created), the cashier assigned to the till, an open till shift whose
       opening float of `500.00` (`{"100": 5}`) is declared through the API, a display-case croissant as in `buildFlowFixture`,
       one order sent by the fixed waiter and **paid in cash by the cashier in the browser** (tender 100.00, 60.00 due, so
       change 40.00 is given back). Flow: the cashier opens `/till`, the widget shows the float, cash so far `60.00` by channel
       (one cash receipt) and expected `560.00`; **Close shift** -> the cash declaration asks for denominations: `{"200": 2, "100": 1,
       "50": 1}` = `550.00` -> the screen shows variance `-10.00` and asks for a reason -> "Miscount" -> the channel table shows cash,
       1 receipt, `60.00` declared 1 and `60.00` -> Submit. The admin approves; API: `expected_total 560.00`, `variance_total
       -10.00`, `cash_sales_total 60.00` (the change given back is not in it), the lock includes the cash block (the canonical
       payload names `560.00`), chain verified.
  - `p3-opening-prefill` (`opening-prefill.spec.ts`), one test, three contexts (bread baker, admin, API): *"a Night shift scheduled
    for yesterday is started late, closed, and the next shift opens prefilled; dates are the local start dates @live"*. Fixture:
    the bakery station and location, the bread baker assigned, a Night template "E2E Night" 22:00-06:00 on every day for the
    bakery (found by name or created), a run-unique ingredient of 5 kg at the bakery; `idleStation` closes and approves whatever
    runs there, so there is a locked closing to prefill from. Flow: the admin schedules the Night template for **yesterday's
    local date** with the baker on the roster (`POST /shifts`), then in the browser presses **Start now** on that shift (the
    admin's `force`, the only way to start a window that is over); the baker opens `/production`, the shift bar names the
    Night shift **of yesterday's date**, the opening count is prefilled from the locked closing and shows `5000` for the
    ingredient -> Submit; the baker writes a handover note and closes -> counts unchanged -> no variance, so the count locks at
    once and shows its hash; the baker presses **Start shift**, the day's ad-hoc shift, and its opening count shows the Night
    closing values and the note in the handover panel. API: the Night shift's `date` is yesterday (computed in the test with
    `Intl` for `Africa/Addis_Ababa`) while its `opened_at` is today; its `starts_at` is yesterday 22:00 local; the next shift's
    `date` is today; `GET /shifts?station_id=&from=yesterday&to=today` lists both; each opening line's `carried_qty` equals the
    Night closing's `counted_qty`; the closing's `previous_hash` equals the previous link's `payload_hash`; verify is `ok`.
    **Why this is the cross-midnight test on a live site:** a Playwright test cannot make the server clock say 00:01. The
    instant-level boundaries (23:59, 00:01, 05:59, 06:00) are proven in Pest with `travelTo` through the HTTP layer
    (`CrossMidnightGateTest`, below); this flow proves the date semantics end to end (a shift that started yesterday and is worked
    today keeps yesterday's date and the prefill crosses the boundary) without needing the clock.
  - Until their screens land, each project holds one `test.fixme` placeholder that names its step and carries a phase-3 TODO
    marker (3.1a); `p3-handover` test 1 replaces its placeholder at 3.7b, test 2 at 3.7d, `p3-opening-prefill` at 3.7c.
  - **What the waits are.** As in Phase 2: the only thing the flows wait for is the notification inbox lines
    (`waitForQueue`, the same helper); everything else is synchronous. The count queue, the admin queue and the shift bar are
    polled reads, so a flow waits on them with the API-state helpers the Phase 1 flows use.
- **Gate** (`tests/Gate/`, group `gate:p3`): `Phase3StateMachinesTest` (the two exhaustive tables and the CHECK lists equal the
  enums), `HashChainTest` (tamper detection: the cases of 3.4, one per column and per link operation), `ShiftAttributionTest`
  (movements, orders, payments and requests made during an open shift carry its id; an adjustment carries the shift it
  corrects; queued work carries the shift of the movement that caused it), `PresenceResolverSwapTest` (the auto request for a
  sold-out cake targets the people on the running shift first; with nobody on it, everyone assigned), `EnforcementPathsGateTest`
  (one case per row of the table in 10.1: refused at a closed station with the exact key, allowed at an open one, and with the
  flag off allowed everywhere), `HandoverGateTest` (the whole HTTP path of `p3-handover` test 1 on the seeded database),
  `CrossMidnightGateTest` (Night 22:00-06:00 through `POST /stations/{id}/shifts/start` with `travelTo` at 23:59 Friday, 00:01
  Saturday, 05:59 Saturday, 06:00 Saturday, and the day-of-week bit of a Friday-only template), `CashDeclarationGateTest` (the
  worked tables of 10.5 through HTTP), `ReplayGateTest` and `CountsLedgerPropertyTest` and `HashChainPropertyTest` re-tagged
  `gate:p3`, `Phase3ActorGateTest` (every row written by an approval or by queued work names the right person and shift). The gate
  also re-runs `gate:p0`, `gate:p1` and `gate:p2` twice in CI: once as they are, once in `enforced-gates` with the flag on, which is
  the "Phases 0 to 2 gates still green with the updated seeds" criterion made mechanical.

## 8. Seeds

`Phase3Seeder` (step 3.8) extends the demo bakery and never rewrites `Phase0Seeder`, `Phase1Seeder` or `Phase2Seeder`: same
branch, same demo admin as actor, same idempotence (natural keys and deterministic client UUIDs: a shift's is the UUIDv5 of
`demo-shift:{station code}:{date}:{slot}`), every write through the module code the API uses (`Shifts`, `Counts`, `Locks`, the
Ledger, `Payments`, `Orders` actions through the `Demo\DemoOrders` helper extracted from `Phase1Seeder`), never a raw insert into
a shift, count, lock or ledger table. It runs the queued listeners synchronously for its duration (it sets `queue.default` to
`sync` and restores it, as `Phase2Seeder` does), so the demo has real inbox lines without a worker.

**Two days of locked history, and why the clock has to move.** A locked history is only consistent with the ledger if its
movements happened before its counts, and the Phase 0 to 2 seeders write the opening stock "now". So the history cannot be
written into the past of a branch that was just seeded. The fix is small and lives in the seeding entry point only:
`DemoSeeder::run()` (and `bakery:seed-demo`) asks `Demo\DemoClock` whether the demo branch already has any shift instance. If it
has none, the Phase 0 to Phase 2 seeders run with `Carbon::setTestNow` **and** `CarbonImmutable::setTestNow` (both, because
`CarbonImmutable::now()` is what the Ledger and the seeders use) set to the anchor, **05:30 local on the day three days ago**; `Phase3Seeder`
then plays the days forward by moving the clock, and `DemoClock` puts the real clock back in a `finally`. When shifts already exist, or when `SEED_PHASE` is below 3, the
clock is never touched: the earlier seeders behave exactly as today, so `e2e-p0`, `e2e-p1`, `e2e-p2` (which seed up to their own
phase) and every earlier seeder test are unaffected. Anchoring changes only `created_at` and similar dates of the phase 0 to 2
demo rows (three days older), and the relative dates they compute ("due 06:00 tomorrow", `is_new_until` +30 days) move with it.

The history, per station, for D-2 and D-1 (D = today, local), all through module code:

- **D-3 (the anchor day)**: the shifts `DemoShifts` opened for Phases 0 to 2 (a "Day" shift per tracked station, opened carried by
  the demo admin) are closed at 21:30 with unchanged counts and locked: the first link of every place's chain.
- **Kitchen, bar, cake room, bakery, till**: a Day shift 06:00-14:00 per day; opening counted from the previous locked closing;
  during the day plain movements (waste, a transfer from the store, production by the bakers) and at the till three cash
  orders per day through `DemoOrders` (taken at the pad, moved along by the station actions, paid by the cashier with tender and
  change); the closings are the ledger figures except the planned differences below; locked.
- **Bakery Night**: a Night template shift (22:00-06:00) on D-2, scheduled with the bread baker on the roster, started at 22:05 and
  closed at 05:55 on D-1, so there is a shift whose `date` is D-2 and whose work happens on D-1, and the D-1 Day shift opens
  prefilled from it.
- **Planned differences, approved by the manager as the second person**: D-2 kitchen flour counted 500 g under expected, reason
  "Spoilage"; D-1 bakery bread counted 3 over, reason "Miscount"; D-1 till cash 20.00 under expected, reason "Miscount" (a
  cash count with its own denominations), all with `count_adjustment` rows where stock is concerned.
- **Today**: every tracked station has an **open** shift whose opening count was prefilled from yesterday's locked closing, except
  one on purpose: the bar's D-1 closing is left **pending approval** (a variance of 250 ml of milk, reason "Spoilage") so the
  admin has something to approve on the first demo, and the bar's shift today was therefore started *carried* (the "Start
  without counting" path, with its block on the bar's own closing until the pending one is resolved). One handover note from the D-1
  evening shift is on the kitchen's open shift.
- It also writes the demo branch's templates ("Day" 06:00-14:00, "Night" 22:00-06:00, the stations they apply to), its five
  variance reasons (the migration and `BranchCreated` already did) and the roster of the history days.

**What the history guarantees**, asserted by `Phase3SeederTest`: every place's chain verifies; the chain of each place has the
expected number of links (the test holds the table: one closing and one opening per shift, the bakery's Night shift adding a pair, the bar's pending closing not yet a link); every closing count's `expected_qty` equals
`balanceAt(snapshot_at)`; `Replay::at` at the midpoint of each day equals the independent oracle; the sum of cash sales of each
till shift equals `Totals::cash`; a second run writes nothing, not even an audit row; a run into a branch that is not a demo
branch is refused; it runs identically with the enforcement flag on and off (the seeder opens shifts either way).

**What the earlier seeders must change, and why that cannot break the earlier gates.** With enforcement on, `Phase0Seeder`'s
opening stock (movements at the kitchen, bar, cake room, bakery and display case), `Phase1Seeder`'s sample orders (the
chef's and barista's `ready` consumption at their stations, the cashier's payment and the display-case sale) and
`Phase2Seeder`'s purchases, transfers, batches and the `count_adjustment` that leaves the gate product at `on_hand = 1`
all change `on_hand` at station locations, so each needs the station's shift open. The change (step 3.2c) is one call at the
top of each seeder's transaction: `Demo\DemoShifts::ensureOpen($branch, $stations, $admin)`, which, for each tracked station
that has no running shift, starts one through `Shifts::start(... carry: true)` as the demo admin (origin `started`, opening
`carried`, the admin on the roster) and returns the shift ids; the seeder then sets `ActorContext::inShift()` to the right id
while it acts as the chef, barista or cashier at that station, which is what `ResolveActor` does for a request. `DemoShifts` is
idempotent (it starts nothing where a shift runs), so a second run still writes nothing. Why the earlier gates stay green:
(1) the Pest suites run with the flag **off**, where the added rows (a few shift instances and roster rows) are invisible to
every assertion the earlier seeder tests make (they compare catalog, stock and order tables, and "a second run writes
nothing"); (2) `e2e-p0..p2` seed their own phase only and their flows build their own fixture in the "E2E Test Branch", which the
fixture helpers now open shifts in (section 10.1); (3) the new `enforced-gates` job runs `gate:p0..p2` with the flag **on**
against exactly these seeders and fixtures, in the PR that adds them (3.2c), before the flag's default changes (3.7e);
(4) `DemoSeederTest` gains the composition for `SEED_PHASE` 0 to 3 and a second run that writes nothing.

**On the live site** the demo bakery is loaded by hand (`bakery:seed-demo --phase=3`, runbook); nothing here runs on a deploy.
Run against a demo branch that was loaded at Phase 2 days ago and has no shifts, `Phase3Seeder` starts today's shifts
(prefilled from nothing: carried) and writes **no history** when the branch's newest movement is less than two days old, and says so
in its summary ("history not generated: the demo data is newer than two days; load a new demo branch"); when the newest movement
is older it writes the history ending yesterday. A fresh demo branch always gets the anchored two days.

## 9. ADRs this phase writes

- **0012** Hash chain and its canonical JSON. The chain is per `(branch, location)` and lives in the columns of `stock_counts`
  (`chain_seq`, `previous_hash`, `payload_hash`, `canonical_payload`, `payload_version`), claimed by a unique key, not by a head
  table or a lock on a row that may not exist (the first-row trap). Every count that is approved (opening, closing and the cash
  drawer's) is locked and becomes a link in the same transaction as its decision. `payload_hash = lowercase hex of
  sha256(previous_hash + canonical_payload)`, genesis `previous_hash` = 64 zeros. The canonical form is a closed grammar with its
  own encoder (section 10.3), versioned by `payload_version`; the exact bytes are stored next to the hash, so verification
  checks the link, the hash, and that the stored bytes equal what the live rows produce today. An unlock supersedes, never
  rewrites: the old link stays and verifies. The database refuses any UPDATE of a locked row except the two supersede columns and
  any DELETE of a count (a trigger on both engines). Limits said plainly: someone with write access to the database *and* the
  application can recompute a whole chain; the defences are the nightly `shifts:verify-chain`, the head hashes shown on the
  admin screen and printed to the log, and (Phase 4) their inclusion in the weekly summary sent outside the database. A
  Python re-implementation of the grammar (`scripts/verify_chain.py`, standard library only) checks pinned vectors in CI so a
  PHP or library change cannot silently change the bytes.
- **0013** Shift attribution rules. (1) *The station of a request*: `X-Station-Id`, else the only station the user is assigned
  to, else none; a header the user may not work at is ignored. (2) *The stamp*: `ActorContext.shift_instance_id` is the running
  (open or counting) shift at that station when the request begins, else null; pre-Phase-3 rows stay null and nothing is
  backfilled; queued work carries the shift of the movement that caused it. (3) *A row belongs to the shift in which it was
  written*: an order to the shift where it was created, a payment to the shift where the money was taken (cash totals are by
  the payment's shift), a movement to the actor's shift, else to the shift of the station owning its location (the fallback),
  a count adjustment to the shift it corrects (`runAs`). (4) *What "on duty" means*: a roster row that has joined and not left
  on the running shift; with no running shift nobody is on duty and callers fall back to the assigned people. (5) *When the
  ledger refuses*: rule E1 of section 10.1, exemptions included. (6) *The freeze*: a station whose shift is not `open`
  refuses on-hand movements from anyone not covered by a shift of their own, so a closing count compares against a ledger
  that is not moving. (7) *Call-ins*: `ShiftAttacher::attach` opens an ad-hoc shift carried and puts the baker on the roster.
  (8) *The date of a shift* is the local start date of its window (section 10.2). (9) *One unresolved count per place*
  (rule R-A). (10) *Cash is attributed to the till shift that took it.*
- Dated notes (not new ADRs): **0010** the two interfaces were rebound to Shifts in 3.2b; what was kept true (the Phase 2
  bindings still exist and are asserted when bound explicitly; the contracts run against both; `Recipients::onDuty` still falls
  back to the assigned people when the roster is empty); **0009** Phase 3 stays on polling: the shift bar polls
  `shifts/current` at 5 s (30 s hidden), the approval queue every 10 s, no new channel; **0008** unchanged (reserve at send,
  consume at ready, sale at pay), now with the added rule that consume and sale need the station's shift once enforcement is on.

Both ADRs are `proposed` as written in their steps (0013 in 3.2a, 0012 in 3.4) and become `accepted` in the gate report.

## 10. Assumptions and open questions

Only one question in this phase touches the ledger's meaning (negative stock, 10.4) and it is the one already open with Ramin
since Phase 0, refused by default; so nothing here blocks. Safer defaults taken are below. The six points the owner asked to be
settled come first, each with a recommended default.

### 10.1 Turning on `ledger.require_shift_for_station_locations`

**The rule (E1), exactly.** The config key lives in `config/ledger.php` as `require_shift_for_station_locations`, read from the
environment variable `LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS`; `config/payments.php` has `require_shift_for_cash` reading its **own**
variable, `PAYMENTS_REQUIRE_SHIFT_FOR_CASH` (corrected in 3.5: the first draft shared the ledger's variable, but the two rules fail in different
ways, so each gets its own kill switch; 3.7e flips all three together). With it on, `Ledger::appendMany` refuses the whole batch with `409 inventory.shift.required
{station_id, location_id}` and writes nothing when it contains an intent that

1. changes `on_hand` (every reason except `reservation` and `reservation_release`; a `production_consume` or `sale` that settles
   a reservation does change `on_hand`), and
2. is at a location whose `station_id` is set and whose station `tracks_shifts`, and
3. is not a `count_adjustment` with `ref_type = 'stock_count_line'` (a count's own correction is how a frozen station is
   brought back in line with the shelf), and
4. is not **covered**. Covered means (a) the actor carries a shift (`ActorContext.shift_instance_id`) and that shift is `open`, or
   (b) the location's station has a shift in status `open`, whoever acts. A batch needs every tracked station it touches
   covered; an actor covered by (a) covers all of them. The row is stamped with the actor's shift when (a), else with the shift
   found in (b) (ADR 0013 rule 3).

The check runs inside the Ledger's transaction, before any balance lock, through Shared's `OpenShifts` (never an import of
Shifts), and takes a **shared** lock on each shift row it relies on, in ascending id order; `close` takes the same row
`FOR UPDATE`. That is what makes the freeze real: a movement that began before the close finishes first and is inside the
counted figure; one that starts after it is refused. The system's lock order is therefore: domain row (order, purchase,
transfer, batch, count) -> `shift_instances` -> `stock_balances` ascending `(item_id, location_id)` -> `item_costs` ascending.
With the flag off none of this runs and nothing about stamping changes. Why both (a) and (b): (a) lets a night baker deliver
bread into the display case while the till is closed, and a cashier sell at the display case; (b) lets the admin record waste or
move stock in the kitchen during the kitchen's shift without being "on" one; a manager with neither (confirming a night batch
into a closed till) is refused and opens a shift or waits for the morning (open question 18). Payments adds one rule
under the same switch: `Payments::record` needs the actor to carry an `open` shift (`409 payments.shift.required`), because a
payment taken after the till's count began would fall outside the declared cash and a payment with no shift would be in no
drawer's total.

**Every existing code path that has to open a shift first** (these are all the writers of `on_hand` at a station location
today; found by reading `Ledger`'s callers, the actions under `app/Modules/*/Actions` and the seeders):

| Path (module, action) | Where it writes | Covered by | Pest fixtures that change (3.2c) | `@live` fixtures that change (3.2c) |
|---|---|---|---|---|
| KDS **Ready**: `production_consume` of the recipe (Orders `MarkLineReady` -> `Reservations::consumeMany`) | the line's station location (kitchen, bar) | the chef's or barista's own shift (a), or the station's (b) | `OrderFloor::open`, `OrdersMenu`, the station-action and gate tests | `buildStationFixture`, `buildFlowFixture`: open kitchen and bar; `order-flow*.spec.ts`, `shortfall.spec.ts`, `pad-order-flow.spec.ts` |
| KDS **Accept**, **Serve**, **Reject**, order **Void** | no `on_hand` movement (a reject or void releases a reservation, exempt) | not needed | none | none (the KDS shift bar still asks for a shift before Accept, a screen rule) |
| Display-case **sale** at payment (Orders `ApplyPayment` -> `consumeMany(sale)`) | the display case; its station is the till in the demo bakery, none in the E2E branch | the cashier's till shift (a); `payments.require_shift_for_cash` asks for it anyway | `OrderFloor`'s cashier assigned to a till station with a shift | `buildTillStaff`, `buildFlowFixture`: find or create the till station, assign the fixed cashier with `can_count`, open its shift |
| Production **batch** done and confirm (Production `FinishBatch`/`ConfirmBatch` -> `appendMany`) | N `production_consume` at the production station's location and one `production_output` at the product's place (display case, kitchen) | the baker's shift (a); an overseer's confirm needs (a) or (b) at **both** places | `ProductionFixture` | `buildProductionFixture` (cake room, display case's station), `buildCatalogFixture` (bakery); `stockout-production`, `stockout-catalog-planning` |
| Production **accept** by an off-shift baker | writes no movement, but opens the ad-hoc shift (`ShiftAttacher`) | n/a: it is the way in | the call-in test | none (the fixtures open the shift up front; the call-in is tested in Pest) |
| **Transfer** confirm (Inventory `ConfirmTransfer`) | two `transfer` rows: out of the source, into the destination | each tracked end (the store is untracked) | `TransferFixture` | `buildTransferFixture` (kitchen); `purchase-transfer.spec.ts` |
| **Purchase** confirm (Inventory `ConfirmPurchase`) | `purchase_in` at the store | nothing by default: the store `tracks_shifts` is false | none | none (`buildInventoryFixture` finds or creates the store; a created store gets false) |
| Manual **stock movement** (`POST /stock/movements`: `purchase_in`, `waste`, `return`, a hand `count_adjustment`) | any location | the station's open shift (b) for the admin | the `stockIn()` helper opens the station's shift first when the flag is on | every `/stock/movements` call in `fixture.ts` (2 places), `inventory.ts`, `production.ts` (3 places) follows an `ensureOpenShift` |
| A count's own **adjustment** (Shifts) | the counted place | exempt (rule 3) | n/a | n/a |
| **Reservation** and **release** (send, void, cancel, reject) | `reserved` only | exempt | none | none |
| Demo **seeders** (`demo_seed` movements, the sample orders' consumption and sale, the gate product's `on_hand = 1` adjustment) | the stations they stock | `Demo\DemoShifts` opens each station's shift first (section 8) | `Phase0/1/2SeederTest` run both ways | n/a |
| Queued work (`EvaluateBalance`, `CreateAutoRequest`, notifications) | no `on_hand` movement | n/a | none | none |

**The PR order, so that no earlier gate goes red in between** (section 6 has the detail; this is the logic):

1. *3.1b* adds the check dark: the flag is false in `config/ledger.php` and in every environment; the tests use a fake
   `OpenShifts`. Nothing observable.
2. *3.2a/3.2b* add Shifts; the stamps start to flow (a request made while a shift is open carries its id), but no shift is open
   anywhere and nothing is enforced.
3. *3.2c* makes every writer in the table able to run with the flag on, **and turns the flag on in CI only**: `compose.ci.yml` for
   `e2e-p0..p2` and the new `enforced-gates` job for `gate:p0..p2`. The PR's first commit turns it on and the earlier jobs go red
   (the PR description lists which); the following commits change fixtures and seeders until they are green. The default in the
   code is still false, so the unit and module jobs of every module run as before. At merge, the earlier gates are green with
   the flag **both** off and on.
4. *3.3 to 3.6* change counts, locks, cash and replay; every one of those PRs runs the same two extra proofs, so a change that
   breaks an earlier fixture is caught in the PR that makes it.
5. *3.7a to 3.7d* add the screens that open, join and close shifts (the till refuses to pay without one; the KDS shows the
   reason and a Start button). Still flag off by default; e2e runs with it on.
6. *3.7e* changes the default to `true` (four lines: the two config files, `.env.example`, `.env.ci`; the CI overrides become
   redundant and are dropped). After it merges, the deploy runs every `@live` flow of phases 1 to 3 against the live site with
   enforcement on. The fixtures were already opening shifts since 3.2c, so this is the same code path the rehearsal ran.
7. *3.8* adds the history seeder and closes the phase.

**Kill switch.** Set `LEDGER_REQUIRE_SHIFT_FOR_STATION_LOCATIONS=false` in `~/bakery-ops.orcamind.dev/.env` and run
`/opt/alt/php83/usr/bin/php artisan config:cache` (the release caches configuration, so an `.env` edit is not read until then);
nothing is deployed and nothing is lost: shift stamps keep flowing, only the refusal and the payment rule stop. The runbook
gets the two lines in 3.8.

**Recommended default and the alternative.** Recommended: the order above (flip at 3.7e). The manifest's literal order flips
in 3.2; that also keeps every gate green (3.2c's fixtures make it safe) and differs only in that from 3.2c to 3.7a the live
site has no screen with a Start button, so a demo on the live URL would hit `inventory.shift.required` on the KDS. Nobody but
Ramin and the E2E branch uses the live site before the pilot, so the cost is small; the delay costs nothing either. Either
way the rehearsal in 3.2c is the same. This is open question 2.

### 10.2 Cross-midnight shifts (a Night template)

A shift's `date` is the **local start date** in the branch's timezone (`branches.timezone`, `Africa/Addis_Ababa` today), not the
UTC date and not the date it ends. Times are stored in UTC and converted with `CarbonImmutable::setTimezone($branch->timezone)`;
there is no `+3` anywhere (Addis Ababa has no daylight saving, but the code does not rely on it). `ShiftWindow` (pure, in
`App\Modules\Shifts\Support`) answers which window of a template contains an instant:

> For the instant `t` (local time `L`): try the start dates `d = L.date` and `d - 1`. The window starting on `d` begins at
> `d` + `start_time` and ends at `d` + `end_time` if `end_time > start_time`, else at `d + 1` + `end_time`. It matches when
> `start <= t < end` (end exclusive) **and** `days_mask` has the ISO weekday of `d` (the day the window *starts*). Of the windows
> that match, the one that started latest wins; a tie goes to the lower template id. The shift's `date` is `d`, `starts_at` and
> `ends_at` that window in UTC. An instant that matches no template makes an ad-hoc shift whose `date` is the local date of
> `opened_at`.

| Template, instant (local) | Result |
|---|---|
| Night 22:00-06:00, daily; Fri 21:59 | no match (ad-hoc, date Fri) |
| Night; **Fri 23:59** | match, `date` Fri |
| Night; **Sat 00:01** | match, `date` **Fri** (the window started Friday) |
| Night; Sat 05:59 | match, `date` Fri |
| Night; Sat 06:00 | no match (end exclusive; ad-hoc, date Sat) |
| Night on Fridays only; Sat 01:00 | match, `date` Fri (the weekday of the *start* counts) |
| Night on Fridays only; Sat 23:00 and Sun 01:00 | no match |
| Day 06:00-14:00; 13:59 / 14:00 | match / no match |
| Day and a custom 12:00-18:00 at one station; 13:00 | both match; a running shift wins, else the later start (custom) |
| Night; 23:30 local (20:30 UTC) and 01:30 local (22:30 UTC the day before) | `date` from local time in both cases |

Boundary tests: `ShiftWindowTest` (one row per line of the table, plus the exact-midnight window and `start = end`
refused), `ShiftWindowPropertyTest`, and `CrossMidnightGateTest` through the HTTP layer with `travelTo` at 23:59 Friday and
00:01 Saturday (a second start the same night is `sequence = 2`, same `date`). Consequences said so nobody is surprised: an
order sold at 00:30 during Friday's Night shift has `orders.business_date` Saturday and the shift's `date` Friday (the first is
the trading day, the second the shift's name); reports "by shift" use the shift date, "by day" the business date. Editing a
template never changes an instance already made. A scheduled instance started late by an admin (`force`) keeps the `date` it was
scheduled for; that is how `p3-opening-prefill` proves the semantics on a live site (section 7).

### 10.3 Canonical JSON for the hash

`Hash\CanonicalJson` is an encoder of its own for a closed set of types, not a call to `json_encode` (whose flags, escaping and
float behaviour are a PHP-version and library concern). It accepts `null`, `bool`, `int`, `string`, a list of values and a map of
string keys to values; anything else (a float, an object) is a `LogicException`. The grammar:

- **Output**: UTF-8, no BOM, no whitespace outside strings, no trailing newline.
- **Maps**: keys sorted by byte order (`strcmp`), recursively; every key of the schema is always present, with `null` when it has
  no value (absent and null never both mean something).
- **Lists**: ordered by a documented key, never by insertion: lines by `item_id` ascending; adjustments by `item_id`; channel
  rows by channel `code` bytewise; roster entries by `user_id`; denominations are a map, so their keys sort as strings (`"10"` before
  `"100"` before `"200"` before `"5"`), which is deterministic and re-implementable.
- **Decimals**: fixed-scale strings, quantities with 4 decimals (`"12.5000"`, `"0.0000"`), money with 2 (`"1500.00"`), a leading `-`
  for negatives, **never** `"-0.0000"` (zero is normalised), no exponent, no thousands separator, no `+`. Built from `Quantity` /
  `Money` / `BigDecimal::toScale`, never from a PHP float.
- **Integers**: JSON integers (ids, counts, `chain_seq`, `seq`, `payload_version`). **Booleans**: `true` / `false`.
- **Timestamps**: UTC, `YYYY-MM-DDTHH:MM:SS.ffffffZ` with exactly six fraction digits, whatever the column's precision (a
  `DATETIME(0)` value has `.000000`). **Dates**: `YYYY-MM-DD`.
- **Strings**: escaped minimally: `"` as `\"`, `\` as `\\`, U+0000 to U+001F as `\u00xx` (lowercase hex); everything else,
  including `/`, U+007F, U+2028 and non-BMP characters (Amharic notes), is raw UTF-8. No Unicode normalisation: the bytes
  hashed are the bytes stored.
- **The payload** (version 1): `branch_id`, `location_id`, `shift_instance_id`, `shift_date`, `station_id`, `type`, `seq`,
  `supersedes_count_id`, `snapshot_at`, `ledger_movement_id`, `counted_by_user_id`, `cosigned_by_user_id`, `decision`,
  `decided_by_user_id`, `decided_at`, `decision_note`, `submitted_at`, `lines` (each: `item_id`, `expected_qty`, `carried_qty`,
  `counted_qty`, `variance_qty`, `reason_code`, `reason_note`, `adjustment_movement_id`, `adjustment_qty`), `cash` (null, or
  `denominations`, `counted_total`, `expected_total`, `variance_total`, `carried_float_total`, `cash_sales_total`,
  `float_left_total`, `channels`, `reason_code`, `reason_note`, `currency`), `chain_seq`, `locked_at`, `locked_by_user_id`,
  `payload_version`. Illustrative shape (abbreviated, not a pinned vector):
  `{"branch_id":1,"cash":null,"chain_seq":4,"counted_by_user_id":9,...,"lines":[{"adjustment_movement_id":812,"adjustment_qty":"-500.0000","carried_qty":null,"counted_qty":"9500.0000","expected_qty":"10000.0000","item_id":31,"reason_code":"spoilage","reason_note":null,"variance_qty":"-500.0000"}],...}`.
- **The `cash` block, as built in 3.5.** `denominations` is the object of every denomination the count was declared with (whole counts, zero
  included, keys sorted as strings by the encoder), the money figures are two-place decimal strings or null (`carried_float_total` only at an
  opening, `cash_sales_total` and `float_left_total` only at a closing), `channels` is `[]` at an opening and, at a closing, one row per channel
  ordered by `code` with the keys `code`, `expected_receipts`, `expected_sum`, `declared_receipts`, `declared_sum`, `difference_sum` (the declared
  figures and the difference null where nothing was declared), `reason_code` / `reason_note` are the declaration's. `lines` is `[]` for a drawer
  count. The version stays 1: no link had a non-null `cash` before 3.5, so no old link changes its bytes, and a stock count still has
  `cash: null`. `scripts/verify_chain.py` checks the channel row keys and that every `*_sum` is a two-place decimal; the pinned till vector in
  `vectors.json` has the new row shape.
- **The hash**: `payload_hash = lowercase_hex(sha256(previous_hash . canonical_payload))`, where `.` is byte concatenation of the 64
  ASCII characters of the previous hash and the payload bytes; the first link of a place has `previous_hash = "0" x 64`.
  `canonical_payload` is stored with the row, so verification (a) recomputes the hash from the stored bytes, (b) checks
  `previous_hash` against the previous link, (c) rebuilds the payload from the live rows and requires it byte-equal to the
  stored one, (d) checks `chain_seq` is gapless from 1.
- **Versioning**: `payload_version` is part of the payload and of the verifier's dispatch; a change of grammar is a new version,
  old links verify by the old rules forever.
- **Cross-checks**: `CanonicalJsonTest` pins three vectors (a stock count, a till count with cash, a unicode note); the PHP test
  writes them to `apps/api/tests/Fixtures/chain/*.json` when `UPDATE_CHAIN_FIXTURES=1`; `scripts/verify_chain.py` (standard library
  only, an independent implementation of the grammar above) recomputes every vector and the CI `invariants` job runs it, so a change
  in PHP, in `brick/math` or in the encoder that moves one byte fails a job that has nothing to do with PHP.

### 10.4 Negative stock (still Ramin's question)

The Ledger refuses a batch that would take `on_hand` or `reserved` below zero (`inventory.stock.insufficient`, with CHECK
constraints behind it), and **Phase 3 keeps the refusal everywhere**. Where counts and variance approval touch it, so a later
decision is a small change:

1. *Approving a negative variance.* The adjustment is a delta (counted minus expected at the snapshot). If stock has left since
   (a sale from another path, a transfer out), a delta that is larger than the `on_hand` now would go negative; the approval
   answers `409 shifts.adjustment.insufficient` with nothing written and the admin rejects and asks for a recount. This should be
   rare: the station is frozen from the moment the count began, and E1 refuses movements from a station without an open shift.
2. *Counting below what is reserved.* A count that finds 3 kg where open orders have reserved 5 kg leaves `on_hand` 3 and
   `reserved` 5. The Ledger allows it (it refuses only `on_hand < 0` and `reserved < 0`), `available` becomes `-2` and the pad
   shows the product unavailable (Availability clamps at zero). The line already `ready`-pending cannot consume 5 from 3
   (`production_consume` with its reservation would take `on_hand` to -2 and is refused), so the chef rejects that line
   (`out_of_stock`, which releases the reservation): the approval screen lists the at-risk open lines
   (`Orders::openAt` joined to the reservations) so the admin sees the consequence before approving. Nothing is blocked.
3. *Positive variances, openings, carried openings, purchases, outputs and inbound transfers* never go negative.
4. *Every other writer of `on_hand` is as in Phase 2* (10.4 of that plan: batch consume and transfer confirm pre-check
   `available`).

If Ramin decides stations may run negative (a baker makes what he can see on the shelf), the changes are the Ledger rule, the
two CHECK constraints and the pre-checks of Production and Transfers, plus here: remove case 1's `409` (approvals always succeed),
keep case 2's list of affected lines, and relax the property tests that say "never below zero". No Phase 3 table or event
depends on the answer.

### 10.5 Cash declaration arithmetic

**Which Payments capability provides it now: none.** Today Payments exposes `Channels`, `Payments::record` and (through Orders)
`Orders::totals(order)` per order. The `payments` table already has what is needed: `amount` (what the order was paid by this
payment, change excluded), `channel_id`, `status`, `shift_instance_id` (stamped from `ActorContext` by `RecordPayment`) and
`received_at`. **Added (F5):** `Payments\Contracts\Totals::cash(shiftId): Money` and `Totals::byChannel(shiftId):
list<ChannelTotal>`, one `SELECT channel_id, COUNT(*), SUM(amount) FROM payments WHERE shift_instance_id = ? AND status <>
'voided' GROUP BY channel_id` (branch-scoped by the model), cash being the row of channel code `cash`; the `Totals::byChannel(date)`
form of the manifest is Phase 4's. The key is the **payment's** shift (amended wording, section 2): money enters the drawer when it is
taken.

Opening: `counted_total` is Σ denomination x count of the opening declaration. `expected_total` is the previous locked
closing's `float_left_total` at this drawer (`carried_float_total`), or null for the first shift ever (then no variance:
`expected_total = counted_total`). `variance_total = counted_total - expected_total`; a non-zero variance needs a reason.

Closing: `cash_sales_total = Totals::cash(shift)` read in the submit transaction, after the till froze (no cash can be taken once
`close` has run: `payments.require_shift_for_cash`, the payment rule of section 10.1). `expected_total = opening.counted_total +
cash_sales_total`. `variance_total = counted_total - expected_total` (negative = short). `float_left_total` is what the
cashier leaves in the drawer for the next shift (at most `counted_total`); the difference is what they take to bank, shown but not
stored. The next opening expects `float_left_total`. Channel reconciliation: for each channel with expected payments or a declared
figure, `expected_receipts` / `expected_sum` from `Totals::byChannel`, `declared_receipts` / `declared_sum` as the cashier counted
their slips, `difference_sum`; any non-zero channel difference needs the same reason as a cash variance (one reason and note
for the declaration). Only cash can be recorded before Phase 4 (digital channels need receipt photos), so until then the table
has one row; the declared digital figures are accepted for enabled channels so the screen is ready.

Worked table (the one `CashDeclarationTest` and `CashDeclarationGateTest` pin):

| Case | Opening count | Payments in the shift (amount, tender, change) | Cash sales | Expected | Counted | Variance |
|---|---|---|---|---|---|---|
| 1 | `{"100": 5}` = 500.00 | 60.00 (100.00, 40.00); 120.00 (120.00, 0); 45.50 (50.00, 4.50) | 225.50 | 725.50 | `{"200": 3, "100": 1, "10": 2, "5": 1, "0.50": 1}` = 725.50 | 0.00 |
| 2 | 500.00 | the same three | 225.50 | 725.50 | 700.00 | -25.50 (reason required) |
| 3 | 500.00 | the same three, plus a 30.00 payment taken in another shift and a voided 80.00 one | 225.50 | 725.50 | 725.50 | 0.00 |
| 4 | none ever before, counted 300.00 | none | 0.00 | 300.00 (= counted) | 300.00 | 0.00 |

Denominations are `config('shifts.denominations')`: the ETB notes `200, 100, 50, 10, 5, 1` and coins `0.50, 0.25, 0.10, 0.05`; counts are
non-negative integers; the server computes `counted_total`; an unknown denomination is `422 shifts.cash.denomination_unknown`.
No cash rounding rule is applied (the "nearest 0.05" setting of the architecture is not built).

**Decisions of 3.5 that the text above left open.**

- **A till count without a declaration refuses to be handed in** (`422 shifts.cash.declaration_missing`), and a closing without
  `float_left_total` refuses too (`422 shifts.cash.float_required`): an empty drawer is declared by sending `{}` and a float of `0.00`; nothing
  is guessed. The declaration is made with the draft of the cash-drawer count (`DraftBuilder::buildCash`, called by start and close next to
  `build`, so `Counts::draftsFor` and the stock contract still answer stock places only); a station with a cash drawer and no stock place now
  starts in `opening`, not `open`.
- **The opening figure a closing is measured against** is what the shift's opening count at the drawer counted (the newest revision that was
  handed in and not rejected); with no opening count (a shift started with `carry`) it is the float the last locked closing left; with neither,
  nothing. This is the counted figure, not the carried one: a drawer that was 20.00 short at the start (explained and approved) is measured
  from what it held.
- **Figures are live while a draft and fixed at the submit.** `cash_sales_total` is read from `Totals::cash` inside the submit transaction
  after the shift row is locked; with `payments.require_shift_for_cash` on, a cash payment takes a shared lock on its shift row and needs the
  shift `open`, so nothing can be taken once `close` has run. With the flag off (today) a payment stamped with the shift can still land between
  the draft screen and the submit; the submit's read is the one that counts, and a payment after the submit is outside the declared cash.
- **Channel differences.** A row exists for every channel that took money in the shift or was declared, ordered by code. `difference_sum` =
  declared - expected exists only where something was declared; an undeclared channel (the cash row included) is listed with nulls and cannot
  make the count differ. A non-zero difference needs the declaration's reason (and note, if the reason asks) exactly as a cash variance does,
  and sends the count to a decision. A declared channel must be a channel of the branch and enabled (`422 shifts.cash.channel_unknown`).
- **Denominations** are every configured value with a whole count (zero where none); a key is read in any spelling that equals a configured
  value (`"0.5"` is `"0.50"`); a count is an integer or a digit string up to 1,000,000; a locked count keeps the denominations it was counted
  with when the configuration changes.
- **A recount (reject) and an unlock revision copy the declaration** (denominations, float left, declared channels, reason and note) and leave
  it unconfirmed; the figures of the submit are not copied.
- **The rule for cash** reads `PAYMENTS_REQUIRE_SHIFT_FOR_CASH` (own variable, default false everywhere). It is not switched on in the
  `enforced-gates` rehearsal of 3.2c: the fixtures that take cash would each need a till shift, which the 3.7 fixtures bring; 3.5 proves the
  rule both ways in `PaymentRequiresShiftTest` and `PaymentsContractTest` case 9.

### 10.6 New permissions and how they reach the live database

New to `PermissionRegistry` in Phase 3 (default roles; the admin can still grant any single permission to a user; there is no
per-user withhold):

| Permission | Default roles | Used by |
|---|---|---|
| `shifts.view` | admin, manager, cashier, chef, barista, cake_baker, bread_baker | reading shifts, counts, templates, reasons, notes and the handover panel at one's stations |
| `shifts.manage` | admin | templates, variance reasons, scheduling, roster, cancelling, `force` start, opening an ad-hoc shift for someone else, closing someone's shift |
| `shifts.replay` | admin, manager | the replay, the chain and its verification |

Already in the registry since Phase 0 and used as they are: `shifts.count` (the seven floor roles; with a station assignment),
`shifts.cosign` (admin, manager; plus the overseer flag), `shifts.approve` (admin, manager), `shifts.unlock` (admin),
`stations.all`, `people.manage`, `payments.take`, `inventory.view`, `audit.view`. The waiter holds none: the pad needs no shift.

A deploy never runs seeders, so a registry row alone does not reach the live database (the lesson of `tables.manage`). The
registry change lands in step 3.1a together with `2026_10_24_100100_grant_phase_3_permissions`, the same thin caller of
`Identity\Support\GrantRegistryPermissions` as Phase 2's: it reads `PermissionRegistry`, creates the permissions the `web` guard
lacks, attaches each registry permission to the default roles that exist and lack it; it never revokes, deletes, renames or
updates anything (so an admin's edits to `shifts.count` or `shifts.approve` survive), creates no role, leaves users' roles and
direct permissions alone, writes nothing the second time, clears spatie's permission cache, works on the tables rather than the
models, and `down()` does nothing. `release.sh` already runs `migrate --force`, so the live database has the permissions as soon
as 3.1a is deployed and before any route uses them (the first is 3.2b). `GrantPhase3PermissionsMigrationTest` starts from a
database in the state "after Phase 2" (roles seeded from the old registry, an extra permission handed to a role by hand, a direct
permission on a user), runs it, and asserts the three new permissions exist and are granted per the table, nothing was removed
and a second run changes nothing; on a fresh database `RolesSeeder` writes the whole registry. The other schema changes that
reach the live database the same way (by `migrate --force` at release): `stations.tracks_shifts`, the foreign keys, the
tables, the notification CHECK. No existing row is rewritten except the one-statement backfill `tracks_shifts = 0` for
`store` stations.

### 10.7 Live-host facts that shaped this plan

Shared cPanel behind Cloudflare, MariaDB 10.11, the database queue drained by cron every minute, polling only (no WebSocket), no
Octane, `config:cache` at every release. Consequences: notifications can be a minute late and nothing depends on them (section 5);
the freeze uses row locks and a shared lock, not anything that needs a long-lived process; the nightly verification is a
`schedule:run` job, not a daemon; the kill switch is an `.env` edit plus `config:cache`; the foreign-key migration (3.2a) and the
triggers (3.3a, 3.4, 3.5, 3.6) are DDL on tables of at most tens of thousands of rows, inside the release's maintenance window,
with `log_bin_trust_function_creators` already required by the existing triggers. The PostgreSQL branches of the triggers are written
and reviewed but, as in Phases 0 to 2, only the MariaDB engine runs in CI; `docs/runbook/server.md` keeps saying so.

### 10.8 Other assumptions (safer defaults)

- **R-A: one unresolved count per place.** A second count at a place whose earlier count is `awaiting_cosign` or `pending_approval`
  cannot be submitted (`409 shifts.count.blocked_by_pending`). Reason: a count measures a difference between the shelf and the
  ledger; two unresolved counts would each correct the same missing stock and the approvals would remove it twice. The morning
  consequence: the next shift can *start* but not count in; the screen offers "Start without counting" (carried), and its own
  closing waits until the earlier count is resolved. An opening count is still prefilled from a submitted closing that is not yet
  approved (flagged provisional).
- **Count scope.** The lines of a count are the items with a non-zero `on_hand` at the place, the items of the previous count of
  that place, and anything the counter adds ("found an item not on the list", expected 0). An inactive item drops off once it was
  counted at zero. Nothing from Catalog's "items held at a location type" is needed (F9).
- **The store does not track shifts** (`stations.tracks_shifts = false` for `store`): purchases need no shift and the store has no
  count in the pilot; the admin and manager adjust its stock by hand. The admin can switch it on; then it behaves like any station.
- **The display case belongs to the till.** In the demo bakery the display case's `station_id` is the till; in a real branch the
  admin must set it (the admin Shifts "setup check" lists stock places with no station and stations with no template, so a
  place left unlinked is visible rather than silently outside the shifts). The E2E branch's display case has no station on
  purpose: it exercises the other branch of the rule.
- **Overseer co-sign reuses `stations.requires_overseer`** (the flag Phase 2 added for batches): a station with the flag needs a
  second person to co-sign every count before approval, none of the demo stations has it by default.
- **The counter cannot approve their own count.** `409 shifts.count.approve_self`, also for an admin who counted (the manager is
  the second person; a one-person shop is open question 4), the same two-person rule as Phase 2's batches.
- **Reject is a whole recount.** The recount draft is prefilled with the counted figures and the marked lines highlighted; the
  rejected attempt and its note stay visible; accepted lines of a rejected count write nothing.
- **An unlock is a delta revision** (the new revision's expected is the superseded count's counted figure) and needs a reason.
- **A call-in opens carried.** An off-shift baker who accepts a request gets an ad-hoc shift with no opening count (a 3 a.m. full
  count is unreasonable); its closing count is still required and is blocked only by rule R-A.
- **Forgotten shifts.** A shift left running is not closed by any timer. The admin Shifts screen marks a shift that is more than
  `config('shifts.overdue_hours')` (default 14) past the end of its window, or 14 hours old when ad-hoc, and an admin can close
  it for the person (`shifts.manage`).
- **No automatic scheduling.** Nothing creates `scheduled` instances from templates by itself; the roster calendar does, a week
  at a time. People can always start a shift without a schedule.
- **Closing with work open** (queued or accepted lines at the station) is allowed; the summary warns. Reservations are not part
  of a count.
- **Photos for variances** are the Files capability of Phase 4; `photo_key` exists and stays null, and the approval screen says so.
- **The pad has no shift.** Waiters on the shared pad, which is not a station, carry no shift; their orders carry null.
- **`X-Station-Id` is sent by every station screen**; with exactly one assignment the header is not needed (the API infers it).
  A manager or admin working a screen sends the screen's station.
- **Time.** UTC stored, `Africa/Addis_Ababa` shown, local dates for `date`, `from`, `to`; the replay's bare times are local.
- **Units.** Count quantities are in the item's base unit and shown so (a kilogram item reads `10000` g), as in 2.8b.
- **Retention.** Counts, locks, notes and shifts are kept for ever; nothing is pruned.
- **Polling.** The shift bar polls every 5 s visible, 30 s hidden; the approval queue every 10 s; a late commit below the
  highest id is covered by the `OverlapCursor` rule for notes.
- **Docs that move.** `docs/architecture.md` is not edited (it is the original brief); `domain-model.md` and the phase reference
  gain the statuses, the cash-as-a-count model, the flip order and the count-scope rule in 3.8.

Open questions (none blocks; the default stands until Ramin answers): (1) negative stock at stations (10.4); (2) flip at 3.7e
(recommended) or in 3.2 as the manifest says (10.1); (3) must a shift be opened with a full count in the morning even when the
night baker has already done one (the default: yes for scheduled and started shifts, carried only for call-ins and when the
previous count is pending); (4) may an admin working alone approve their own count; (5) whether the store should track shifts
and be counted in the pilot; (6) whether a count should list every active item of the station's kind instead of the
non-zero ones (a longer list, catches items that run out completely and then reappear); (7) whether co-signing should be on for
any station from day one; (8) whether the variance reasons need more or different words after the staff see the Amharic draft;
(9) photos for variances now (needs the Phase 4 Files work first); (10) cash: banking mid-shift and a float that is not carried
forward (the model above carries it); (11) whether shifts should be created automatically from templates each week; (12) whether
a forgotten shift should be closed by a timer; (13) who approves at night when the admin sleeps (the notification can be a
minute late and the approval queue is polled; a manager account on the late shift is the practical answer); (14) whether `date`
should instead be the date the shift *ends* for the Night shift in reports (default: start date, as the manifest says); (15) the
head hashes' off-database home (Phase 4's weekly summary by Telegram or email, or a printed page); (16) whether the pad's
waiters should ever be on a shift; (17) whether `ActorContext::atStation` may now be filled for every request (this plan does,
F4; it changes `station_id` stamps from null to the station); (18) whether a manager's overnight confirmation of a baker's batch
into a closed display case should be allowed without a shift (the default refuses with a clear message, the morning works);
(19) whether Phase 4 should add `Totals::byChannel(date)` before or with the dashboards (it is Phase 4's); (20) whether the
shop wants push and Telegram for count approvals from the first pilot day (they ship configured-off as in Phase 2; the inbox
and the polled queue work without them).

## 11. Risks from `references/traps.md` that apply

- **Hash chain: canonical JSON.** Non-canonical output breaks verification across PHP versions. The encoder is our own over a
  closed grammar, the exact bytes are stored beside the hash, three vectors are pinned, and a second implementation in Python
  re-verifies them in a CI job unrelated to PHP (10.3). The ADR states the limit: a person who can write the database and run
  the application can recompute a chain; the nightly check, the printed head hashes and the Phase 4 weekly summary are the
  defence, not a claim of impossibility.
- **Locked-row trigger exceptions for `superseded_by_id`.** The exception is two columns, written by one query-builder `UPDATE`
  that touches nothing else; the MariaDB trigger lists every column with `<=>` and a schema test fails when a column is added and
  the trigger does not name it; `LockedRowsTest` refuses every other column one by one, from `information_schema`; a locked
  row can never be deleted. The tamper test needs to bypass the trigger, so it runs on `DatabaseTruncation` (DDL commits
  implicitly in MariaDB) through a helper that drops the table's triggers, makes the raw change and recreates them in a
  `finally`; it is the only code in the repository allowed to do that, and an arch test pins that.
- **Time zone and the local start date.** `ShiftWindow` is pure and table-tested at 23:59 and 00:01 and at the exact edges; the
  HTTP gate test moves the clock; the property test refuses two dates for one window; `orders.business_date` and a shift's `date`
  are different things on purpose and the plan says so (10.2). A `+3` anywhere is a bug; a grep test forbids the literal in
  `app/Modules/Shifts`.
- **Seeds for earlier phases must open shifts once enforcement is on.** `DemoShifts` in all three earlier seeders, the Pest
  helpers, the e2e helpers, and the `enforced-gates` job and compose override that run the earlier gates with the flag on in the
  PR that adds them (3.2c), before the default changes (3.7e). The new risk this creates is the seeding clock: `DemoClock` sets
  both Carbon test clocks and restores them in a `finally`, applies only when the branch has no shifts and `SEED_PHASE` is 3 or
  more, and `DemoClockTest` asserts the real clock is back after an exception; a leaked test clock in a long-lived process would
  be very visible, so the arch test forbids `setTestNow` anywhere under `app/`.
- **Rebinding interfaces without breaking the Phase 2 contract.** Three Phase 2 tests assert the *default* binding and must bind
  the Phase 2 classes explicitly; every other presence assertion of `production->identity` and `notifications->identity` runs
  against the roster double (3.1) and the real Shifts resolver (3.2b), so the rebind changes who is told and never how
  Production and Notifications ask (ADR 0010 note).
- **First-row race on new unique rows (traps.md).** A shift start, a chain link and a roster join are all "insert the first row
  of something": none uses `SELECT ... FOR UPDATE` on a row that may not exist. The running-shift key, the chain's `(location,
  chain_seq)` key and the roster's `(shift, user)` key decide the race, the loser's insert fails inside a savepoint and is retried
  or answered `409`; forked tests compare `Innodb_deadlocks` before and after, 30 rounds each.
- **Lock order and the new shared lock.** Every movement now takes a shared lock on the shift row it relies on, before the balance
  rows. The order is written down (10.1 and the concurrency tests), `close` and `approve` take the shift row exclusively before
  any balance, and `CloseVersusMovementRaceTest` and `ApprovalAgainstSaleRaceTest` would deadlock or lose a movement if the order
  were wrong. With the flag off none of it runs.
- **Queued listener vs `sync`; the `worker` container; cron on the live host.** Same class of risk as Phase 2, same proof:
  `Queue::fake` plus a `sync` twin for each of the three listeners, the compose `worker` in `e2e-p3`, and an `@live` wait for the
  inbox lines. Nothing a person must do depends on the notification (the queues are polled reads).
- **Data on the live database.** The foreign-key migration touches the thirteen existing tables that carry the column; every existing `shift_instance_id` is null so
  none can fail, and the migration runs in the maintenance window. Between the deploy of 3.3b and the deploy of 3.4 an
  approved count has no lock yet (only the E2E branch and the demo can have one); the migration of 3.4 ends by locking any count
  left `approved` (`Locks::lockApproved()`, each in its own transaction, idempotent), and `LockApprovedBackfillTest` starts from
  such a database. `tracks_shifts` is backfilled false for stores in the same migration that adds it.
- **The live E2E Test Branch accumulates.** The branch is shared by all `@live` flows and lives for weeks: every fixture leaves
  run-unique ingredients with leftover stock at the kitchen, bar, cake room and bakery, which become count lines, and a failed
  run can leave a count pending. Mitigations: the count screen and the helpers use "nothing changed" in one call, the
  fixtures call `idleOrOpen` first (resolving pending counts as the admin), and the count scope drops zero-stock inactive items;
  if the kitchen's list passes about 500 lines the fixture gains a step that deactivates its own zero-stock items. The cost is a
  longer first `p3-handover` run, not a flake.
- **Behaviour change for staff.** Starting a shift is a count of 20 to 60 lines at 06:00. Mitigations built in: the prefilled
  figures, the explicit "nothing changed" button (audited as one action), "Start without counting" for the morning an approval is
  pending, call-ins that open carried, and the pilot feedback template that records exactly where it hurt. The risk is real and is
  what the one-week pilot after this phase measures.
- **A display case with no station is silently outside the shifts.** The setup check in the admin Shifts screen lists it; the
  runbook's day-one checklist (3.8) has the line "every stock place has a station".
- **Double counting.** Two unresolved counts at one place would each correct the same missing stock. Rule R-A, the delta
  approach (never "set to counted"), the unlock-as-delta rule and `CountsLedgerPropertyTest` (ledger equals counted plus what
  moved since, for every approval, over random histories) are the answer.
- **A refused payment loses a sale if the screen lets it be tapped.** `payments.require_shift_for_cash` and the display-case
  sale rule roll the payment back whole. The till's Pay button is disabled with the reason when no shift is running (3.7a) and
  `PaymentRequiresShiftTest` proves the rollback leaves no payment, no order change and no audit row.
- **`ActorContext::atStation` starts being filled.** Rows written by requests will carry `station_id` where they carried null
  (F4). `ActorStampTest` and the three or four tests that assert a null stamp are listed in the 3.1a PR; no behaviour depends
  on it.
- **One more read per request.** `ResolveActor` now asks `OpenShifts::stampFor` once per authenticated request that resolves a
  station: an indexed lookup on the unique `(station_id, open_key)` key, memoised for the request (`scoped()`), so the KDS's 2 s
  polls pay one cheap query each. `ResolveActorQueryCountTest` pins the count so it cannot grow quietly on the CPU-limited host.
- **Manifest and skill drift.** This plan edits the manifest (shifts depends on orders; a fourth contract; the amended
  `Totals::cash` wording; `enforced-gates`; the `seeds` group move), `STATUS.json`'s `contracts_required` and the skill's CI copy
  and references in the commits that need them, so `gate_check.py` and the CI job graph keep mirroring the manifest.
- **Photos and PostgreSQL.** Photos for variances are Phase 4 (the column exists and stays null). The PostgreSQL trigger bodies
  are written and reviewed, not executed in CI, as in every earlier phase.
- **Scope creep.** The step list of the manifest is the scope; the additions of this plan (`opening`, `cancelled`, `shifts->orders`,
  `tracks_shifts`, the cash-as-a-count model, `shifts:verify-chain`, the setup check, `X-Station-Id`) are each argued above and
  each is smaller than the problem it prevents; anything else is a TODO marker of a later phase (`TODO(p<N>)`), and `gate_check --mode done`
  forbids the markers of the phase it closes.
