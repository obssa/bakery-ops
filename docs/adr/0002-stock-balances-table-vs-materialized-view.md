# ADR 0002: `stock_balances` table is the lock target; `item_stock_view` is the oracle

- Status: accepted
- Date: 2026-10-18
- Phase: 0

## Context

Rule 1 says stock is a sum over an append-only `stock_movements` ledger and names a materialized view
`item_stock_view (on_hand, reserved, available)`. Rule 3 says sending an order runs one transaction with
`SELECT … FOR UPDATE` on the affected stock rows. PostgreSQL cannot lock rows of a materialized view;
`REFRESH MATERIALIZED VIEW` takes an exclusive lock on the whole view, and `REFRESH … CONCURRENTLY`
cannot run inside the reservation transaction. Both rules cannot be satisfied by the view alone.

## Decision

- `stock_balances (branch_id, item_id, location_id, on_hand NUMERIC(14,4), reserved NUMERIC(14,4))`
  is upserted by `Ledger::append` in the same database transaction as every movement. It is the row that
  `SELECT … FOR UPDATE` locks, always in ascending `(item_id, location_id)` order, and the read path for
  availability.
- `item_stock_view` stays as a materialized `SUM` over `stock_movements`, refreshed by a queued job and
  on demand. It is the reconciliation oracle and the admin ledger report, never a lock target.
- The Phase 0 gate property test asserts `sum(movements) == stock_balances == item_stock_view` after
  random movement sequences, which is stronger than the document's original `sum == view`.
- `Ledger` is the only class allowed to write `stock_movements` or `stock_balances`.

## Consequences

- Positive: rule 3 is implementable; two waiters cannot sell the last cake; drift between the table and
  the ledger is detected by the gate test and by a nightly reconciliation job.
- Negative: one more table to keep consistent; the discipline that only `Ledger` writes it must be
  enforced mechanically.

## Note (2026-10-19)

The live host runs MySQL 8 (ADR 0006), which has no materialized views: `item_stock_view` is a plain view /
reconciliation query there. The decision stands unchanged because `stock_balances` was already the lock
target and read path.

## Enforcement

`tests/Arch/LedgerIsSoleWriterTest.php`, `tests/Gate/Phase0GateTest.php` (`LedgerPropertiesTest`,
`ReservationLockTest`), PG trigger `forbid_update_delete()` on `stock_movements`.

## Alternatives considered

- Advisory locks keyed by item/location: works, but invisible in the schema and easy to forget on a
  new code path. Rejected.
- Recompute availability from the ledger on every send under a table lock: correct but serialises every
  order in the shop. Rejected.
