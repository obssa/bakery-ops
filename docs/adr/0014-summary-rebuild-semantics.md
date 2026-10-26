# ADR 0014: Summary rebuild semantics

- Status: proposed (step 4.4a, `phase-4/analytics-rebuild`); accepted at the Phase 4 gate
- Date: 2026-10-26
- Phase: 4

## Context

The admin's dashboards (sales, channels, products, stations, staff, shifts) must answer in well under a second on a shared cPanel host
with a CPU limit, a cron-drained database queue and no long-running process (ADR 0006, phase-4 plan 10.1). Aggregating the raw tables
of orders, payments, the ledger and the shifts in a request would not: a 90-day range touches tens of thousands of rows in six modules.
The figures also disagree about time. An order sent at 23:50 and paid at 00:10 belongs to which day? A Night shift works 22:00 to
06:00. A refund comes days after its sale. The moving average of an ingredient changes when a purchase lands, so a margin worked out
next month must not differ from the one worked out today. And two rebuilds of a day can start at once (a shift lock and the nightly
run, or two workers), so a day must never be half rebuilt or rebuilt twice over. This ADR fixes what a summary row means and how it
is made. Step 4.4a builds it (`App\Modules\Analytics\Contracts\Rebuild`); 4.4b adds the triggers, 4.4c the dashboards.

## Decision

1. **Summary tables, derived.** `daily_sales`, `daily_hour_sales`, `daily_channel_totals`, `daily_item_metrics`, `daily_station_metrics`
   and `daily_user_metrics` hold one row per branch, LOCAL day and dimension; `analytics_days` holds one row per built day. They are
   derived data: written only by `Rebuild::day`, deleted physically when a day is rebuilt, with no `client_uuid`, no actor stamp and no
   `deleted_at`. `branch_id` leads every primary key. Money is DECIMAL(12,2), quantities DECIMAL(14,4) (ADR 0004). `daily_sales` has a
   row for every built day, zeros on a quiet day, so "built and empty" differs from "not built"; the other tables have a row only where
   there is a figure.
2. **Read through the ports only.** Analytics reads the other modules through their `Contracts\Facts` classes and the `*Fact` rows they
   return, Catalog's contracts (names, categories, recipe versions) and Shared; never a model, an action or a table of another module
   (`ModuleBoundariesTest`). A provider that changes its schema fails `contract:analytics->facts` in its own module job.
3. **Days are local.** Instants are stored in UTC and bucketed by the branch's timezone (`Africa/Addis_Ababa`, UTC+3, no daylight
   saving; the code asks the zone, `Shared\Time\LocalDay`). 20:59:59Z is 23:59:59 on day D; 21:00:00Z is D+1. A branch whose timezone
   changes would need its history rebuilt (not built: R-S).
