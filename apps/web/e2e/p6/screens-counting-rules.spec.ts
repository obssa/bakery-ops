import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { expectAccessible, fontsReady, settledAnimations } from "../support/a11y";
import { uuid } from "../support/api";
import { live, requireAdmin } from "../support/env";
import { findOrCreateLocation, findOrCreateStation, loginOnce } from "../support/fixture";
import { openAs } from "../support/pad";
import { ensureOpenShift, settleStation } from "../support/shifts";

// phase-6/counting-rules (Ramin 2026-11-12: "a setting page where the admin can turn on and off the double count and the co-sign"; ADR 0025).
// On the CI stack, as the E2E Test Branch's admin: Admin > System > Counting rules shows one card per rule with its "All stations" switch and a
// switch per station; the admin switches the kitchen's opening count off on the page, and the kitchen's next shift opens at once, carried,
// with no count to do. Captures at 1440 x 900 and 390 x 844 in English and Amharic, with axe and no sideways scroll. The rules are put back
// as they were at the end, and the kitchen is left with an open shift, as the other fixtures expect.

const SHOTS = path.join("test-results", "screens");
const DESKTOP = { width: 1440, height: 900 };
const PHONE = { width: 390, height: 844 };

interface RulesView {
  stations: { id: number; code: string; name_en: string; name_am: string; opening_count: boolean; cosign: boolean; approval: boolean }[];
}

async function capture(page: Page, name: string): Promise<void> {
  await fontsReady(page);
  await settledAnimations(page);
  mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true });
}

async function noSidewaysScroll(page: Page, what: string): Promise<void> {
  const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
  expect(scroll, `${what} scrolls sideways`).toBeLessThanOrEqual(client);
}

test.describe("screens: the counting rules", () => {
  test.skip(live, "CI only: the flow switches a rule of the E2E branch and starts a shift, and the captures are for review");

  test("the admin switches the kitchen's opening count off on the page, and its next shift opens without a count", async ({ page }) => {
    test.setTimeout(240_000);
    const t = en.counting_rules;
    const credentials = requireAdmin(test.skip);
    const { api: admin } = await loginOnce(credentials.phone, credentials.password);
    // a kitchen with a place to count, so that a start with the opening count on would count first
    const station = await findOrCreateStation(admin, "kitchen");
    await findOrCreateLocation(admin, station);
    const before = await admin.get<{ data: RulesView }>("/counting-rules");
    const kitchen = before.data.stations.find((row) => row.id === station.id);
    if (!kitchen) throw new Error("the E2E branch has no kitchen that runs shifts");
    try {
      await settleStation(admin, { id: kitchen.id });
      if (!kitchen.opening_count) await admin.put("/counting-rules", { stations: [{ id: kitchen.id, opening_count: true }] });

      await page.setViewportSize(DESKTOP);
      await openAs(page, admin, "en/admin/system/counting/");
      await expect(page.getByRole("heading", { level: 1, name: t.title })).toBeVisible({ timeout: 30_000 });
      const card = page.getByRole("region", { name: t.opening_count.title, exact: true });
      await expect(card.getByRole("switch", { name: t.all_stations })).toBeVisible();
      const one = card.getByRole("list", { name: t.stations_label.replace("{rule}", t.opening_count.title) }).getByRole("switch", { name: kitchen.name_en, exact: true });
      await expect(one).toHaveAttribute("aria-checked", "true");

      await one.click();
      await expect(one).toHaveAttribute("aria-checked", "false");
      await expect(card.getByRole("status")).toHaveText(t.saved_off.replace("{rule}", t.opening_count.title).replace("{station}", kitchen.name_en), { timeout: 15_000 });
      await expect.poll(async () => (await admin.get<{ data: RulesView }>("/counting-rules")).data.stations.find((row) => row.id === kitchen.id)?.opening_count).toBe(false);
      await capture(page, "counting-rules-1440x900-en");
      await expectAccessible(page, "counting rules (en)");

      // the kitchen's next shift opens at once, carried: there is nothing to count at the start
      const started = await admin.post<{ shift: { status: string; opening_mode: string | null }; counts: unknown[] }>(`/stations/${kitchen.id}/shifts/start`, { client_uuid: uuid() });
      expect(started.shift.status).toBe("open");
      expect(started.shift.opening_mode).toBe("carried");
      expect(started.counts).toEqual([]);

      await page.setViewportSize(PHONE);
      await page.reload();
      await expect(page.getByRole("heading", { level: 1, name: t.title })).toBeVisible({ timeout: 30_000 });
      await noSidewaysScroll(page, "the counting rules at 390 px");
      await capture(page, "counting-rules-390x844-en");

      for (const [size, viewport] of [["1440x900", DESKTOP], ["390x844", PHONE]] as const) {
        await page.setViewportSize(viewport);
        await openAs(page, admin, "am/admin/system/counting/");
        await expect(page.getByRole("heading", { level: 1, name: am.counting_rules.title })).toBeVisible({ timeout: 30_000 });
        await expect(page.getByRole("region", { name: am.counting_rules.cosign.title, exact: true })).toBeVisible();
        await noSidewaysScroll(page, `the counting rules in Amharic at ${size}`);
        await capture(page, `counting-rules-${size}-am`);
        await expectAccessible(page, `counting rules (am, ${size})`);
      }

      // the System page leads there
      await page.setViewportSize(DESKTOP);
      await openAs(page, admin, "en/admin/system/");
      await expect(page.getByTestId("system-counting-rules")).toContainText(t.title, { timeout: 30_000 });
    } finally {
      await admin.put("/counting-rules", { stations: [{ id: kitchen.id, opening_count: kitchen.opening_count, cosign: kitchen.cosign, approval: kitchen.approval }] });
      await ensureOpenShift(admin, { id: kitchen.id });
    }
  });
});
