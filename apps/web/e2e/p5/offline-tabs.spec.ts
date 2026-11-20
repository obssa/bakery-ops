import { expect, test, type Page } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildStationFixture, clearStaleOrders, type StationFixture } from "../support/fixture";
import { ordersWithUuid, queuedWrites } from "../support/outbox";
import { chooseTable, openPadAndUnlock } from "../support/pad";

// ADR 0029, gate project p5-offline: the tabs of one tablet share one outbox.
// - Two tabs of the same tablet (the pad open twice, one browser context: one IndexedDB, one device id), both offline, each send an order.
//   Both writes are in the store (before ADR 0029 the second tab's write cleared the store and put only its own); a reload of one tab
//   with the API unreachable shows both waiting; back online, every order reaches the API exactly once, with the two tabs online together.
// - A newer page upgrading the database (another tab after a deploy) closes this tab's connection: a write queued after it is still kept
//   on the tablet (before, the write failed in silence and lived in the tab's memory only).
// CI serves the app with `next start` (no shell cache), so the reload happens with the API unreachable, as in offline.spec.ts.

const API_CALLS = "**/api/v1/**";

async function cartWithProduct(page: Page, fixture: StationFixture) {
  await chooseTable(page, fixture.table.code);
  await page.getByRole("tab", { name: "Other" }).click();
  await page.getByRole("button", { name: `Add ${fixture.product.name_en}` }).click();
  await expect(page.getByRole("region", { name: "Cart" }).getByRole("listitem").filter({ hasText: fixture.product.name_en })).toBeVisible();
}

test.describe("p5 offline: two tabs of one tablet", () => {
  test("two tabs offline each queue an order: both are kept on the tablet, and back online each reaches the API once", async ({ page, context }) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    const fixture = await buildStationFixture(credentials, "kitchen");

    const second = await context.newPage();
    await openPadAndUnlock(page, fixture.waiter);
    await openPadAndUnlock(second, fixture.waiter);

    await context.setOffline(true);
    for (const tab of [page, second]) {
      await cartWithProduct(tab, fixture);
      await tab.getByRole("button", { name: "Send order" }).click();
      await expect(tab.getByRole("status").filter({ hasText: /waiting to send/ })).toBeVisible();
    }
    // the store holds both writes: the second tab's write did not erase the first's
    await expect.poll(async () => (await queuedWrites(page)).length, { timeout: 15_000 }).toBe(2);
    const queued = await queuedWrites(second);
    expect(queued.map((w) => w.kind)).toEqual(["send_order", "send_order"]);
    const uuids = queued.map((w) => w.subjectUuid!);
    expect(new Set(uuids).size).toBe(2);
    // each tab has taken in the other's write
    for (const tab of [page, second]) {
      await expect(tab.getByRole("status").filter({ hasText: "2 changes waiting to send" })).toBeVisible({ timeout: 15_000 });
    }

    // the first tab reloads while the API cannot be reached: both writes are still there, nothing reached the server
    await context.route(API_CALLS, (route) => route.abort("internetdisconnected"));
    await context.setOffline(false);
    await page.reload();
    await expect(page.getByRole("status").filter({ hasText: "2 changes waiting to send" })).toBeVisible({ timeout: 30_000 });
    expect((await queuedWrites(page)).map((w) => w.subjectUuid)).toEqual(uuids);
    for (const clientUuid of uuids) expect(await ordersWithUuid(fixture.waiter.api, clientUuid)).toHaveLength(0);

    // back online, both tabs at once: one tab sends at a time, and each order reaches the API once
    await context.unroute(API_CALLS);
    await context.setOffline(true);
    await context.setOffline(false);
    for (const clientUuid of uuids) {
      await expect.poll(async () => (await ordersWithUuid(fixture.waiter.api, clientUuid)).length, { timeout: 90_000 }).toBe(1);
    }
    await expect.poll(async () => (await queuedWrites(page)).length, { timeout: 30_000 }).toBe(0);
    for (const tab of [page, second]) {
      await expect(tab.getByRole("status").filter({ hasText: "waiting to send" })).toHaveCount(0, { timeout: 30_000 });
    }
    for (const clientUuid of uuids) {
      const [order] = await ordersWithUuid(fixture.waiter.api, clientUuid);
      expect(order.status).toBe("sent");
      expect(order.lines).toHaveLength(1);
    }
  });

  test("a newer page upgrading the database does not leave this tab's next write unsaved", async ({ page, context }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    const fixture = await buildStationFixture(credentials, "kitchen");
    await openPadAndUnlock(page, fixture.waiter);

    // another tab, after a deploy, opens the database at a newer version: this tab's connection is asked to close, and closes
    const upgraded = await page.evaluate(
      () =>
        new Promise<number>((resolve, reject) => {
          const probe = indexedDB.open("bakery-ops");
          probe.onerror = () => reject(probe.error);
          probe.onsuccess = () => {
            const version = probe.result.version + 1;
            probe.result.close();
            const open = indexedDB.open("bakery-ops", version);
            open.onerror = () => reject(open.error);
            open.onblocked = () => reject(new Error("blocked: the page kept its connection"));
            open.onsuccess = () => {
              open.result.close();
              resolve(version);
            };
          };
        }),
    );
    expect(upgraded).toBeGreaterThan(2);

    await cartWithProduct(page, fixture);
    await context.setOffline(true);
    await page.getByRole("button", { name: "Send order" }).click();
    await expect(page.getByRole("status").filter({ hasText: "1 change waiting to send" })).toBeVisible();
    // the write is in IndexedDB: the page opened the database again
    await expect.poll(async () => (await queuedWrites(page)).length, { timeout: 15_000 }).toBe(1);
    const [queued] = await queuedWrites(page);
    await expect(page.getByRole("alert").filter({ hasText: "kept only while this page is open" })).toHaveCount(0);

    await context.setOffline(false);
    await expect.poll(async () => (await ordersWithUuid(fixture.waiter.api, queued.subjectUuid!)).length, { timeout: 60_000 }).toBe(1);
    await expect.poll(async () => (await queuedWrites(page)).length, { timeout: 30_000 }).toBe(0);
  });
});
