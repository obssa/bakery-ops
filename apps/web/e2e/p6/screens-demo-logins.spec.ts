import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { expectAccessible, fontsReady, settledAnimations } from "../support/a11y";
import type { Api } from "../support/api";
import { deviceOf, placeDevice } from "../support/devices";
import { apiOrigin, live } from "../support/env";
import { loginOnce } from "../support/fixture";
import { openAs } from "../support/pad";

// TEMPORARY (ADR 0025, Ramin 2026-11-12): the sign-in page's one-click demo buttons, one per role of the demo bakery. They are drawn only
// while the demo bakery's admin has switched them on in Admin > System (PUT /brand/demo-login) and the server's BAKERY_DEMO_LOGIN=false
// does not force them off. So:
// - CI (p6-a11y, as the demo bakery's admin): the System card switches them on and the sign-in page shows the eight buttons in role order;
//   switched off, they are gone. A tap on "Admin" lands on the admin console as the demo admin. Captures of the System card and of the
//   sign-in page at 1440 x 900 and 390 x 844 in English and Amharic, each passing axe and, at the phone's width, not scrolling sideways.
//   The switch is left off at the end.
// - @live: the sign-in page renders whether the switch is on or off; when the section is there it holds the demo people and no phone. The
//   live switch is Ramin's: no test moves it.

const SHOTS = path.join("test-results", "screens");
const MESSAGES = { en, am } as const;
const ROLES = ["admin", "manager", "cashier", "waiter", "chef", "barista", "cake_baker", "bread_baker"] as const;
const SIZES = [
  { name: "1440x900", viewport: { width: 1440, height: 900 } },
  { name: "390x844", viewport: { width: 390, height: 844 } },
] as const;

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

