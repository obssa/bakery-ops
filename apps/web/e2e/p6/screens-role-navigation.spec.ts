import { mkdirSync, writeFileSync } from "node:fs";
import path from "node:path";
import { expect, test, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { expectAccessible, fontsReady, settledAnimations } from "../support/a11y";
import type { Api } from "../support/api";
import { deviceOf, placeDevice } from "../support/devices";
import { live } from "../support/env";
import { loginOnce } from "../support/fixture";

// ADR 0027, "Navigation follows the page guards" (Ramin, 2026-11-12: a menu option a role cannot open is pointless). Each of the demo
// bakery's eight roles signs in through the sign-in page's demo button (ADR 0025; the demo admin switches the buttons on for this file
// and off at its end) and:
// - lands on its own screen (lib/access.ts homeOf);
// - sees in the admin sidebar exactly the sections its role opens, and under "Stations" exactly the station screens it opens;
// - opens every one of those links without "Your account cannot open this page" and without a 403 from the API while the page loads;
// - on a station screen, finds in the top bar's "Screens" menu only the screens it opens (no menu when it opens one screen).
// The sidebar of the manager, the cashier, the waiter and the bread baker is captured at 1440 x 900 and, as the drawer, at 390 x 844, in
// English and Amharic, each passing axe and, at the phone's width, not scrolling sideways. What each role saw is written to
// test-results/screens/role-navigation.json.

const SHOTS = path.join("test-results", "screens");
const MESSAGES = { en, am } as const;
type Role = "admin" | "manager" | "cashier" | "waiter" | "chef" | "barista" | "cake_baker" | "bread_baker";
type Section = keyof typeof en.admin;

/** What each role sees: the sidebar's sections (in its order), its "Stations", and where the role lands. */
const EXPECTED: Record<Role, { home: string; sections: Section[]; stations: (keyof typeof en.nav)[] }> = {
  admin: {
    home: "admin",
    sections: ["today", "orders", "payments", "tables", "catalog", "inventory", "planner", "people", "shifts", "analytics", "audit", "system"],
    stations: ["pad", "till", "kds_kitchen", "kds_bar", "production"],
  },
  manager: {
    home: "admin",
    sections: ["today", "orders", "payments", "tables", "catalog", "inventory", "planner", "shifts", "analytics", "audit"],
    stations: ["pad", "till", "kds_kitchen", "kds_bar", "production"],
  },
  cashier: { home: "till", sections: ["today", "orders", "payments", "catalog", "inventory", "shifts", "analytics"], stations: ["pad", "till", "production"] },
  waiter: { home: "pad", sections: ["today", "orders", "catalog", "analytics"], stations: ["pad"] },
  chef: { home: "kds/kitchen", sections: ["today", "catalog", "inventory", "shifts", "analytics"], stations: ["kds_kitchen", "production"] },
  barista: { home: "kds/bar", sections: ["today", "catalog", "inventory", "shifts", "analytics"], stations: ["kds_bar", "production"] },
  cake_baker: { home: "production", sections: ["today", "catalog", "inventory", "shifts", "analytics"], stations: ["production"] },
  bread_baker: { home: "production", sections: ["today", "catalog", "inventory", "shifts", "analytics"], stations: ["production"] },
};

const ROLES = Object.keys(EXPECTED) as Role[];
const CAPTURED: Role[] = ["manager", "cashier", "waiter", "bread_baker"];
const seen: Record<string, { landed: string; sidebar: string[]; stations: string[]; screensMenu: string[] | null }> = {};

async function capture(page: Page, name: string): Promise<void> {
  await fontsReady(page);
  await settledAnimations(page);
  mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true });
}

async function noSidewaysScroll(page: Page, what: string): Promise<void> {
  const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
  expect(scroll, `${what} must not scroll sideways at a phone's width`).toBeLessThanOrEqual(client);
}

/** Taps the role's demo button; a sign-in refused for the minute's budget (429) is tried again once the minute has passed. */
async function signInAs(page: Page, role: Role): Promise<void> {
  await page.goto("en/login/");
  const button = page.getByTestId("demo-logins").locator(`button[data-role="${role}"]`);
  for (let attempt = 0; attempt < 2; attempt++) {
    await expect(button).toBeEnabled({ timeout: 30_000 });
    await button.click();
    const left = await page
      .waitForURL((url) => !url.pathname.includes("/login"), { timeout: 20_000 })
      .then(() => true)
      .catch(() => false);
    if (left) return;
    await page.waitForTimeout(61_000);
  }
  throw new Error(`the demo ${role} could not sign in`);
}

/** The links of a navigation, as [name, path]. */
async function linksOf(page: Page, name: string): Promise<[string, string][]> {
  const nav = page.getByRole("navigation", { name, exact: true });
  if ((await nav.count()) === 0) return [];
  return nav.first().getByRole("link").evaluateAll((links) => links.map((a) => [(a.textContent ?? "").trim(), new URL((a as HTMLAnchorElement).href).pathname] as [string, string]));
}

/** Opens `pathname` and checks the person's role opens it: no "cannot open this page", no 403 from the API while it loads. */
async function opens(page: Page, pathname: string): Promise<void> {
  const refused: string[] = [];
  const onResponse = (response: { status(): number; url(): string }) => {
    if (response.status() === 403 && response.url().includes("/api/v1/")) refused.push(new URL(response.url()).pathname);
  };
  page.on("response", onResponse);
  try {
    await page.goto(pathname);
    await expect(page.getByText(en.admin.checking)).toHaveCount(0, { timeout: 30_000 });
    // let the page's first reads answer
    await page.waitForTimeout(2_500);
    await expect(page.getByText(en.admin.no_access), `${pathname} says the account cannot open it`).toHaveCount(0);
    expect(refused, `${pathname} was refused by the API`).toEqual([]);
  } finally {
    page.off("response", onResponse);
  }
}

