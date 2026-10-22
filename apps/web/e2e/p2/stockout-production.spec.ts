import { test, expect, type Browser, type Page } from "@playwright/test";
import { uuid } from "../support/api";
import { live, requireAdmin } from "../support/env";
import { buildStationFixture, menuAvailable, sendOrder } from "../support/fixture";
import { transfersOf } from "../support/inventory";
import { openAs, openPadAndUnlock } from "../support/pad";
import { buildProductionFixture, stockAlertsOf, inboxOf, movementsOf, pushOffered, requestById, requestsOf, waitForQueue, type ProductionFixture } from "../support/production";

// Phase 2 gate project p2-stockout-production (manifest phases.2.gate.criteria; docs/phases/phase-2-plan.md section 7),
// step 2.8a. Three browser contexts, one per screen: the waiter pad, the cake baker's production board and the
// overseer's production board.
//
// The cake room requires an overseer. A cake with exactly 1 on hand in the display case is sold on the pad; the sale
// reserves it, its availability falls to 0, the stock-out becomes a production request (queued work: the test waits for
// it by polling the API, never by sleeping) and the card appears on the baker's board. The baker accepts, starts and marks
// it done with 6 produced and 0 waste; the card waits in Pending confirmation and the stock has not moved. The overseer
// confirms; the card moves to Done today and the pad shows the cake with 6 available. Through the API: the ledger holds one
// production_consume per ingredient with the scaled quantity and one production_output of 6.
//
// The fixture is built through the admin API inside the isolated "E2E Test Branch" (e2e/support/production.ts), so the
// flow carries @live.

const TABLET = { width: 1280, height: 800 };

function column(page: Page, name: string) {
  return page.getByRole("region", { name, exact: true });
}

async function runFlow(f: ProductionFixture, pages: { pad: Page; baker: Page; overseer: Page }) {
  const { pad, baker, overseer } = pages;
  const cake = f.cake.name_en;

  // the baker's board is open before anything is sold
  await openAs(baker, f.baker.api, "en/production/");
  await expect(baker.getByRole("heading", { level: 1, name: "Production board" })).toBeVisible({ timeout: 30_000 });
  for (const name of ["Requested", "In progress", "Pending confirmation", "Done today"]) await expect(column(baker, name)).toBeVisible();

  // the pad sells the last cake
  expect(await menuAvailable(f.waiter.api, f.cake.id)).toBe("1.0000");
  await openPadAndUnlock(pad, f.waiter);
  await pad.getByRole("button", { name: `Table ${f.table.code}` }).click();
  await pad.getByRole("tab", { name: "Other" }).click();
  await pad.getByRole("button", { name: `Add ${cake}` }).click();
  await pad.getByRole("button", { name: "Send order" }).click();
  await expect(pad.getByRole("status").filter({ hasText: "Order sent" })).toBeVisible({ timeout: 30_000 });
  await expect.poll(() => menuAvailable(f.waiter.api, f.cake.id), { timeout: 30_000 }).toBe("0.0000");

  // the stock-out becomes a production request: queued work, so wait for it (150 s on the live host)
  const request = await waitForQueue(`a production request for ${cake}`, async () => (await requestsOf(f.admin, f.cake.id))[0] ?? null);
  expect(request).toMatchObject({ status: "requested", reason: "stock_out", requested_qty: "6.0000" });

  // the card is on the baker's board, in Requested, and opens with the scaled recipe
  const requested = column(baker, "Requested");
  const card = requested.getByRole("article").filter({ hasText: cake });
  await expect(card).toBeVisible({ timeout: 30_000 });
  await expect(card).toContainText("Stock out");
  await expect(card).toContainText("6");
  await card.getByRole("button", { name: `Open ${cake}` }).click();
  const detail = baker.getByRole("region", { name: `Request card: ${cake}` });
  await expect(detail).toBeVisible();
  const recipeLine = detail.getByRole("row").filter({ hasText: f.ingredient.name_en });
  await expect(recipeLine).toContainText("1800 g"); // 0.9 kg per 3 cakes, 6 cakes, in the base unit
  await expect(recipeLine).toContainText("10000 g"); // 10 kg on hand in the cake room
  await expect(detail.getByText("Short", { exact: true })).toHaveCount(0);

  // accept: the card stays in Requested and shows who took it
  await detail.getByRole("button", { name: "Accept", exact: true }).click();
  await expect(card).toContainText(f.baker.name, { timeout: 15_000 });
  await expect.poll(async () => (await requestById(f.admin, request.id)).status).toBe("accepted");

  // start, then done with 6 produced and 0 waste
  await detail.getByRole("button", { name: "Start", exact: true }).click();
  await expect(column(baker, "In progress").getByRole("article").filter({ hasText: cake })).toBeVisible({ timeout: 15_000 });
  await detail.getByLabel("Produced").fill("6");
  await detail.getByLabel("Waste").fill("0");
  await detail.getByRole("button", { name: "Done", exact: true }).click();

  // the cake room needs an overseer: the card waits in Pending confirmation and nothing is written yet
  await expect(column(baker, "Pending confirmation").getByRole("article").filter({ hasText: cake })).toBeVisible({ timeout: 15_000 });
  const waiting = await requestById(f.admin, request.id);
  expect(waiting.status).toBe("pending_confirmation");
  expect(waiting.batch).toMatchObject({ status: "finished", produced_qty: "6.0000", waste_qty: "0.0000" });
  expect((await movementsOf(f.admin, f.cake.id, f.displayCase.id)).map((m) => m.reason)).toEqual(["purchase_in", "reservation"]);
  expect((await movementsOf(f.admin, f.ingredient.id, f.cakeRoomLocation.id)).map((m) => m.reason)).toEqual(["purchase_in"]);

  // the overseer's board has the batch waiting for sign-off
  await openAs(overseer, f.overseer.api, "en/production/");
  const pending = column(overseer, "Pending confirmation").getByRole("article").filter({ hasText: cake });
  await expect(pending).toBeVisible({ timeout: 30_000 });
  await pending.getByRole("button", { name: `Open ${cake}` }).click();
  const signOff = overseer.getByRole("region", { name: `Request card: ${cake}` });
  await expect(signOff).toContainText(f.baker.name);
  await signOff.getByRole("button", { name: "Confirm", exact: true }).click();
  await expect(column(overseer, "Done today").getByRole("article").filter({ hasText: cake })).toBeVisible({ timeout: 15_000 });
  await expect(column(baker, "Done today").getByRole("article").filter({ hasText: cake })).toBeVisible({ timeout: 30_000 });

  // the pad shows the cake with 6 available (the menu is polled every 5 s)
  await expect.poll(() => menuAvailable(f.waiter.api, f.cake.id), { timeout: 30_000 }).toBe("6.0000");
  await expect(pad.getByRole("button", { name: new RegExp(`^Add ${cake}.*6 available`) })).toBeVisible({ timeout: 30_000 });

  // post-conditions through the API
  const done = await requestById(f.admin, request.id);
  expect(done.status).toBe("done");
  expect(done.assigned_to?.id).toBe(f.baker.id);
  expect(done.batch).toMatchObject({ status: "confirmed", produced_qty: "6.0000", waste_qty: "0.0000" });
  expect(done.batch?.finished_by?.id).toBe(f.baker.id);
  expect(done.batch?.confirmed_by?.id).toBe(f.overseer.id);

  const cakeMoves = await movementsOf(f.admin, f.cake.id, f.displayCase.id);
  expect(cakeMoves.map((m) => m.reason)).toEqual(["purchase_in", "reservation", "production_output"]);
  expect(cakeMoves[2].qty).toBe("6.0000");
  const flourMoves = await movementsOf(f.admin, f.ingredient.id, f.cakeRoomLocation.id);
  expect(flourMoves.map((m) => m.reason)).toEqual(["purchase_in", "production_consume"]);
  expect(flourMoves[1].qty).toBe(`-${f.cake.consumedForBatch}`);
}

