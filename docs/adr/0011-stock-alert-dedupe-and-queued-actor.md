# ADR 0011: Stock alerts fire once per episode, and queued work acts as the person who caused it

- Status: accepted (Phase 2, step 2.1); the Notifications half is added when it is built (step 2.5)
- Date: 2026-10-21
- Phase: 2

## Context

A product that runs out must tell the baker once, not once per sale, per retry or per redelivered event, and must tell
them again the next time it runs out after stock came back. The work is queued (it must not slow the sale that caused
it), and on the live host the queue is the database queue drained by cron once a minute, so it can run late, twice, or
overlap another run. Two rules of this system meet here: every write records who did it (rule 4), but a queue worker has
no request and so no actor; and a queued listener cannot be made unique, while a queued job can.

## Decision

1. **Episodes in the database.** `stock_alert_states` holds one row per episode of an item running out (`out`) or low
   (`low`) at a place. `is_active` is 1 while the episode is open and NULL once it is cleared; the unique key
   `(item_id, location_key, type, is_active)` therefore allows any number of cleared rows and exactly one open one, on
   MariaDB and PostgreSQL alike, with no partial index. `location_key` is the location id (0 for a branch-wide rule,
   step 2.6) because NULLs are distinct in a unique index.
2. **Only the evaluation that inserts the row announces it.** `AlertEvaluator` reads the current balance under the
   balance row's lock, inserts the open row with `insertOrIgnore` when `available <= 0` and none is open (then
   dispatches `StockOut`), and closes the open row when `available > 0` (then dispatches `StockRecovered`). A repeated,
   concurrent or redelivered evaluation finds the row and does nothing. `available <= 0` includes negative (stock that
   was reserved and then wasted); the episode ends only when available is above 0, not at 0. No hysteresis for `out`.
3. **A synchronous listener, a unique job.** `StockChanged` is handled by `QueueBalanceEvaluation`, which only
   dispatches `EvaluateBalance` (`ShouldBeUniqueUntilProcessing`, keyed branch, item, location, default queue,
   after commit). The job reads the current balance, not the event's payload, so one pending job covers any number of
   newer changes and a burst of movements queues one evaluation, not one each.
4. **The actor comes from the movement.** The job restores the actor stamp (user, acting-as, shift, station, device) of
   the latest movement of that balance and the branch, through `ActorContext::runAs`, which puts back exactly what was
   there afterwards (a worker runs many jobs in one process; a synchronous queue runs inside someone else's request).
   No system user exists. `caused_by_user_id` on the episode and on `StockOut` is the person who physically did it (the
   acting user when there is one), and every row and event produced downstream of the alert is written as that person.
   A balance nothing ever moved has no one to act as and nothing to evaluate: the job does nothing.
5. **Notifications keeps its own gate** (added in step 2.5, `notification_dedupe`): Notifications imports no producer
   (dependency-graph.md, cycle 7) and must be correct however often an event is redelivered or by whom it is produced.
   Inventory's table decides when an episode starts; Notifications' decides whether people have already been told about
   it, and clears on `StockRecovered`.

## Consequences

- The alert fires once per episode and again after recovery, however the queue behaves; the property test
  (`AlertEpisodePropertyTest`) walks random balances to prove it.
- On the live host the chain can lag up to a minute behind the sale (cron tick); the pad already shows the product
  unavailable at once, because availability is a read of the balance, not of the queue.
- The uniqueness lock uses the cache lock table, so it works on the database cache without Redis; a job that never
  starts releases it after ten minutes.
- If Ramin later wants the evaluation to run in the same request (`afterResponse`), only the listener changes.