4. **Each figure has its own clock.**

   | Figure | Day it lands on |
   |---|---|
   | Revenue, orders, hour sales, units sold, item revenue and COGS, the waiter's orders | the order's `settled_at` (the balance reached zero) |
   | Payments (channel totals, the cashier's payments) | `received_at` |
   | Refunds | `refunded_at` (cash basis: net revenue of a day can be below zero) |
   | Voids, cancellations | `voided_at`, `cancelled_at` |
   | Prep times, lines prepared, tickets / lines served / lines rejected | the line's `ready_at` / `served_at` / `rejected_at` |
   | Production (batches, produced, waste) | `finished_at` |
   | Stock movements (produced, consumed, waste, purchased, adjusted) | `created_at` |
   | Closing on-hand and its value | the end of the local day (the ledger's sums up to the next local midnight) |
   | Shifts counted, shifts locked | every local day between the shift's opening and its lock (a Night shift counts on two days) |
   | Variance lines, cash variance | the shift's `date` (its local start date) only, so nothing is counted twice |
   | Stock-outs | the day the episode began |

   Not `business_date` (the numbering day) and not `paid_at` (which waits for the last line to be served): R-J.
5. **Cost of sales is never estimated (R-K).** `cogs_total` is the units of each line times the cost of the recipe version the line
   snapshotted: the sum over the version's required lines of qty x (100 + waste %) / 100 x the ingredient's moving average in force at
   the end of the sale's local day (`Inventory\Facts::unitCosts`), divided by the yield; a line with no version (display case, bought in)
   is costed at the product's own moving average. The item-day's sum is exact and rounded half-up once. A version Catalog does not know,
   a yield that is not positive, an ingredient or a product with no cost: that part counts 0 and `cogs_complete` is false, which the
   dashboards show as "cost incomplete". A later purchase or a later recipe version never moves a past day's figure. A modifier's recipe
   delta is not costed (the line's Facts row does not carry it).
6. **`Rebuild::day(branch, localDate, trigger)` is one transaction, idempotent.** With the branch resolved for the call
   (`CurrentBranch::runAs`): (1) upsert the day's `analytics_days` row with an update that changes nothing, never a lock on a row that may
   not exist (traps.md, "First-row race"); (2) `SELECT ... FOR UPDATE` it, so a second rebuild of the day waits for the first; (3) read
   every Facts port after the lock, one snapshot; (4) build every row in memory with six pure builders; (5) replace the day's rows of each
   table; (6) write `version + 1`, `rebuilt_at`, the trigger, the time it took, the row counts, the fingerprint and `changed`. A failure
   rolls all of it back: a day is its old rows or its new rows. `AnalyticsDayRebuilt` is announced after the commit.
7. **Replacing a day never locks a range.** Each table's day is written by upserts in primary-key order and the rows it no longer has
   are deleted by their full primary key. The plan's "delete the day, then insert" was measured and refused: a range `DELETE` takes a
   gap lock after the day, and a rebuild of the neighbouring day that inserts a row into that gap deadlocks with it (`RebuildRaceTest`:
   InnoDB deadlock 1213 within 30 rounds, with or without skipping an empty day; none with the upserts).
8. **Fingerprint and versions.** The fingerprint is the sha256 of the canonical form of every summary row of the day (tables in name
   order, keys sorted, rows sorted, decimal strings with their scale, `rebuilt_at` left out). Two rebuilds of unchanged facts give the
   same rows and the same fingerprint; only `version`, `rebuilt_at` and `changed` move. `changed` is whether the fingerprint differs from
   the run before (the first run is a change).
9. **Triggers, one day per job.** `shift_locked` (the local days of a locked shift's life, two for a Night shift), `nightly` (00:05 local:
   yesterday and the day before, the second heals a late lock), `manual` (`analytics:rebuild`, `POST /analytics/rebuild`), `touch`
   (today, debounced after a payment) and `seed`. Every job is one branch and one day (`RebuildDay`, unique per `branch:day` for 120 s,
   3 tries; a failure is logged with the branch, the day and the class of the error, never the facts or the message), because a
   cron-drained worker has about 50 s. The schedule and the listeners are step 4.4b. An interactive request never aggregates raw tables;
   the one exception is the admin's bounded `sync` rebuild of at most 7 days.
10. **What the dashboards may read.** Only the summary tables, `analytics_days` and the name tables behind Catalog, Payments and Identity
    contracts (step 4.4c, a query-log test). Every answer says `as_of` (the oldest `rebuilt_at` it used) and the days of its range with
    no `analytics_days` row, shown as "not built", never as zero (R-U).

## Consequences

- Today's figures lag the till by at most about three minutes (one cron tick plus the touch delay); past days are as fresh as the last
  shift lock or the nightly run. The freshness feed (4.4b) makes that visible.
- A rebuild costs one read of each port for one day and a few hundred row writes; the nightly run is two per branch.
- The Facts ports grew with the tables' needs (step 4.4a, contract-first): `Orders\Facts::voids` and `::stationLines`,
  `PaymentFact::duplicateReference`, `RefundFact::orderRefundId`, `StockOutFact::stationId`, `ShiftFact::varianceLineCount` and
  `cashVariance`, `ItemSummary::categoryId`.
- Display-case products made in the house carry no recipe version on their order lines and no cost of their own, so their COGS is
  `cogs_complete = false` until either Orders snapshots the version on such lines or Production values its output; this is visible,
  not hidden (for Ramin to decide).
- `duplicate_reference_count` is as of the rebuild: a duplicate made later flags an earlier day only when that day is rebuilt.
- `refunded_qty` stays 0 until order refunds exist (step 4.1b, on hold for R-C); the money side of refunds is already counted.

## Alternatives considered

- **Aggregate in the request.** Too slow on the shared host for a 90-day range; the dashboards' budget is 1000 ms first paint.
- **Materialized views.** MariaDB has none, and the code stays portable (ADR 0006); refresh semantics would still be ours to define.
- **Incremental counters updated by every write.** Every module would write Analytics' tables in its own transaction (a dependency the
  wrong way), and a missed or doubled event would drift forever; a rebuild from facts heals itself.
- **READ COMMITTED for the rebuild,** which takes no gap locks: every port would then read a different moment and the day would not be
  one snapshot.
