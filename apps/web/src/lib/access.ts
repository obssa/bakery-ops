import type { Me, StationCode } from "./api/types";
import { SCREENS } from "./screens";

// Who may open which page (ADR 0027, "Navigation follows the page guards"). One table, read by every menu, card and link that leads
// somewhere: the admin sidebar, the "Screens" lists (sidebar, station top bar, start page), the links inside pages and the bell's lines.
// A role is shown only what it can open, so no menu ends on "Your account cannot open this page".
//
// A rule is the permission the page's own guard asks for (<RequireAuth permission=…>, PeopleScreen, DevicesScreen, AnalyticsGate): the
// person needs one of `anyOf` (an empty list: any signed-in person). A screen bound to one station (the kitchen and bar displays) also
// needs that station among the person's work stations (GET /me work_stations, every station for a holder of stations.all), the station
// KdsScreen opens. The page guards stay (a typed address is still refused); e2e/p6/screens-role-navigation-logic.spec.ts fails when a
// page's guard and its rule here disagree, or a page has no rule.

export interface Rule {
  /** one of these permissions; empty: any signed-in person */
  anyOf: readonly string[];
  /** a screen bound to one station: the person must work there (or hold stations.all) */
  station?: StationCode;
  /** the admin console's first page: open when at least one of its sections is */
  console?: true;
}

const ANALYTICS: Rule = { anyOf: ["analytics.view"] };

export const ROUTES: Readonly<Record<string, Rule>> = {
  "/": { anyOf: [] },
  "/pad": { anyOf: ["orders.create"] },
  "/till": { anyOf: ["payments.take"] },
  "/kds/kitchen": { anyOf: ["orders.accept"], station: "kitchen" },
  "/kds/bar": { anyOf: ["orders.accept"], station: "bar" },
  "/production": { anyOf: ["production.view"] },
  "/shifts/count": { anyOf: ["shifts.view"] },

  "/admin": { anyOf: [], console: true },
  "/admin/catalog": { anyOf: ["catalog.view"] },
  "/admin/catalog/new": { anyOf: ["catalog.manage"] },
  "/admin/people": { anyOf: ["people.manage"] },
  "/admin/people/devices": { anyOf: ["devices.manage"] },
  "/admin/orders": { anyOf: ["orders.create"] },
  "/admin/orders/record": { anyOf: ["payments.verify"] },
  "/admin/inventory": { anyOf: ["inventory.view"] },
  "/admin/inventory/ledger": { anyOf: ["inventory.view"] },
  "/admin/inventory/transfers": { anyOf: ["inventory.view"] },
  "/admin/inventory/reorder": { anyOf: ["inventory.view"] },
  "/admin/inventory/purchases": { anyOf: ["purchases.record"] },
  "/admin/inventory/suppliers": { anyOf: ["purchases.record"] },
  "/admin/planner": { anyOf: ["production.plan"] },
  "/admin/shifts": { anyOf: ["shifts.view"] },
  "/admin/shifts/templates": { anyOf: ["shifts.view"] },
  "/admin/shifts/roster": { anyOf: ["shifts.view"] },
  "/admin/shifts/history": { anyOf: ["shifts.view"] },
  "/admin/shifts/notes": { anyOf: ["shifts.view"] },
  "/admin/shifts/reasons": { anyOf: ["shifts.view"] },
  "/admin/shifts/review": { anyOf: ["shifts.view"] },
  "/admin/shifts/replay": { anyOf: ["shifts.replay"] },
  "/admin/payments": { anyOf: ["payments.view"] },
  "/admin/payments/reconciliation": { anyOf: ["payments.verify"] },
  "/admin/payments/channels": { anyOf: ["channels.manage"] },
  "/admin/payments/receipts": { anyOf: ["payments.take"] },
  "/admin/tables": { anyOf: ["tables.manage"] },
  "/admin/analytics": ANALYTICS,
  "/admin/analytics/sales": ANALYTICS,
  "/admin/analytics/channels": ANALYTICS,
  "/admin/analytics/products": ANALYTICS,
  "/admin/analytics/product": ANALYTICS,
  "/admin/analytics/stations": ANALYTICS,
  "/admin/analytics/staff": ANALYTICS,
  "/admin/analytics/shifts": ANALYTICS,
  "/admin/analytics/me": { anyOf: ["analytics.view_own"] },
  "/admin/audit": { anyOf: ["audit.view"] },
  "/admin/system": { anyOf: ["system.view"] },
  "/admin/system/counting": { anyOf: ["shifts.manage"] },
};

