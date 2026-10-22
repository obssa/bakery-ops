# ADR 0013: Shift attribution rules

- Status: proposed (Phase 3, step 3.2a; becomes accepted in the Phase 3 gate report)
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
   (rule E1, section 10.1, exemptions included: `reservation`, `reservation_release`, a count's own adjustment). The flag stays
   off everywhere until step 3.7e.
6. **The freeze.** A station whose shift is not `open` refuses on-hand movements from anyone not covered by a shift of their
   own, so a closing count compares against a ledger that is not moving. `close` takes the shift row `FOR UPDATE`; the ledger
   check takes it shared: a movement that began before the close finishes inside the counted figure, one that begins after is
   refused. Lock order: domain row, `shift_instances`, `stock_balances` ascending, `item_costs` ascending.
7. **Call-ins.** `ShiftAttacher::attach` for a person with no open shift at a station they can work at opens an ad-hoc shift
   (`origin = call_in`, opened carried) and puts them on the roster. The accept row, the batch and its movements then carry it.
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
