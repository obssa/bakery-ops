# ADR 0007: Bearer tokens (Sanctum personal access tokens), not SPA cookies

- Status: accepted
- Date: 2026-10-19
- Phase: 0

## Context

Rule 6 (permissions enforced server-side on every endpoint) needs an authenticated user on every API
request, and the clients are a Next.js PWA served from a different origin than the API (static export
or `localhost:3000` against `localhost:8000` in development, ADR 0006), on shared tablets that must keep
working through connectivity drops with an outbox of writes (rule 7, Phase 5 offline). Laravel Sanctum
offers two modes: cookie-based "SPA authentication" (session cookie + CSRF cookie, same-site only) and
personal access tokens sent as `Authorization: Bearer`. The traps reference (Host Node vs Docker API)
already warned that cookies make cross-origin and offline replay needlessly fragile.

## Decision

`POST /api/v1/login` (phone + password) issues a Sanctum personal access token named `api`
(`config('bakery.identity.token_name')`); every protected route is `auth:sanctum` followed by the
`actor` middleware (`App\Modules\Identity\Http\Middleware\ResolveActor`), which fills `CurrentBranch`
from the token's user and `ActorContext` from the user, the optional `X-Device-Id` header (an enrolled
device) and the optional `X-Acting-Session` header (a short-code acting-as session). Sanctum's stateful
SPA mode is not used: `SANCTUM_STATEFUL_DOMAINS` stays empty, no CSRF cookie route is called, and no
session is started for API requests. CORS is an explicit allowlist of the web origins (the live site and
`http://localhost:3000` for development), never `*`. `POST /api/v1/logout` revokes the current token.
Tokens do not expire by themselves; revocation (logout, user deactivation through `is_active`) is the
control, and the PWA stores the token for offline use.

The shared waiter pad is a second credential on top of the token: `POST /api/v1/short-code/verify`
(public, throttled, `X-Device-Id` required) turns a 4–6 digit short code into a 10-minute acting
session whose id the pad sends on every write, so `acting_user_id` is stamped on each write while the
pad's own account stays the `actor` (rule 4).

## Consequences

- Positive: one credential format for the browser, the PWA's offline outbox and any future native or
  print-bridge client; no same-site constraints between the static web app and the API host; tests
  exercise the real token path (`withToken`) and the Sanctum test helper (`Sanctum::actingAs`).
- Negative / cost: the token lives in the browser's storage on a shared device, so device enrolment
  (Phase 5, `EnsureEnrolledDevice`) and short-lived acting sessions carry the per-person attribution;
  a leaked token must be revoked explicitly (logout, deactivate the user), there is no cookie expiry.
- Later phases: Phase 1 pads log in once with a device account and switch waiters by short code;
  Phase 5 enforces `X-Device-Id` behind a flag without changing the credential; Reverb private channels
  authenticate with the same bearer token (`authEndpoint` + `Authorization` header, traps.md).

## Enforcement

`tests/Invariants/RoutePermissionCoverageTest.php` (every non-public `api/v1` route carries
`auth:sanctum` and a permission/can middleware; `scripts/check_invariants.py --idempotency` repeats the
check on the host from `artisan route:list --json`), `tests/Modules/Identity/Feature/LoginTest.php` and
`MeAndLogoutTest.php` (token issue, bearer use, revocation), `tests/Feature/RoutePermissionMatrixTest.php`
(every route as every role), `tests/Support/public_routes.php` (the only public routes: health, login,
short-code verify).

## Alternatives considered

- Sanctum SPA cookies: simplest for a same-origin SPA, but the web app is static and cross-origin, and
  an offline outbox cannot refresh a CSRF cookie; rejected.
- Laravel Passport (OAuth2 / JWT): more than a single first-party client needs; refresh-token rotation
  would be welcome later but can be added on top of Sanctum tokens with expiry if the pilot asks for it.
- Session-less signed device tokens of our own: reinvents Sanctum's table and middleware; rejected.
