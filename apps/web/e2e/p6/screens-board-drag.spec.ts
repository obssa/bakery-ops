import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test, type Locator, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { expectAccessible, fontsReady, settledAnimations } from "../support/a11y";
import { uuid } from "../support/api";
import { live, requireAdmin } from "../support/env";
import { openAs } from "../support/pad";
import { buildProductionFixture, requestById, type ProductionFixture } from "../support/production";

// phase-6/board-drag (Ramin 2026-11-11: "can we move a card from one state to the other and its status updates by itself"). On the CI stack,
// in the E2E Test Branch (e2e/support/production.ts: a cake room that needs an overseer, its cake baker and its overseer):
//   - the baker drags a new request from Requested to In progress: it is accepted and started in one drop, and the card stays there;
//   - while the baker drags the batch, the columns say where it can go: Requested is dimmed with its reason, Pending confirmation and Done
//     today say "Drop here" (captured mid-drag);
//   - the drop on Pending confirmation opens the Done form; once it is sent the card waits in Pending confirmation;
//   - the overseer moves it to Done today with the keyboard (the grip, Space, the right arrow, Space), and the baker's board shows it there
//     by itself (it polls);
//   - captures of the board with a card waiting (its waiting time, who moves it next, its grip) at 1440 x 900 and 390 x 844 in en and am,
//     with axe and no sideways scroll; at 390 the cards are dropped on the column tabs.

const SHOTS = path.join("test-results", "screens");
const DESKTOP = { width: 1440, height: 900 };
const PHONE = { width: 390, height: 844 };
const MESSAGES = { en, am } as const;