test.describe("each role sees only what it can open (ADR 0027)", () => {
  test.skip(live, "the live host's demo switch is Ramin's: the sign-ins run on the CI stack");
  test.describe.configure({ mode: "serial" });

  let admin: Api;

  test.beforeAll(async () => {
    const phone = process.env.DEMO_ADMIN_PHONE ?? "";
    const password = process.env.DEMO_ADMIN_PASSWORD ?? "";
    if (!phone || !password) throw new Error("DEMO_ADMIN_PHONE and DEMO_ADMIN_PASSWORD must be set (e2e.yml): the e2e job seeds the demo bakery.");
    admin = (await loginOnce(phone, password)).api;
    await admin.put("/brand/demo-login", { on: true });
  });

  test.afterAll(async () => {
    await admin?.put("/brand/demo-login", { on: false });
    mkdirSync(SHOTS, { recursive: true });
    writeFileSync(path.join(SHOTS, "role-navigation.json"), JSON.stringify(seen, null, 2));
  });

  for (const role of ROLES) {
    test(`the ${role}: lands on their own screen, and every menu lists only what they open`, async ({ page }) => {
      test.setTimeout(420_000);
      const want = EXPECTED[role];
      await page.setViewportSize({ width: 1440, height: 900 });
      await placeDevice(page, deviceOf(process.env.DEMO_ADMIN_PHONE));
      await signInAs(page, role);
      await expect(page).toHaveURL(new RegExp(`/app/en/${want.home}/$`), { timeout: 30_000 });
      const landed = new URL(page.url()).pathname;

      // a station screen: the top bar's "Screens" menu lists only the screens the role opens (none when it opens one)
      let screensMenu: string[] | null = null;
      if (want.home !== "admin") {
        await expect(page.getByText(en.admin.checking)).toHaveCount(0, { timeout: 30_000 });
        const button = page.getByRole("button", { name: en.nav.main, exact: true });
        const screens = [...want.stations.map((key) => en.nav[key]), en.nav.admin];
        if (screens.length < 2) {
          await expect(button).toHaveCount(0);
        } else {
          await button.click();
          screensMenu = (await linksOf(page, en.nav.main)).map(([name]) => name);
          const order = ["pad", "till", "kds_kitchen", "kds_bar", "production", "admin"] as const;
          expect(screensMenu).toEqual(order.filter((key) => key === "admin" || want.stations.includes(key)).map((key) => en.nav[key]));
          await page.keyboard.press("Escape");
        }
      }

      // the admin sidebar: its sections and its "Stations", in order
      await page.goto("en/admin/");
      await expect(page.getByRole("navigation", { name: en.admin.sections })).toBeVisible({ timeout: 30_000 });
      const sidebar = await linksOf(page, en.admin.sections);
      expect(sidebar.map(([name]) => name)).toEqual(want.sections.map((key) => en.admin[key]));
      const stations = await linksOf(page, en.nav.main);
      expect(stations.map(([name]) => name)).toEqual(want.stations.map((key) => en.nav[key]));
      seen[role] = { landed, sidebar: sidebar.map(([name]) => name), stations: stations.map(([name]) => name), screensMenu };

      // every one of them opens for this role
      for (const [, pathname] of [...sidebar, ...stations]) await opens(page, pathname);

      if (CAPTURED.includes(role)) {
        for (const locale of ["en", "am"] as const) {
          const t = MESSAGES[locale];
          await page.setViewportSize({ width: 1440, height: 900 });
          await page.goto(`${locale}/admin/`);
          await expect(page.getByRole("navigation", { name: t.admin.sections })).toBeVisible({ timeout: 30_000 });
          await expectAccessible(page, `${locale}/admin/ as the ${role}`);
          await capture(page, `role-nav-${role}-1440x900-${locale}`);

          await page.setViewportSize({ width: 390, height: 844 });
          await page.getByRole("button", { name: t.admin.open_sections }).click();
          await expect(page.getByRole("dialog", { name: t.admin.sections })).toBeVisible();
          await noSidewaysScroll(page, `the admin drawer of the ${role}`);
          await expectAccessible(page, `${locale}/admin/ drawer as the ${role}`);
          await capture(page, `role-nav-${role}-390x844-${locale}`);
        }
      }
    });
  }

  test("a page the role does not open, typed in, says so and leads to the person's own screen", async ({ page }) => {
    test.setTimeout(180_000);
    await page.setViewportSize({ width: 1440, height: 900 });
    await placeDevice(page, deviceOf(process.env.DEMO_ADMIN_PHONE));
    await signInAs(page, "bread_baker");
    await expect(page).toHaveURL(/\/app\/en\/production\/$/, { timeout: 30_000 });
    await page.goto("en/admin/people/");
    await expect(page.getByText(en.admin.no_access)).toBeVisible({ timeout: 30_000 });
    await page.getByRole("link", { name: en.admin.go_home }).click();
    await expect(page).toHaveURL(/\/app\/en\/production\/$/, { timeout: 30_000 });
  });
});
