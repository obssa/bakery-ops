# ADR 0018: The offline outbox and its conflicts

- Status: **accepted** (step 5.6b, 2026-11-02; proposed in step 5.2c, 2026-11-02, from phase-5 plan sections 4.2, 9 and 10.2 D10). The
  decision is accepted as built in steps 5.2a to 5.2c; the note at the end records what changed from the proposal.
- Date: 2026-11-02
- Phase: 5

## Context

The shop's tablets sit on a Wi-Fi that drops several times a day, behind a shared cPanel host with no WebSockets. Since Phase 1 the pad
and the kitchen and bar displays queued their writes in `localStorage` and sent them again with the same `Idempotency-Key`; every other
screen failed while offline. A refused write was shown once in a strip with a Dismiss button. Phase 5 makes the floor work through a drop:
the writes a station makes must survive a reload, reach the server exactly once and in order, and a write the server refuses when it
finally arrives (the stock was sold meanwhile, the shift was closed) must not vanish.

## Decision

1. **Which writes are queued** (plan 10.2 D10): every station write that needs no PIN. The pad's sends, changes, cancels and served
   marks; the kitchen and bar line actions; the till's payment batches on every channel with their receipt photos; production batch start
   and finish; the shift bar's count line drafts and cash declaration. A write that needs a PIN (void, refund, count approval, handover
   signature) and every admin screen are **online-only**: their buttons are disabled offline and say "(Needs a connection)".
2. **IndexedDB** holds the queue (database `bakery-ops`, store `outbox`; version 2 adds `photos`, the shrunk receipt photos as blobs). A
   small in-house wrapper (`src/lib/outbox/db.ts`), no library. Without IndexedDB (a private window) the queue lives in the tab's memory.
3. **Order**: the writes of one order (or one line, one batch, one count) go strictly one after another; a write waits for the write it
   `depends_on` and takes what it `needs` from that write's answer (a payment after its photos' upload takes each file id).
4. **The same key on every retry.** A network failure, a 5xx, 408, 429 and `409 idempotency.in_flight` retry after 1, 2, 4 ... 60 s with
   the key fixed at enqueue; the API's stored answer, or after the key's retention the `client_uuid` unique index, makes a replay harmless
   (`IdempotencyReplayAfterExpiryTest`).
5. **A refusal on sync is shown, never dropped.** Any other 4xx, and a refusal made on the tablet (a photo no longer there, a dependency
   answered without what it needs; status 0), keeps the write in IndexedDB as a conflict, with the writes it held back. The **conflict
   tray** in the shell lists each with what was tried, the server's message in both languages (a shortfall's lines too), and the actions
   the refusal allows (`src/lib/outbox/tray.ts`):
   - the pad's and the till cart's sends: **Edit and resend**. The order goes back to the cart (rebuilt from the refusal when the cart
     is no longer in memory); the cart's next send replaces the refused writes. A shortfall's order is held open by the server, so the
     cart is that order and goes as PUT lines + send, each a new write with a new key; a refused new order comes back as a new order with
     new client_uuids;
   - a write whose cause can be put right elsewhere (no open shift, not enough stock): **Send again**, as a new write with a new key and
     new client_uuids, so the API never answers it with what it stored for the refused one;
   - a closed order, a photo refused or gone, a missing dependency answer: **Discard** only (it would be refused again). Discard is offered
     on every entry and also lets go of the photos the discarded writes named.
   A device refusal (unknown, revoked, another branch) keeps every write pending and the tray counts them until the tablet is enrolled.
6. **Reconnect reconcile.** On the browser's `online` event, and when the page becomes visible after 30 s or more hidden
   (`src/lib/reconnect.ts`): the outbox sends what waits; the pad's and the till's lists are read again from the start
   (`LiveFeed.reload`); the kitchen and bar rails replay `tickets?since=<cursor>` through their poll transport (which already looks again
   on `online` and on visibility). An order write tried before without an answer and refused because the order moved on is looked up
   (`GET /orders/{id}`): when the order already shows what it wanted, it had landed and is settled. No new read was needed: a create
   whose answer was lost is answered by its replay (the key, or the `client_uuid`), and `GET /orders` carries `client_uuid`.

## Consequences

- **What is lost if the browser's storage is cleared**: every write still queued, the photos kept for them, and the conflicts not yet
  acted on. The server never saw them; nothing on the server is inconsistent, but the work must be done again. The pending count and the
  tray make a non-empty queue visible before anyone clears a tablet.
- Two tabs of one tablet share IndexedDB but each sends from its own memory; the same key makes a double send harmless.
- A write that is not a create (a line action) replayed after its key expired may be refused because it already happened; the tray shows
  it with Discard (an order's send or cancel is looked up first, decision 6).
- The pad's inline refusals (the shortfall on its line) now also stay in the tray until the order is sent again or discarded.

## Alternatives considered

- **Dropping a refused write after showing it once** (Phases 1 to 4): a refusal that arrived while nobody looked was lost silently.
- **Re-sending a refused write with its old key**: the API would answer the stored refusal; a new key, and new client_uuids where a row
  is created, make it a new write.
- **A `GET /orders/by-client-uuid/{uuid}` read** (plan 4.2): not added; the replay answers a lost create and the list carries the uuid.
- **A service-worker Background Sync queue**: not available on every tablet browser, and it could not show the refusals on the screens.

## Note, 2026-11-02: accepted at the Phase 5 gate (step 5.6b)

This ADR was written in step 5.2c, after the outbox was built (steps 5.2a to 5.2c), so its decision already describes the build. What
the build changed from the proposal of phase-5 plan section 4.2 and the steps' texts, and what came after it:

- **The lookup read was not added** (decision 6; the plan's `GET /orders/by-client-uuid/{uuid}`): a create whose answer was lost is
  answered by its replay, and only a send or a cancel tried before without an answer and refused as closed is looked up, with
  `GET /orders/{id}`. Line actions are not looked up; they show in the tray with Discard.
- **`409 idempotency.in_flight` is retried** with its key (decision 4), not made a conflict as the plan's "a 4xx moves the item to the
  conflict list" would have it (step 5.2b: a slow upload whose answer timed out, or a second tab).
- **The photo store** (decision 2) has a quota: a new photo is refused, in both languages, when the photos still waiting for their upload
  would pass `NEXT_PUBLIC_PHOTO_QUEUE_MB` (50). A photo no queued write needs is dropped when it is let go of, or after 12 hours. A
  batch's photos go as one `upload_photos` write, and the payment's `needs` is a list, one file id per photo.
- **One API change**: `FileUploadRaceTest` found the losing one of two uploads with one `client_uuid` answered 404. `Files::put` now
  inserts under a savepoint and reads the winner with a locking read; the upload that stored the file answers 201, the other 200.
- **Print is online only** (step 5.3c, ADR 0019): it is a station write without a PIN, an exception to decision 1. A print sent when the
  line came back could reach the printer long after the customer left, and the API expires a job after 15 minutes anyway.
- **Left open** (step 5.2c): the tray shows every conflict of the tablet, whoever is signed in (only the account that queued a write
  sends it); a rebuilt cart waits while the person's cart holds another order; discarding a refused send whose order the server holds
  open (a shortfall) leaves that order open, shown as not sent on the waiter's "My orders" with its Cancel.

The rest is as proposed.
