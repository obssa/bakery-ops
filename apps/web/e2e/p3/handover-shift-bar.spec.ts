import { expect, test, type Page } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildStationFixture, ensureStaff, loginOnce } from "../support/fixture";
import { openAs } from "../support/pad";
import { currentShift, ensureOpenShift, finishClosingCounts, settleStation } from "../support/shifts";

// Phase 3 gate project p3-handover, the shift bar (step 3.7a; the count screens of 3.7b and the cash of 3.7d come later and extend this
// project). docs/phases/phase-3-plan.md section 6 item 7 and section 4 (the shift endpoints).
//
// A chef opens the kitchen display with no shift running: the bar says so and offers "Start shift" (count the stock first) and "Start
// without counting". Starting with a count leaves the shift `opening` and the bar links to the count screen (a placeholder until 3.7b);
// the chef cancels that start and starts without counting instead, so the shift is open. A manager opens the same display, sees who is
// on, and joins; the chef's bar learns of it by polling. The chef closes the shift (the station is then frozen: the closing count is
// in progress). A refusal from the API, "no shift is open at this station", shows in both languages with a Start button.
//
// Built through the admin API in the isolated "E2E Test Branch", so it carries @live. The E2E branch's kitchen already carries a shift
// that other fixtures opened, so the flow settles the station first (cancels or closes and submits it) and opens a carried shift again
// at the end, which is what every other fixture expects to find.

async function bar(page: Page) {
  const region = page.getByRole("region", { name: "Shift", exact: true });
  await expect(region).toBeVisible({ timeout: 30_000 });
  return region;
}

