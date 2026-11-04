import { expect, test, type Locator, type Page } from "@playwright/test";
import en from "../../messages/en.json";
import { requireAdmin } from "../support/env";
import { buildStationFixture, buildTillStaff, findOrCreateStation, loginOnce } from "../support/fixture";
import { openAs } from "../support/pad";
import { currentShift, ensureDrawer, ensureOpenShift, resolvePendingCounts, settleStation } from "../support/shifts";

// The shift's prompt must not cover the work, and must not vanish either (phase-6/shift-strip, Ramin with a screenshot of the till, on phone and
// laptop). The floating panel in the top bar opens only on a tap; while the shift needs someone a strip under the bar, in the page's flow, says
// so and holds the state's one main action. On the till of the E2E branch, with no shift, with a count in progress and with the shift open, and
// on the kitchen display for a refusal. The till is settled first (the branch's stations carry the shifts other fixtures opened) and left with
// its shift open again, which is what every other fixture expects to find. Not @live: the live run has no room for more settling of shifts, and
// the p3 flows that run there go through the same bar.

const PHONE = { width: 390, height: 844 };

function parts(page: Page) {
  const region = page.getByRole("region", { name: en.shifts.region, exact: true });
  const strip = page.getByTestId("shift-strip");
  return {
    region,
    toggle: region.locator("button[data-shift-toggle]"),
    dot: region.locator("[data-shift-attention]"),
    panel: region.locator("[data-shift-panel]"),
    strip,
    more: strip.getByRole("button", { name: en.shifts.strip_more, exact: true }),
    queue: page.getByRole("region", { name: en.till.queue_title }),
  };
}

/** Nothing floats over `target`: the point at the centre of what shows of it belongs to it, not to the strip or a panel. */
async function expectUncovered(target: Locator) {
  await target.scrollIntoViewIfNeeded();
  const hit = await target.evaluate((element) => {
    const box = element.getBoundingClientRect();
    const at = document.elementFromPoint(box.left + box.width / 2, (Math.max(box.top, 0) + Math.min(box.bottom, window.innerHeight)) / 2);
    return { inside: at !== null && element.contains(at), covered: at?.closest('[data-testid="shift-strip"], [data-shift-panel], [data-cash-panel]') != null };
  });
  expect(hit).toEqual({ inside: true, covered: false });
}

async function expectNoSideScroll(page: Page) {
  const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
  expect(scroll).toBeLessThanOrEqual(client);
}

/** The strip is in the page's flow: not positioned, directly under the bar, and the page's content starts below it. */
async function expectInFlow(page: Page, strip: Locator, content: Locator) {
  expect(await strip.evaluate((element) => getComputedStyle(element).position)).toBe("static");
  const bar = (await page.locator("header").first().boundingBox())!;
  const band = (await strip.boundingBox())!;
  const first = (await content.boundingBox())!;
  expect(Math.abs(band.y - (bar.y + bar.height))).toBeLessThanOrEqual(1);
  expect(first.y).toBeGreaterThanOrEqual(band.y + band.height);
}

/** A floating panel fits under the bar and within the window, and scrolls inside itself. */
async function expectFitsTheWindow(page: Page, panel: Locator) {
  const window = page.viewportSize()!;
  const bar = (await page.locator("header").first().boundingBox())!;
  const box = (await panel.boundingBox())!;
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(window.width);
  expect(box.y).toBeGreaterThanOrEqual(bar.y + bar.height);
  expect(box.y + box.height).toBeLessThanOrEqual(window.height);
  expect(await panel.evaluate((element) => getComputedStyle(element).overflowY)).toBe("auto");
}

/** The fixed cashier at the E2E branch's till, with a drawer and no shift running. */
async function tillWithNoShift() {
  const credentials = requireAdmin(test.skip);
  const staff = await buildTillStaff(credentials);
  const till = await findOrCreateStation(staff.admin, "till");
  await ensureDrawer(staff.admin, till);
  await settleStation(staff.admin, till);
  await resolvePendingCounts(staff.admin, till);
  expect(await currentShift(staff.admin, till)).toBeNull();
  return { ...staff, till };
}

