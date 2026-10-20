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

The live host runs MariaDB 10.11 (ADR 0006; this note first said MySQL 8), which has no materialized views: `item_stock_view` is a plain view /
reconciliation query there. The decision stands unchanged because `stock_balances` was already the lock
target and read path.

## Note (2026-10-19, step 0.6: how it was built)

- `App\Modules\Inventory\Ledger\Ledger` is the writer; the `StockMovement` / `StockBalance` models live in the
  `Ledger` namespace and nothing outside it uses them (readers get `Contracts\Movement` / `StockLevel`).
- A balance row is created at zero the first time an (item, location) moves (`insertOrIgnore`, then the
  `FOR UPDATE` read); rows are locked one by one in ascending `(item_id, location_id)` order, so the lock order does
  not depend on the query plan.
- A batch that would take `on_hand` or `reserved` below zero is refused whole (409 `inventory.stock.insufficient` /
  `inventory.stock.reserved_below_zero`); CHECK constraints on `stock_balances` are the backstop. `available` may
  still go negative when stock held for orders is wasted afterwards; Availability then answers zero.
- `Reservations` settle exactly once without relying on isolation levels: each row a reservation writes carries a
  `client_uuid` derived from (ref, role, item, location), and its release and its consumption share the same derived
  uuid, so the unique `(branch_id, client_uuid)` index admits only one of two racing settlements.
- `item_stock_view` is a plain view (`CREATE OR REPLACE VIEW`, migration `2026_10_19_210400`).

## Enforcement

`tests/Arch/LedgerIsSoleWriterTest.php`, `tests/Gate/LedgerPropertiesTest.php`, `tests/Gate/ReservationPropertyTest.php`,
`tests/Gate/ReservationLockTest.php` (group `gate:p0`), the `stock_movements_forbid_update_delete_*` triggers
(MySQL / MariaDB) and `forbid_update_delete()` (PostgreSQL) on `stock_movements`.

## Alternatives considered

- Advisory locks keyed by item/location: works, but invisible in the schema and easy to forget on a
  new code path. Rejected.
- Recompute availability from the ledger on every send under a table lock: correct but serialises every
  order in the shop. Rejected.
