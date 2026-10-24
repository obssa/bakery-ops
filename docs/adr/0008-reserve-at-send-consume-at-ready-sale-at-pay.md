# ADR 0008: Reserve at send, consume at ready, sale at pay; one reservation per order line

- Status: accepted (Phase 1 gate, 2026-10-21)
- Date: 2026-10-20
- Phase: 1

## Context

Rule 3 says sending an order runs one transaction that locks the affected balance rows, checks every line against
its recipe, writes reservation movements and otherwise returns a shortfall naming the ingredient, the quantity short
and the station; consumption is written when the station marks the line ready, not at send. Architecture section 4.1
step 4 adds "reservation becomes `sale` on payment, or is released on void", and section 4.5 step 5 "Complete writes
`sale` movements for display-case products". Read together these say different things for different lines: a
sandwich is not a stocked thing that can be sold out of a location, its ingredients are; a cake slice is a stocked
thing. Phase 0 already built the ledger side (ADR 0002: `stock_balances` as the lock target, `MovementReason` as the
one reason -> delta map, `Reservations` that settle exactly once through derived client UUIDs). What was missing is
which movement each order event writes, per kind of line, and how an order with several lines does that without
deadlocking against another order.

## Decision

1. **Two kinds of order line**, decided by Catalog (`Routing::stationFor`, `items.fulfilment`), never by Orders:
   - a **station line** (`to_order` product routed to the kitchen or the bar): at send its recipe (with the line's
     modifier deltas) is exploded into ingredients and those are reserved at the **location of the line's station**;
     at **ready** the reservation is consumed as `production_consume` (one row per ingredient carrying the ref); at
     reject or void before ready it is released (`reservation_release`). No `sale` row is ever written for the
     product itself: it was never in stock.
   - a **display-case line** (`to_stock` or `bought_in` product, `stationFor` null): at send the product itself is
     reserved at the branch's **display case** location; the line is ready at once (the cashier picks it, there is no
     station step); when the order is **paid** the reservation is consumed as `sale`; at void it is released.
2. **One reservation per order line.** `order_lines.reservation_ref` is a UUID derived from the line's client UUID
   (UUIDv5 of `order-line:{branch_id}:{client_uuid}`), so an offline replay of the same line can only ever reach the same
   reservation. Lines are settled one by one (ready, reject), so each needs its own ref.
3. **One lock pass per order.** An order's lines are reserved, released or sold in one call:
   `Reservations::reserveMany(refs => lines)`, `releaseMany(refs)`, `consumeMany(refs, as)`. Each call explodes every
   line, locks the union of the balance rows once in ascending `(item_id, location_id)` order and writes every row in
   one `Ledger::appendMany`, so two orders whose lines share ingredients in a different order never wait on each other
   in a cycle. Calling the single-ref methods once per line inside one transaction would lock in line order and could
   deadlock; Orders never does that (step 1.1 contract test, group `concurrency`).
4. **Allocation in line order.** `reserveMany` gives each line what it needs in the order the lines were sent; a
   line that cannot be covered by what earlier lines left is short, and the shortfall names that line's ref, the
   ingredient, the quantity short in the ingredient's base unit and the station working from the location. Nothing is
   reserved for any line when one is short (the order stays `open`, the pad shows the shortfall on the exact line).
5. **Modifiers change what is reserved.** A line's modifiers carry recipe deltas in base units (Catalog
   `Modifiers::for`); `ReserveLine` takes them per unit, so "no onion" reserves no onion (and is not refused when onion
   is out) and "extra cheese" reserves the extra. Optional recipe lines are reserved only when a modifier adds them.
6. **Consumption is what was reserved.** Phase 1 has no screen to enter actual use, so ready consumes exactly the
   reservation; `consume(ref, actual)` (less releases the rest, more takes the excess from what is available) stays
   for Production and later phases. Negative stock stays refused (Phase 0 decision, still open with Ramin).
7. **Void releases, it never reverses.** A void (unpaid orders only in Phase 1, manager PIN) releases every open
   reservation of the order in one `releaseMany`; ingredients already consumed at ready stay consumed, because the food
   was made. Refunds of paid orders write reversing movements and arrive in Phase 4 (`Orders::refund`).

| Order event | Station line | Display-case line |
|---|---|---|
| send | `reservation` per ingredient at the station's location | `reservation` of the product at the display case |
| accept | nothing | (no station step) |
| ready | `production_consume` per ingredient, with the ref | ready at send, nothing written |
| reject | `reservation_release` | (no station step) |
| served | nothing | nothing |
| paid -> closed | nothing (already consumed) | `sale` of the product, with the ref |
| void (unpaid) | `reservation_release` if not yet ready | `reservation_release` |

## Consequences

- Positive: two waiters cannot sell the last cake or the last chicken breast; a rejected or voided sandwich deducts
  nothing; what the kitchen made is what left the shelf; the ledger shows for every order exactly the sequence
  `reservation -> production_consume` (station lines) or `reservation -> sale` (counter lines), which is what the
  Phase 1 gate checks through the API.
