import { expect, test } from "@playwright/test";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative, resolve, sep } from "node:path";
import { canOpen, homeOf, ROUTES, ruleOf, screensFor, stationScreensFor } from "../../src/lib/access";
import type { Me, StationCode } from "../../src/lib/api/types";

// ADR 0027, "Navigation follows the page guards": lib/access.ts is the one table every menu, card and link reads to show a person only the
// pages their role opens. Plain functions, no page: it runs in p6-a11y beside the screenshots.
//
// 1. The drift guard: every page of the app has a rule, and the rule asks for exactly what the page's own guard asks for (its
//    <RequireAuth permission=…>, or the guard of the screen it draws), so a menu can never show a page that then says "Your account cannot
//    open this page", nor hide one the person may open.
// 2. What each role opens, with the default permissions read from the API's own matrix (PermissionRegistry.php), so a change to a
//    role's grants shows here.

const WEB = resolve(__dirname, "../..");
const APP = join(WEB, "src", "app", "[locale]");
const REGISTRY = resolve(WEB, "../api/app/Modules/Identity/Contracts/PermissionRegistry.php");

/** Every page.tsx under the locale folder, as its route ("/admin/shifts/roster"). */
function pages(dir = APP): { route: string; file: string }[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return pages(path);
    if (name !== "page.tsx") return [];
    const route = `/${relative(APP, dir).split(sep).join("/")}`.replace(/\/$/, "") || "/";
    return [{ route, file: path }];
  });
}

const GUARD = /<RequireAuth\s+permission="([^"]+)"/;

/** Pages whose guard sits in the screen they draw, not in page.tsx. */
const SCREEN_GUARDS: Record<string, string> = {
  "/admin/people": "src/app/[locale]/admin/people/PeopleScreen.tsx",
  "/admin/people/devices": "src/app/[locale]/admin/people/devices/DevicesScreen.tsx",
};

/** Pages with no permission guard of their own, and why their rule is what it is. */
const OPEN: Record<string, string[]> = {
  // the start page: any signed-in person (its cards are the screens they open)
  "/": [],
  // the console's Today: open when one of its sections is (Rule.console)
  "/admin": [],
  // read by every role (catalog.view is everyone's); editing is catalog.manage, on the page's own buttons and /admin/catalog/new
  "/admin/catalog": ["catalog.view"],
};

/** The pages that are not places a menu leads to. */
const NOT_NAVIGATION = new Set(["/login", "/not-found"]);

function guardOf(route: string, file: string): string[] | null {
  const source = readFileSync(file, "utf8");
  const own = GUARD.exec(source);
  if (own) return [own[1]!];
  if (source.includes("<AnalyticsGate")) return ["analytics.view"];
  const screen = SCREEN_GUARDS[route];
  if (screen) {
    const found = GUARD.exec(readFileSync(join(WEB, screen), "utf8"));
    return found ? [found[1]!] : null;
  }
  return OPEN[route] ?? null;
}

test.describe("the access table follows the page guards (ADR 0027)", () => {
  const all = pages().filter(({ route }) => !NOT_NAVIGATION.has(route));

  test("the app's pages were found", () => {
    expect(all.length).toBeGreaterThan(40);
  });

  for (const { route, file } of all) {
    test(`${route}: its rule asks for what its page's guard asks for`, () => {
      const guard = guardOf(route, file);
      expect(guard, `${route} has no guard this test knows: add it to SCREEN_GUARDS or OPEN`).not.toBeNull();
      const rule = ROUTES[route];
      expect(rule, `${route} has no rule in lib/access.ts ROUTES`).toBeDefined();
      expect([...rule!.anyOf]).toEqual(guard);
    });
  }

  test("every rule is a page that exists", () => {
    const routes = new Set(all.map(({ route }) => route));
    for (const route of Object.keys(ROUTES)) expect(routes.has(route), `ROUTES has ${route}, which is no page`).toBe(true);
  });

  test("a link's query, hash and trailing slash do not change its rule; a page not listed has none", () => {
    expect(ruleOf("/admin/shifts/?station=3#x")).toBe(ROUTES["/admin/shifts"]);
    expect(ruleOf("/admin/analytics/me/")).toBe(ROUTES["/admin/analytics/me"]);
    expect(ruleOf("/nowhere")).toBeNull();
  });
});

