# ADR 0010: "Who is on duty at a station" is an interface, answered by assignment until Shifts exists

- Status: accepted (Phase 2 gate, 2026-10-22)
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

## As built

Built in step 2.1 (the interfaces, the bindings and both contract files, before any Production or Notifications code) and used by
2.5 and 2.7. What the code does, which is the fact where it differs from the plan text:

- `StationPresenceResolver::onDuty(station)` and `ShiftAttacher::attach(user, station)` are interfaces in Identity
  (`Modules\Identity\Contracts`); the Phase 2 bindings are `Resolvers\AssignedUsersResolver` (assigned and active) and
  `Resolvers\NullShiftAttacher` (answers null), bound in `IdentityServiceProvider`. Consumers do not see the interfaces: Identity
  offers `Contracts\Recipients` (who to tell: `forStation`, `byPermission`, `overseers`, `byIds`) and `Contracts\People` (what a
  module may ask about a person: `can`, `isOverseer`, `attachShift`), both final classes built per use, so Production and
  Notifications import only Identity contracts.
- Production calls `People::attachShift` when a baker accepts a request, before the write, and records what it answered as
  `shift_instance_id` in the audit context of `production_request.accept` (null today). Nothing else in Production knows about shifts.
- Notifications asks `Recipients::forStation`, which uses `onDuty` and falls back to `assignedUsers` when nobody is on duty, so a
  stock-out reaches the station's people even before Shifts exists. Inactive users are never addressed.
- Both contract files (`tests/Contract/Production/IdentityContractTest.php`, `tests/Contract/Notifications/IdentityContractTest.php`)
  run against the Phase 2 binding and against a roster double that stands in for Shifts (a user who is assigned but not on the
  roster is not on duty), so the swap is already proven.
- What Phase 3 must keep true: rebind the two interfaces, change nothing in Production or Notifications; `onDuty` stays a subset of
  `assignedUsers` unless a call-in is added on purpose; `attach` returns the shift instance id the caller's next writes should carry, or null.
- Not as the plan said: there is no per-user "withhold" of a permission (spatie permissions are additive), so `byPermission` honours
  grants, role removal and inactive users, and nothing else (plan section 6, step 2.1 note).

## Note, 2026-10-24: rebound to Shifts in Phase 3

The two interfaces were rebound in step 3.2b: `Shifts\Support\OnShiftResolver` answers `onDuty` (the active people assigned to the station who have joined the
running shift and not left; everyone assigned when no shift runs; nobody when a shift runs and nobody has joined, where `Recipients::onDuty` falls back to the
assigned people so an alert never reaches nobody) and `Shifts\Support\ShiftsAttacher` answers `attach` (the shift id the person's next writes should carry,
a call-in shift opened when `shifts.open_ad_hoc_on_accept` is on and none runs, null for a person who cannot count there). What was kept true: the Phase 2
bindings still exist and are asserted when bound explicitly; `production->identity` and `notifications->identity` run against both (`PresenceResolverSwapTest`
runs their presence assertions against the real resolver); Production and Notifications changed not at all; `onDuty` is a subset of the assigned people. The
resolver collaborators are resolved per call because Identity's `People` is built with the attacher.