test.describe("the shift's prompt is a strip that covers nothing", () => {
  test("the till with no shift: the panel stays closed, the strip pushes the page down, More, a tap outside and Escape", async ({ page }) => {
    test.setTimeout(180_000);
    const { admin, cashier, till } = await tillWithNoShift();
    try {
      await openAs(page, cashier.api, "en/till/");
      const { region, toggle, dot, panel, strip, more, queue } = parts(page);
      const start = strip.getByRole("button", { name: en.shifts.start, exact: true });

      // the page loaded with the shift needing someone: the panel is closed, the strip and its action show
      await expect(strip).toContainText(en.shifts.idle, { timeout: 30_000 });
      await expect(strip).toContainText(en.shifts.badge_none);
      await expect(toggle).toHaveAttribute("aria-expanded", "false");
      await expect(panel).toHaveCount(0);
      await expect(start).toBeVisible();
      await expect(dot).toBeVisible();
      await expect(strip).toHaveAttribute("role", "status");
      await expect(region.getByTestId("shift-strip")).toHaveCount(0);

      // nothing covers the page, and the band is slim on a laptop: one row of about 52 px
      await expectUncovered(queue);
      await expectInFlow(page, strip, queue);
      expect((await strip.boundingBox())!.height).toBeLessThanOrEqual(60);

      // "More" opens the panel; its focus moves in, as "More" goes away while it is open; each name has one visible control
      await expect(more).toHaveAttribute("aria-expanded", "false");
      await expect(more).toHaveAttribute("aria-controls", /.+/);
      const stripHeight = (await strip.boundingBox())!.height;
      await more.click();
      await expect(toggle).toHaveAttribute("aria-expanded", "true");
      await expect(panel).toBeVisible();
      await expect(panel).toBeFocused();
      await expect(page.getByRole("button", { name: en.shifts.start, exact: true })).toHaveCount(1);
      await expect(panel.getByRole("button", { name: en.shifts.start, exact: true })).toBeVisible();
      await expect(start).toBeHidden();
      await expect(more).toBeHidden();
      // the strip keeps its place, so the page does not jump under a tap that closes the panel
      expect((await strip.boundingBox())!.height).toBe(stripHeight);

      // a tap or click outside closes it, in the window's corner, where no order card can be
      const window = page.viewportSize()!;
      await page.mouse.click(4, window.height - 4);
      await expect(panel).toHaveCount(0);
      await expect(toggle).toHaveAttribute("aria-expanded", "false");
      await expect(start).toBeVisible();

      // Escape closes it, and the focus is back on the chip
      await toggle.click();
      await expect(panel).toBeVisible();
      await page.keyboard.press("Escape");
      await expect(panel).toHaveCount(0);
      await expect(toggle).toBeFocused();

      // so does the chip
      await toggle.click();
      await expect(panel).toBeVisible();
      await toggle.click();
      await expect(panel).toHaveCount(0);

      // a tap inside the panel is not a tap outside it
      await toggle.click();
      await panel.getByRole("button", { name: en.counts.handover_show, exact: true }).click();
      await expect(panel.getByRole("button", { name: en.counts.handover_hide, exact: true })).toBeVisible();
      await page.keyboard.press("Escape");
      await expect(panel).toHaveCount(0);

      // at phone width: the message on one line, the action the whole width under it, nothing overlapping, no side scroll
      await page.setViewportSize(PHONE);
      await expectNoSideScroll(page);
      await expectUncovered(queue);
      await expectInFlow(page, strip, queue);
      const action = (await start.boundingBox())!;
      const message = (await strip.getByText(en.shifts.idle, { exact: true }).boundingBox())!;
      expect(action.width).toBeGreaterThanOrEqual(PHONE.width - 2 * 16 - 1);
      expect(message.y + message.height).toBeLessThanOrEqual(action.y);
      await more.click();
      await expect(panel).toBeVisible();
      await expectFitsTheWindow(page, panel);
      await expectNoSideScroll(page);
      await page.keyboard.press("Escape");
      await expect(panel).toHaveCount(0);
    } finally {
      await ensureOpenShift(admin, till);
    }
  });

  test("the strip's action starts the shift and the page keeps its panel closed; with a count in progress it opens the count, where no strip shows; an open shift has none", async ({ page }) => {
    test.setTimeout(180_000);
    const { admin, cashier, till } = await tillWithNoShift();
    try {
      await openAs(page, cashier.api, "en/till/");
      const { toggle, dot, panel, strip, more, queue } = parts(page);

      // "Start shift" in the strip: the shift is `opening`, the strip now offers the count and the panel stays closed
      await strip.getByRole("button", { name: en.shifts.start, exact: true }).click({ timeout: 30_000 });
      await expect(strip).toContainText(en.shifts.opening, { timeout: 15_000 });
      await expect(strip).toContainText(en.shifts.badge_opening);
      expect((await currentShift(admin, till))?.status).toBe("opening");
      await expect(toggle).toHaveAttribute("aria-expanded", "false");
      await expect(panel).toHaveCount(0);
      const count = strip.getByRole("link", { name: en.shifts.open_count, exact: true });
      await expect(count).toHaveAttribute("href", /\/shifts\/count\/\?shift=\d+/);
      await expect(dot).toBeVisible();

      // a new visit to the page, as on the owner's screenshot: closed, and nothing over the page
      await page.reload();
      await expect(strip).toContainText(en.shifts.opening, { timeout: 30_000 });
      await expect(toggle).toHaveAttribute("aria-expanded", "false");
      await expectUncovered(queue);
      await expectInFlow(page, strip, queue);

      // one visible copy of the name while the panel is open, and its other actions are in the panel
      await more.click();
      await expect(page.getByRole("link", { name: en.shifts.open_count, exact: true })).toHaveCount(1);
      await expect(panel.getByRole("link", { name: en.shifts.open_count, exact: true })).toBeVisible();
      await expect(panel.getByRole("button", { name: en.shifts.cancel_start, exact: true })).toBeVisible();

      // a dialog opened from the panel: a tap in it is not a tap outside, and Escape closes the dialog first
      await panel.getByRole("button", { name: en.shifts.declare_drawer, exact: true }).click();
      const dialog = page.getByRole("dialog", { name: en.shifts.drawer_title });
      await dialog.getByRole("heading", { name: en.shifts.drawer_title }).click();
      await expect(dialog).toBeVisible();
      await page.keyboard.press("Escape");
      await expect(dialog).toHaveCount(0);
      await expect(panel).toBeVisible();
      await page.keyboard.press("Escape");
      await expect(panel).toHaveCount(0);

      await page.setViewportSize(PHONE);
      await expectNoSideScroll(page);
      await expectUncovered(queue);
      await page.setViewportSize({ width: 1280, height: 800 });

      // the action works, and the count screen is the count: no strip for it there (`quiet`), and its panel closed too
      await count.click();
      await expect(page.getByRole("heading", { name: en.counts.title, level: 1 })).toBeVisible({ timeout: 30_000 });
      await expect(toggle).toHaveAttribute("aria-expanded", "false", { timeout: 30_000 });
      await expect(strip).toBeHidden();
      await expect(dot).toHaveCount(0);

      // the shift opens: no strip, the page has its full height back, and the chip alone shows the state
      await ensureOpenShift(admin, till);
      await page.goto("en/till/");
      await expect(toggle).toContainText(en.shifts.open, { timeout: 30_000 });
      await expect(strip).toBeHidden();
      await expect(more).toHaveCount(0);
      await expect(dot).toHaveCount(0);
      await expect(toggle).toHaveAttribute("aria-expanded", "false");

      // the till's cash panel opens on a tap only and closes the same way, and it fits a phone
      const cash = page.getByRole("region", { name: en.shifts.cash_title });
      const figure = cash.getByRole("button", { name: /^Cash in the drawer/ });
      const details = cash.locator("[data-cash-panel]");
      await expect(figure).toHaveAttribute("aria-expanded", "false", { timeout: 30_000 });
      await expect(details).toHaveCount(0);
      await figure.click();
      await expect(details).toBeVisible();
      const window = page.viewportSize()!;
      await page.mouse.click(4, window.height - 4);
      await expect(details).toHaveCount(0);
      await figure.click();
      await expect(details).toBeVisible();
      await page.keyboard.press("Escape");
      await expect(details).toHaveCount(0);
      await expect(figure).toBeFocused();
      await page.setViewportSize(PHONE);
      await expectNoSideScroll(page);
      await figure.click();
      await expect(details).toBeVisible();
      await expectFitsTheWindow(page, details);
      await expectNoSideScroll(page);
      await page.keyboard.press("Escape");
    } finally {
      await ensureOpenShift(admin, till);
    }
  });

  test("a refusal shows in the strip in amber with the same action, instead of forcing the panel open, on the kitchen display", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildStationFixture(credentials, "kitchen");
    const { admin, station, worker } = fixture;
    await settleStation(admin, station);
    await resolvePendingCounts(admin, station);
    const chef = await loginOnce(worker.phone, worker.password, fixture.branchId);
    try {
      const said = "No shift is open at this station. Start a shift here before moving its stock.";
      await page.route("**/stock-strip*", (route) =>
        route.fulfill({
          status: 409,
          contentType: "application/json",
          body: JSON.stringify({ message_key: "inventory.shift.required", message_en: said, message_am: "በዚህ ጣቢያ የተከፈተ ፈረቃ የለም።", station_id: station.id, location_id: fixture.location.id }),
        }),
      );
      await openAs(page, chef.api, "en/kds/kitchen/");
      const { toggle, panel, strip, more } = parts(page);
      await expect(strip).toContainText(said, { timeout: 30_000 });
      await expect(toggle).toHaveAttribute("aria-expanded", "false");
      await expect(panel).toHaveCount(0);
      await expect(strip.getByRole("button", { name: en.shifts.start, exact: true })).toBeVisible();

      // the panel still says it, as an alert, for a person who opens it
      await more.click();
      await expect(panel.getByRole("alert")).toContainText(said);
      await page.keyboard.press("Escape");
      await page.unroute("**/stock-strip*");

      // the same action answers it: the shift starts, and the refusal is behind us
      await strip.getByRole("button", { name: en.shifts.start, exact: true }).click();
      await expect(strip).toContainText(en.shifts.opening, { timeout: 15_000 });
      await expect(strip).not.toContainText(said);
    } finally {
      await page.unroute("**/stock-strip*");
      await ensureOpenShift(admin, station);
    }
  });

  test("a refusal with the shift open: the strip says it and Dismiss takes it away, so an open shift ends with none", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const staff = await buildTillStaff(credentials);
    const till = await findOrCreateStation(staff.admin, "till");
    await ensureOpenShift(staff.admin, till);
    const before = await currentShift(staff.admin, till);
    await openAs(page, staff.cashier.api, "en/till/");
    const { toggle, panel, strip } = parts(page);
    await expect(toggle).toContainText(en.shifts.open, { timeout: 30_000 });
    await expect(strip).toBeHidden();

    const said = "This shift cannot be changed now.";
    await page.route(/\/shifts\/\d+\/(join|leave)$/, (route) =>
      route.fulfill({ status: 409, contentType: "application/json", body: JSON.stringify({ message_key: "shifts.shift.not_open", message_en: said, message_am: said }) }),
    );
    await toggle.click();
    const onShift = before?.roster.some((row) => row.user?.id === staff.cashier.id && row.left_at === null);
    await panel.getByRole("button", { name: onShift ? en.shifts.leave : en.shifts.join, exact: true }).click();
    await expect(panel.getByRole("alert")).toContainText(said, { timeout: 15_000 });
    await page.keyboard.press("Escape");

    await expect(strip).toContainText(said);
    const dismiss = strip.getByRole("button", { name: en.shifts.strip_dismiss, exact: true });
    await dismiss.click();
    await expect(strip).toBeHidden();
    await expect(parts(page).dot).toHaveCount(0);
  });
});
