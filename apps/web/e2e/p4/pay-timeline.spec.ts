import { expect, test, type Locator, type Request } from "@playwright/test";
import { ApiFailure } from "../support/api";
import { requireAdmin, run } from "../support/env";
import { orderLabel } from "../support/fixture";
import { openAs } from "../support/pad";
import { buildPayFixture, payBatch, removeChannel, takeawayOrder, tidyOrder, uploadReceipt } from "../support/payments";
import { canvasJpeg, runTint } from "../support/photo";

// Phase 4 project p4-pay-photo-split (the name matches /pay/), step 4.5d: the order record page (/admin/orders/record?id=), for the payment
// checkers (payments.verify: the admin and the manager).
//
// 1. @live: the cashier is paid 250.00 for a run-unique order through the API, split into cash (70.00, 100.00 handed over) and the run's
//    wallet (180.00, a photo drawn in a colour of this run's own and a reference); the order is not served yet, so it waits on the board
//    settled. The admin opens it from the orders board ("Open the full record"): the header's money, the timeline in the order the API
//    wrote it (created, sent, the two payments by the cashier, settled), the payments with the reference's last four characters and the
//    photo, which opens from a fresh signed address and loads; the receipt downloads; the order's "Audit history" opens the audit log
//    filtered to it.
// 2. @live: on a second order (cash 50.00 and the run's bank transfer 70.00), the admin voids the transfer from the record page: a wrong PIN
//    is refused with the API's message and the next try carries a new Idempotency-Key, the manager's PIN approves it, and the timeline's
//    void names the manager as its approver; the order is owed 70.00 again. A cashier has no "Open the full record" on the board and
//    reads that the record page is not theirs.
//
// Fixtures through the admin API of the isolated "E2E Test Branch" (e2e/support/payments.ts), run-unique names; nothing is counted.

/** The labels of the timeline's entries, in the order the page shows them. */
async function timelineLabels(timeline: Locator): Promise<string[]> {
  return timeline.getByRole("listitem").allInnerTexts();
}

/** The position of the first entry after `from` whose text holds every piece, or -1. */
function indexOf(labels: string[], pieces: string[], from = 0): number {
  for (let i = from; i < labels.length; i += 1) if (pieces.every((piece) => labels[i].includes(piece))) return i;
  return -1;
}

