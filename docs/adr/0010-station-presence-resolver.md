# ADR 0010: "Who is on duty at a station" is an interface, answered by assignment until Shifts exists

- Status: accepted (Phase 2, step 2.1)
- Date: 2026-10-21
- Phase: 2

## Context

Production routes a request to the people who can make the product, and Notifications tells the people who can act on a
stock-out. Both need "who is at this station right now". The honest answer comes from the shift roster, and Shifts is
Phase 3: Shifts depends on Inventory, Payments and Identity (dependency-graph.md, cycle 3), while Production and
Notifications are built first and may not import Shifts. Phase 2 must work, and be proven, before Shifts exists, and
Phase 3 must be able to change the answer without touching Production or Notifications.

## Decision

1. `Identity\Contracts\StationPresenceResolver::onDuty(Station): Collection<User>` is an interface (it has existed since
   Phase 0). Production and Notifications ask it through the container each time, never a concrete class.
2. Phase 2 binds it to `Identity\Resolvers\AssignedUsersResolver`: on duty means "an active user of the branch assigned
   to the station" (`Stations::assignedUsers`). Phase 3 rebinds the interface to the open shift's roster.
3. `Identity\Contracts\Recipients::onDuty(station)` is the one Notifications uses: it asks the resolver, keeps active
   users of the current branch, and when nobody is on duty falls back to the people assigned to the station, so an
   alert is not addressed to nobody while someone could act.
4. The `production->identity` and `notifications->identity` contracts run every presence assertion twice, against the
   Phase 2 binding and against a roster-style double (a resolver that names only some of the assigned people). A rebind
   in Phase 3 therefore changes who is told, never how Production or Notifications ask.
5. `Identity\Contracts\ShiftAttacher` follows the same rule (cycle 3): `NullShiftAttacher` answers `null` ("no shift
   applies") until Phase 3, so `shift_instance_id` stays NULL on everything Production writes in Phase 2.

## Consequences

- Phase 3 swaps two bindings and nothing else on the consumer side.
- Until then "on duty" overstates presence (an assigned baker who has gone home is still addressed). The in-app inbox
  keeps the notification either way, so nothing is lost; the shop sees what it costs before Shifts sharpens it.
- A third kind of presence (a roster that includes a call-in) needs no new interface.