test.describe("sign-in page: demo buttons (temporary)", () => {
  test("the sign-in page renders, with or without the demo buttons @live", async ({ page, request }) => {
    await page.goto("en/login/");
    await expect(page.getByRole("heading", { level: 1, name: en.login.title })).toBeVisible();
    await expect(page.getByLabel(en.login.phone)).toBeVisible();
    await expect(page.getByRole("button", { name: en.login.submit, exact: true })).toBeVisible();

    const answer = await request.get(`${apiOrigin}/api/v1/demo-logins`);
    expect([200, 404]).toContain(answer.status());
    if (answer.status() === 404) {
      await expect(page.getByTestId("demo-logins")).toHaveCount(0);
      return;
    }
    const body = await answer.text();
    expect(body, "the demo list never carries a phone number").not.toMatch(/\+?2519\d{8}/);
    const people = (JSON.parse(body) as { data: { role: string }[] }).data;
    const section = page.getByTestId("demo-logins");
    await expect(section).toBeVisible();
    await expect(section.getByRole("button")).toHaveCount(people.length);
  });

  test.describe("on the CI stack", () => {
    test.skip(live, "the live host's switch is Ramin's; the switch, the captures and the sign-in run on the CI stack");
    test.describe.configure({ mode: "serial" });

    let admin: Api;

    test.beforeAll(async () => {
      const phone = process.env.DEMO_ADMIN_PHONE ?? "";
      const password = process.env.DEMO_ADMIN_PASSWORD ?? "";
      if (!phone || !password) throw new Error("DEMO_ADMIN_PHONE and DEMO_ADMIN_PASSWORD must be set (e2e.yml): the e2e job seeds the demo bakery.");
      admin = (await loginOnce(phone, password)).api;
    });

    test.afterAll(async () => {
      await admin?.put("/brand/demo-login", { on: false });
    });

    test("the demo bakery's admin switches the buttons on and off in Admin > System", async ({ page }) => {
      test.setTimeout(180_000);
      const t = en.system.demo_login;
      await admin.put("/brand/demo-login", { on: false });

      await page.setViewportSize(SIZES[0].viewport);
      await openAs(page, admin, "en/admin/system/");
      const card = page.getByTestId("system-demo-login");
      await expect(card.getByRole("heading", { name: t.title })).toBeVisible({ timeout: 30_000 });
      const toggle = card.getByRole("switch", { name: t.switch });
      await expect(toggle).toHaveAttribute("aria-checked", "false");

      await toggle.click();
      await expect(toggle).toHaveAttribute("aria-checked", "true");
      await expect(card.getByText(t.saved_on)).toBeVisible();
      await expectAccessible(page, "en/admin/system/ with the demo sign-in card");
      await capture(page, "system-demo-login-1440x900-en");

      const login = await page.context().newPage();
      await login.goto("en/login/");
      await expect(login.getByTestId("demo-logins").getByRole("button")).toHaveCount(ROLES.length);
      await login.close();

      await toggle.click();
      await expect(toggle).toHaveAttribute("aria-checked", "false");
      await expect(card.getByText(t.saved_off)).toBeVisible();
      const after = await page.context().newPage();
      await after.goto("en/login/");
      await expect(after.getByRole("button", { name: en.login.submit, exact: true })).toBeVisible();
      await expect(after.getByTestId("demo-logins")).toHaveCount(0);
      await after.close();
    });

    for (const size of SIZES) {
      for (const locale of ["en", "am"] as const) {
        if (size.name === "1440x900" && locale === "en") continue; // captured by the switch's own test
        test(`capture: the System card switched on at ${size.name} in ${locale}`, async ({ page }) => {
          const t = MESSAGES[locale].system.demo_login;
          await admin.put("/brand/demo-login", { on: true });
          await page.setViewportSize(size.viewport);
          await openAs(page, admin, `${locale}/admin/system/`);
          const card = page.getByTestId("system-demo-login");
          await expect(card.getByRole("heading", { name: t.title })).toBeVisible({ timeout: 30_000 });
          await expect(card.getByRole("switch", { name: t.switch })).toHaveAttribute("aria-checked", "true");
          await card.scrollIntoViewIfNeeded();
          await expectAccessible(page, `${locale}/admin/system/ with the demo sign-in card at ${size.name}`);
          if (size.viewport.width < 600) await noSidewaysScroll(page, "the System page");
          await capture(page, `system-demo-login-${size.name}-${locale}`);
        });
      }
    }

    test("lists one demo person per role and signs the admin in with one tap", async ({ page }) => {
      await admin.put("/brand/demo-login", { on: true });
      await placeDevice(page, deviceOf(process.env.DEMO_ADMIN_PHONE));
      await page.goto("en/login/");
      const section = page.getByTestId("demo-logins");
      await expect(section.getByRole("heading", { name: en.login.demo.title })).toBeVisible();
      await expect(section.getByRole("button")).toHaveCount(ROLES.length);
      const roles = await section.getByRole("button").evaluateAll((buttons) => buttons.map((b) => b.getAttribute("data-role")));
      expect(roles).toEqual([...ROLES]);
      // the name is "Role , Person": the hidden comma is positioned, so Chromium reads a space before it
      await expect(section.getByRole("button", { name: new RegExp(`^${en.people.roles.bread_baker}\\s*,\\s*Abebe Girma$`) })).toBeVisible();

      await section.getByRole("button", { name: new RegExp(`^${en.people.roles.admin}\\s*,\\s*Dawit Bekele$`) }).click();
      await expect(page).toHaveURL(/\/app\/en\/admin\/$/);
      await expect(page.getByRole("heading", { level: 1, name: "Today" })).toBeVisible();
    });

    for (const size of SIZES) {
      for (const locale of ["en", "am"] as const) {
        test(`capture: the sign-in page with the demo buttons at ${size.name} in ${locale}`, async ({ page }) => {
          const t = MESSAGES[locale];
          await admin.put("/brand/demo-login", { on: true });
          await page.setViewportSize(size.viewport);
          await page.goto(`${locale}/login/`);
          const section = page.getByTestId("demo-logins");
          await expect(section.getByRole("heading", { name: t.login.demo.title })).toBeVisible();
          await expect(section.getByRole("button")).toHaveCount(ROLES.length);
          await expect(section.getByRole("button", { name: new RegExp(t.people.roles.cashier) })).toBeVisible();

          await expectAccessible(page, `${locale}/login/ with the demo buttons at ${size.name}`);
          if (size.viewport.width < 600) await noSidewaysScroll(page, "the sign-in page");
          await capture(page, `login-demo-${size.name}-${locale}`);
        });
      }
    }
  });
});
