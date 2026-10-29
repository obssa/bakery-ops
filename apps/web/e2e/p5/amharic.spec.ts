import { expect, test, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import { Api } from "../support/api";
import { settle, sweep, typedNames, type LatinGap } from "../support/amharic";
import { freshDevice } from "../support/devices";
import { live } from "../support/env";
import { loginOnce } from "../support/fixture";
import { openAs, typeCode } from "../support/pad";

// Phase 5 gate project p5-amharic (docs/phases/phase-5-plan.md, step 5.4a and section 7): signed in as each role, every screen of that
// role under /app/am/ shows no Latin letter outside the allowlist of plan 10.2 D19 (support/amharic.ts). It runs in the seeded demo
// bakery of the CI stack, whose screens are full (orders at every stage, shifts, counts, two days of history) and whose names are typed
// in Amharic, on a tablet of its own enrolled there (device enforcement is on in e2e-p5). CI only: the live demo branch is never touched.
//
// Each role's list is the screens that role works on; a screen it may not use still shows its Amharic refusal. On every screen the
// sweep also follows one link with a query to a screen of the same list (an order's record, a product's dashboard, a shift's replay,
// an item's page), so the detail screens are swept with real rows. Every gap of a role is gathered before the test fails, so one run
// lists them all.

type Role = "admin" | "manager" | "cashier" | "waiter" | "chef" | "barista" | "cake_baker" | "bread_baker";

const STATION = ["am/pad/", "am/till/", "am/kds/kitchen/", "am/kds/bar/", "am/production/", "am/shifts/count/"];
const ADMIN = [
  "am/admin/",
  "am/admin/catalog/",
  "am/admin/people/",
  "am/admin/people/devices/",
  "am/admin/planner/",
  "am/admin/orders/",
  "am/admin/orders/record/",
  "am/admin/inventory/",
  "am/admin/inventory/ledger/",
  "am/admin/inventory/purchases/",
  "am/admin/inventory/suppliers/",
  "am/admin/inventory/transfers/",
  "am/admin/inventory/reorder/",
  "am/admin/shifts/",
  "am/admin/shifts/review/",
  "am/admin/shifts/history/",
  "am/admin/shifts/replay/",
  "am/admin/shifts/notes/",
  "am/admin/shifts/roster/",
  "am/admin/shifts/templates/",
  "am/admin/shifts/reasons/",
  "am/admin/payments/",
  "am/admin/payments/channels/",
  "am/admin/payments/receipts/",
  "am/admin/payments/reconciliation/",
  "am/admin/analytics/",
  "am/admin/analytics/sales/",
  "am/admin/analytics/products/",
  "am/admin/analytics/product/",
  "am/admin/analytics/channels/",
  "am/admin/analytics/stations/",
  "am/admin/analytics/shifts/",
  "am/admin/analytics/staff/",
  "am/admin/analytics/me/",
  "am/admin/audit/",
];
const OWN = ["am/admin/analytics/me/"];

const SCREENS: Record<Role, string[]> = {
  admin: ["am/", ...STATION, ...ADMIN],
  manager: ["am/", ...STATION.filter((s) => s !== "am/pad/"), ...ADMIN],
  cashier: ["am/", "am/till/", "am/production/", "am/shifts/count/", "am/admin/payments/", ...OWN],
  waiter: ["am/", "am/pad/"],
  chef: ["am/", "am/kds/kitchen/", "am/production/", "am/shifts/count/", ...OWN],
  barista: ["am/", "am/kds/bar/", "am/production/", "am/shifts/count/", ...OWN],
  cake_baker: ["am/", "am/production/", "am/shifts/count/", ...OWN],
  bread_baker: ["am/", "am/production/", "am/shifts/count/", ...OWN],
};

interface Account {
  api: Api;
  id: number;
}

interface DemoBranch {
  admin: Api;
  device: string;
  names: string[];
  users: { id: number; phone: string; roles: string[] }[];
}

const demoPhone = process.env.DEMO_ADMIN_PHONE ?? "";
const demoPassword = process.env.DEMO_ADMIN_PASSWORD ?? "";

let branch: Promise<DemoBranch> | null = null;

/** The demo bakery as its admin sees it, a tablet enrolled there for the sweep, and the names its people typed. Once per worker. */
function demoBranch(): Promise<DemoBranch> {
  branch ??= (async () => {
    if (!demoPhone || !demoPassword) throw new Error("DEMO_ADMIN_PHONE and DEMO_ADMIN_PASSWORD must be set (e2e.yml): the e2e job seeds the demo bakery.");
    const { api: admin } = await loginOnce(demoPhone, demoPassword);
    const device = await freshDevice(admin, "amharic");
    const users = (await admin.get<{ data: { id: number; phone: string; roles: string[] }[] }>("/users")).data;
    return { admin, device, names: await typedNames(admin), users };
  })();
  branch.catch(() => (branch = null));
  return branch;
}

/** The first demo account of `role`, signed in (every demo account has the demo password, DemoCredentials). */
async function accountOf(demo: DemoBranch, role: Role): Promise<Account> {
  const user = demo.users.find((u) => u.roles.includes(role));
  if (!user) throw new Error(`the demo bakery has no ${role}`);
  if (user.phone === demoPhone) return { api: demo.admin, id: user.id };
  const { api } = await loginOnce(user.phone, demoPassword);
  return { api, id: user.id };
}

const pathOf = (url: string) => new URL(url).pathname.replace(/^\/app\//, "");

/** Opens each screen of `screens` in turn (and one link with a query per screen), settles it and gathers what the sweep finds. */
async function sweepScreens(page: Page, account: Account, screens: string[], demo: DemoBranch, after?: (path: string) => Promise<LatinGap[]>) {
  const found: { path: string; gap: LatinGap }[] = [];
  const queue = [...screens];
  const followed = new Set<string>();
  let opened = false;
  for (let path = queue.shift(); path !== undefined; path = queue.shift()) {
    if (!opened) {
      await openAs(page, account.api, path, { device: demo.device });
      opened = true;
    } else {
      await page.goto(path);
    }
    await settle(page);
    expect(pathOf(page.url()), `${path} did not send the person to the sign-in page`).not.toMatch(/login\/$/);
    for (const gap of await sweep(page, demo.names)) found.push({ path, gap });
    if (after) for (const gap of await after(path)) found.push({ path: `${path} (after)`, gap });

    const hrefs = await page.locator("main a[href]").evaluateAll((links) => links.map((a) => (a as HTMLAnchorElement).href));
    for (const href of hrefs) {
      const url = new URL(href);
      const target = pathOf(url.href);
      if (!url.search || !screens.includes(target) || followed.has(target)) continue;
      followed.add(target);
      queue.push(`${target}${url.search}`);
    }
  }
  return found;
}

/** One line per distinct gap, with the screens that show it. */
function describeGaps(found: { path: string; gap: LatinGap }[]): string[] {
  const byText = new Map<string, Set<string>>();
  for (const { path, gap } of found) {
    const key = `"${gap.text}" in ${gap.where}`;
    byText.set(key, (byText.get(key) ?? new Set()).add(path));
  }
  return [...byText].map(([key, paths]) => `${key} on ${[...paths].join(", ")}`);
}

test.describe("amharic", () => {
  test.skip(live, "CI only: the sweep reads the seeded demo bakery, which the live host never seeds for tests");

  for (const role of Object.keys(SCREENS) as Role[]) {
    test(`${role}: every screen of the role is in Amharic`, async ({ page }) => {
      test.setTimeout(role === "admin" || role === "manager" ? 480_000 : 180_000);
      const demo = await demoBranch();
      const account = await accountOf(demo, role);

      // The waiter's pad also shows its order screen, behind the short code (reset here by the demo admin).
      let unlockPad: ((path: string) => Promise<LatinGap[]>) | undefined;
      if (role === "waiter") {
        const { short_code: code } = await demo.admin.post<{ short_code: string }>(`/users/${account.id}/short-code/reset`);
        unlockPad = async (path) => {
          if (path !== "am/pad/") return [];
          const lock = page.getByRole("heading", { name: am.pad.enter_code });
          await expect(lock).toBeVisible({ timeout: 30_000 });
          await typeCode(page, code, am.pad.unlock);
          await expect(lock).toBeHidden({ timeout: 30_000 });
          await settle(page);
          return sweep(page, demo.names);
        };
      }

      const gaps = describeGaps(await sweepScreens(page, account, SCREENS[role], demo, unlockPad));
      expect(gaps, `Latin text on the Amharic screens of the ${role}:\n${gaps.join("\n")}`).toEqual([]);
    });
  }

  test("signed out: the sign-in page and the page that does not exist are in Amharic", async ({ page }) => {
    const demo = await demoBranch();
    const found: string[] = [];
    for (const path of ["am/login/", "am/not-found/"]) {
      await page.goto(path);
      await settle(page);
      for (const gap of await sweep(page, demo.names)) found.push(`"${gap.text}" in ${gap.where} on ${path}`);
    }
    expect(found, `Latin text on the signed-out Amharic screens:\n${found.join("\n")}`).toEqual([]);
  });
});
