import { readFile } from "node:fs/promises";
import { expect, test, type Page } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { orderLabel } from "../support/fixture";
import { openAs } from "../support/pad";
import { buildPayFixture, paymentsOf, removeChannel, serveAll, takeawayOrder, tidyOrder, WALLET_HINT_EN, type PayFixture } from "../support/payments";
import { fetchBytes, hasExif, jpegSize, receiptPhoto, recordUploads, uploadsOf } from "../support/photo";

// Phase 4 gate project p4-pay-photo-split (docs/phases/phase-4-plan.md sections 7 and 10.3 item 2), step 4.5b: the till's full pay sheet.
//
// The cashier splits a bill of 250.00 over the run's wallet channel (a receipt photo and a reference with a pattern) and cash. Complete
// stays disabled while anything is missing, last of all the photo: the reference is checked as it is typed, the cash handed over shows its
// change, and only once the photo (shrunk in the browser, uploaded with its own client_uuid) is attached can the bill be completed, in one
// POST /orders/{order}/payment-batches. The order is closed, two payments of one batch exist, the photo is attached to the wallet's and
// the server holds it without EXIF at 1600 px (read back through the API). The receipt is then downloaded as a PDF and shared as a link.
//
// The fixture is built through the admin API inside the isolated "E2E Test Branch" (e2e/support/payments.ts): run-unique product and
// channels, the fixed cashier on the till with its shift running. So the flow carries @live.

async function openSheet(page: Page, fixture: PayFixture, label: string) {
  await recordUploads(page);
  await openAs(page, fixture.cashier.api, "en/till/");
  await expect(page.getByRole("heading", { level: 1, name: "Cashier till" })).toBeVisible({ timeout: 30_000 });
  const queue = page.getByRole("region", { name: "Orders awaiting payment" });
  const pay = queue.getByRole("button", { name: `Take payment for order ${label}`, exact: true });
  await expect(pay).toBeEnabled({ timeout: 30_000 });
  await pay.click();
  return page.getByRole("dialog", { name: `Payment for order ${label}` });
}

