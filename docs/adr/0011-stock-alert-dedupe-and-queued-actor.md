# ADR 0011: Stock alerts fire once per episode, and queued work acts as the person who caused it

- Status: accepted (Phase 2 gate, 2026-10-22; first accepted in step 2.1); the Notifications half was added when it was built (step 2.5a, decision 5); the `low` level in step 2.6a (decision 6)
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
5. **Notifications keeps its own gate** (step 2.5a, `notification_dedupe`): Notifications imports no producer
   (dependency-graph.md, cycle 7) and must be correct however often an event is redelivered or by whom it is produced.
   Inventory's table decides when an episode starts; Notifications' decides whether people have already been told about
   it, and clears on `StockRecovered`.
   - *Key.* `stock_out:{item}:{location}` (the manifest's item, location, type), built from the event payload by the type
     registry (`config/notifications.php`). `is_active` is 1 while the gate is closed to repeats and NULL once cleared, with the
     unique key `(branch_id, dedupe_key, is_active)`, the same technique as the episodes: any number of cleared rows and one
     active one, on both engines, no partial index.
   - *A claim is one INSERT.* `Dedupe::claim` is `insertOrIgnore`: the unique key picks one winner when two deliveries race, and
     nothing reads first or takes `SELECT ... FOR UPDATE` on a row that may not exist (traps.md, "First-row race"; the test
     records the statements). `Dedupe::clear` closes the active row and keeps it as history.
   - *Inbox line and gate in one transaction.* `Notifier::send` claims the gate and writes every recipient's inbox line in one
     transaction, so a failed attempt leaves no gate behind and a retry is not held back. Nobody to tell claims nothing. A gate
     claimed by a redelivery that wrote no new line (every line already existed) is given back, so a stale redelivery of an
     old episode cannot swallow the next one.
   - *The inbox line is keyed per episode.* `stock_out:{item}:{location}:{alert}` is the unique `(user_id, dedupe_key)` of the
     line, so a redelivery writes one line per person and the next episode, after recovery, is a new line. The two layers are
     different questions: the gate asks "were we already told about this run-out?", the line key "do I already have this
     event?".
   - *The actor of the listener.* Inventory's `RestoresActor` reads Inventory's ledger and Notifications may not import it, so
     Notifications' own `Support\EventScope` resolves the event's branch and runs the listener as the person the event names
     (`caused_by_user_id` for a stock-out, the maker of a line, the decider of a transfer) through `ActorContext::runAs`, putting
     back exactly what the worker had. The inbox is not an actor-stamped table, so the person lands in
     `payload.caused_by_user_id`.

6. **The `low` level (step 2.6a).** A live, active reorder rule makes a second episode kind for the same balance: `low` opens when
   `available <= min_qty` and closes when `available > min_qty` (no hysteresis: the plan default, open question 3), beside `out`
   and independent of it (a fall from fine to zero opens both, recovery closes each on its own level). The key is the same
   `(item_id, location_key, type, is_active)`; a rule with no place is branch-wide, `location_key` 0, and its number is the sum
   of `available` over the stock places. The rule is read at every evaluation, so a changed minimum moves the episode and a deleted
   or switched-off rule closes it; nothing but `AlertEvaluator` writes an episode, so a rule change queues the evaluation of the
   balance it covers (after commit) instead of touching the table. The claim is an `INSERT` as for `out`; the only `FOR UPDATE` is
   the balance row. Notifications gates `stock_low:{item}:{location}` apart from `stock_out`, cleared by `StockRecovered` of type
   `low`, so neither alert uses up or re-arms the other.

## Consequences

- The alert fires once per episode and again after recovery, however the queue behaves; the property test
  (`AlertEpisodePropertyTest`) walks random balances to prove it.
- On the live host the chain can lag up to a minute behind the sale (cron tick); the pad already shows the product
  unavailable at once, because availability is a read of the balance, not of the queue.
- The uniqueness lock uses the cache lock table, so it works on the database cache without Redis; a job that never
  starts releases it after ten minutes.
- If Ramin later wants the evaluation to run in the same request (`afterResponse`), only the listener changes.

## As built

Built across 2.1 (the evaluation and the actor), 2.5 (Notifications' own gate), 2.6 (the `low` level, the sweep) and 2.7 (the
automatic request). The decisions above hold; what the code adds or says differently:

- **Two levels, two independent episodes.** `out` (available at or below 0) and `low` (a live, active reorder rule says available is
  at or below its minimum) are separate rows in `stock_alert_states`; one movement from fine to zero opens both, recovery closes both
  (`StockRecovered` carries the type). A rule changed in mid-episode moves the episode on the next evaluation, which always reads the
  live rule; a branch-wide rule is `location_key` 0 and reads the sum of `available` over the branch's stock places.
- **The sweep.** `inventory:sweep-alerts` (hourly) re-evaluates the open episodes and every active rule, so a job lost to a failed
  queue self-heals; it is the same evaluation, so it cannot double-announce.
- **Notifications' gate is a second, separate table** (`notification_dedupe`, key `{type}:{item}:{location}`, `is_active` 1 or NULL,
  unique with the branch), so a redelivered or foreign `StockOut` creates no second line; the inbox row has its own unique
  `(user_id, dedupe_key)` that includes the episode id. The "unique key over NULL" technique (a nullable `is_active`, 1 while open) is used
  by `stock_alert_states`, `notification_dedupe`, and the production request's open stock-out key, on MariaDB and PostgreSQL alike with
  no partial index; where a soft-delete column is involved a generated column carries it.
- **The actor of queued work** is restored by `Support\RestoresActor` (Inventory, from the ledger row of the movement) or
  `Support\EventScope` (Notifications) and `Support\EventActor` (Production), both from the event's `causedByUserId`, through `ActorContext::runAs`, which also
  resets the scoped instances after each job so a long-lived worker leaks nothing (CI's `worker` container proves it). No system user
  exists. The rows written by queued work therefore name the person who caused them: the episode (`caused_by_user_id`), the automatic
  request (`requested_by_user_id`, `created_by_user_id`) and its audit row `production_request.auto_create`, and every inbox line
  (`payload.caused_by_user_id`). "That the cause was automatic" is carried by the action name (`production_request.auto_create`)
  and by the line's type, not by a `context.cause` key as the first draft said. `Phase2ActorGateTest` pins this.
- **Evaluation.** `EvaluateBalance` is `ShouldBeUniqueUntilProcessing` (branch, item, location) on the default queue after commit and
  reads the current balance, so a burst of movements costs one job; the lock is the database cache's `cache_locks` table, so no Redis
  is needed on the live host. All six Notifications listeners and `CreateAutoRequest` are `ShouldQueue` and
  `ShouldHandleEventsAfterCommit` (an arch test enforces it).
- **Where it is proven.** `AlertEvaluatorTest` (decision table), `AlertEpisodePropertyTest` and `LowAlertEpisodePropertyTest` (random
  availability walks: episodes alternate, never two open, `StockOut` count equals the falls), `StockAlertDedupeTest` (end to end on the
  sync queue), the race tests in `tests/Gate/` (two evaluations at the moment of reaching 0, two auto requests for one episode), the
  `Queue::fake` / `sync` pairs, and `StockOutToProductionGateTest` on the seeded demo product.
- **Live timing.** As the consequences say: the chain waits for the cron tick on the live host; the first `@live` run of it took about a
  minute end to end, and the e2e helper `waitForQueue` allows 150 s there and 30 s in CI.