async function runBell(f: ProductionFixture, baker: Page) {
  // the baker was told: one production_requested line, linking to the card, and the bell shows it
  const line = await waitForQueue("the baker's production_requested notification", async () => (await inboxOf(f.baker.api)).find((l) => l.type === "production_requested" && l.deep_link?.includes("request=")) ?? null);
  const bell = baker.getByRole("button", { name: /^Notifications/ });
  await expect(bell).toHaveAccessibleName(/unread/, { timeout: 60_000 });
  await bell.click();
  const inbox = baker.getByRole("region", { name: "Notifications" });
  await expect(inbox.getByRole("link").first()).toHaveAttribute("href", /\/production\/\?request=\d+/);
  expect(line.deep_link).toMatch(/^\/production\?request=\d+$/);

  // push is offered only when the API says it is on (it is off on the live host)
  const offered = await pushOffered(f.baker.api);
  await expect(inbox.getByRole("button", { name: "Enable notifications" })).toHaveCount(offered ? 1 : 0);

  // read and read all go through the API
  await inbox.getByRole("button", { name: "Mark all as read" }).click();
  await expect(bell).not.toHaveAccessibleName(/unread/, { timeout: 15_000 });
  await expect.poll(async () => (await inboxOf(f.baker.api)).filter((l) => l.read_at === null).length).toBe(0);
}

async function openContexts(browser: Browser, baseURL: string | undefined) {
  const make = () => browser.newContext({ baseURL, viewport: TABLET, serviceWorkers: "block" });
  const contexts = await Promise.all([make(), make(), make()]);
  const [pad, baker, overseer] = await Promise.all(contexts.map((context) => context.newPage()));
  return { contexts, pages: { pad, baker, overseer } };
}