// --- the roles ----------------------------------------------------------------------------------------------------------------------------

/** role => its default permissions, read from PermissionRegistry::MATRIX (self::EVERYONE, self::FLOOR or a list of roles per row). */
function registry(): Record<string, string[]> {
  const php = readFileSync(REGISTRY, "utf8");
  const list = (text: string) => [...text.matchAll(/'([a-z_]+)'/g)].map((m) => m[1]!);
  const roles = list(/const array ROLES = \[([^\]]*)\]/.exec(php)![1]!);
  const floor = list(/const array FLOOR = \[([^\]]*)\]/.exec(php)![1]!);
  const matrix = /const array MATRIX = \[([\s\S]*?)\n {4}\];/.exec(php)![1]!;
  const out: Record<string, string[]> = Object.fromEntries(roles.map((role) => [role, []]));
  for (const [, permission, holders] of matrix.matchAll(/^\s*'([a-z_.]+)' => (self::EVERYONE|self::FLOOR|\[[^\]]*\]),/gm)) {
    const who = holders === "self::EVERYONE" ? roles : holders === "self::FLOOR" ? floor : list(holders!);
    for (const role of who) out[role]!.push(permission!);
  }
  return out;
}

const ROLES = registry();

function me(role: string, stations: StationCode[] = []): Me {
  return {
    user: { id: 1, name: role } as Me["user"],
    permissions: ROLES[role]!,
    locale: "en",
    acting: null,
    device: null,
    work_stations: stations.map((code, i) => ({ station_id: i + 1, code, name_en: code, name_am: code }) as Me["work_stations"][number]),
  };
}

/** The demo bakery's people, each at their own station (DemoBakery.php); the admin and the manager see every station (stations.all). */
const PEOPLE: Record<string, Me> = {
  admin: me("admin", ["till", "kitchen", "bar", "cake_room", "bakery", "store"]),
  manager: me("manager", ["till", "kitchen", "bar", "cake_room", "bakery", "store"]),
  cashier: me("cashier", ["till"]),
  waiter: me("waiter"),
  chef: me("chef", ["kitchen"]),
  barista: me("barista", ["bar"]),
  cake_baker: me("cake_baker", ["cake_room", "bakery"]),
  bread_baker: me("bread_baker", ["bakery"]),
};

const keys = (list: readonly { key: string }[]) => list.map(({ key }) => key);