async function capture(page: Page, name: string): Promise<void> {
  await fontsReady(page);
  await settledAnimations(page);
  mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`) });
}

function column(page: Page, name: string): Locator {
  return page.getByRole("region", { name, exact: true });
}

function cardIn(page: Page, columnName: string, product: string): Locator {
  return column(page, columnName).getByRole("article").filter({ hasText: product });
}

async function noSidewaysScroll(page: Page, what: string): Promise<void> {
  const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
  expect(scroll, `${what} scrolls sideways`).toBeLessThanOrEqual(client);
}

/** Presses the mouse on the card's top and moves it over `target` in steps (the board picks a card up after 8 px); `release` drops it. */
async function dragTo(page: Page, card: Locator, target: Locator, release = true): Promise<void> {
  await card.scrollIntoViewIfNeeded();
  const from = await card.boundingBox();
  if (!from) throw new Error("the card has no box");
  await page.mouse.move(from.x + from.width / 2, from.y + 24);
  await page.mouse.down();
  await page.mouse.move(from.x + from.width / 2 + 12, from.y + 36, { steps: 4 });
  const to = await target.boundingBox();
  if (!to) throw new Error("the drop target has no box");
  await page.mouse.move(to.x + to.width / 2, to.y + Math.min(to.height / 2, 90), { steps: 16 });
  if (release) await page.mouse.up();
}

async function askForCake(f: ProductionFixture, note: string): Promise<number> {
  return (await f.admin.post<{ id: number }>("/production/requests", { client_uuid: uuid(), product_item_id: f.cake.id, note })).id;
}

test.describe("screens: dragging a card along the production board", () => {
  test.skip(live, "CI only: the flow makes and confirms a batch, and the captures are for review");

  test("a baker drags a request to the oven and to the overseer, the overseer confirms it by keyboard, and the board follows by itself", async ({ browser }, testInfo) => {
    test.setTimeout(300_000);
    const t = en.production;
    const f = await buildProductionFixture(requireAdmin(test.skip));
    const cake = f.cake.name_en;
    const requestId = await askForCake(f, "e2e drag");
    const make = () => browser.newContext({ baseURL: testInfo.project.use.baseURL, viewport: DESKTOP, serviceWorkers: "block" });
    const [bakerContext, overseerContext] = await Promise.all([make(), make()]);
    const baker = await bakerContext.newPage();
    const overseer = await overseerContext.newPage();
    try {
      await openAs(baker, f.baker.api, "en/production/");
      await expect(baker.getByRole("heading", { level: 1, name: t.title })).toBeVisible({ timeout: 30_000 });

      // Requested -> In progress: accepted and started in one drop
      const asked = cardIn(baker, t.col_requested, cake);
      await expect(asked).toBeVisible({ timeout: 30_000 });
      // nobody has it yet: the card says who takes it
      await expect(asked).toContainText("accepts it.");
      await dragTo(baker, asked, column(baker, t.col_in_progress));
      await expect(cardIn(baker, t.col_in_progress, cake)).toBeVisible({ timeout: 15_000 });
      await expect.poll(async () => (await requestById(f.admin, requestId)).status, { timeout: 30_000 }).toBe("in_progress");

      // while the batch is dragged, each column says what a drop does there: captured mid-drag
      const baking = cardIn(baker, t.col_in_progress, cake);
      await expect(baking.getByRole("button", { name: t.drag_handle.replace("{name}", cake) })).toBeVisible();
      await dragTo(baker, baking, column(baker, t.col_done_today), false);
      await expect(column(baker, t.col_requested).getByText(t.drop_no_back)).toBeVisible();
      await expect(column(baker, t.col_pending_confirmation).getByText(t.drop_here, { exact: true })).toBeVisible();
      await capture(baker, "production-drag-1440x900-en");
      // over Pending confirmation, then dropped: the Done form opens
      const pendingBox = await column(baker, t.col_pending_confirmation).boundingBox();
      if (!pendingBox) throw new Error("Pending confirmation has no box");
      await baker.mouse.move(pendingBox.x + pendingBox.width / 2, pendingBox.y + 90, { steps: 10 });
      await baker.mouse.up();
      const detail = baker.getByRole("region", { name: `Request card: ${cake}` });
      await expect(detail).toBeVisible({ timeout: 15_000 });
      await detail.getByLabel("Produced").fill(f.cake.batch.replace(/\.0+$/, ""));
      await detail.getByLabel("Waste").fill("0");
      await detail.getByRole("button", { name: "Done", exact: true }).click();
      await expect(cardIn(baker, t.col_pending_confirmation, cake)).toBeVisible({ timeout: 15_000 });
      // the baker made it, so the card says who confirms it and has no grip for them
      await expect(cardIn(baker, t.col_pending_confirmation, cake)).toContainText(t.next_confirm_mine);
      await expect(cardIn(baker, t.col_pending_confirmation, cake).getByRole("button", { name: t.drag_handle.replace("{name}", cake) })).toHaveCount(0);

      // the overseer moves it to Done today with the keyboard
      await openAs(overseer, f.overseer.api, "en/production/");
      const waiting = cardIn(overseer, t.col_pending_confirmation, cake);
      await expect(waiting).toBeVisible({ timeout: 30_000 });
      const grip = waiting.getByRole("button", { name: t.drag_handle.replace("{name}", cake) });
      await grip.focus();
      await overseer.keyboard.press("Space");
      await overseer.keyboard.press("ArrowRight");
      await overseer.keyboard.press("Space");
      await expect(cardIn(overseer, t.col_done_today, cake)).toBeVisible({ timeout: 15_000 });
      await expect.poll(async () => (await requestById(f.admin, requestId)).status, { timeout: 30_000 }).toBe("done");
      const done = await requestById(f.admin, requestId);
      expect(done.batch?.confirmed_by?.id).toBe(f.overseer.id);

      // the baker's board follows by itself
      await expect(cardIn(baker, t.col_done_today, cake)).toBeVisible({ timeout: 30_000 });
    } catch (error) {
      for (const [name, page] of Object.entries({ baker, overseer })) {
        await testInfo.attach(`${name}.png`, { body: await page.screenshot().catch(() => Buffer.alloc(0)), contentType: "image/png" });
      }
      throw error;
    } finally {
      await Promise.all([bakerContext.close(), overseerContext.close()]);
    }
  });

  for (const locale of ["en", "am"] as const) {
    test(`the board with a request waiting, its next mover and its grip, at 1440 x 900 and 390 x 844, in ${locale}`, async ({ page }) => {
      test.setTimeout(180_000);
      const t = MESSAGES[locale].production;
      const f = await buildProductionFixture(requireAdmin(test.skip));
      const cake = locale === "am" ? f.cake.name_am : f.cake.name_en;
      await askForCake(f, `e2e board ${locale}`);
      await page.setViewportSize(DESKTOP);
      await openAs(page, f.baker.api, `${locale}/production/`);
      await expect(page.getByRole("heading", { level: 1, name: t.title })).toBeVisible({ timeout: 30_000 });
      const asked = cardIn(page, t.col_requested, cake);
      await expect(asked).toBeVisible({ timeout: 30_000 });
      await expect(asked.getByRole("button", { name: t.drag_handle.replace("{name}", cake) })).toBeVisible();
      for (const column of ["requested", "in_progress", "pending_confirmation", "done_today"] as const) {
        await expect(page.getByRole("heading", { level: 2, name: t[`col_${column}`], exact: true })).toHaveCount(1);
      }
      await capture(page, `production-drag-board-1440x900-${locale}`);
      await noSidewaysScroll(page, `the board at 1440 in ${locale}`);
      await expectAccessible(page, `${locale}/production/ with a card that can be dragged`);

      await page.setViewportSize(PHONE);
      await expect(page.getByRole("tablist", { name: t.tabs })).toBeVisible();
      await asked.scrollIntoViewIfNeeded();
      await capture(page, `production-drag-board-390x844-${locale}`);
      await noSidewaysScroll(page, `the board at 390 in ${locale}`);
      // on a phone a card is dropped on its column's tab: held over the In progress tab, the tabs say where it can go
      const tab = page.getByRole("tablist", { name: t.tabs }).getByRole("tab", { name: new RegExp(t.tab_in_progress) });
      await dragTo(page, asked, tab, false);
      await capture(page, `production-drag-390x844-${locale}`);
      await page.keyboard.press("Escape");
      await page.mouse.up();
      await expectAccessible(page, `${locale}/production/ at 390 x 844 with a card that can be dragged`);
      await page.setViewportSize(DESKTOP);
    });
  }
});
