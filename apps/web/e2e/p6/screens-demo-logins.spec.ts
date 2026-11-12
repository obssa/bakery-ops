import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { expectAccessible, fontsReady, settledAnimations } from "../support/a11y";
import { deviceOf, placeDevice } from "../support/devices";
import { apiOrigin, live } from "../support/env";

// TEMPORARY (ADR 0025, Ramin 2026-11-12): the sign-in page's one-click demo buttons, one per role of the demo bakery. They are drawn only
// while the server's BAKERY_DEMO_LOGIN is on: e2e-p6 turns it on for its stack (ci.yml, e2e.yml input demo_login), the other e2e jobs
// leave it off, and the live host has it on only while Ramin wants it. So:
// - CI (p6-a11y): the eight buttons in role order, a tap on "Admin" lands on the admin console as the demo admin, and the page at
//   1440 x 900 and 390 x 844 in English and Amharic, each passing axe and, at the phone's width, not scrolling sideways. Captures under
//   test-results/screens/login-demo-<size>-<locale>.png.
// - @live: the sign-in page renders whether the switch is on or off; when the section is there it holds the demo people and no phone.

const SHOTS = path.join("test-results", "screens");
const MESSAGES = { en, am } as const;
const ROLES = ["admin", "manager", "cashier", "waiter", "chef", "barista", "cake_baker", "bread_baker"] as const;
const SIZES = [
  { name: "1440x900", viewport: { width: 1440, height: 900 } },
  { name: "390x844", viewport: { width: 390, height: 844 } },
] as const;

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
    test.skip(live, "the live host's switch is Ramin's; the captures and the sign-in run on the CI stack, where e2e-p6 turns it on");

    test("lists one demo person per role and signs the admin in with one tap", async ({ page }) => {
      await placeDevice(page, deviceOf(process.env.DEMO_ADMIN_PHONE));
      await page.goto("en/login/");
      const section = page.getByTestId("demo-logins");
      await expect(section.getByRole("heading", { name: en.login.demo.title })).toBeVisible();
      await expect(section.getByRole("button")).toHaveCount(ROLES.length);
      const roles = await section.getByRole("button").evaluateAll((buttons) => buttons.map((b) => b.getAttribute("data-role")));
      expect(roles).toEqual([...ROLES]);
      await expect(section.getByRole("button", { name: `${en.people.roles.bread_baker}, Abebe Girma` })).toBeVisible();

      await section.getByRole("button", { name: `${en.people.roles.admin}, Dawit Bekele` }).click();
      await expect(page).toHaveURL(/\/app\/en\/admin\/$/);
      await expect(page.getByRole("heading", { level: 1, name: "Today" })).toBeVisible();
    });

    for (const size of SIZES) {
      for (const locale of ["en", "am"] as const) {
        test(`capture: the sign-in page with the demo buttons at ${size.name} in ${locale}`, async ({ page }) => {
          const t = MESSAGES[locale];
          await page.setViewportSize(size.viewport);
          await page.goto(`${locale}/login/`);
          const section = page.getByTestId("demo-logins");
          await expect(section.getByRole("heading", { name: t.login.demo.title })).toBeVisible();
          await expect(section.getByRole("button")).toHaveCount(ROLES.length);
          await expect(section.getByRole("button", { name: new RegExp(t.people.roles.cashier) })).toBeVisible();

          await expectAccessible(page, `${locale}/login/ with the demo buttons at ${size.name}`);
          if (size.viewport.width < 600) {
            const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
            expect(scroll, "the sign-in page must not scroll sideways at a phone's width").toBeLessThanOrEqual(client);
          }

          await fontsReady(page);
          await settledAnimations(page);
          mkdirSync(SHOTS, { recursive: true });
          await page.screenshot({ path: path.join(SHOTS, `login-demo-${size.name}-${locale}.png`), fullPage: true });
        });
      }
    }
  });
});
