# ADR 0031: Claims that outlive a lock: in-flight idempotency keys and claimed notification deliveries

- Status: **accepted** (phase-6/delivery-and-key-locks, 2026-11-20)
- Date: 2026-11-20
- Phase: 6 (a fix after the claim)

## Context

The review of the app's implicit state machines (2026-11-20) found two places where "is this already being done?" was answered by a check
that another process could pass at the same time.

1. **A notification delivery could be sent twice (item 13).** `DeliverNotification` read the delivery, checked `status === 'pending'`, sent
   it, then wrote the result. Nothing claimed the row between the check and the write. Two copies of the job (a database-queue job handed
   out again after `retry_after`, 90 s, while the first worker was still sending; or the same job on two workers) both saw "pending" and
   both sent.
2. **A slow write could run twice under one Idempotency-Key (item 14).** ADR 0003's middleware holds a cache lock for `lock_seconds` (60 s)
   while the handler runs and stores the answer afterwards. A handler that ran longer than the lock (a row lock waited for, a slow host)
   left the key unguarded: a retry took the lock, found no stored answer and ran the write a second time.

The live host is shared cPanel: the cache, the queue and the locks are all in MariaDB (`max_statement_time=20`), there is one queue worker
started by cron every minute (`queue:work --stop-when-empty --max-time=50`), and a PHP process can be killed without running `finally`.

## Decision

### Notification deliveries: a claim lock shorter than the queue's retry_after

- `DeliverNotification::handle` takes the cache lock `notification-delivery:{id}` for `CLAIM_SECONDS` (60 s) before anything else, reads the
  delivery again under it, and only then checks "pending", sends and records the outcome. The lock is released in `finally`.
- A copy that finds the lock taken does nothing and ends. The copy holding it either finishes the delivery (sent, gone, failed, or released
  for its next try with the usual backoff) or dies; a dead holder's job is still in the `jobs` table and is handed out again after
  `retry_after`.
- `CLAIM_SECONDS` (60) is shorter than `retry_after` (90): by the time a dead worker's job is handed out again its lock has expired, so the
  delivery is tried again, or failed by `failed()` once the tries are used. A test pins the order.
- **A send ends inside the claim.** The push library's auto-discovered HTTP client had no timeout at all, so a hanging push service could
  outlast any claim. `PushDriver` now builds it with a 20 s timeout (5 s to connect); the Telegram client already stops at 10 s. A test
  pins `PushDriver::TIMEOUT_SECONDS < CLAIM_SECONDS`.
- `failed()` takes the same lock: while another copy is sending, the queue giving one copy up does not mark the delivery failed.
- No column and no status were added: `pending | sent | gone | failed` stays the whole state machine, and the claim lives in the cache's
  lock table like ADR 0003's.

### Idempotency keys: the key's row is written "in flight" before the handler runs

- Under the lock, the middleware now writes the key's row before the handler runs: fingerprint, no answer (`response_status` and
  `response_body` null), `updated_at` now. When the handler answers, the row gets the answer (as before), or, for an answer that is not kept
  (5xx, a refusal of a request that carried a secret), the row is deleted so the key runs again. A handler that throws also leaves no row.
- A request that finds the key's row **without an answer** gets the existing `409 idempotency.in_flight`, also when the first request's lock
  has run out. The client already treats that as "the outcome is unknown, try again later" (the outbox and the pay sheet).
- **A row without an answer that is older than `abandon_after_seconds` (300 s) belongs to a request that died** (the PHP process was killed
  between writing it and answering). The next request with that key takes the row over and runs. 300 s is far longer than any request runs
  on the host, and a till retrying a write whose first try died waits at most 5 minutes, the same as it would for a host that is down.
- The same key with a different body still gets 422, in flight or not.
- `lock_seconds` stays 60 s: the lock still serializes the check and the claim, and the row now guards the time after it.
- No schema change: `response_status` and `response_body` were already nullable. `bakery:prune-idempotency` (ADR 0028) is unaffected, because
  an in-flight row's `expires_at` is 24 hours ahead.

## Alternatives considered

- **A longer lock** (lock_seconds = 300). Rejected: a request killed without `finally` would block every retry of its key for the whole lock,
  and a lock still cannot know when a request is over. The row-based claim keeps the 60 s lock for the fast path.
- **A `sending` status or a `claimed_until` column on `notification_deliveries`.** Equivalent, but it changes the table, the ERD and the
  status list that the System page and the tests read, for no gain over the lock the codebase already uses for the same purpose (ADR 0003).
- **Releasing a job that finds the delivery claimed** (so it re-checks later). Rejected: every release counts as an attempt, and a duplicate
  could use up the tries and reach `failed()`.

## Consequences

- A delivery is sent at most once per claim. The only remaining way to send twice is a send that takes longer than 60 s and is then retried,
  which the drivers' timeouts (push 20 s, Telegram 10 s) rule out.
- A write under one key runs at most once unless its first request has had no answer for 5 minutes.
- One more write per write request (the in-flight row, then its answer). On the live host that is one small `INSERT` before the handler.
- Tests: `DeliveryClaimTest` (a second copy started while the first is sending sends nothing; a held claim stops `handle` and `failed()`;
  the claim is shorter than `retry_after` and longer than a push send; the real push client is built with its timeouts) and four cases in `IdempotencyMiddlewareTest` (a duplicate after the lock ran out gets 409 and the
  write runs once; an in-flight row before and after `abandon_after_seconds`; 422 in flight; the configuration's order). The race cases fail
  on the code before this ADR.
