# Phase 3 — Shifts and handover (weeks 9–11)

The control point of the business. After this phase the shop runs a one-week pilot, so the gate is a
full handover: closing count with a variance, approval, lock, hash, and the next opening pre-filled.
This phase also closes the two soft cycles left open since Phase 0 (shift attribution on movements,
presence resolution for production).

## Entry criteria (`gate_check.py --mode entry --phase 3`)
- `gate-p2` green on `main`; plan approved.
- `contract:shifts->inventory|identity|payments|orders` green with no Shifts code yet.

## Scope in build order

| Step | Module | Builds | Consumes | Groups |
|---|---|---|---|---|
| 3.1 | contracts | `tests/Contract/Shifts/{Inventory,Identity,Payments,Orders}ContractTest.php` | | `contract:shifts->*` |
| 3.2 | shifts.instances | `shift_templates`, `shift_instances` (`date` = local start date for cross-midnight), `shift_roster`, state machine, `Shifts::current(station)`, ActorContext middleware filling `shift_instance_id`, `openAdHoc`, rebind `StationPresenceResolver` / `ShiftAttacher` to Shifts, update Phase 0–2 seeds and every fixture to open shifts (`DemoShifts::ensureOpen`, `Tests\Support\Shifts`, `e2e/support/shifts.ts`) so the rules can be rehearsed in CI (`enforced-gates`); the flag stays off until 3.7e | Identity, Shared | `module:shifts`, `unit` |
| 3.3 | shifts.counts | `stock_counts`, `stock_count_lines`; opening expected = previous locked closing; closing expected = `balanceAt(now)`; bilingual `variance_reasons`; lines immutable after submit (a submit drops the draft lines of items deleted since); expected = the locked ledger balance at submit; approve → one `count_adjustment` per variance line as the counted shift; reject → recount with both attempts kept; overseer co-sign; a count with no difference locks at submit | Inventory, Identity | `feature` |
| 3.4 | shifts.locks | `payload_hash`, `previous_hash`, `chain_seq` per `(branch, location)` claimed by a unique key; canonical JSON with its own encoder and the stored bytes; DB trigger forbids UPDATE on locked rows except `superseded_by_id` and DELETE of any count; admin unlock creates a revision; `HashChain::verify`, nightly `shifts:verify-chain`, `scripts/verify_chain.py` | Audit | `invariants`, `unit` |
| 3.5 | shifts.cash | `cash_declarations` (denominations JSON, float carried and left); expected = opening count + `Totals::cash(shift)`; per-channel reconciliation counts; `payments.require_shift_for_cash` | Payments | `feature` |
| 3.6 | shifts.handover | `handover_notes`; `GET /replay?station&at` (balances, open orders, who was on) | Inventory, Orders | `feature` |
| 3.7 | web | five PRs: shift bar, counts, admin Shifts (templates, roster, side-by-side approval, notes, replay), cash declaration, and 3.7e the flip (the three rules on by default, kill switch documented). Photos on variance lines are not built (the column exists) | | Playwright `p3-handover`, `p3-opening-prefill` |
| 3.8 | docs | `Phase3Seeder` (two days of locked history on a fresh branch, only today's part on a branch that already holds movements) and the `DemoClock` anchor; ADR 0012 hash chain canonical JSON; ADR 0013 shift attribution rules; the `gate:p3` tests; runbook Phase 3 section; report; pilot feedback template | | |

## Contract tests (consumer view, written first)
- Inventory: `balanceAt(location, t)` equals the sum of movements with `created_at <= t`;
  `Ledger::append` accepts `count_adjustment` with `ref_type=stock_count_line`.
- Identity: `canCount`, `isOverseer` per station; `StationPresenceResolver` can be rebound to the Shifts
  implementation and the Production contract still passes.
- Payments: `Totals::cash(shift)` sums only non-voided cash payments carrying that shift themselves (the money enters the drawer when the payment is taken, so the key is the payment's own `shift_instance_id`, not its order's);
  `Totals::byChannel(shift)` counts and sums per channel.
- Orders: `Orders::openAt(station, t)` reads the timeline, never the current status (the replay of 3.6 builds on it).

## Functional tests
Instance state machine exhaustive; `Shifts::current` with overlapping templates; middleware fills
`shift_instance_id`; with enforcement on, a movement at a station location without an open shift is
rejected (and the earlier seeds now open shifts, so earlier gates still pass); counts immutable after
submit; approve writes adjustment; reject keeps both; lock hash = `sha256(previous_hash + canonical_json)`;
tamper detection; unlock creates a new version; cash declaration arithmetic; replay equals state at time
(property-style over seeded history); Night template crossing midnight gets the right `date`.

## Exit gate (`gate:p3`, CI job `gate-p3`)
1. Playwright `p3-handover`: kitchen closing count with one variance → reason required → submit → admin
   side-by-side approve → `count_adjustment` visible in the ledger → lock → hash shown → next shift
   opening pre-filled with the locked values.
2. Playwright `p3-opening-prefill` across midnight on the Night template.
3. `gate:p3` Pest: `HashChainTest` (tamper), `ShiftAttributionTest` (movements during an open shift
   carry its id), `PresenceResolverSwapTest` (auto-request targets on-shift users first), enforcement
   rejection, replay property.
4. Phases 0–2 gates still green with the updated seeds, with the shift rules on (`gate-p0..p2` and the e2e jobs) and off (`rules-off-gates`, the kill-switch state).

## Artifacts
ADR 0012, ADR 0013, OpenAPI, ERD, `phase-3-report.md`, pilot feedback list template, STATUS: shifts `stable`.

## Traps specific to this phase
Canonical JSON for hashes · locked-row trigger exceptions for `superseded_by_id` · time zone and local
start date · seeds for earlier phases must open shifts once enforcement is on · rebinding interfaces
without breaking the Phase 2 contract.

## As built: where the build departed from the plan

- The three rules (`ledger.require_shift_for_station_locations`, `payments.require_shift_for_cash`, `shifts.open_ad_hoc_on_accept`) are separate flags with one env variable each; all three ship on since 3.7e. The kill switch is three lines in the server `.env` plus `php artisan config:cache`.
- Expected figures come from the balance rows locked under `Ledger::lock` at submit, not from `balanceAt`; `stock_movements.created_at` stays whole seconds.
- Cash declarations are the cash-drawer count's own row (`cash_declarations.count_id`), with the opening float carried from the last locked closing; the first opening of a drawer is taken as counted. A drawer is declared whenever a counted till shift opens; a carried start declares nothing.
- Count lines of deleted items are dropped at submit (the lines trigger allows DELETE of draft lines); a switched-off item with stock is countable.
- The settling ETag, the login token file and the reorder-rules list that left out rules of deleted items are live findings of 3.7b to 3.7e (see traps.md).
- `enforced-gates` became `rules-off-gates` at 3.7e.
- Photos on variance lines and a cash rounding rule are not built.
