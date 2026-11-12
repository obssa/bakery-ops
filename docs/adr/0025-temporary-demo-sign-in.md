# ADR 0025: Temporary one-click demo sign-in on the sign-in page

- Status: **accepted, temporary** (phase-6/demo-logins, 2026-11-12; amended by phase-6/demo-login-switch the same day: an admin switch in
  Admin > System replaces the `.env` line); to be removed before the bakery uses the site
- Date: 2026-11-12
- Phase: 6

## Context

On 2026-11-12 Ramin asked for a demo account for each role on the sign-in page, "so I can log in as the other role and see it". The demo
bakery (its own branch, "Demo Bakery", `docs/runbook/server.md`) already holds one person per role, and they all share
`BAKERY_DEMO_PASSWORD`, a secret kept only in the live `.env`. Asked whether the buttons should only fill in the phone number, sign in
without a password for every role but admin, or for every role, he chose **one-click buttons for every role, admin included, for now**:
"we are going to remove eventually, this is just temporary".

The sign-in page is public. While these buttons are on, **anyone who opens the site can sign in as any demo person, the demo admin
included**, and do what that role may do in the demo branch: take and pay orders, move stock, close shifts, change the demo branch's
people, prices and brand. Nothing outside the demo branch is reachable: every query is scoped to the signed-in person's branch (rule 8),
and the admin's permissions stop at their branch.

## Decision

1. **An admin switch, off by default** (amended 2026-11-12, phase-6/demo-login-switch). The first version read `BAKERY_DEMO_LOGIN=true`
   from the live `.env`, which meant editing a file on the server and refreshing the cached configuration to turn it on or off; Ramin
   asked for a better way. Now the demo bakery's admin turns it on and off in **Admin > System > Demo sign-in buttons**:
   - `brand_profiles.demo_login` (BOOLEAN NOT NULL DEFAULT false, migration `2026_11_19_100000`) on the demo branch's row holds it.
   - `GET /api/v1/brand/demo-login` answers `{data: {on, available, forced_off}}` and `PUT /api/v1/brand/demo-login {on}` (idempotent,
     audited `identity.brand.demo_login` with before and after) changes it. Both are `branding.manage` (the admin's), like the name, logo
     and clock.
   - Only the demo branch's admin holds the switch: another branch's admin reads `available: false` and gets a bilingual 409
     (`identity.demo_login.not_demo_branch`) on a PUT, and the System page shows them no card.
   - `BAKERY_DEMO_LOGIN` is now only the server's kill switch: `false` (or `0`, `off`, `no`) keeps the buttons off whatever the admin
     saved (`bakery.demo.login_forced_off`; the card then shows the switch off and disabled, and says why); unset, the admin's switch
     decides.
   - Off, both routes below answer a bare 404, as if they did not exist, and the sign-in page draws nothing new. CI turns it on through
     the switch, as the demo admin, in the e2e-p6 spec itself, and leaves it off at the end.
2. **Two public, throttled routes** (Identity, `DemoLoginController`; `tests/Support/public_routes.php` and `PUBLIC_ROUTES` in
   `scripts/check_invariants.py`):
   - `GET /api/v1/demo-logins` (60 a minute per address): `{data: [{role, name_en, name_am}]}`, one person per role in the order admin,
     manager, cashier, waiter, chef, barista, cake baker, bread baker. It carries no phone number and no short code.
   - `POST /api/v1/demo-login {role}` (the login's limit, 10 a minute per address): signs that person in through `Authenticate`, the path
     `POST /login` takes, so the answer (token, user, permissions, locale) and the audit row (`auth.login`) are a login's; the row also
     says `via: demo_button`. Like `/login` it carries no Idempotency-Key: a sign-in makes a token and writes nothing else.
3. **Only the listed demo people.** `App\Modules\Identity\Demo\DemoLogins::PEOPLE` names one made-up phone per role, the first person of
   each role in `Database\Seeders\Demo\DemoBakery::PEOPLE` (a test keeps the two in step). A person is offered only in the branch named
   `bakery.demo.branch` that is marked `settings.demo = true` (what `bakery:seed-demo` creates), and only while active and still holding
   that role. The second waiter, the shared pad's account, anyone the admin adds and anyone in another branch are never reachable, and an
   unknown or unavailable role gets a bilingual 422 (`identity.demo_login.unavailable`).
4. **The page.** Under the sign-in form, a "Demo accounts" section marked "Temporary", with one button per role (its icon, the role and the
   person's name, in the page's language). A tap signs in and lands where a sign-in of that person lands (the admin console for the admin,
   the start page for the others). The section is drawn only when `GET /demo-logins` answers 200.

## Consequences

- While it is on, the demo branch is open to anyone with the link. Its data may be changed by strangers; that is accepted for a demo
  branch, which can be loaded again (`bakery:seed-demo`). The shop's own branch is not affected.
- The audit log tells a demo-button sign-in from a password one (`via: demo_button`).

## Removal

1. **Turn it off** (any time, nothing to deploy): as the demo bakery's admin, Admin > System > Demo sign-in buttons, switch it off. Both
   routes answer 404 and the section disappears at once. To keep it off whatever the switch says, the server's `.env` may also carry
   `BAKERY_DEMO_LOGIN=false` (then `cd ~/bakery-ops.orcamind.dev && /opt/alt/php83/usr/bin/php artisan config:cache`).
2. **Delete the feature** (a PR): `apps/api/app/Modules/Identity/Demo/`, `DemoLoginController`, `DemoLoginRequest`,
   `DemoLoginSwitchController`, `UpdateDemoLoginRequest`, `BrandProfiles::saveDemoLogin`, the four routes in `routes/modules/identity.php`,
   the `demo-logins` limiter in `IdentityServiceProvider`, `Authenticate::signInDemo`, `IdentityError::demoAccountUnavailable` and
   `notDemoBranch`, the `identity.demo_login` messages, `bakery.demo.login_forced_off`, a migration dropping `brand_profiles.demo_login`
   (and its line in `docs/erd.dbml`), the entries in `public_routes.php`, `check_invariants.py`, `route_permissions.php`,
   `non_station_routes.php` and `write_endpoints.php`, `DemoLoginTest` and `DemoLoginSwitchTest`; on the web,
   `app/[locale]/login/DemoLogins.tsx` and its line in `page.tsx`, `ROLE_ICONS`, the `login.demo` and `system.demo_login` messages,
   `components/system/DemoLoginCard.tsx` and its line in `SystemScreen.tsx`, `lib/api/demoLoginSwitch.ts` and
   `e2e/p6/screens-demo-logins.spec.ts`; the openapi entries; this ADR's status becomes "superseded (removed)".
