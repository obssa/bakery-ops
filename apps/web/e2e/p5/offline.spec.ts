import { expect, test, type Page } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildStationFixture, clearStaleOrders, orderLabel, type StationFixture } from "../support/fixture";
import { ordersWithUuid, photosOnTablet, queuedWrites } from "../support/outbox";
import { chooseTable, openAs, openPadAndUnlock } from "../support/pad";
import { buildPayFixture, paymentsOf, removeChannel, serveAll, takeawayOrder, tidyOrder } from "../support/payments";
import { fetchBytes, jpegSize, receiptPhoto } from "../support/photo";

// Phase 5 gate project p5-offline (docs/phases/phase-5-plan.md, steps 5.2a to 5.2c and section 7), step 5.2a: the outbox in IndexedDB.
// - An order sent while the tablet is offline waits in the outbox, survives a reload, and reaches the API exactly once when the tablet is
//   online again.
// - A send whose answer is lost after the server committed it is sent again with the same Idempotency-Key, and the API still holds one
//   order (the key's stored answer is replayed).
// The tablet is the pad of the run's waiter (its enrolled device, step 5.1b).
// Step 5.2b, the photo queue: a bill paid offline on a channel that needs a receipt photo. The photo is shrunk and kept in IndexedDB, the
// payment waits on its upload, both survive a reload, and back online the photo goes up first and the payment follows with its file id:
// one upload, one payment, the photo readable through its signed URL. The tablet is the till of the run's cashier (its enrolled device).
//
// CI serves the app with `next start`, which has no shell cache (sw.js is built only for the static export the live host serves), so a
// reload with the whole browser offline has no page to show. The reload here happens with the API unreachable instead (every API call
// aborted as if the connection were gone, the page itself served): what it proves is that the queue lives in IndexedDB, not in memory.

const API_CALLS = "**/api/v1/**";

async function cartWithProduct(page: Page, fixture: StationFixture) {
  await openPadAndUnlock(page, fixture.waiter);
  await chooseTable(page, fixture.table.code);
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
    await expect.poll(async () => (await queuedWrites(page)).length).toBe(0);
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
    await expect.poll(async () => (await queuedWrites(page)).length).toBe(0);
  });
});

