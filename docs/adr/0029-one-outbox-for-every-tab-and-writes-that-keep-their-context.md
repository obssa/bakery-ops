# ADR 0029: One outbox for every tab, and queued writes that keep their context

- Status: **accepted** (phase-6/outbox-integrity, 2026-11-20)
- Date: 2026-11-20
- Phase: 6 (amends ADR 0018)

## Context

ADR 0018 keeps the station writes in an IndexedDB outbox. A review of the app's implicit state machines (2026-11-20) found five ways that
outbox could lose a write or send it as someone else's:

1. **Two tabs erased each other's queue.** Each tab kept the queue in memory and wrote it back whole: `replaceAll` cleared the store and
   put the tab's own items. With the pad and the till open side by side on one tablet, offline, each save wiped the other tab's writes. A
   reload then lost them.
2. **The station was read at send time.** A write froze only its `X-Acting-Session`. `X-Station-Id` was added when the write finally went,
   from whatever screen was open then. A payment queued on the till and sent while the kitchen display was open was stamped with the
   kitchen's station and shift (rule 4).
3. **One waiter's refusal reached another waiter.** A queued write refused with `identity.acting_session.expired` locked the pad of
   whichever waiter had it now. The tray's "Edit and resend" put the refused order into the cart of whoever opened the pad next, so waiter
   A's order could go out under waiter B's name.
4. **A database upgrade closed the tab's connection for good.** A newer page (another tab after a deploy) upgrading the database closes
   the old tab's connection. Every later write failed in silence, and the tab still said its queue was kept on the tablet.
5. **A send pass outlived its account.** The pass read the token once and kept sending after a sign-out. Its writes went with the old
   token, never the new account's, so nothing was misattributed. But a revoked token's 401 then marked the session "expired" for whoever
   came next.

## Decision

1. **A tab writes only what it changed.** The store's API is `apply(puts, deletes)`, one transaction per call, and nothing ever clears it.
   - **What is written.** Each tab remembers what it last wrote or read (`saved`). `diffQueue` is the records it changed since then, as
     new objects (the queue's rules never change a record in place), and the ids it dropped.
   - **What the other tabs wrote.** `mergeQueue` folds them in as a three-way merge on `saved`:
     - a record this tab changed, or queued and has not written yet, stays as this tab has it;
     - a record this tab dropped stays dropped;
     - a record this tab had written that the store no longer holds was sent by another tab, and it leaves;
     - anything else is the store's.
2. **The tabs talk on a BroadcastChannel** (`bakery-ops.outbox`). Two messages:
   - **"changed"**, after every write: the other tabs read the store and merge it in.
   - **"applied"**: the tab that sent a write passes on the API's answer or refusal. A screen in the tab that queued the write may be
     waiting for it (`submit`). That tab resolves it, or rejects it and lets the write go, as if it had sent the write itself.
3. **One tab sends at a time.** The send pass runs under a Web Lock (`bakery-ops.outbox.flush`). It first merges what the store holds and
   releases the lock only once its own writes are stored. Two tabs therefore never put one write on the wire at once.
   - **Fallback.** A browser without Web Locks or BroadcastChannel keeps each tab on its own, as before ADR 0029. The per-record writes
     still stop a tab from erasing another's queue.
4. **A write keeps the context it was queued in.**
   - **`station`.** The station picked at enqueue is sent as `X-Station-Id`. The client's `request({ station })` overrides the station
     picked now, and null means none. A write queued before this change has no `station` and goes with the station picked at send time, as
     it always did.
   - **`actor`.** The waiter whose acting session the write carries is stored with it.
5. **A refusal of one waiter's write stays with that waiter.**
   - **The lock.** `identity.acting_session.expired` on a queued write locks the pad only when the write carried the session of the
     waiter who has the pad now (`expiryLocks`). A call made now locks as before.
   - **"Edit and resend".** It gives the order back to the cart of the waiter the write was queued for (`CartRestore.owner`), or of the
     signed-in cashier on the till. While another waiter has the pad, the order waits, and the panel says whose it is, in English and
     Amharic.
6. **A closed connection is opened again.**
   - **Reopening.** After `versionchange`, or when the browser closes the database, the next use opens it again with no version, so it
     opens at whatever version the newer page left. An upgrade only adds stores (ADR 0018), so this page's stores are still there. The tab
     then writes its whole queue again.
   - **When it still fails.** A write that still fails sets `durable` to false, and the shell says the waiting changes are kept only
     while the page is open.
7. **A send pass stops when its account signs out.** Before each write the pass checks that the token it started with is still the
   session's.

## Consequences

- **Two tabs offline keep both queues.** Back online, every write reaches the API once (`offline-tabs.spec.ts`, p5-offline). The merge
  rules, the frozen station and the pad's ownership are covered without a browser in `offline-tabs-logic.spec.ts`.
- **Two tabs can share a `seq`.** Ties are broken by `createdAt`, then by id. Within one order the two tabs' writes stay in the order they
  were made.
- **Messages between tabs can be missed.** A screen whose write was sent by another tab learns the answer from the channel; if that
  message is lost, the screen waits as it did before. Nothing changes on the server, in the permissions or in the API.
