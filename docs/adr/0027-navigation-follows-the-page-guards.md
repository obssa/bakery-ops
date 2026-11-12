# ADR 0027: Navigation follows the page guards

- Status: **accepted** (phase-6/role-navigation, 2026-11-12)
- Date: 2026-11-12
- Phase: 6

## Context

The API refuses everything a role may not do (rule 6), and every web page has its own guard (`<RequireAuth permission=…>`, or the guard of
the screen it draws). The menus did not follow those guards:

- **Admin sidebar.** Seven sections were shown to every role: Today, Orders, Catalog, Inventory, Planner, People and Shifts.
- **Station lists.** The sidebar's "Stations", the station top bar's "Screens" menu, the start page and the sign-in page's footer listed
  all six screens to everyone.
- **Links inside pages.** A few checked a different permission from the page they open, or checked none.

So a waiter saw Till, Kitchen, Bar, Production, Inventory, Planner, People and Shifts. A bread baker saw the Pad, the Till and both
displays. Every one of those links ended on "Your account cannot open this page. Ask an admin."

After signing in, only the admin landed on the console. Everyone else, the manager included, got the start page with all six screens.

On 2026-11-12 Ramin signed in as each demo role and asked: menu options and links a role cannot open are pointless. Every list on the
screen should show only what that role has direct access to.

## Decision

1. **One table** (`apps/web/src/lib/access.ts` `ROUTES`) gives each page the rule its guard asks for:
   - `anyOf` lists the permissions (one is enough); an empty list means any signed-in person.
   - `station` adds that the kitchen and bar displays need the person to work at that station: `GET /me work_stations`, which lists every
     station for a holder of `stations.all`.
   - `console` makes the admin console's Today open when one of its sections is.

   `canOpen(me, href)` reads the rule of the link's path, ignoring query, hash and trailing slash. The nearest listed parent counts. A page
   not listed opens for nobody, so a new page must be added to the table before a menu can show it.
2. **Every place that leads somewhere asks `canOpen`:**
   - the admin sidebar's sections (Analytics leads to a person's own figures for `analytics.view_own`; Payments leads to the channels for a
     holder of `channels.manage` without `payments.view`);
   - the sidebar's "Stations", the station top bar's "Screens" menu (gone when the person opens fewer than two screens) and the start
     page (a person with one screen goes straight to it);
   - the section tabs of Inventory, Payments, Analytics and Shifts;
   - the links inside pages, through `<CanLink>`, which draws nothing, or the words alone where they sit in a sentence or a table cell;
   - the bell's lines (a line whose page the person cannot open shows without a link).

   The sign-in page lists no screens: a signed-out visitor opens none.
3. **Landing after signing in** (`homeOf`):
   - `?next=` is followed only when the person opens it.
   - Otherwise the person lands on their own screen: the console for whoever runs the bakery (`people.manage`, `catalog.manage`,
     `system.view` or `analytics.view`: the admin and the manager), then the till (cashier), the pad (waiter), the display of the station
     they work at (chef, barista), the production board (the bakers).
   - The sign-in now waits for `/me`, so the station is known.
4. **The page guards stay** for a typed or old address. Their message now offers "Go to my screen" (`homeOf`).
5. **The drift guard** (`e2e/p6/screens-role-navigation-logic.spec.ts`):
   - It reads every `page.tsx` and fails when a page's guard and its rule disagree, when a page has no rule, or when a rule names no page.
   - It reads the role matrix from `PermissionRegistry.php` and pins what each role opens and where it lands.
   - `e2e/p6/screens-role-navigation.spec.ts` signs each of the eight demo roles in through the demo buttons and checks:
     - the landing page;
     - the sidebar, "Stations" and "Screens" lists;
     - that every listed page opens without the refusal message or a 403.

## Consequences

- **No API or permission change.** The server stays the guard; this only stops the web from offering what the server refuses.
- **A new page must be added to `ROUTES`** with its guard's permission, or the logic spec fails and the page appears in no menu.
- **Direct grants follow automatically.** A permission granted to a person beyond their role (`permissions_direct`) shows its pages,
  because `me.permissions` holds both.
- **A chef or barista sees only their own station's display.** Someone covering the other station needs that station assigned in
  People, which is what the API checks for its tickets anyway.
