# ADR 0013: Shift attribution rules

- Status: accepted (Phase 3 gate, 2026-10-24; proposed in step 3.2a)
- Date: 2026-10-22
- Phase: 3

## Context

Rule 4 says every write records who, acting-as, shift, station, device and when; rule 5 says shifts are per station and
their counts are confirmed, explained, approved, locked and hash-chained. Until Phase 3 the shift part of the stamp was a
plain `shift_instance_id` column with no table behind it, and two soft cycles (dependency-graph.md, cycles 1 and 3) stood in
for Shifts: Identity's `StationPresenceResolver` answered "who is on duty" from assignments, and `ShiftAttacher` attached
nothing. Phase 3 builds the table and has to settle, once and in one place, questions the code of five modules will otherwise
answer five ways: which shift a row belongs to, who counts as "on duty", when the ledger may refuse a movement, what a
station does while its closing count is made, how a shift is dated across midnight, and whose till a payment belongs to.
Migrations 3 to 7 of the Phase 3 plan (step 3.2a) make the tables; this ADR is what their columns mean. The code cites it.

## Decision

1. **The station of a request.** `X-Station-Id` when the user may work at that station, else the only station the user is
   assigned to, else none. A header naming a station the user may not work at is ignored, never an error: a pad left on the
   wrong station degrades to "no shift", not to a refusal on every screen.
2. **The stamp.** `ActorContext.shift_instance_id` is the running (open or counting) shift at the request's station when the
   request begins, else null. Rows written before Phase 3 stay null and nothing is backfilled. Queued work carries the shift
   of the movement that caused it (ADR 0011, `ActorContext::runAs`), not the shift running when the worker gets to it.
