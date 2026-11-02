import { expect, test, type Page } from "@playwright/test";
import { uuid, type Api } from "../support/api";
import { claimBytes, FakeBridge } from "../support/bridge";
import { live, requireAdmin } from "../support/env";
import { checkReceiptFrame } from "../support/escpos";
import { orderLabel } from "../support/fixture";
import { openAs } from "../support/pad";
import { buildPayFixture, payBatch, takeawayOrder, tidyOrder, type PayFixture } from "../support/payments";

// Phase 5 gate project p5-device (docs/phases/phase-5-plan.md, step 5.3c and section 7): printing from the till.
//
// A print bridge runs in the test process (e2e/support/bridge.ts): the admin enrols a device of kind bridge, and the test speaks the
// bridge's routes with its token (poll GET /bridge/jobs/next, claim, report printed or failed), one step at a time, so the till is seen
// in between. The receipt's bytes are checked for their framing (e2e/support/escpos.ts: ESC @ first, GS v 0 raster bands, the GS V cut
// last) until 5.3b's JS emulator is on main.
// - With no bridge seen (every bridge of the branch revoked), Print after payment is refused with payments.print.no_bridge and the pay
//   sheet shows the receipt link and its QR code at once; no job is made.
// - With the bridge polling, Print after payment says the receipt waits for the printer, then that it prints (claimed), then that it
//   printed; the bytes are one copy on 80 mm paper (576 dots).
// - On the order record, a job the printer fails three times (out of paper) is failed, and the record shows the link and its QR code.
// - The receipt settings choose 58 mm paper: the bridge's claim, sent without a width, draws 384 dots.
// The CI stack only: the live E2E branch must not collect bridges, and a bridge's polls make the branch's printer look connected.

const NO_PRINTER = "The receipt printer is not connected right now. Give the customer the receipt link instead.";
const QR = { name: "QR code of the receipt link" } as const;

interface PrintJobRead {
  id: number;
  order_id: number;
  status: string;
  attempts: number;
  failure_key: string | null;
}

function jobsOf(api: Api, orderId: number): Promise<{ data: PrintJobRead[] }> {
  return api.get<{ data: PrintJobRead[] }>("/print-jobs", { order_id: orderId });
}

/** Prints whatever a former attempt left queued in the branch, so the next job the bridge sees is this flow's. */
async function drain(bridge: FakeBridge): Promise<void> {
  for (let job = await bridge.next(); job !== null; job = await bridge.next()) {
    await bridge.claim(job);
    await bridge.printed(job);
  }
}

/** The cashier pays the whole order in cash at the till; answers the pay sheet, showing the receipt. */
async function payAtTill(page: Page, fixture: PayFixture, label: string) {
  await openAs(page, fixture.cashier.api, "en/till/");
  await expect(page.getByRole("heading", { level: 1, name: "Cashier till" })).toBeVisible({ timeout: 30_000 });
  const pay = page.getByRole("region", { name: "Orders awaiting payment" }).getByRole("button", { name: `Take payment for order ${label}`, exact: true });
  await expect(pay).toBeEnabled({ timeout: 30_000 });
  await pay.click();
  const sheet = page.getByRole("dialog", { name: `Payment for order ${label}` });
  await sheet.getByRole("group", { name: "Payment 1" }).getByRole("button", { name: "Exact amount" }).click();
  await sheet.getByRole("button", { name: "Complete payment" }).click();
  await expect(sheet.getByRole("status").filter({ hasText: "Payment recorded" })).toBeVisible({ timeout: 30_000 });
  return sheet;
}