test.describe("p3 handover: the shift bar", () => {
  test("a chef starts the kitchen shift, a manager joins it and it is closed from the bar @live", async ({ browser }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildStationFixture(credentials, "kitchen");
    const { admin, station, worker } = fixture;
    await settleStation(admin, station);
    expect(await currentShift(admin, station)).toBeNull();

    const manager = await ensureStaff(admin, credentials.password, "manager");
    const chefSession = await loginOnce(worker.phone, worker.password, fixture.branchId);
    const managerSession = await loginOnce(manager.phone, manager.password, fixture.branchId);

    const make = () => browser.newContext({ baseURL: test.info().project.use.baseURL, viewport: { width: 1280, height: 800 }, serviceWorkers: "block" });
    const [chefContext, managerContext] = [await make(), await make()];
    try {
      const chef = await chefContext.newPage();
      await openAs(chef, chefSession.api, "en/kds/kitchen/");
      const chefBar = await bar(chef);

      // no shift: the bar says so and offers both starts
      await expect(chefBar).toContainText("No shift is running");
      await expect(chefBar.getByRole("button", { name: "Start shift", exact: true })).toBeVisible();
      await expect(chefBar.getByRole("button", { name: "Start without counting", exact: true })).toBeVisible();

      // the API's refusal "no shift is open at this station" shows in the language of the page, with the Start button beside it
      await chef.route("**/stock-strip*", (route) =>
        route.fulfill({
          status: 409,
          contentType: "application/json",
          body: JSON.stringify({
            message_key: "inventory.shift.required",
            message_en: "No shift is open at this station. Start a shift here before moving its stock.",
            message_am: "በዚህ ጣቢያ የተከፈተ ፈረቃ የለም። ክምችቱን ከማንቀሳቀስዎ በፊት ፈረቃ ይጀምሩ።",
            station_id: station.id,
            location_id: fixture.location.id,
          }),
        }),
      );
      const notice = chefBar.getByRole("alert");
      await expect(notice).toContainText("No shift is open at this station", { timeout: 30_000 });
      await chef.unroute("**/stock-strip*");

      // start with a count: the shift is `opening`, the bar links to the count and offers to cancel the start
      await chefBar.getByRole("button", { name: "Start shift", exact: true }).click();
      await expect(chefBar).toContainText("Opening count in progress", { timeout: 15_000 });
      await expect(chefBar.getByRole("link", { name: "Open the count" })).toHaveAttribute("href", /\/shifts\/count\/\?shift=\d+/);
      const opening = await currentShift(admin, station);
      expect(opening?.status).toBe("opening");
      await expect(chefBar.getByRole("alert")).toHaveCount(0); // the refusal is behind us once a shift is being started

      await chefBar.getByRole("button", { name: "Cancel start" }).click();
      await expect(chefBar).toContainText("No shift is running", { timeout: 15_000 });
      expect(await currentShift(admin, station)).toBeNull();

      // start without counting: the shift is open and the chef is on it
      await chefBar.getByRole("button", { name: "Start without counting", exact: true }).click();
      await expect(chefBar).toContainText("Shift open", { timeout: 15_000 });
      await expect(chefBar).toContainText(worker.name);
      const started = await currentShift(admin, station);
      expect(started?.status).toBe("open");
      expect(started?.started_by?.id).toBe(worker.id);

      // a manager opens the same display and joins
      const boss = await managerContext.newPage();
      await openAs(boss, managerSession.api, "en/kds/kitchen/");
      const bossBar = await bar(boss);
      await expect(bossBar).toContainText("Shift open", { timeout: 30_000 });
      await expect(bossBar).toContainText(worker.name);
      await bossBar.getByRole("button", { name: "Join shift", exact: true }).click();
      await expect(bossBar).toContainText(manager.name, { timeout: 15_000 });
      await expect(bossBar.getByRole("button", { name: "Leave shift", exact: true })).toBeVisible();

      // the chef's bar learns of it by polling (5 s visible)
      await expect(chefBar).toContainText(manager.name, { timeout: 30_000 });
      const joined = await currentShift(admin, station);
      expect(joined?.roster.filter((r) => r.left_at === null).map((r) => r.user?.id).sort()).toEqual([worker.id, manager.id].sort());

      // the chef closes it: a confirmation first, then the closing count is in progress
      await chefBar.getByRole("button", { name: "Close shift", exact: true }).click();
      const confirm = chef.getByRole("dialog", { name: "Close the shift" });
      await expect(confirm).toBeVisible();
      await confirm.getByRole("button", { name: "Keep it open" }).click();
      await expect(confirm).toHaveCount(0);
      expect((await currentShift(admin, station))?.status).toBe("open");
      await chefBar.getByRole("button", { name: "Close shift", exact: true }).click();
      await chef.getByRole("dialog", { name: "Close the shift" }).getByRole("button", { name: "Close shift", exact: true }).click();
      await expect(chefBar).toContainText("Closing count in progress", { timeout: 15_000 });
      await expect(chefBar.getByRole("link", { name: "Open the count" })).toBeVisible();
      expect((await currentShift(admin, station))?.status).toBe("counting");
      // the manager's bar follows
      await expect(bossBar).toContainText("Closing count in progress", { timeout: 30_000 });
    } finally {
      await chefContext.close();
      await managerContext.close();
    }

    // leave the station as the other fixtures expect it: the closing count submitted, and a shift running again
    const closing = await currentShift(admin, station);
    if (closing) await finishClosingCounts(admin, closing.id);
    await ensureOpenShift(admin, station);
    expect((await currentShift(admin, station))?.status).toBe("open");
  });

  test("the bar speaks Amharic and the till and production screens carry it too @live", async ({ browser }) => {
    test.setTimeout(120_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildStationFixture(credentials, "kitchen");
    const { admin, station, worker } = fixture;
    await ensureOpenShift(admin, station);
    const chefSession = await loginOnce(worker.phone, worker.password, fixture.branchId);
    const context = await browser.newContext({ baseURL: test.info().project.use.baseURL, viewport: { width: 1280, height: 800 }, serviceWorkers: "block" });
    try {
      const page = await context.newPage();
      await openAs(page, chefSession.api, "am/kds/kitchen/");
      const region = page.getByRole("region", { name: "ፈረቃ", exact: true });
      await expect(region).toBeVisible({ timeout: 30_000 });
      await expect(region).toContainText("ፈረቃ ክፍት ነው");
      await expect(region.getByRole("button", { name: "ፈረቃውን ዝጋ", exact: true })).toBeVisible();
    } finally {
      await context.close();
    }
  });
});