test.describe("p5 offline: the photo queue", () => {
  test("a bill paid offline on a channel that needs a photo: the photo goes up first when back online, then the payment, once each", async ({ page, context }) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildPayFixture(credentials, "125.00");
    const { wallet } = fixture.channels;
    let orderId: number | null = null;
    // every answer to an upload the page made (none can reach the server while the tablet is offline)
    const uploads: number[] = [];
    page.on("response", (response) => {
      if (response.url().endsWith("/api/v1/files") && response.request().method() === "POST") uploads.push(response.status());
    });
    try {
      const sent = await takeawayOrder(fixture, "2");
      orderId = sent.id;
      await serveAll(fixture, sent.id);
      const label = orderLabel(sent);

      await openAs(page, fixture.cashier.api, "en/till/");
      await expect(page.getByRole("heading", { level: 1, name: "Cashier till" })).toBeVisible({ timeout: 30_000 });
      const take = page.getByRole("region", { name: "Orders awaiting payment" }).getByRole("button", { name: `Take payment for order ${label}`, exact: true });
      await expect(take).toBeEnabled({ timeout: 30_000 });
      await take.click();
      const sheet = page.getByRole("dialog", { name: `Payment for order ${label}` });

      // the whole bill on the wallet, which asks for a reference and a receipt photo
      const row = sheet.getByRole("group", { name: "Payment 1" });
      await row.getByRole("button", { name: wallet.name_en, exact: true }).click();
      await expect(row.getByLabel("Amount", { exact: true })).toHaveValue("250.00");
      await row.getByLabel("Reference number").fill("TX123456");
      const complete = sheet.getByRole("button", { name: "Complete payment" });
      await expect(sheet.getByRole("status", { name: "Why the payment cannot be completed yet" })).toContainText("Payment 1: take the photo of the receipt");

      // the connection goes; the photo is shrunk and kept on the tablet, and Complete does not wait for an upload
      const photo = await receiptPhoto(page);
      await context.setOffline(true);
      await row.getByLabel("Receipt photo file").setInputFiles(photo);
      await expect(row).toContainText("Photo saved on this tablet", { timeout: 15_000 });
      await expect(row.getByRole("img", { name: "Receipt photo of payment 1" })).toBeVisible();
      await expect(complete).toBeEnabled();
      await complete.click();
      await expect(sheet.getByRole("status").filter({ hasText: "Saved on this tablet. It will be sent when the connection is back." })).toBeVisible({
        timeout: 30_000,
      });

      // IndexedDB holds the shrunk photo and two writes: its upload, then the payment that waits for its file id
      await expect.poll(async () => (await queuedWrites(page)).map((w) => w.kind)).toEqual(["upload_photos", "pay_batch"]);
      const kept = await photosOnTablet(page);
      expect(kept).toHaveLength(1);
      expect(kept[0]).toMatchObject({ fileId: null, type: "image/jpeg" });
      expect(kept[0].size).toBeLessThan(photo.buffer.length);
      const [upload, batch] = await queuedWrites(page);
      expect(upload.body).toEqual({ photos: [kept[0].clientUuid] });
      const queuedRows = batch.body.rows as { receipt_file_id: unknown; reference_no: string }[];
      expect(queuedRows).toHaveLength(1);
      expect(queuedRows[0]).toMatchObject({ receipt_file_id: `{{photo:${kept[0].clientUuid}}}`, reference_no: "TX123456" });
      await sheet.getByRole("button", { name: "Done" }).click();

      // the tablet reloads while the API cannot be reached: the photo and both writes are still there, and nothing reached the server
      await context.route(API_CALLS, (route) => route.abort("internetdisconnected"));
      await context.setOffline(false);
      await page.reload();
      await expect(page.getByRole("status").filter({ hasText: "2 changes waiting to send" })).toBeVisible({ timeout: 30_000 });
      expect((await queuedWrites(page)).map((w) => w.idempotencyKey)).toEqual([upload.idempotencyKey, batch.idempotencyKey]);
      expect((await photosOnTablet(page)).map((p) => p.clientUuid)).toEqual([kept[0].clientUuid]);
      expect((await paymentsOf(fixture.cashier.api, sent.id)).data).toHaveLength(0);
      expect(uploads).toEqual([]);

      // the connection comes back (the browser's online event): the photo goes up first, then the payment with its file id
      await context.unroute(API_CALLS);
      await context.setOffline(true);
      await context.setOffline(false);
      await expect.poll(async () => (await paymentsOf(fixture.cashier.api, sent.id)).data.length, { timeout: 90_000 }).toBe(1);
      await expect.poll(async () => (await queuedWrites(page)).length, { timeout: 30_000 }).toBe(0);
      await expect.poll(async () => (await photosOnTablet(page)).length, { timeout: 30_000 }).toBe(0);
      // one upload reached the server, and one payment: closed, with that photo
      expect(uploads).toEqual([201]);
      const { data: payments, balance } = await paymentsOf(fixture.cashier.api, sent.id);
      expect(balance).toBe("0.00");
      expect(payments).toHaveLength(1);
      expect(payments[0]).toMatchObject({ channel: { code: wallet.code }, amount: "250.00", reference_no: "TX123456", status: "recorded" });
      expect(payments[0].receipt?.file_id).toEqual(expect.any(Number));
      await expect.poll(async () => (await fixture.cashier.api.get<{ status: string }>(`/orders/${sent.id}`)).status, { timeout: 15_000 }).toBe("closed");

      // the photo is readable through its signed URL, shrunk to 1600 px as the browser sent it
      const stored = await fetchBytes(payments[0].receipt?.url ?? "");
      expect(stored.status).toBe(200);
      expect(jpegSize(stored.bytes)).toEqual({ width: 1600, height: 1200 });
    } finally {
      await context.setOffline(false);
      await context.unroute(API_CALLS);
      if (orderId !== null) await tidyOrder(fixture, orderId);
      await removeChannel(fixture.admin, fixture.channels.wallet);
      await removeChannel(fixture.admin, fixture.channels.bank);
    }
  });
});
