import { expect, test, type Page } from "@playwright/test";
import { freshDevice } from "../support/devices";
import { requireAdmin, TICKET_VISIBLE_MS } from "../support/env";
import { buildStationFixture, clearStaleOrders, loginOnce, sendOrder, type StationFixture } from "../support/fixture";
import { ordersWithUuid, queuedWrites } from "../support/outbox";
import { chooseTable, ensureUnlocked, openAs, openPad, openPadAndUnlock, typeCode } from "../support/pad";

// Phase 5 gate project p5-offline (docs/phases/phase-5-plan.md, step 5.2c and section 7): the conflict tray and reconnect reconcile.
// - A stock conflict made while the pad was offline: the waiter's send waits on the tablet, another sale takes the stock it needed, and
//   back online the API refuses the send (409 orders.send.shortfall). The refusal stays in the tray, through a reload, with the server's
//   message in both languages and the line that is short; "Edit and resend" brings the order back to the cart, the waiter changes the
//   line and sends it: the same order is sent, and the tray is empty.
// - A kitchen display offline for a minute shows, once it is back, the tickets sent meanwhile (tickets?since=<cursor>).
// - Two tablets offline at once, each with orders waiting: back online at the same moment, every order reaches the API exactly once.
// The pads are the run's waiter on enrolled devices (step 5.1b; the second tablet is a device of the flow's own). Not @live.

const tray = (page: Page) => page.getByRole("region", { name: "Changes not saved" });
const ETHIOPIC = /[ሀ-፿]/;

async function cartWith(page: Page, fixture: StationFixture, qty: number) {
  await chooseTable(page, fixture.table.code);
  await page.getByRole("tab", { name: "Other" }).click();
  const product = fixture.product.name_en;
  await page.getByRole("button", { name: `Add ${product}` }).click();
  const line = page.getByRole("region", { name: "Cart" }).getByRole("listitem").filter({ hasText: product });
  for (let more = 1; more < qty; more++) await line.getByRole("button", { name: `Increase quantity of ${product}` }).click();
  await expect(line.getByRole("textbox", { name: `Quantity of ${product}` })).toHaveValue(String(qty));
}