test.describe("p5 printing from the till", () => {
  test.describe.configure({ mode: "serial" });
  test.skip(live, "a fake print bridge runs against the CI stack only (the live E2E branch must not collect bridges)");

  test("with no printer connected, Print after payment shows the receipt link and its QR code at once", async ({ page }) => {
    test.setTimeout(180_000);
    const fixture = await buildPayFixture(requireAdmin(test.skip), "60.00");
    await FakeBridge.revokeAll(fixture.admin);
    const sent = await takeawayOrder(fixture, "1");
    try {
      const sheet = await payAtTill(page, fixture, orderLabel(sent));
      await expect(sheet.getByRole("img", QR)).toHaveCount(0);

      await sheet.getByRole("button", { name: "Print receipt" }).click();
      await expect(sheet.getByRole("alert").filter({ hasText: NO_PRINTER })).toBeVisible({ timeout: 15_000 });
      await expect(sheet.getByLabel("Receipt link")).toHaveValue(/^https?:\/\/\S+$/, { timeout: 15_000 });
      await expect(sheet.getByRole("img", QR)).toBeVisible();
      expect((await jobsOf(fixture.cashier.api, sent.id)).data).toEqual([]);
    } finally {
      await tidyOrder(fixture, sent.id);
    }
  });

  test("Print after payment waits for the printer, prints, then says it printed; the bridge gets one 80 mm receipt", async ({ page }) => {
    test.setTimeout(180_000);
    const fixture = await buildPayFixture(requireAdmin(test.skip), "60.00");
    const bridge = await FakeBridge.enrol(fixture.admin, "till");
    const sent = await takeawayOrder(fixture, "1");
    try {
      await drain(bridge);
      const sheet = await payAtTill(page, fixture, orderLabel(sent));
      const status = sheet.getByRole("status", { name: "Print status" });

      await sheet.getByRole("button", { name: "Print receipt" }).click();
      await expect(status).toHaveText("Waiting for the printer…", { timeout: 15_000 });
      const jobId = await bridge.waitForJob();
      const [mine] = (await jobsOf(fixture.cashier.api, sent.id)).data;
      expect(mine).toMatchObject({ id: jobId, order_id: sent.id, status: "queued" });

      const claim = await bridge.claim(jobId);
      await expect(status).toHaveText("Printing…", { timeout: 15_000 });
      expect(claim.copies).toBe(1);
      const frame = checkReceiptFrame(claimBytes(claim));
      expect(frame).toMatchObject({ copies: 1, widthDots: 576 });
      expect(frame.rows).toBeGreaterThan(100);

      await bridge.printed(jobId);
      await expect(status).toHaveText("Printed.", { timeout: 15_000 });
      await expect(sheet.getByRole("button", { name: "Print again" })).toBeEnabled();
      await expect(sheet.getByRole("img", QR)).toHaveCount(0);
      expect((await jobsOf(fixture.cashier.api, sent.id)).data[0]).toMatchObject({ id: jobId, status: "printed", attempts: 1 });
    } finally {
      await bridge.revoke(fixture.admin).catch(() => undefined);
      await tidyOrder(fixture, sent.id);
    }
  });

  test("on the order record, a receipt the printer fails three times shows the receipt link and its QR code", async ({ page }) => {
    test.setTimeout(180_000);
    const fixture = await buildPayFixture(requireAdmin(test.skip), "60.00");
    const bridge = await FakeBridge.enrol(fixture.admin, "record");
    const sent = await takeawayOrder(fixture, "1");
    try {
      await drain(bridge);
      await payBatch(fixture, sent.id, [{ channel_code: "cash", amount: "60.00", tendered_amount: "60.00" }]);
      await openAs(page, fixture.admin, `en/admin/orders/record/?id=${sent.id}`);
      const receipt = page.getByRole("region", { name: "Receipt" });
      const status = receipt.getByRole("status", { name: "Print status" });
      await receipt.getByRole("button", { name: "Print receipt" }).click({ timeout: 30_000 });
      await expect(status).toHaveText("Waiting for the printer…", { timeout: 15_000 });

      for (let attempt = 1; attempt <= 3; attempt++) {
        const jobId = await bridge.waitForJob();
        await bridge.claim(jobId);
        await bridge.failed(jobId, "paper_out");
      }
      await expect(status).toContainText("Not printed: the printer is out of paper.", { timeout: 15_000 });
      await expect(receipt.getByLabel("Receipt link")).toHaveValue(/^https?:\/\/\S+$/, { timeout: 15_000 });
      await expect(receipt.getByRole("img", QR)).toBeVisible();
      expect((await jobsOf(fixture.admin, sent.id)).data[0]).toMatchObject({ status: "failed", attempts: 3, failure_key: "paper_out" });
    } finally {
      await bridge.revoke(fixture.admin).catch(() => undefined);
      await tidyOrder(fixture, sent.id);
    }
  });

  test("the receipt settings choose 58 mm paper, and the bridge's claim draws 384 dots", async ({ page }) => {
    test.setTimeout(180_000);
    const fixture = await buildPayFixture(requireAdmin(test.skip), "60.00");
    const { admin } = fixture;
    const bridge = await FakeBridge.enrol(admin, "narrow");
    const sent = await takeawayOrder(fixture, "1");
    try {
      await openAs(page, admin, "en/admin/payments/receipts/");
      const paper = page.getByRole("radiogroup", { name: "Receipt printer paper" });
      await expect(paper.getByRole("radio", { name: "80 mm, the wide roll" })).toBeChecked({ timeout: 30_000 });
      await paper.getByRole("radio", { name: "58 mm, the narrow roll" }).check();
      await page.getByRole("button", { name: "Save receipt settings" }).click();
      await expect(page.getByRole("status").filter({ hasText: "Receipt settings saved." })).toBeVisible({ timeout: 15_000 });
      expect((await admin.get<{ paper_width_mm: number }>("/receipt-settings")).paper_width_mm).toBe(58);
      await page.reload();
      await expect(page.getByRole("radio", { name: "58 mm, the narrow roll" })).toBeChecked({ timeout: 30_000 });

      await drain(bridge);
      await payBatch(fixture, sent.id, [{ channel_code: "cash", amount: "60.00", tendered_amount: "60.00" }]);
      await admin.post(`/orders/${sent.id}/print`, { client_uuid: uuid() });
      const jobId = await bridge.waitForJob();
      const claim = await bridge.claim(jobId);
      expect(checkReceiptFrame(claimBytes(claim)).widthDots).toBe(384);
      await bridge.printed(jobId);
    } finally {
      const settings = await admin.get<Record<string, unknown>>("/receipt-settings");
      if (settings.paper_width_mm !== 80) {
        const form: Record<string, unknown> = { ...settings, paper_width_mm: 80 };
        delete form.is_default;
        await admin.put("/receipt-settings", form).catch(() => undefined);
      }
      await bridge.revoke(admin).catch(() => undefined);
      await tidyOrder(fixture, sent.id);
    }
  });
});