- Negative / cost: Inventory's `Reservations` grows batch methods and per-unit deltas (step 1.1 provider fix, with its
  own module tests); a made-but-voided dish is not recorded as waste in Phase 1 (its ingredients are consumed, the
  dish itself was never stocked).
- Later phases: Production (Phase 2) uses the same ledger without reservations (`appendMany` of consume + output);
  Shifts (Phase 3) count what is left; Phase 4 refunds write reversing rows that reference the originals.

## As built

Phase 1 built exactly the decisions above; nothing in them changed. What the build settled, and where the ledger shows it:

- **One lock pass per order is real.** `Reservations::reserveMany`, `releaseMany` and `consumeMany` explode every line,
  lock the union of the balance rows once in ascending `(item_id, location_id)` order and write every row in one
  `Ledger::appendMany`. The step 1.1 concurrency test (`tests/Contract/Orders/InventoryLockOrderConcurrencyTest.php`: 50
  rounds, two orders that share ingredients and list them in opposite order, real parallel processes) deadlocked in every
  round against a provider that locked line by line, and passes with the single ascending pass with the engine's
  deadlock counter unchanged. Orders never calls the single-ref methods in a loop.
- **Send** (`SendOrder`, one `AuditedAction`, one transaction): lock the order, resolve every line at `sent_at`, one
  `reserveMany` (refs in line order, the modifiers' recipe deltas from the line's snapshot), and only then take the
  day's number from `order_counters`, so that row is held for the last writes only. A `Shortfall` throws `SendRefused`
  and everything rolls back: no reservation, no number, no event, no audit row; the cart stays `open`. The gate checks
  the one transaction (`tests/Gate/SendOrderGateTest.php`).
- **Ready** is `Reservations::consume(ref)` in the same transaction as the line's move to `ready`: one
  `production_consume` per ingredient carrying the ref, what was reserved is what was used. A second tap is refused by the
  line machine (409) and Inventory settles a ref once besides. **Reject** is `release(ref)`.
- **Sale at payment** is `Support\Settlement`: `served -> paid`, `consumeMany(refs, sale)` for the served display-case
  lines, `closed`. The two orders of events share it: payment after the food (`ApplyPayment`, a synchronous listener of
  `PaymentRecorded` inside the payment's transaction) and food after the payment (`LineWork`, right after the last line is
  served). The `sale` row is written when the order closes, not when the line is served.
- **Void** releases every line that is not rejected or voided in one `releaseMany`, including display-case lines (ready at
  send, still holding their reservation until the sale). What was consumed at ready stays consumed; nothing is reversed.
  A line rejected after full payment would leave an overpayment: refunds and reversing movements are Phase 4.
- **The ledger shows the sequence the decision promises**: `reservation -> production_consume` per station line,
  `reservation -> sale` per display-case line, `reservation -> reservation_release` for a rejected or voided one.
  `tests/Gate/OrderLedgerPropertyTest.php` (100 seeded sequences of at most 50 operations, group `gate:p1`) checks after
  every operation that movements, balances and the view agree, that `reserved` equals the reservation rows of the lines
  still open, and that `available` never goes below zero; at the end it checks the sequence of every closed order's lines.
  `OrderMovementsGateTest` and the `p1-order-flow` Playwright flow check the same through the API and the screens.
- **Negative stock** is still refused (the Phase 0 decision, still Ramin's); see the Phase 1 gate report.

## Enforcement

`apps/api/tests/Contract/Orders/InventoryContractTest.php` (`contract:orders->inventory`: sum per shared ingredient,
shortfall shape and its line ref, release twice is a no-op, one `production_consume` per ingredient, deltas,
`consumeMany(Sale)`), `InventoryLockOrderConcurrencyTest.php` (group `concurrency`: 50 rounds of two orders sharing
ingredients in opposite line orders, real parallel processes, no deadlock), the Phase 1 gate tests (`gate:p1`: movement
sequence per line, void releases, one transaction per send), `MovementReason` (one reason -> delta map),
`tests/Arch/LedgerIsSoleWriterTest.php`.

## Alternatives considered

- One reservation per order: consuming one line at ready would release (or consume) the whole order. Rejected.
- Consume everything at send: a rejected or voided line would deduct ingredients that were never used (rule 3).
  Rejected.
- `sale` rows for station lines at payment as well: double counts the ingredients already consumed at ready, or
  needs product stock that does not exist. Rejected.
- Orders locking the balance rows itself before calling `reserve` per line: needs the recipe explosion in Orders
  (dependency-graph.md cycle 6 forbids re-implementing it). Rejected.

## Note, 2026-10-24: shifts in the path

Reserve at send, consume at ready, sale at pay is unchanged. Since step 3.7e a consume or a sale at a station that tracks shifts needs that station's shift
open (a reservation does not: it has no `on_hand` effect), and a cash payment needs the till's open shift; the stamp of the reservation, the consume and the
sale is the shift of the station where each happened. With the three rules off (the kill switch) the old behaviour is exactly back.