test.describe("p4 pay sheet: photo and split", () => {
  test("a wallet payment with a receipt photo plus the cash remainder settles the bill, and Complete stays disabled until the photo is attached @live", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildPayFixture(credentials, "125.00");
    const { wallet } = fixture.channels;
    let orderId: number | null = null;
    try {
      // two slices at 125.00, sent and served: paying it in full closes it
      const sent = await takeawayOrder(fixture, "2");
      orderId = sent.id;
      expect(sent.totals.total).toBe("250.00");
      await serveAll(fixture, sent.id);
      const label = orderLabel(sent);

      const sheet = await openSheet(page, fixture, label);
      await expect(sheet.getByRole("status", { name: "Amount due" })).toHaveText("250.00 ETB");
      await expect(sheet.getByRole("status", { name: "Left to pay" })).toHaveText("0.00 ETB");

      // the first payment is cash for the whole balance; it becomes the 70.00 remainder
      const first = sheet.getByRole("group", { name: "Payment 1" });
      await expect(first.getByRole("button", { name: "Cash", exact: true })).toHaveAttribute("aria-pressed", "true");
      await expect(first.getByLabel("Amount", { exact: true })).toHaveValue("250.00");
      await first.getByLabel("Amount", { exact: true }).fill("70.00");
      await expect(sheet.getByRole("status", { name: "Left to pay" })).toHaveText("180.00 ETB");

      // a second payment takes the rest, on the wallet
      await sheet.getByRole("button", { name: "Add a payment" }).click();
      const second = sheet.getByRole("group", { name: "Payment 2" });
      await expect(second.getByLabel("Amount", { exact: true })).toHaveValue("180.00");
      await expect(sheet.getByRole("status", { name: "Left to pay" })).toHaveText("0.00 ETB");
      await second.getByRole("button", { name: wallet.name_en, exact: true }).click();
      await expect(second.getByRole("button", { name: wallet.name_en, exact: true })).toHaveAttribute("aria-pressed", "true");

      const complete = sheet.getByRole("button", { name: "Complete payment" });
      await expect(complete).toBeDisabled();
      // cash: what was handed over, and the change
      await expect(sheet.getByRole("status", { name: "Why the payment cannot be completed yet" })).toContainText("Payment 1");
      for (const digit of "100") await first.getByRole("button", { name: digit, exact: true }).click();
      await expect(first).toContainText("Change due: 30.00 ETB");

      // the reference: the channel's hint, and the pattern checked as it is typed
      const reference = second.getByLabel("Reference number");
      await expect(second).toContainText(WALLET_HINT_EN);
      await expect(sheet.getByRole("status", { name: "Why the payment cannot be completed yet" })).toContainText("Payment 2: type the reference number");
      await reference.fill("tx12");
      await expect(reference).toHaveAttribute("aria-invalid", "true");
      await expect(complete).toBeDisabled();
      await reference.fill("TX123456");
      await expect(reference).not.toHaveAttribute("aria-invalid", "true");

      // everything is there but the photo: Complete is still disabled, and says why
      await expect(complete).toBeDisabled();
      await expect(sheet.getByRole("status", { name: "Why the payment cannot be completed yet" })).toContainText("Payment 2: take the photo of the receipt");

      // the photo: 2400 x 1800 with GPS in its EXIF, shrunk in the browser to 1600 x 1200 before it is uploaded
      const photo = await receiptPhoto(page);
      const upload = page.waitForResponse((r) => r.url().endsWith("/api/v1/files") && r.request().method() === "POST");
      await second.getByLabel("Receipt photo file").setInputFiles(photo);
      const uploaded = await upload;
      expect(uploaded.status()).toBe(201);
      const file = (await uploaded.json()) as { id: number; width: number; height: number; attached: boolean };
      expect(file).toMatchObject({ width: 1600, height: 1200, attached: false });
      // what left the browser was already the shrunk JPEG, without the EXIF block
      const sentFiles = await uploadsOf(page);
      expect(sentFiles).toHaveLength(1);
      expect(sentFiles[0].type).toBe("image/jpeg");
      expect(jpegSize(sentFiles[0].bytes)).toEqual({ width: 1600, height: 1200 });
      expect(hasExif(sentFiles[0].bytes)).toBe(false);

      await expect(second.getByRole("img", { name: "Receipt photo of payment 2" })).toBeVisible();
      await expect(second.getByRole("button", { name: "Replace photo" })).toBeVisible();
      await expect(complete).toBeEnabled();

      // one action records both
      const batch = page.waitForResponse((r) => r.url().endsWith(`/orders/${sent.id}/payment-batches`) && r.request().method() === "POST");
      await complete.click();
      expect((await batch).status()).toBe(201);
      await expect(sheet.getByRole("status").filter({ hasText: "Payment recorded" })).toBeVisible({ timeout: 30_000 });
      await expect(sheet).toContainText("Give change: 30.00 ETB");

      // the receipt: a preview, the PDF, and a link to share
      const preview = sheet.getByRole("region", { name: "Receipt preview" });
      await expect(preview).toContainText(`Order ${label}`);
      await expect(preview).toContainText(fixture.product.name_en);
      await expect(preview).toContainText("250.00");
      await expect(preview).toContainText(wallet.name_en);
      await expect(preview).toContainText("3456");
      const download = page.waitForEvent("download");
      await sheet.getByRole("button", { name: "Download receipt (PDF)" }).click();
      const pdf = await (await download).path();
      expect((await readFile(pdf)).subarray(0, 5).toString("latin1")).toBe("%PDF-");
      await sheet.getByRole("button", { name: "Copy receipt link" }).click();
      const link = sheet.getByLabel("Receipt link");
      await expect(link).toHaveValue(/^https?:\/\/.+\/receipts\//, { timeout: 30_000 });
      const shared = await fetchBytes(await link.inputValue());
      expect(shared.status).toBe(200);
      expect(shared.type).toContain("application/pdf");

      await sheet.getByRole("button", { name: "Done" }).click();
      await expect(page.getByRole("button", { name: `Take payment for order ${label}`, exact: true })).toHaveCount(0, { timeout: 15_000 });

      // through the API: closed, two payments of one batch, the photo on the wallet's and stored without EXIF at 1600 px
      await expect.poll(async () => (await fixture.cashier.api.get<{ status: string }>(`/orders/${sent.id}`)).status, { timeout: 15_000 }).toBe("closed");
      const { data: payments, balance } = await paymentsOf(fixture.cashier.api, sent.id);
      expect(balance).toBe("0.00");
      expect(payments).toHaveLength(2);
      const [cash, digital] = payments;
      expect(cash).toMatchObject({ channel: { code: "cash" }, amount: "70.00", tendered_amount: "100.00", change_amount: "30.00", status: "recorded", receipt: null });
      expect(digital).toMatchObject({ channel: { code: wallet.code }, amount: "180.00", reference_no: "TX123456", status: "recorded" });
      expect(cash.batch_uuid).not.toBeNull();
      expect(digital.batch_uuid).toBe(cash.batch_uuid);
      expect(digital.receipt?.file_id).toBe(file.id);
      const stored = await fetchBytes(digital.receipt?.url ?? "");
      expect(stored.status).toBe(200);
      expect(hasExif(stored.bytes)).toBe(false);
      expect(jpegSize(stored.bytes)).toEqual({ width: 1600, height: 1200 });
    } finally {
      if (orderId !== null) await tidyOrder(fixture, orderId);
      await removeChannel(fixture.admin, fixture.channels.wallet);
      await removeChannel(fixture.admin, fixture.channels.bank);
    }
  });
});
