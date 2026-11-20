import { expect, test, type Page, type Route } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { orderLabel } from "../support/fixture";
import { openAs } from "../support/pad";
import { buildPayFixture, paymentsOf, serveAll, takeawayOrder, tidyOrder } from "../support/payments";

// The implicit state machines review (2026-11-20), item 4: the till's pay sheet sits beside the waiting list from 1024 px and is a dialog
// over the screen below it. Turning the tablet across 1024 px used to mount the sheet again in the other place: a payment on its way lost
// its "Taking payment…", Complete became pressable again, and the answer's "Give change" was gone. The sheet now keeps one element tree
// at both widths (Modal's `inline`), so the payment, its pending state and its receipt stay.
//
// The fixture is built through the admin API inside the isolated "E2E Test Branch" (e2e/support/payments.ts). The batch's answer is held
// back by the test while the window is turned, so the pending state is seen at both widths.

const WIDE = { width: 1280, height: 800 };
const NARROW = { width: 800, height: 1280 };

async function openSheet(page: Page, label: string) {
  const queue = page.getByRole("region", { name: "Orders awaiting payment" });
  const pay = queue.getByRole("button", { name: `Take payment for order ${label}`, exact: true });
  await expect(pay).toBeEnabled({ timeout: 30_000 });
  await pay.click();
  return page.getByRole("dialog", { name: `Payment for order ${label}` });
}

test.describe("p6 till: the pay sheet across a rotation", () => {
  test("a payment on its way keeps its state, Complete stays disabled, and the change to give stays after the answer", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildPayFixture(credentials, "125.00");
    let orderId: number | null = null;
    try {
      const sent = await takeawayOrder(fixture, "1");
      orderId = sent.id;
      await serveAll(fixture, sent.id);
      const label = orderLabel(sent);

      await page.setViewportSize(WIDE);
      await openAs(page, fixture.cashier.api, "en/till/");
      await expect(page.getByRole("heading", { level: 1, name: "Cashier till" })).toBeVisible({ timeout: 30_000 });
      const sheet = await openSheet(page, label);
      await expect(sheet).toBeVisible();
      // from 1024 px the sheet is the till's centre, not a modal
      await expect(sheet).not.toHaveAttribute("aria-modal", "true");

      // cash for the whole bill, 200 handed over
      const first = sheet.getByRole("group", { name: "Payment 1" });
      await first.getByLabel("Cash received").fill("200");
      await expect(first).toContainText("Change due: 75.00 ETB");
      const complete = sheet.getByRole("button", { name: "Complete payment" });
      await expect(complete).toBeEnabled();

      // the batch's answer is held back while the tablet is turned
      let release: () => void = () => {};
      const held = new Promise<void>((resolve) => {
        release = resolve;
      });
      let batches = 0;
      await page.route(`**/api/v1/orders/${sent.id}/payment-batches`, async (route: Route) => {
        if (route.request().method() !== "POST") return route.continue();
        batches += 1;
        await held;
        await route.continue();
      });
      await complete.click();
      const paying = sheet.getByRole("button", { name: "Taking payment…" });
      await expect(paying).toBeDisabled();

      // turned to portrait: the same sheet, now a dialog over the screen, still waiting for its answer
      await page.setViewportSize(NARROW);
      const modal = page.getByRole("dialog", { name: `Payment for order ${label}` });
      await expect(modal).toHaveAttribute("aria-modal", "true");
      await expect(modal.getByRole("button", { name: "Taking payment…" })).toBeDisabled();
      await expect(modal.getByRole("group", { name: "Payment 1" }).getByLabel("Cash received")).toHaveValue("200");
      await expect(modal.getByRole("button", { name: "Complete payment" })).toHaveCount(0);

      // and back: still the one payment on its way
      await page.setViewportSize(WIDE);
      await expect(sheet.getByRole("button", { name: "Taking payment…" })).toBeDisabled();

      // the answer arrives: recorded once, with the change to give
      const answer = page.waitForResponse((r) => r.url().endsWith(`/orders/${sent.id}/payment-batches`) && r.request().method() === "POST");
      release();
      expect((await answer).status()).toBe(201);
      await expect(sheet.getByRole("status").filter({ hasText: "Payment recorded" })).toBeVisible({ timeout: 30_000 });
      await expect(sheet).toContainText("Give change: 75.00 ETB");

      // turned again after the answer: the receipt and the change stay
      await page.setViewportSize(NARROW);
      await expect(modal).toContainText("Give change: 75.00 ETB");
      await expect(modal.getByRole("status").filter({ hasText: "Payment recorded" })).toBeVisible();
      expect(batches).toBe(1);
      expect((await paymentsOf(fixture.cashier.api, sent.id)).data).toHaveLength(1);
    } finally {
      if (orderId !== null) await tidyOrder(fixture, orderId);
    }
  });
});
