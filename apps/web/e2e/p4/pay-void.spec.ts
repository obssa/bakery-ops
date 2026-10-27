import { expect, test } from "@playwright/test";
import { ApiFailure } from "../support/api";
import { requireAdmin } from "../support/env";
import { orderLabel } from "../support/fixture";
import { openAs } from "../support/pad";
import { buildPayFixture, paymentsOf, removeChannel, serveAll, takeawayOrder, tidyOrder } from "../support/payments";

// Phase 4 project p4-pay-photo-split (docs/phases/phase-4-plan.md section 7), step 4.5b: voiding one wrong payment from the till (the void
// of step 4.3c; a refund is step 4.3d, on hold, and is not here).
//
// The cashier splits a bill of 120.00 into cash and the run's bank transfer while the order is not served yet: it is settled and waits in
// the queue. The transfer turns out to be wrong, so the cashier voids that payment with a manager's PIN (a wrong PIN is refused with the
// API's message): the order is owed 70.00 again, the cashier takes it in cash, the line is served and the order closes. A paid order then
// refuses the void (payments.void.order_closed: it would be a refund).

test.describe("p4 pay sheet: void a payment", () => {
  test("a wrong payment is voided before the order is paid, and a paid order refuses the void @live", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildPayFixture(credentials, "60.00");
    const { bank } = fixture.channels;
    let orderId: number | null = null;
    try {
      const sent = await takeawayOrder(fixture, "2");
      orderId = sent.id;
      expect(sent.totals.total).toBe("120.00");
      const label = orderLabel(sent);

      await openAs(page, fixture.cashier.api, "en/till/");
      const queue = page.getByRole("region", { name: "Orders awaiting payment" });
      await expect(queue.getByRole("button", { name: `Take payment for order ${label}`, exact: true })).toBeEnabled({ timeout: 30_000 });
      await queue.getByRole("button", { name: `Take payment for order ${label}`, exact: true }).click();
      const sheet = page.getByRole("dialog", { name: `Payment for order ${label}` });

      // 50.00 in cash, 70.00 by bank transfer (a reference, no photo)
      const first = sheet.getByRole("group", { name: "Payment 1" });
      await first.getByLabel("Amount", { exact: true }).fill("50.00");
      await first.getByRole("button", { name: "Exact amount" }).click();
      await expect(first).toContainText("Change due: 0.00 ETB");
      await sheet.getByRole("button", { name: "Add a payment" }).click();
      const second = sheet.getByRole("group", { name: "Payment 2" });
      await second.getByRole("button", { name: bank.name_en, exact: true }).click();
      await second.getByLabel("Reference number").fill("WRONG-TRANSFER-1");
      await sheet.getByRole("button", { name: "Complete payment" }).click();
      await expect(sheet.getByRole("status").filter({ hasText: "Payment recorded" })).toBeVisible({ timeout: 30_000 });
      await sheet.getByRole("button", { name: "Done" }).click();

      // paid in full but not served: it waits, settled, and offers to void a payment
      const voidPayment = queue.getByRole("button", { name: `Void a payment of order ${label}`, exact: true });
      await expect(voidPayment).toBeVisible({ timeout: 15_000 });
      await voidPayment.click();
      const dialog = page.getByRole("dialog", { name: `Void a payment of order ${label}` });
      // a settled order cannot be voided as a whole: only its payments are offered
      await expect(dialog.getByRole("radio", { name: /The whole order/ })).toHaveCount(0);
      await expect(dialog.getByRole("radio")).toHaveCount(2, { timeout: 15_000 });
      await dialog.getByRole("radio", { name: new RegExp(`${bank.name_en} 70\\.00 ETB`) }).check();
      await dialog.getByLabel("Reason").fill("The transfer was for another order");

      // a PIN that is nobody's is refused with the API's own message, and nothing changes
      await dialog.getByLabel("Manager PIN").fill("000000");
      await dialog.getByRole("button", { name: "Void this payment" }).click();
      await expect(dialog.getByRole("alert")).toContainText("That PIN does not belong to someone allowed to approve this.", { timeout: 30_000 });
      expect((await paymentsOf(fixture.cashier.api, sent.id)).data.map((p) => p.status)).toEqual(["recorded", "recorded"]);

      // the manager's PIN approves it
      await dialog.getByLabel("Manager PIN").fill(fixture.manager.pin);
      await dialog.getByRole("button", { name: "Void this payment" }).click();
      await expect(dialog).toHaveCount(0, { timeout: 30_000 });

      // the order is owed the 70.00 again: the cashier takes it in cash
      const payAgain = queue.getByRole("button", { name: `Take payment for order ${label}`, exact: true });
      await expect(payAgain).toBeEnabled({ timeout: 15_000 });
      const voided = await paymentsOf(fixture.cashier.api, sent.id);
      expect(voided.balance).toBe("70.00");
      expect(voided.data.map((p) => [p.channel.code, p.amount, p.status])).toEqual([
        ["cash", "50.00", "recorded"],
        [bank.code, "70.00", "voided"],
      ]);
      await payAgain.click();
      const again = page.getByRole("dialog", { name: `Payment for order ${label}` });
      await expect(again.getByRole("status", { name: "Amount due" })).toHaveText("70.00 ETB");
      const only = again.getByRole("group", { name: "Payment 1" });
      await expect(only.getByLabel("Amount", { exact: true })).toHaveValue("70.00");
      await only.getByRole("button", { name: "Exact amount" }).click();
      await again.getByRole("button", { name: "Complete payment" }).click();
      await expect(again.getByRole("status").filter({ hasText: "Payment recorded" })).toBeVisible({ timeout: 30_000 });
      await again.getByRole("button", { name: "Done" }).click();

      // served, it closes; a payment of a closed order is refunded, never voided
      await serveAll(fixture, sent.id);
      await expect.poll(async () => (await fixture.cashier.api.get<{ status: string }>(`/orders/${sent.id}`)).status, { timeout: 15_000 }).toBe("closed");
      const closed = await paymentsOf(fixture.cashier.api, sent.id);
      expect(closed.balance).toBe("0.00");
      expect(closed.data.map((p) => [p.channel.code, p.amount, p.status])).toEqual([
        ["cash", "50.00", "recorded"],
        [bank.code, "70.00", "voided"],
        ["cash", "70.00", "recorded"],
      ]);
      const refused = await fixture.cashier.api.tryPost(`/payments/${closed.data[2].id}/void`, { reason: "Too late for a void", pin: fixture.manager.pin });
      expect(refused.ok).toBe(false);
      if (!refused.ok) {
        expect(refused.failure).toBeInstanceOf(ApiFailure);
        expect(refused.failure.status).toBe(409);
        expect(refused.failure.key).toBe("payments.void.order_closed");
      }
    } finally {
      if (orderId !== null) await tidyOrder(fixture, orderId);
      await removeChannel(fixture.admin, fixture.channels.wallet);
      await removeChannel(fixture.admin, fixture.channels.bank);
    }
  });
});
