# Phase 3 — Shifts and handover (weeks 9–11)

The control point of the business. After this phase the shop runs a one-week pilot, so the gate is a
full handover: closing count with a variance, approval, lock, hash, and the next opening pre-filled.
This phase also closes the two soft cycles left open since Phase 0 (shift attribution on movements,
presence resolution for production).

## Entry criteria (`gate_check.py --mode entry --phase 3`)
- `gate-p2` green on `main`; plan approved.
- `contract:shifts->inventory|identity|payments` green with no Shifts code yet.

## Scope in build order

| Step | Module | Builds | Consumes | Groups |
|---|---|---|---|---|
| 3.1 | contracts | `tests/Contract/Shifts/{Inventory,Identity,Payments}ContractTest.php` | | `contract:shifts->*` |
| 3.2 | shifts.instances | `shift_templates`, `shift_instances` (`date` = local start date for cross-midnight), `shift_roster`, state machine, `Shifts::current(station)`, ActorContext middleware filling `shift_instance_id`, `openAdHoc`, rebind `StationPresenceResolver` / `ShiftAttacher` to Shifts, flip `ledger.require_shift_for_station_locations=true`, update Phase 0–2 seeds to open shifts for every station the earlier e2e touches | Identity, Shared | `module:shifts`, `unit` |
| 3.3 | shifts.counts | `stock_counts`, `stock_count_lines`; opening expected = previous locked closing; closing expected = `balanceAt(now)`; bilingual `variance_reasons`; lines immutable after submit; approve → `count_adjustment` movement; reject → recount with both attempts kept; overseer co-sign | Inventory, Identity | `feature` |
| 3.4 | shifts.locks | `payload_hash`, `previous_hash` per `(branch, location)`; canonical JSON; DB trigger forbids UPDATE on locked rows except `superseded_by_id`; admin unlock creates a new version; `HashChain::verify` | Audit | `invariants`, `unit` |
| 3.5 | shifts.cash | `cash_declarations` (denominations JSONB, opening float); expected = float + `Totals::cash(shift)`; per-channel reconciliation counts | Payments | `feature` |
| 3.6 | shifts.handover | `handover_notes`; `GET /replay?station&at` (balances, open orders, who was on) | Inventory, Orders | `feature` |
| 3.7 | web | open/close flows on KDS, production board, till widget; admin Shifts (templates, roster calendar, side-by-side variance approval with photos, notes, replay at a point in time) | | Playwright `p3-handover`, `p3-opening-prefill` |
| 3.8 | docs | `Phase3Seeder` (two days of locked history); ADR 0012 hash chain canonical JSON; ADR 0013 shift attribution rules; report; pilot feedback template | | |

## Contract tests (consumer view, written first)
- Inventory: `balanceAt(location, t)` equals the sum of movements with `created_at <= t`;
  `Ledger::append` accepts `count_adjustment` with `ref_type=stock_count_line`.
- Identity: `canCount`, `isOverseer` per station; `StationPresenceResolver` can be rebound to the Shifts
  implementation and the Production contract still passes.
- Payments: `Totals::cash(shift)` sums only non-voided cash payments whose order carries that shift;
  `Totals::byChannel(shift)` counts and sums per channel.

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
4. Phases 0–2 gates still green with the updated seeds.

## Artifacts
ADR 0012, ADR 0013, OpenAPI, ERD, `phase-3-report.md`, pilot feedback list template, STATUS: shifts `stable`.

## Traps specific to this phase
Canonical JSON for hashes · locked-row trigger exceptions for `superseded_by_id` · time zone and local
start date · seeds for earlier phases must open shifts once enforcement is on · rebinding interfaces
without breaking the Phase 2 contract.