test.describe("p5 offline: the conflict tray and reconnect", () => {
  test("a stock conflict made while the pad was offline lands in the tray with the shortfall, and the order is edited and sent again", async ({ page, context }) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    // stock for exactly 2 portions, one portion each
    const fixture = await buildStationFixture(credentials, "kitchen", { stock: "2.0000", perUnit: "1.0000" });
    const product = fixture.product.name_en;
    await openPadAndUnlock(page, fixture.waiter);
    await cartWith(page, fixture, 2);

    // offline: the send of 2 waits on the tablet
    await context.setOffline(true);
    await page.getByRole("button", { name: "Send order" }).click();
    await expect(page.getByRole("status").filter({ hasText: "1 change waiting to send" })).toBeVisible();
    await expect.poll(async () => (await queuedWrites(page)).length).toBe(1);
    const [queued] = await queuedWrites(page);
    const clientUuid = queued.subjectUuid!;

    // meanwhile another tablet sells one: only 1 portion is left
    const other = await sendOrder(fixture, "1");
    expect(other.status).toBe("sent");

    // back online: the API refuses the send, and the refusal stays in the tray (kept in IndexedDB as a conflict)
    await context.setOffline(false);
    const entry = tray(page).getByRole("listitem").filter({ hasText: "Not saved: send order" });
    await expect(entry).toBeVisible({ timeout: 60_000 });
    await expect.poll(async () => (await queuedWrites(page)).map((w) => w.state)).toEqual(["conflict"]);

    // it is still there after a reload (the cart in memory is gone; the tray is not)
    await page.reload();
    await expect(entry).toBeVisible({ timeout: 30_000 });
    // the server's message in both languages, and the line that is short
    await expect(entry.locator('[lang="en"]').first()).toContainText("Some lines cannot be made with the stock on hand");
    await expect(entry.locator('[lang="am"]').first()).toHaveText(ETHIOPIC);
    await expect(entry).toContainText(fixture.ingredient.name_en);
    await expect(entry).toContainText(fixture.ingredient.name_am);
    await expect(entry.getByRole("button", { name: "Discard" })).toBeVisible();

    // edit and resend: the order comes back to the waiter's cart with the shortfall on its line
    await entry.getByRole("button", { name: "Edit and resend" }).click();
    await ensureUnlocked(page, fixture.waiter);
    const line = page.getByRole("region", { name: "Cart" }).getByRole("listitem").filter({ hasText: product });
    await expect(line.getByRole("alert")).toContainText(fixture.ingredient.name_en, { timeout: 30_000 });
    await expect(line.getByRole("textbox", { name: `Quantity of ${product}` })).toHaveValue("2");
    await line.getByRole("button", { name: `Decrease quantity of ${product}` }).click();
    await expect(line.getByRole("textbox", { name: `Quantity of ${product}` })).toHaveValue("1");
    await page.getByRole("button", { name: "Send order" }).click();
    await expect(page.getByRole("status").filter({ hasText: "Order sent" })).toBeVisible({ timeout: 30_000 });

    // the same order, sent with the one portion left; nothing is waiting and the tray is empty
    await expect(tray(page)).toHaveCount(0);
    await expect.poll(async () => (await queuedWrites(page)).length).toBe(0);
    const orders = await ordersWithUuid(fixture.waiter.api, clientUuid);
    expect(orders).toHaveLength(1);
    expect(orders[0].status).toBe("sent");
    expect(orders[0].lines).toHaveLength(1);
  });

  test("a kitchen display offline for a minute shows the tickets sent meanwhile once it is back", async ({ page, context }) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    const fixture = await buildStationFixture(credentials, "kitchen");
    const { api: chef } = await loginOnce(fixture.worker.phone, fixture.worker.password, fixture.branchId);
    await openAs(page, chef, "en/kds/kitchen/");
    await expect(page.getByRole("heading", { level: 1, name: "Kitchen" })).toBeVisible({ timeout: 30_000 });

    const tickets = page.getByRole("article").filter({ hasText: fixture.product.name_en });
    await sendOrder(fixture);
    await expect(tickets).toHaveCount(1, { timeout: TICKET_VISIBLE_MS * 3 });

    // a minute offline, two orders sent in it: the rail cannot know
    await context.setOffline(true);
    expect((await sendOrder(fixture)).status).toBe("sent");
    await page.waitForTimeout(30_000);
    expect((await sendOrder(fixture)).status).toBe("sent");
    await page.waitForTimeout(30_000);
    await expect(tickets).toHaveCount(1);

    // back online: the changes since its cursor come in, and both tickets are on the rail
    await context.setOffline(false);
    await expect(tickets).toHaveCount(3, { timeout: TICKET_VISIBLE_MS * 3 });
  });

  test("two tablets offline at once replay their orders without duplicates", async ({ page, context, browser }, testInfo) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    const fixture = await buildStationFixture(credentials, "kitchen");

    // tablet A: the run's waiter device; tablet B: a device of this flow's own, the same waiter unlocked on both
    await openPadAndUnlock(page, fixture.waiter);
    const contextB = await browser.newContext({ baseURL: testInfo.project.use.baseURL, viewport: testInfo.project.use.viewport, serviceWorkers: "allow" });
    try {
      const pageB = await contextB.newPage();
      await openPad(pageB, fixture.waiter, "en/pad/", { device: await freshDevice(fixture.admin, "second-pad") });
      await expect(pageB.getByRole("heading", { name: "Enter your short code" })).toBeVisible({ timeout: 30_000 });
      await typeCode(pageB, fixture.waiter.shortCode);
      await expect(pageB.getByText(`Acting: ${fixture.waiter.name}`)).toBeVisible({ timeout: 30_000 });

      // both offline; each sends an order of the same table, which waits on its tablet
      await Promise.all([context.setOffline(true), contextB.setOffline(true)]);
      for (const tablet of [page, pageB]) {
        await cartWith(tablet, fixture, 1);
        await tablet.getByRole("button", { name: "Send order" }).click();
        await expect(tablet.getByRole("status").filter({ hasText: "1 change waiting to send" })).toBeVisible();
        await expect.poll(async () => (await queuedWrites(tablet)).length).toBe(1);
      }
      const uuids = [...(await queuedWrites(page)), ...(await queuedWrites(pageB))].map((w) => w.subjectUuid!);
      expect(new Set(uuids).size).toBe(2);

      // both back at the same moment, and tablet B's connection flaps once more: every order reaches the API once
      await Promise.all([context.setOffline(false), contextB.setOffline(false)]);
      await contextB.setOffline(true);
      await contextB.setOffline(false);
      for (const tablet of [page, pageB]) await expect.poll(async () => (await queuedWrites(tablet)).length, { timeout: 90_000 }).toBe(0);
      for (const clientUuid of uuids) {
        const orders = await ordersWithUuid(fixture.waiter.api, clientUuid);
        expect(orders, clientUuid).toHaveLength(1);
        expect(orders[0].status).toBe("sent");
        expect(orders[0].lines).toHaveLength(1);
      }
      await expect(tray(page)).toHaveCount(0);
      await expect(tray(pageB)).toHaveCount(0);
    } finally {
      await contextB.close();
    }
  });
});
