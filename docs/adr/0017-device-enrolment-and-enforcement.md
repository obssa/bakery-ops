# ADR 0017: Device enrolment and enforcement

- Status: **accepted** (step 5.6b, 2026-11-02; proposed in step 5.1a, 2026-10-29, from phase-5 plan sections 4.1, 9 and 10.5). The
  decision is accepted as built, the API (step 5.1a) and the web half (step 5.1b). The live sign-off `devices-enrolled` stays a go-live
  checklist row that Ramin signs before the phase is claimed: it gates the claim, not the decision. The code default stays off until he
  signs it; then a one-line PR turns it on (plan 10.5). What the build changed is in the dated notes below.
- Date: 2026-10-29
- Phase: 5

## Context

The shop runs on shared tablets: a pad for the waiters, a till, a screen in the kitchen and at the bar, the production board. Anyone who
knows a person's password can sign in from any browser, and the floor screens (orders, payments, counts, the shift bar) were reachable from
any device that held a token. Since Phase 0 the `devices` table and three admin routes have existed (enrol by fingerprint, list, revoke) and
the actor middleware has stamped a known device on every write (`X-Device-Id`), refusing a revoked one; an unknown fingerprint was simply
ignored. The architecture asks for more before go-live: a floor route must answer only a tablet the admin enrolled (dependency-graph.md,
cycle 8: `EnsureEnrolledDevice` behind a flag). The live host is shared cPanel: no Redis, no WebSockets, a database cache.

## Decision