3. **A row belongs to the shift in which it was written.** An order belongs to the shift where it was created; a payment to the
   shift where the money was taken (cash totals are by the payment's shift); a movement to the actor's shift, else to the
   shift of the station that owns its location (the fallback of rule E1, plan section 10.1); a count adjustment to the shift it
   corrects (`runAs`). A shift never takes rows from another by editing them: corrections are new rows.
4. **What "on duty" means.** A `shift_roster` row that has joined (`joined_at` set) and not left (`left_at` null) on the
   running shift of the station. With no running shift nobody is on duty and callers fall back to the assigned people
   (`Recipients::onDuty`, the presence resolver), so a stock-out alert never reaches nobody. Being scheduled is not being on
   duty.
5. **When the ledger refuses.** With `ledger.require_shift_for_station_locations` on, `Ledger::appendMany` refuses the whole
   batch with `409 inventory.shift.required {station_id, location_id}` when an intent changes `on_hand` at a location of a
   station that tracks shifts and is not covered, either by the actor's own open shift or by the station's open shift
   (rule E1, section 10.1, exemptions included: `reservation`, `reservation_release`, a count's own adjustment). The flag ships on
   since step 3.7e.
6. **The freeze.** A station whose shift is not `open` refuses on-hand movements from anyone not covered by a shift of their
   own, so a closing count compares against a ledger that is not moving. `close` takes the shift row `FOR UPDATE`; the ledger
   check takes it shared: a movement that began before the close finishes inside the counted figure, one that begins after is
   refused. Lock order: domain row, `shift_instances`, `stock_balances` ascending, `item_costs` ascending.
7. **Call-ins.** `ShiftAttacher::attach` for a person with no open shift at a station they can work at opens an ad-hoc shift
   (`origin = call_in`, opened carried) and puts them on the roster. The accept row, the batch and its movements then carry it.
   **Rollout guard (step 3.2b).** The opening of that ad-hoc shift is behind the flag `shifts.open_ad_hoc_on_accept`
   (`SHIFTS_OPEN_AD_HOC_ON_ACCEPT`, default false until step 3.7e, true since): until step 3.7e
   gives staff a way to see and close shifts, a call-in must not create a shift nobody can close. With the flag off the attacher
   attaches the person to the shift that already runs at the station (and puts them on its roster) and otherwise answers null,
   exactly as the Phase 2 `NullShiftAttacher` did. Step 3.7e switches the flag on together with
   `ledger.require_shift_for_station_locations` (runbook, "Shift rules and the kill switch").
8. **The date of a shift** is the local start date of its window in the branch's timezone (`Africa/Addis_Ababa`), not the UTC
   date and not the date it ends: a Night 22:00 to 06:00 running at Saturday 00:01 is Friday's. An ad-hoc shift is dated by the
   local date it opened. `App\Modules\Shifts\Support\ShiftWindow` and `LocalClock` are the only code that decides it (plan
   section 10.2); an order sold at 00:30 in Friday's Night has `orders.business_date` Saturday and the shift's date Friday.
9. **One unresolved count per place.** At most one count of a location is `awaiting_cosign` or `pending_approval` at a time
   (rule R-A): two unresolved counts would each correct the same missing stock. Code, pinned by a property test (step 3.3a).
10. **Cash is attributed to the till shift that took it.** A cash declaration belongs to the count of the station's cash-drawer
    location; `Totals::cash` sums the payments stamped with that shift.

Structure that carries these rules in the schema (step 3.2a): at most one running shift per station is the unique key
`(station_id, open_key)` where `open_key` is 1 while the status is `opening`, `open` or `counting` and NULL otherwise (NULLs
are distinct in a unique index, so no partial index and no generated column is needed on either engine, the technique of
ADR 0011); one instance per `(station_id, date, slot_key, sequence)` where `slot_key` is the template id or 0; the roster is
unique per `(shift_instance_id, user_id)`; every `shift_instance_id` column outside `audit_log` has a foreign key to
`shift_instances.id` (`audit_log` keeps plain ids, ADR 0001).

## Consequences

- Positive: one definition of "which shift", "on duty" and "the date of a shift", each testable on its own; the database, not
  a SELECT before an INSERT, decides two people pressing Start at once; the freeze makes a closing count meaningful.
- Negative / cost: every writer of `on_hand` at a tracked station needs an open shift once enforcement is on (the 3.2c
  rehearsal and the 3.7e flip); a shift's `date` and the order's `business_date` can differ for work done after midnight, and
  reports must say which they group by; a station left without a closer stays `open` until someone closes it.
- Later phases: counts (3.3), the lock and hash chain (3.4, ADR 0012), cash (3.5) and handover (3.6) hang off the shift row
  these rules define; Phase 4 reports group by shift date or business date explicitly.

## Enforcement

- `tests/Modules/Shifts/ShiftsSchemaTest.php`: the CHECKs, the unique keys (a second running shift at one station and a second
  row of one slot and sequence are refused by the database).
- `tests/Modules/Shifts/ShiftStampForeignKeysTest.php`: every `shift_instance_id` column outside `audit_log` has its foreign key.
- `tests/Unit/Shifts/ShiftWindowTest.php`: the table of plan section 10.2, 23:59 and 00:01 included.
- `tests/Unit/StateMachineExhaustiveTest.php`: every from x to pair of `ShiftStatus`.
- `tests/Arch/ModuleBoundariesTest.php`: Shifts reaches other modules through Contracts only; only Analytics imports Shifts.
- Rule 5 and the freeze are enforced by `LedgerEnforcementTest` (step 3.1b) and the contract tests `contract:shifts->*`.

## Alternatives considered

- **Roster by assignment (keep the Phase 2 resolver).** Rejected: assignment says who may work at a station, not who is
  there tonight; the alert would wake sleeping staff.
- **Date a shift by its end date or by the UTC date.** Rejected: a Night would change name at midnight and two ends of one
  shift would land in different report days.
- **A partial unique index or a generated column for "one running shift".** Rejected: needs engine-specific DDL where a plain
  nullable key does the same on MariaDB and PostgreSQL.
- **Backfill old rows with a synthetic shift.** Rejected: it invents attribution; null honestly means "before shifts existed".

## As built (step 3.8, 2026-10-24)

Accepted at the Phase 3 gate. Rules 1 to 10 hold as written, with these facts from the build:

- **Rule 5 and rule 7 are on.** `ledger.require_shift_for_station_locations`, `payments.require_shift_for_cash` and `shifts.open_ad_hoc_on_accept` each
  have an env variable and default to `true` since step 3.7e (the flip). The kill switch is the three variables set to `false` in the server `.env` and
  `php artisan config:cache`; the `rules-off-gates` CI job runs the Phase 0-2 gates in that state.
- **Rule 3, the fallback.** A movement the actor's shift does not cover is stamped with the open shift of the station that owns its location only when the
  rule enforces it; reservations and reservation releases (no `on_hand` effect), untracked places (the store) and a count's own adjustment (`ref_type =
  stock_count_line`, stamped by `runAs` with the counted shift) are exempt, so some rows legitimately keep a null shift.
- **Rule 4.** On duty = the active people assigned to the station who have joined the running shift and not left. A rostered person who is not assigned
  (the admin) is not on duty. With a running shift nobody has joined, callers fall back to everyone assigned.
- **Rule 6, the freeze.** `close` takes the shift row `FOR UPDATE`; `Ledger::shiftCover` takes it shared, in ascending id order, inside the ledger
  transaction (`LedgerEnforcementTest`: 16 reasons by 6 places by 7 states of cover, flag off and on; the close-in-between case).
- **Rule 7 (call-ins).** A baker accepting at a closed station opens a `call_in` shift (carried) and joins its roster; `ShiftsAttacher` resolves Identity's
  `People` per call (a constructor cycle otherwise).
- **Rule 8.** `ShiftWindow` and `LocalClock` are the only code that decides the date; the Night 22:00-06:00 cases are `ShiftWindowTest` (23:59, 00:01, 05:59, 06:00).
- **Rule 9** is code, pinned by `CountsPropertyTest`. **Rule 10** is `Totals::cash`, keyed on the payment's own shift (contract case 2, amended).
- **Seeds.** The demo seeders open their shifts through `DemoShifts::ensureOpen`; `Phase3Seeder` writes the history through the same actions the screens
  call, and runs every seeded movement and cash payment inside the right open shift.

## Note (step 4.3b, 2026-10-26): a payment on every channel carries the till's shift

Phase 4 takes payments on every enabled channel (phase-4 plan section 4.3). Rule 10 (`Totals::cash`, keyed on the payment's own shift) already
held for every channel through `Totals::byChannel(shift)`; what changes is the guard. `payments.require_shift_for_cash` asked an open shift of
cash only; plan 10.1 R-A asks it of **every** channel, because a Telebirr or bank payment with no shift would be in no shift's reconciliation.
It is built without a fourth kill-switch line: `config/payments.php` gains `require_shift` (`PAYMENTS_REQUIRE_SHIFT`), unset by default, and
`Payments\Support\ShiftRule` reads it as "follow the cash switch" while it is unset. So `PAYMENTS_REQUIRE_SHIFT_FOR_CASH=false` still switches
the rule off (for every channel), and the runbook's kill switch stays three lines; setting the new variable splits the two rules. The shared
lock on the shift row (rule 6, the freeze) is the same for every channel, taken after the order's row lock and before the receipt photo's.