test.describe("p2 stock-out to production", () => {
  test("a sold-out cake becomes a production request and is back on the pad once the baker made it and the overseer confirmed @live", async ({ browser }, testInfo) => {
    // the queued hops take a cron tick or two on the live host; CI drains the queue within seconds
    test.setTimeout(live ? 600_000 : 240_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildProductionFixture(credentials);
    const { contexts, pages } = await openContexts(browser, testInfo.project.use.baseURL);
    try {
      await runFlow(fixture, pages);
      await runBell(fixture, pages.baker);
    } catch (error) {
      for (const [name, page] of Object.entries(pages)) {
        await testInfo.attach(`${name}.png`, { body: await page.screenshot().catch(() => Buffer.alloc(0)), contentType: "image/png" });
      }
      throw error;
    } finally {
      await Promise.all(contexts.map((context) => context.close()));
    }
  });
});

// The plan's second test (step 2.8a deferred it to the transfer work of 2.8c).
//
// A made-to-order sandwich sold down to the last of its ingredient raises a stock-out of the ingredient, but production is
// not asked for anything: the auto request is only for a to-stock product (the test waits for the alert, then looks for a
// request and finds none). Then a cake request asked for by hand over a cake room that needs no overseer (so Done writes the stock at once) and a shelf that holds 1000 g of a recipe that
// needs 1800 g for a batch of 6: the card shows the red shortfall (800 g missing, the store has 5000 g), Done is refused with
// the API's message inline and nothing is written, and "Request transfer" from the card creates a requested transfer from
// the store to the cake room that carries the request.

async function runNoRequestForMadeToOrder(admin: ProductionFixture["admin"], credentials: { phone: string; password: string }) {
  const station = await buildStationFixture(credentials, "kitchen", { stock: "0.2000", perUnit: "0.2000", price: "180.00" });
  await sendOrder(station, "1");
  await waitForQueue(`the stock alert of ${station.ingredient.name_en}`, async () => (await stockAlertsOf(admin)).find((a) => a.item?.id === station.ingredient.id) ?? null);
  // production gets the same event; give the queue a few drains, then look: no request for a made-to-order product
  for (let look = 0; look < 3; look++) {
    expect(await requestsOf(admin, station.product.id), "a made-to-order product never gets an automatic production request").toEqual([]);
    await new Promise((resolve) => setTimeout(resolve, 2000));
  }
}

async function runShortfallAndTransfer(f: ProductionFixture, baker: Page) {
  const cake = f.cake.name_en;
  const request = await f.admin.post<{ id: number }>("/production/requests", { client_uuid: uuid(), product_item_id: f.cake.id, note: "e2e shortfall" });

  await openAs(baker, f.baker.api, `en/production/?request=${request.id}`);
  const detail = baker.getByRole("region", { name: `Request card: ${cake}` });
  await expect(detail).toBeVisible({ timeout: 30_000 });
  const line = detail.getByRole("row").filter({ hasText: f.ingredient.name_en });
  await expect(line).toContainText("1800 g");
  await expect(line).toContainText("1000 g");
  await expect(line).toContainText("Short");
  await expect(line).toContainText("800 g missing");

  // accept and start are allowed; Done is refused with the shortage, and nothing was written
  await detail.getByRole("button", { name: "Accept", exact: true }).click();
  await detail.getByRole("button", { name: "Start", exact: true }).click();
  await detail.getByLabel("Produced").fill("6");
  await detail.getByLabel("Waste").fill("0");
  await detail.getByRole("button", { name: "Done", exact: true }).click();
  await expect(detail.getByRole("alert")).toContainText("short by 800 g", { timeout: 15_000 });
  expect((await movementsOf(f.admin, f.ingredient.id, f.cakeRoomLocation.id)).map((m) => m.reason)).toEqual(["purchase_in"]);
  expect((await requestById(f.admin, request.id)).status).toBe("in_progress");

  // "Request transfer" from the card: the same sheet as the kitchen strip, asking the store for the 800 g
  await line.getByRole("button", { name: `Request transfer of ${f.ingredient.name_en}` }).click();
  const sheet = baker.getByRole("dialog", { name: `Request transfer: ${f.ingredient.name_en}` });
  await expect(sheet.getByLabel("Quantity (g)")).toHaveValue("800");
  await sheet.getByRole("button", { name: "Send request" }).click();
  await expect(sheet.getByRole("status")).toContainText("Transfer requested", { timeout: 15_000 });
  const [asked] = await transfersOf(f.admin, f.ingredient.id);
  expect(asked).toMatchObject({ status: "requested", qty: "800.0000" });
  expect(asked.from_location.id).toBe(f.store.id);
  expect(asked.to_location.id).toBe(f.cakeRoomLocation.id);
}

test.describe("p2 stock-out, second case", () => {
  test("a made-to-order stock-out makes no request, and a short batch shows the shortfall and can ask the store for the rest @live", async ({ browser }, testInfo) => {
    test.setTimeout(live ? 600_000 : 240_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildProductionFixture(credentials, { cakeRoomStock: "1.0000", storeStock: "5.0000", requiresOverseer: false });
    const context = await browser.newContext({ baseURL: testInfo.project.use.baseURL, viewport: TABLET, serviceWorkers: "block" });
    const baker = await context.newPage();
    try {
      await runNoRequestForMadeToOrder(fixture.admin, credentials);
      await runShortfallAndTransfer(fixture, baker);
    } catch (error) {
      await testInfo.attach("baker.png", { body: await baker.screenshot().catch(() => Buffer.alloc(0)), contentType: "image/png" });
      throw error;
    } finally {
      await context.close();
    }
  });
});
