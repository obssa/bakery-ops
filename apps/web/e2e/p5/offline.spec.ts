import { expect, test, type Page } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildStationFixture, clearStaleOrders, type StationFixture } from "../support/fixture";
import { ordersWithUuid, queuedWrites } from "../support/outbox";
import { openPadAndUnlock } from "../support/pad";

// Phase 5 gate project p5-offline (docs/phases/phase-5-plan.md, steps 5.2a to 5.2c and section 7), step 5.2a: the outbox in IndexedDB.
// - An order sent while the tablet is offline waits in the outbox, survives a reload, and reaches the API exactly once when the tablet is
//   online again.
// - A send whose answer is lost after the server committed it is sent again with the same Idempotency-Key, and the API still holds one
//   order (the key's stored answer is replayed).
// The tablet is the pad of the run's waiter (its enrolled device, step 5.1b).
//
// CI serves the app with `next start`, which has no shell cache (sw.js is built only for the static export the live host serves), so a
// reload with the whole browser offline has no page to show. The reload here happens with the API unreachable instead (every API call
// aborted as if the connection were gone, the page itself served): what it proves is that the queue lives in IndexedDB, not in memory.

const API_CALLS = "**/api/v1/**";

async function cartWithProduct(page: Page, fixture: StationFixture) {
  await openPadAndUnlock(page, fixture.waiter);
  await page.getByRole("button", { name: `Table ${fixture.table.code}` }).click();
  await page.getByRole("tab", { name: "Other" }).click();
  await page.getByRole("button", { name: `Add ${fixture.product.name_en}` }).click();
  await expect(page.getByRole("region", { name: "Cart" }).getByRole("listitem").filter({ hasText: fixture.product.name_en })).toBeVisible();
}

test.describe("p5 offline: the outbox", () => {
  test("an order sent offline waits through a reload and reaches the API exactly once when the tablet is back online", async ({ page, context }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    const fixture = await buildStationFixture(credentials, "kitchen");
    await cartWithProduct(page, fixture);

    await context.setOffline(true);
    await page.getByRole("button", { name: "Send order" }).click();
    const waiting = page.getByRole("status").filter({ hasText: "1 change waiting to send" });
    await expect(waiting).toBeVisible();
    // the record is written to IndexedDB right after the strip shows it
    await expect.poll(async () => (await queuedWrites(page)).length).toBe(1);
    const queued = await queuedWrites(page);
    expect(queued).toHaveLength(1);
    expect(queued[0]).toMatchObject({ kind: "send_order", path: "/orders", state: "pending" });
    const clientUuid = queued[0].subjectUuid!;
    expect(clientUuid).toBe(queued[0].body.client_uuid);

    // the tablet reloads while the API cannot be reached: the write is still there, and nothing reached the server
    await context.route(API_CALLS, (route) => route.abort("internetdisconnected"));
    await context.setOffline(false);
    await page.reload();
    await expect(waiting).toBeVisible({ timeout: 30_000 });
    expect((await queuedWrites(page)).map((w) => w.idempotencyKey)).toEqual([queued[0].idempotencyKey]);
    expect(await ordersWithUuid(fixture.waiter.api, clientUuid)).toHaveLength(0);

    // the connection comes back (the browser's online event): the write goes, once
    await context.unroute(API_CALLS);
    await context.setOffline(true);
    await context.setOffline(false);
    await expect.poll(async () => (await ordersWithUuid(fixture.waiter.api, clientUuid)).length, { timeout: 60_000 }).toBe(1);
    await expect(waiting).toHaveCount(0, { timeout: 30_000 });
    expect(await queuedWrites(page)).toEqual([]);
    const [order] = await ordersWithUuid(fixture.waiter.api, clientUuid);
    expect(order.status).toBe("sent");
    expect(order.lines).toHaveLength(1);
  });

  test("a send whose answer is lost after the server committed it is sent again with its key and still makes one order", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    const fixture = await buildStationFixture(credentials, "kitchen");
    await cartWithProduct(page, fixture);

    const sends: { key: string | null; clientUuid: string }[] = [];
    let dropped = false;
    await page.route("**/api/v1/orders", async (route) => {
      const request = route.request();
      if (request.method() !== "POST") return route.fallback();
      sends.push({ key: request.headers()["idempotency-key"] ?? null, clientUuid: (request.postDataJSON() as { client_uuid: string }).client_uuid });
      if (dropped) return route.fallback();
      dropped = true;
      // the server takes the order and answers; the answer never reaches the tablet
      const answer = await route.fetch();
      expect(answer.status()).toBe(201);
      await route.abort("connectionreset");
    });

    await page.getByRole("button", { name: "Send order" }).click();
    await expect(page.getByRole("status").filter({ hasText: "Order sent" })).toBeVisible({ timeout: 30_000 });

    expect(sends.length).toBe(2);
    expect(sends[0].key).toBeTruthy();
    expect(sends[1]).toEqual(sends[0]);
    const orders = await ordersWithUuid(fixture.waiter.api, sends[0].clientUuid);
    expect(orders).toHaveLength(1);
    expect(orders[0].lines).toHaveLength(1);
    expect(await queuedWrites(page)).toEqual([]);
  });
});