1. **A flag, off by default, with a one-line kill switch.** `identity.require_enrolled_device`, read from `IDENTITY_REQUIRE_DEVICE`; unset
   means false. It is read from the config on every request. Three states, each reversible by one line (plan 10.5): off on live from 5.1a
   to the sign-off (CI's e2e stacks turn it on from step 5.1b); Ramin enrols every tablet and signs `devices-enrolled`; one PR turns the
   code default on. Kill switch: `IDENTITY_REQUIRE_DEVICE=false` in the server `.env` and `php artisan config:cache`.
2. **Station routes, named in a registry that a test keeps exact.** `tests/Support/station_routes.php` lists the routes a shared tablet
   calls (the pad, the KDS, the till, the production board, the shift bar and count screens, the pad's lock screen), each with the screen
   that calls it; `tests/Support/non_station_routes.php` lists every other route (login and logout, `GET /me`, `GET /devices/this`, the
   per-person routes, the back office, the public signed routes). `StationRoutesTest` fails when a route is in neither list or both, when a
   station route lacks the `device` middleware or another route has it, and, with the flag on, sends every station route an unknown device
   (403) and every other route the same (never a device refusal). A new route cannot ship unclassified.
3. **`EnsureEnrolledDevice`, alias `device`, after `actor` and before the permission check.** On: no header or an unknown (or deleted)
   fingerprint is `403 identity.device.unknown`; another branch's device `403 identity.device.other_branch`; a revoked one
   `403 identity.device.revoked`; all bilingual. Before the permission check, so an unenrolled tablet is told so whoever signed in on it;
   before the idempotency key, so a refusal is never stored. The pad's lock screen (`POST /short-code/verify`) has no user yet: the device
   gives the branch there, so only unknown and revoked apply.
4. **A route the back office also reads stays a station route when a floor screen needs it** (the order list, one order, a shift, a count,
   the variance reasons, the channels, the receipt settings, the production requests). Once the flag is on, a computer that opens those
   admin pages is enrolled like a tablet. The alternative, exempting the admin role, would let a stolen admin password take orders from any
   browser.
5. **Enrol in place.** The admin signs in on the tablet itself and enrols it from the Devices screen (step 5.1b); `GET /devices/this`
   (every role, `devices.self`) tells the screen whether the device it runs on is enrolled, revoked or unknown, and never answers 403 for
   it: it is how the "not enrolled" screen knows. The three device routes move from `people.manage` to `devices.manage` (the admin holds
   both; a grant-only migration puts the two permissions on the live database).
6. **A revoked id is never enrolled again.** Revoke is final; a rename of a revoked device is 409. The tablet makes a new id and is
   enrolled as a new device, so the history of the old id stays what it was.
7. **Kinds, and the bridge as a device with a hashed token.** `kind` is tablet (the default; every Phase 0 row became one), till, kds or
   bridge. A print bridge (step 5.3a) authenticates with a token made at enrolment (32 random bytes, hex), returned once; the row keeps
   only its sha256, unique, and a CHECK allows a hash on a bridge only. The token is a secret field of the idempotency store (an answer that
   carries it is stored encrypted, as a short code's is) and is redacted in the audit log.
8. **`last_seen_at` debounced to once a minute.** The device check skips the write when the row it loaded was seen less than a minute ago;
   otherwise it takes a lock of the default cache store that lives a minute and is never released (the `database` store on the live host),
   so a burst of requests writes once. The write touches `last_seen_at` alone: not `updated_at`, no audit row. The System page (5.5c) reads
   it as "seen in the last 15 minutes".
9. **Why the live flag waits for a person's sign-off.** Turning it on before every tablet is enrolled would lock a working shop out of its
   own screens mid-service. The checklist item `devices-enrolled` (step 5.6a) is signed by Ramin after the System page shows every tablet
   enrolled and seen.

## Consequences

- Every Playwright context sends an enrolled device id from step 5.1b, so the e2e suites run against the stack as the shop will run it.
- A route added later must be put in one of the two lists; the review question is "does a floor screen call it?".
- An admin's own computer needs enrolling for the shared pages once the flag is on (decision 4).
- `GET /devices/this` lets any signed-in person learn whether a fingerprint is enrolled in their own branch, and nothing about another
  branch's devices (one of another branch reads as unknown).
- The debounce costs one cache-lock attempt a minute per device and nothing on the other requests.

## Enforcement

- `tests/Feature/StationRoutesTest.php`: the two lists are exact, the middleware matches, the middleware order, and the 403 table with the
  flag on (each module's routes in its own group).
- `tests/Modules/Identity/Feature/EnsureEnrolledDeviceTest.php`: off changes nothing; on refuses unknown, missing, revoked, other branch,
  deleted; the device check answers before the permission check; the flag is read per request.
- `DeviceLastSeenTest` (one write under 30 requests, the lock on the `database` store), `DeviceEnrolmentTest` (kinds, the bridge token,
  rename, revoke, `GET /devices/this`), `DeviceEnrolmentSchemaTest` (the CHECKs and UNIQUEs), `DeployableCachesTest` (the flag caches unset,
  false and true), `RoutePermissionMatrixTest` (`devices.manage`, `devices.self`).

## Alternatives considered

- **Exempt the admin role from the check.** Rejected: the admin's password is the one most worth stealing (decision 4).
- **Enforce on every route.** Rejected: the admin works from a laptop and the public signed routes have no device at all.
- **Write `last_seen_at` on every request**, or a conditional UPDATE each time. Rejected: a write per request per tablet on a shared
  MariaDB, where the polling screens already make the most requests.
- **Re-enrol a revoked id.** Rejected: the stamps written under the old id would then mean two different periods of trust.

## Note, 2026-10-29: one model for every computer (step 5.1b)

The plan's `p5-device` flow (phase-5 plan section 6, 5.1b) said an unenrolled tablet's admin pages still work. Step 5.1a classified 58
routes as station routes, and some of them are read by the admin console too: the order list, one order, a shift, a count, the variance
reasons, the channels, the receipt settings and the production requests (decision 4). With the flag on, an unenrolled computer still opens
the purely admin pages (catalog, people, devices, analytics, audit, settings); an admin page that reads one of those shared routes is
refused. Step 5.1b keeps that, and decides how it looks:

- **5.1a's classification stands.** The check is not weakened and no role or user is exempt from it (the alternative rejected below).
- **An unenrolled computer is treated the same everywhere.** The web client sends `X-Device-Id` on every request; any page whose call comes
  back `403 identity.device.unknown`, `revoked` or `other_branch` is replaced by the same "This tablet is not enrolled" screen, the pad's
  and an admin page's alike (`apps/web/src/components/DeviceGate.tsx`). It says that every computer and tablet that uses the system is
  enrolled once, shows the device's short code, and offers "Enrol this device" right there to whoever holds `devices.manage`; anyone else
  is asked to fetch an admin. Going to another page clears it; that page shows it again only if its own calls are refused.
- **A revoked id is replaced at once.** The first `identity.device.revoked` the client sees makes the device a new id (once, however many
  calls come back revoked), so the admin enrols it as a new device (decision 6). Because the actor middleware refuses a revoked device on
  every signed-in route whatever the flag, the client asks `GET /me` once more with the new id instead of signing the person out.
- **Another branch's device** is enrolled here as a new device: the in-place enrol gives it a new id first (a fingerprint is unique across
  branches, so the old one cannot be enrolled twice).
- **The flow that proves it** (`apps/web/e2e/p5/device.spec.ts`): an unknown tablet sees the screen on the pad, the KDS and the till; the
  same tablet opens catalog, people and devices; the admin enrols it in place and after a reload the pad works; after a revoke the next
  request shows the screen again, with a new code; a device of another branch is refused.

## Note, 2026-11-02: accepted at the Phase 5 gate (step 5.6b)

What the build (steps 5.1a and 5.1b, the As built notes of phase-5 plan section 6) changed from, or made concrete in, the decision:

- **The registry** (decision 2) holds 58 station routes and 126 others. Besides the screens named above it counts the till's receipt
  photo (`POST /files`, `DELETE /files/{file}`), the display case, the KDS strip's transfer request (`POST /transfers`), the production
  board's and the count screen's reads, `GET /variance-reasons`, and the till's channels and receipt settings.
- **One model for every computer** (decision 4, the 2026-10-29 note): the plan's flow had an unenrolled computer's admin pages still
  working; as built, any page whose call is refused for the device shows the same "not enrolled" screen, an admin page included, and
  no role or user is exempt.
- **`GET /devices/this`** (decision 5) answers `{enrolled, revoked, device, enforcement}`; the actor middleware skips the device on that
  one route, so a revoked device reads `revoked: true` instead of a 403. **`POST /devices`** requires a `client_uuid`, and a
  fingerprint used before, in any branch, deleted or not, is `422 identity.device.fingerprint_taken`; rename and revoke create no row
  and take no `client_uuid` (the `Idempotency-Key` makes a retry safe).
- **A revoked id** (decision 6) is renewed by the web client once, and `GET /me` is asked again with the new id instead of signing the
  person out. Because the actor middleware refuses a revoked device whatever the flag, a revoked tablet on the live host (flag off)
  shows the screen once and works again after a reload, as a new device to enrol.
- **The device id** is `dev-<uuid>`, kept under the pad's old storage key, so the tablets keep the `pad-…` ids they had; its short form
  (the last eight characters) is what the screen and the admin's list show. The Devices screen is Admin > People > Devices: it lists
  each device with its last seen time, and adds a print bridge (fingerprint `bridge-<uuid>`, the token shown once).
- **CI** turns the flag on for the compose stack's `app` and `worker`; the Pest runs inside that stack pass
  `IDENTITY_REQUIRE_DEVICE=false` and turn it on only where a test needs it. The `@live` suites use stable `e2e-live-<role>` ids,
  enrolled once in the E2E test branch.
- **The System page** of decisions 8 and 9 is step 5.5c (PR #103), not merged at this gate. Until it lands, the Devices screen is where
  the admin sees which tablets are enrolled and when each was last seen, which is what the `devices-enrolled` sign-off asks.

The rest (the flag and its kill switch, the middleware's place and its three refusals, the kinds and the bridge's hashed token, the
`last_seen_at` debounce) is as proposed.