test.describe("p4 order record", () => {
  test("a split bill's record opens from the board: its money, its timeline in order, the photo from a fresh address, the receipt and the order's audit history @live", async ({ page }) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildPayFixture(credentials, "125.00");
    const { wallet } = fixture.channels;
    let orderId: number | null = null;
    try {
      const sent = await takeawayOrder(fixture, "2");
      orderId = sent.id;
      expect(sent.totals.total).toBe("250.00");
      const label = orderLabel(sent);
      await page.goto("en/login/");
      const photo = await uploadReceipt(fixture.cashier.api, await canvasJpeg(page, 1200, 900, 0.9, runTint(`${run}-record`)));
      const digits = String(parseInt(run.slice(-6), 36) % 1_000_000).padStart(6, "0");
      const reference = `TX${digits}`;
      const [cashPayment, walletPayment] = await payBatch(fixture, sent.id, [
        { channel_code: "cash", amount: "70.00", tendered_amount: "100.00" },
        { channel_code: wallet.code, amount: "180.00", reference_no: reference, receipt_file_id: photo.id },
      ]);
      expect(walletPayment.receipt?.file_id).toBe(photo.id);

      // the board: the order waits settled; its timeline panel links to the full record
      await openAs(page, fixture.admin, "en/admin/orders/");
      const open = page.getByRole("button", { name: `Open order ${label}`, exact: true });
      await expect(open).toBeVisible({ timeout: 30_000 });
      await open.click();
      const panel = page.getByRole("region", { name: `Order ${label} timeline` });
      await panel.getByRole("link", { name: "Open the full record" }).click();
      await expect(page).toHaveURL(new RegExp(`/admin/orders/record/?\\?id=${sent.id}$`));
      await expect(page.getByRole("heading", { level: 1, name: `Order ${label}` })).toBeVisible({ timeout: 30_000 });

      // the header's money, as the API wrote it
      const facts = page.getByRole("region", { name: "Order details" });
      await expect(facts).toContainText("250.00 ETB");
      await expect(facts.getByRole("status", { name: "Balance" })).toContainText("0.00 ETB");
      await expect(facts).toContainText("Paid in full");
      await expect(facts).toContainText("Takeaway");

      // the timeline: oldest first, merged with the payments, each entry naming who did it
      const timeline = page.getByRole("list", { name: `Timeline of order ${label}` });
      await expect(timeline.getByRole("listitem").filter({ hasText: "Order settled" })).toBeVisible({ timeout: 30_000 });
      const labels = await timelineLabels(timeline);
      const created = indexOf(labels, ["Order created"]);
      const orderSent = indexOf(labels, ["Order sent"], created + 1);
      const cash = indexOf(labels, ["Payment recorded", "Cash", "70.00", `By ${fixture.cashier.name}`], orderSent + 1);
      const digital = indexOf(labels, ["Payment recorded", wallet.name_en, "180.00", `By ${fixture.cashier.name}`], cash + 1);
      const settled = indexOf(labels, ["Order settled"], digital + 1);
      expect([created, orderSent, cash, digital, settled].every((i) => i >= 0), labels.join("\n")).toBe(true);
      // the API's own order of the same events
      const detail = await fixture.admin.get<{ events: { event: string }[] }>(`/orders/${sent.id}`);
      const written = detail.events.map((e) => e.event);
      expect(written.indexOf("order.created")).toBeLessThan(written.indexOf("order.sent"));
      expect(written.indexOf("payment.recorded")).toBeLessThan(written.indexOf("order.settled"));

      // the payments: channel, amount, the reference's tail only, who took it, the photo
      const payments = page.getByRole("region", { name: "Payments" });
      const walletCard = payments.getByRole("article", { name: `Payment ${walletPayment.id}` });
      await expect(walletCard).toContainText(wallet.name_en);
      await expect(walletCard).toContainText("180.00 ETB");
      await expect(walletCard).toContainText(`…${reference.slice(-4)}`);
      await expect(walletCard).not.toContainText(reference);
      await expect(walletCard).toContainText(fixture.cashier.name);
      await expect(walletCard).toContainText("Recorded");
      await expect(walletCard.getByRole("link", { name: `Open payment ${walletPayment.id}` })).toHaveAttribute("href", new RegExp(`/admin/payments/?\\?payment=${walletPayment.id}$`));
      const cashCard = payments.getByRole("article", { name: `Payment ${cashPayment.id}` });
      await expect(cashCard).toContainText("No photo");
      await expect(cashCard.getByRole("link", { name: `Audit history of payment ${cashPayment.id}` })).toBeVisible();

      // the photo: the viewer asks for a fresh signed address, and the picture loads
      const signed = page.waitForResponse((r) => r.url().includes(`/api/v1/files/${photo.id}/url`) && r.request().method() === "GET");
      await walletCard.getByRole("button", { name: `Receipt photo of payment ${walletPayment.id}` }).click();
      expect((await signed).status()).toBe(200);
      const viewer = page.getByRole("dialog", { name: `Receipt photo of payment ${walletPayment.id}` });
      const picture = viewer.getByRole("img", { name: `Receipt photo of payment ${walletPayment.id}` });
      await expect(picture).toBeVisible();
      await expect.poll(() => picture.evaluate((img) => (img as HTMLImageElement).complete && (img as HTMLImageElement).naturalWidth)).toBe(1200);
      await viewer.getByRole("button", { name: "Close" }).click();
      await expect(viewer).toHaveCount(0);

      // the receipt of an order paid in full
      const receipt = page.getByRole("region", { name: "Receipt" });
      const [download] = await Promise.all([page.waitForEvent("download"), receipt.getByRole("button", { name: "Download receipt (PDF)" }).click()]);
      expect(download.suggestedFilename()).toMatch(/^receipt-.*\.pdf$/);
      await expect(receipt.getByRole("button", { name: "Copy receipt link" })).toBeVisible();
      // no refund yet (step 4.3d): its place says so
      await expect(page.getByRole("region", { name: "Refund" })).toContainText("Refunds are not available yet");

      // the order's audit history: the audit log filtered to this order
      await page.getByRole("link", { name: "Audit history", exact: true }).click();
      await expect(page.getByRole("heading", { level: 1, name: "Audit log" })).toBeVisible({ timeout: 30_000 });
      await expect(page).toHaveURL(new RegExp(`subject_id=${sent.id}`));
      const log = page.getByRole("table", { name: "Audit log", exact: true });
      await expect(log.getByRole("row").filter({ hasText: "order.create" })).toHaveCount(1, { timeout: 30_000 });
    } catch (error) {
      if (error instanceof ApiFailure) console.error(error.message);
      throw error;
    } finally {
      if (orderId !== null) await tidyOrder(fixture, orderId);
      await removeChannel(fixture.admin, fixture.channels.wallet);
      await removeChannel(fixture.admin, fixture.channels.bank);
    }
  });

  test("a payment voided from the record page with the manager's PIN names its approver; a cashier cannot open the record @live", async ({ page, browser }) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildPayFixture(credentials, "60.00");
    const { bank } = fixture.channels;
    let orderId: number | null = null;
    try {
      const sent = await takeawayOrder(fixture, "2");
      orderId = sent.id;
      expect(sent.totals.total).toBe("120.00");
      const label = orderLabel(sent);
      const [, transfer] = await payBatch(fixture, sent.id, [
        { channel_code: "cash", amount: "50.00", tendered_amount: "50.00" },
        { channel_code: bank.code, amount: "70.00", reference_no: `REC-${run}`.toUpperCase() },
      ]);

      await openAs(page, fixture.admin, `en/admin/orders/record/?id=${sent.id}`);
      await expect(page.getByRole("heading", { level: 1, name: `Order ${label}` })).toBeVisible({ timeout: 30_000 });
      const payments = page.getByRole("region", { name: "Payments" });
      const card = payments.getByRole("article", { name: `Payment ${transfer.id}` });
      await expect(card).toContainText("70.00 ETB", { timeout: 30_000 });

      const keys: string[] = [];
      const onRequest = (request: Request) => {
        if (request.method() === "POST" && request.url().includes(`/api/v1/payments/${transfer.id}/void`)) keys.push(request.headers()["idempotency-key"] ?? "");
      };
      page.on("request", onRequest);
      await card.getByRole("button", { name: `Void payment ${transfer.id}` }).click();
      const dialog = page.getByRole("dialog", { name: `Void a payment of order ${label}` });
      await expect(dialog.getByRole("radio", { name: new RegExp(`${bank.name_en} 70\\.00 ETB`) })).toBeChecked({ timeout: 15_000 });
      const reason = `E2E record ${run}: the transfer was for another order`;
      await dialog.getByLabel("Reason").fill(reason);
      // a PIN that is nobody's is refused with the API's message; the next try is a new request (a new key)
      await dialog.getByLabel("Manager PIN").fill("000000");
      await dialog.getByRole("button", { name: "Void this payment" }).click();
      await expect(dialog.getByRole("alert")).toContainText("That PIN does not belong to someone allowed to approve this.", { timeout: 30_000 });
      await dialog.getByLabel("Manager PIN").fill(fixture.manager.pin);
      await dialog.getByRole("button", { name: "Void this payment" }).click();
      await expect(dialog).toHaveCount(0, { timeout: 30_000 });
      page.off("request", onRequest);
      expect(keys).toHaveLength(2);
      expect(keys[0]).not.toBe("");
      expect(keys[1]).not.toBe(keys[0]);

      // the record reads again: the void names who asked and who approved, the payment is voided, 70.00 is owed again
      const timeline = page.getByRole("list", { name: `Timeline of order ${label}` });
      const voided = timeline.getByRole("listitem").filter({ hasText: "Payment voided" });
      await expect(voided).toHaveCount(1, { timeout: 30_000 });
      await expect(voided).toContainText(`Approved by ${fixture.manager.name}`);
      // the admin asked for it on this page (the asker is the account signed in here)
      const me = await fixture.admin.get<{ user: { name: string } }>("/me");
      await expect(voided).toContainText(`By ${me.user.name}`);
      await expect(voided).toContainText(reason);
      await expect(voided).toContainText(bank.name_en);
      await expect(card).toContainText("Voided");
      await expect(card.getByRole("button", { name: `Void payment ${transfer.id}` })).toHaveCount(0);
      await expect(page.getByRole("region", { name: "Order details" }).getByRole("status", { name: "Balance" })).toContainText("70.00 ETB");
      const after = await fixture.admin.get<{ data: { id: number; status: string }[]; balance: string }>(`/orders/${sent.id}/payments`);
      expect(after.balance).toBe("70.00");
      expect(after.data.find((p) => p.id === transfer.id)?.status).toBe("voided");

      // a cashier: no link to the record on the board, and the record page is not theirs
      const cashierContext = await browser.newContext();
      try {
        const cashierPage = await cashierContext.newPage();
        await openAs(cashierPage, fixture.cashier.api, "en/admin/orders/");
        const open = cashierPage.getByRole("button", { name: `Open order ${label}`, exact: true });
        await expect(open).toBeVisible({ timeout: 30_000 });
        await open.click();
        const panel = cashierPage.getByRole("region", { name: `Order ${label} timeline` });
        await expect(panel.getByText("Order created")).toBeVisible({ timeout: 30_000 });
        await expect(panel.getByRole("link", { name: "Open the full record" })).toHaveCount(0);
        await cashierPage.goto(`en/admin/orders/record/?id=${sent.id}`);
        await expect(cashierPage.getByRole("alert").filter({ hasText: "Your account cannot open this page." })).toBeVisible({ timeout: 30_000 });
        await expect(cashierPage.getByRole("list", { name: `Timeline of order ${label}` })).toHaveCount(0);
      } finally {
        await cashierContext.close();
      }
    } catch (error) {
      if (error instanceof ApiFailure) console.error(error.message);
      throw error;
    } finally {
      if (orderId !== null) await tidyOrder(fixture, orderId);
      await removeChannel(fixture.admin, fixture.channels.wallet);
      await removeChannel(fixture.admin, fixture.channels.bank);
    }
  });
});