/** The admin sections (other than Today) whose being open opens the console. */
const CONSOLE_SECTIONS = [
  "/admin/catalog",
  "/admin/people",
  "/admin/orders",
  "/admin/inventory",
  "/admin/planner",
  "/admin/shifts",
  "/admin/payments",
  "/admin/payments/channels",
  "/admin/tables",
  "/admin/analytics",
  "/admin/analytics/me",
  "/admin/audit",
  "/admin/system",
] as const;

/** A link's path alone: no query, no hash, no trailing slash ("/admin/shifts/?x=1" is "/admin/shifts"). */
export function pathOf(href: string): string {
  const path = href.split(/[?#]/, 1)[0] ?? "";
  return path.replace(/\/+$/, "") || "/";
}

/** The rule of a path: its own, else its nearest listed parent's ("/" only for the start page itself); none for a page not listed. */
export function ruleOf(href: string): Rule | null {
  const path = pathOf(href);
  if (ROUTES[path]) return ROUTES[path];
  if (path === "/") return null;
  const parts = path.split("/");
  for (let n = parts.length - 1; n > 1; n--) {
    const parent = parts.slice(0, n).join("/");
    if (ROUTES[parent]) return ROUTES[parent];
  }
  return null;
}

/** the same test as session.ts can(), here so the logic specs can load this file without the session store */
function can(me: Me, permission: string): boolean {
  return me.permissions.includes(permission);
}

function worksAt(me: Me, station: StationCode): boolean {
  return can(me, "stations.all") || me.work_stations.some((s) => s.code === station);
}

function passes(me: Me, rule: Rule): boolean {
  if (rule.console) return CONSOLE_SECTIONS.some((section) => passes(me, ROUTES[section]!));
  if (rule.anyOf.length > 0 && !rule.anyOf.some((permission) => can(me, permission))) return false;
  return !rule.station || worksAt(me, rule.station);
}

/** Whether this person can open the page at `href` (signed out: nothing; a page with no rule: refused, so a new page must be listed). */
export function canOpen(me: Me | null, href: string): boolean {
  if (!me) return false;
  const rule = ruleOf(href);
  return !!rule && passes(me, rule);
}

/**
 * Where a person lands after signing in when there is no page to go back to: their own screen. The console for whoever runs the bakery
 * (an admin-only section, or the dashboards: the admin and the manager), then the till (cashier), the pad (waiter), the kitchen or bar
 * display where they work (chef, barista), the production board (the bakers), and the console for anyone else.
 */
export function homeOf(me: Me): string {
  const runs = ["people.manage", "catalog.manage", "system.view", "analytics.view"].some((permission) => can(me, permission));
  if (runs && canOpen(me, "/admin")) return "/admin";
  for (const href of ["/till", "/pad", "/kds/kitchen", "/kds/bar", "/production"]) if (canOpen(me, href)) return href;
  return canOpen(me, "/admin") ? "/admin" : "/";
}

/** The screens this person can open, in the shell's order (the start page, the station top bar's "Screens" menu). */
export function screensFor(me: Me | null) {
  return SCREENS.filter(({ href }) => canOpen(me, href));
}

/** The station screens this person can open, the console left out (the admin sidebar's "Stations": the console is the page they are on). */
export function stationScreensFor(me: Me | null) {
  return screensFor(me).filter(({ key }) => key !== "admin");
}
