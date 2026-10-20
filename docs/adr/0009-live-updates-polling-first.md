# ADR 0009: Live updates through a transport abstraction, polling first

- Status: proposed (accepted when Phase 1 closes)
- Date: 2026-10-20
- Phase: 1

## Context

Architecture section 5 (Real-time) has the KDS and the pads updated over Laravel Reverb private channels
(`station.{id}`, `user.{id}`, `branch.{id}.stock`), with `GET /stations/{id}/tickets?since=<cursor>` only to reconcile
after a connection drop, and the Phase 1 gate originally asserted "ticket visible within 5 s over WebSocket, no
`/tickets` request after the initial load". ADR 0006 put the system on shared cPanel hosting at
https://bakery-ops.orcamind.dev: no long-running processes, so no Reverb (or Soketi) server; no Redis; the database
queue is drained by cron once a minute (`queue:work --stop-when-empty --max-time=50`), so anything queued can wait up
to a minute; LiteSpeed boots PHP per request (no Octane); CloudLinux limits CPU and entry processes. ADR 0006 already
said "polling instead of WebSockets until a WebSocket host exists". A kitchen ticket still has to appear within a few
seconds, and moving to a WebSocket host later (a hosted Pusher-compatible service, or the VPS stack) must not need code
changes.

## Decision

1. **The cursor endpoints are the source of truth; the transport only says "look again".** Every change a screen shows
   is an append-only `order_events` row (ADR 0008 lists which). The read models answer from current state, filtered by
   that cursor:
   - KDS: `GET /api/v1/stations/{station}/tickets?since=<cursor>` (lines of that station changed after the cursor, with
     their order header; without `since`, every active ticket);
   - pads and till: `GET /api/v1/orders?mine=1&since=<cursor>` and `?unpaid=1&since=<cursor>` (the waiter's badge for a
     ready line, the till's queue);
   - menu availability: `GET /api/v1/menu` with an ETag over the branch's last stock movement id.
   Responses carry `cursor` (an `order_events.id`), `server_time` and every changed line with its `version`; clients
   merge by line id and keep the higher `version`, so receiving a line twice is harmless.
2. **Polling on the live host.** The web app's `LiveFeed` port has two implementations, chosen by
   `NEXT_PUBLIC_LIVE_TRANSPORT`:
   - `poll` (default, live host and CI): the KDS polls its station's tickets every 2 s, pads and till poll their
     order lists every 3 s, the menu every 15 s; each request sends `since=<cursor>` and `If-None-Match`; an unchanged
     feed answers `304 Not Modified` with no body. Polling pauses while the page is hidden, backs off to 10 s after
     errors or offline, and fetches at once on focus, on reconnect and right after the screen's own write.
   - `reverb` / `pusher` (later): Laravel Echo on the private channels; on every (re)connect, and on any event, the
     client still fetches the same cursor endpoint, so a missed message costs one fetch, never a lost ticket.
3. **Cheap polls.** The ETag of a station feed is `"{station}:{max order_events.id for that station}"`, read from the
   index `(branch_id, station_id, id)`; a matching `If-None-Match` returns 304 before any ticket is loaded. A changed
   feed is one indexed query over events after the cursor plus the lines they name. Budget on the live host: up to 10
   polling screens (about 4 requests a second), each answered in under 100 ms; the pilot measures it and raises idle
   intervals (5 s when a station has no active ticket) before anything else.
4. **No missed events.** Auto-increment ids can become visible out of order when two transactions commit close
   together. The server therefore never advances the cursor past events younger than an overlap window (5 s, longer
   than any order transaction): the returned `cursor` is the highest id among events at least 5 s old, and everything
   after it is sent again on the next poll (deduplicated by `version`). A rolled-back id leaves a permanent gap, which
   the window makes harmless.
5. **Broadcasting stays in the code, switched by configuration.** The order events (`OrderSent`, `LineAccepted`,
   `LineReady`, `LineRejected`, `LineServed`, `OrderPaid`, `OrderVoided`, and Phase 0's `StockChanged`) are dispatched
   after commit, each with a broadcast companion (`ShouldBroadcast`) on `station.{id}`, `user.{owner}` or
   `branch.{id}.stock` whose `broadcastWhen()` is false while `broadcasting.default` is `null`. The live host runs
   `BROADCAST_CONNECTION=null`, so nothing is sent and, importantly, nothing is queued for a cron-drained queue.
   Switching to Reverb or a hosted Pusher-compatible service is `.env` only (`BROADCAST_CONNECTION=reverb|pusher` and
   its keys) plus `NEXT_PUBLIC_LIVE_TRANSPORT` in the web build. Channel authorization (`routes/channels.php`) is
   written and tested now with the same rule as the endpoints: a station channel needs `orders.accept` and an
   assignment to that station (or `stations.all`), a user channel is its own user, the stock channel is the user's
   branch with `inventory.view`.
6. **CI tests what production runs.** `e2e-p1` runs the polling transport (no `ws` service needed); the Phase 1 gate
   asserts the ticket appears within 5 s through the configured transport and that after the initial load the KDS only
   issues `since=` requests with `If-None-Match`, answered 304 while nothing changes. A WebSocket e2e variant is added
   when a WebSocket host exists.

## Consequences

- Positive: works on the hosting the shop has today; one read path for first load, reconnect, offline replay and
  live updates; the switch to WebSockets is configuration; a dropped message can never lose a ticket.
- Negative / cost: up to 2 s (KDS) or 3 s (pads) of extra latency; constant small load on the shared host; the 5 s
  overlap re-sends recent lines on every poll (tens of rows at most).
- Later phases: notifications (Phase 2) use the same cursor idea for the in-app inbox; shift screens (Phase 3) poll
  their counts the same way; if the pilot shows the host cannot carry the polling, the VPS move of ADR 0006 brings
  Reverb, Redis and Octane without touching the endpoints.

## Enforcement

Step 1.2 tests (group `feature`): channel authorization 403 for a foreign station, a non-assigned chef, another
user's channel and another branch's stock channel; `broadcastWhen()` false and no job queued under the `null`
broadcaster. Step 1.5 tests: ETag and 304 on the tickets and orders feeds, cursor monotonic and never past the overlap
window, a late-committed event is still delivered. Gate `gate:p1` and Playwright `p1-order-flow` (ticket within 5 s,
only `since=` polls after the initial load). `apps/web` ESLint rule (step 1.6): `setInterval` / `setTimeout` are
banned outside `src/lib/live/`, so screens subscribe through `LiveFeed` instead of polling on a timer of their own.

## Alternatives considered

- Reverb on the shared host: no long-running process is allowed there. Rejected for now; kept behind configuration.
- Server-sent events: a PHP worker held open per screen on LiteSpeed exhausts the entry-process limit. Rejected.
- Long polling: same problem, and no gain over 2 s polls with 304s. Rejected.
- A hosted Pusher-compatible service now: a monthly cost and an account Ramin has not asked for; the code is ready for
  it. Deferred to his decision.