test.describe("what each role opens (ADR 0027)", () => {
  test("the registry was read: eight roles, the admin holds every permission", () => {
    expect(Object.keys(ROLES)).toHaveLength(8);
    expect(ROLES.admin!.length).toBeGreaterThan(40);
    expect(ROLES.waiter).toEqual(expect.arrayContaining(["orders.create", "catalog.view"]));
  });

  test("the screens each role opens", () => {
    expect(keys(screensFor(PEOPLE.admin!))).toEqual(["pad", "till", "kds_kitchen", "kds_bar", "production", "admin"]);
    expect(keys(screensFor(PEOPLE.manager!))).toEqual(["pad", "till", "kds_kitchen", "kds_bar", "production", "admin"]);
    expect(keys(screensFor(PEOPLE.cashier!))).toEqual(["pad", "till", "production", "admin"]);
    expect(keys(screensFor(PEOPLE.waiter!))).toEqual(["pad", "admin"]);
    expect(keys(screensFor(PEOPLE.chef!))).toEqual(["kds_kitchen", "production", "admin"]);
    expect(keys(screensFor(PEOPLE.barista!))).toEqual(["kds_bar", "production", "admin"]);
    expect(keys(screensFor(PEOPLE.cake_baker!))).toEqual(["production", "admin"]);
    expect(keys(screensFor(PEOPLE.bread_baker!))).toEqual(["production", "admin"]);
    expect(keys(stationScreensFor(PEOPLE.bread_baker!))).toEqual(["production"]);
  });

  test("a chef or barista opens the display of the station they work at, not the other one", () => {
    expect(canOpen(PEOPLE.chef!, "/kds/kitchen")).toBe(true);
    expect(canOpen(PEOPLE.chef!, "/kds/bar")).toBe(false);
    expect(canOpen(PEOPLE.barista!, "/kds/bar")).toBe(true);
    expect(canOpen(PEOPLE.barista!, "/kds/kitchen")).toBe(false);
    // before /me is read again after signing in, a chef has no work stations yet: no display
    expect(canOpen(me("chef"), "/kds/kitchen")).toBe(false);
  });

  test("the admin pages each role opens", () => {
    const admin = ["/admin/people", "/admin/system", "/admin/system/counting", "/admin/people/devices", "/admin/catalog/new", "/admin/payments/channels"];
    for (const href of admin) {
      expect(canOpen(PEOPLE.admin!, href)).toBe(true);
      for (const role of ["manager", "cashier", "waiter", "chef", "barista", "cake_baker", "bread_baker"]) expect(canOpen(PEOPLE[role]!, href), `${role} ${href}`).toBe(false);
    }
    expect(canOpen(PEOPLE.manager!, "/admin/planner")).toBe(true);
    expect(canOpen(PEOPLE.manager!, "/admin/audit")).toBe(true);
    expect(canOpen(PEOPLE.cashier!, "/admin/planner")).toBe(false);
    expect(canOpen(PEOPLE.cashier!, "/admin/payments")).toBe(true);
    expect(canOpen(PEOPLE.cashier!, "/admin/payments/reconciliation")).toBe(false);
    expect(canOpen(PEOPLE.waiter!, "/admin/orders")).toBe(true);
    expect(canOpen(PEOPLE.waiter!, "/admin/inventory")).toBe(false);
    expect(canOpen(PEOPLE.waiter!, "/admin/shifts")).toBe(false);
    expect(canOpen(PEOPLE.chef!, "/admin/orders")).toBe(false);
    expect(canOpen(PEOPLE.bread_baker!, "/admin/shifts")).toBe(true);
    expect(canOpen(PEOPLE.bread_baker!, "/admin/analytics")).toBe(false);
    expect(canOpen(PEOPLE.bread_baker!, "/admin/analytics/me")).toBe(true);
    // the console's Today opens for everyone who opens one of its sections
    for (const role of Object.keys(PEOPLE)) expect(canOpen(PEOPLE[role]!, "/admin"), role).toBe(true);
    expect(canOpen({ ...PEOPLE.waiter!, permissions: [] }, "/admin")).toBe(false);
  });

  test("signed out, nothing opens", () => {
    expect(canOpen(null, "/pad")).toBe(false);
    expect(canOpen(null, "/")).toBe(false);
    expect(screensFor(null)).toEqual([]);
  });

  test("each role lands on its own screen after signing in", () => {
    expect(homeOf(PEOPLE.admin!)).toBe("/admin");
    expect(homeOf(PEOPLE.manager!)).toBe("/admin");
    expect(homeOf(PEOPLE.cashier!)).toBe("/till");
    expect(homeOf(PEOPLE.waiter!)).toBe("/pad");
    expect(homeOf(PEOPLE.chef!)).toBe("/kds/kitchen");
    expect(homeOf(PEOPLE.barista!)).toBe("/kds/bar");
    expect(homeOf(PEOPLE.cake_baker!)).toBe("/production");
    expect(homeOf(PEOPLE.bread_baker!)).toBe("/production");
    // a chef not placed at a station yet has no display: the production board
    expect(homeOf(me("chef"))).toBe("/production");
  });
});
