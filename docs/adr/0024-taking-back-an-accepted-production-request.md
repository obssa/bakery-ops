# ADR 0024: A planner takes back an accepted production request nobody has started

- Status: **accepted** (phase-6/board-drag, 2026-11-11)
- Date: 2026-11-11
- Phase: 6

## Context

`docs/architecture.md` section 4 draws the production request as `requested -> accepted -> in_progress -> pending_confirmation (if the
station requires an overseer) -> done; cancelled`. A planner could name the baker of a request only while it was `requested`
(`AssignRequest`); once a baker accepted it, nobody else could make it, and nothing told anyone when it sat there. On 2026-11-11 Ramin
asked what happens when a request stays in Requested for a long time: on the live board a request accepted by one baker had no button for
anyone else, and the only way out was to cancel it and ask again, which loses who asked, why and by when.

## Decision

1. **`accepted -> requested` is a transition**, made only by `AssignRequest` (a planner, `production.plan`): the request goes back to
   `requested` with the new baker named and `accepted_at` cleared, audited `production_request.reassign`, and ProductionAssigned tells the
   new baker, who accepts it as usual. Naming the baker who accepted it is a repeat that writes nothing. A started request
   (`in_progress` and later) is still never reassigned or cancelled: its batch is finished or sent back.
2. **A request nobody has started that waits too long is flagged and told once.** The board shows its waiting time, an amber "Waiting too
   long" past `production.waiting_too_long_minutes` (120; `production.urgent_minutes`, 30, for an urgent one) and a red "Late by" past its
   due time. `production:overdue`, every 15 minutes, raises ProductionOverdue for each such request, and Notifications tells the station's
   planners once per request (type `production_overdue`, gated by the request id).
3. Nothing about the ledger changes: no stock moves before a batch is started, so taking a request back moves nothing.

## Consequences

- The machine in `architecture.md` section 4 gains one edge: `accepted -> requested (a planner takes it back)`. The tables that pin it
  (`ProductionStatusTest`, `Phase2StateMachinesTest`) list it.
- The request card answers a planner with `bakers`, the people who can make the request, so the planner can choose; nobody else gets it.
- The overdue notice is a nudge, not an escalation chain: one line per planner per request. Should a second reminder be wanted, it is a new
  gate key, not a change to this one.
