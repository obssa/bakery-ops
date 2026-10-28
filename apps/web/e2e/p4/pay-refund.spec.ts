import { expect, test, type Request } from "@playwright/test";
import { requireAdmin, run } from "../support/env";
import { loginOnce, orderLabel, unitLookup } from "../support/fixture";
import { openAs } from "../support/pad";
import { buildRefundFixture, closedCashOrder, ledgerOf, retireRefundProducts, tillExpectedCash } from "../support/refunds";
import { subtractDecimal } from "../../src/lib/decimal";

// Phase 4 project p4-pay-photo-split (docs/phases/phase-4-plan.md section 7), step 4.5g: the refund, on Ramin's rule (R-C, 2026-10-28).
//
// The admin decides per product whether it can be refunded and whether a refunded unit goes back into stock; the refund follows those
// settings. The run makes three products through the catalog API: a display-case cake (back into stock), a burger made to order at the
// kitchen (its ingredients are used: never back into stock) and a display-case water that cannot be refunded. The cashier sells one of
// each and takes the bill in cash in the till's shift; the order closes. At the till's closed orders the cashier opens the refund: the
// water's line is shown but cannot be chosen, the other two say what happens to their stock. A wrong manager's PIN is refused and the next
// try goes under a new Idempotency-Key with the same client_uuid; the manager's PIN approves it. Through the API: one `return` movement for
// the cake, pointing at its sale, none for the burger; the order's refunded_total is the refund; the till shift's expected cash fell by it.
//
// The second flow is the admin's two toggles: a new product saved with both on, the restock toggle greyed out with its reason for a product
// made to order, and a product's settings changed on its page.

interface RefundAnswer {
  refund: { id: number; amount: string; lines: { line_id: number; item_id: number; disposition: string }[] };
  payments: { payment_id: number; channel_code: string; amount: string }[];
  movements: { id: number; item_id: number; location_id: number; qty: string; reverses: number }[];
  order: { refunded: string; net_paid: string };
}

interface OrderRead {
  refunded_total: string;
  lines: { id: number; item: { id: number }; refunded_at: string | null }[];
}

test.describe("pay-refund", () => {
  test("a manager refunds a paid order with a PIN: display-case lines are restocked, the cash drawer total drops @live", async ({ page }) => {
    test.setTimeout(300_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildRefundFixture(credentials);
    try {
      const order = await closedCashOrder(fixture);
      expect(order.status).toBe("closed");
      expect(order.totals.total).toBe("180.00");
      const cakeLine = order.lines.find((l) => l.item.id === fixture.cake.id);
      const burgerLine = order.lines.find((l) => l.item.id === fixture.burger.id);
      const waterLine = order.lines.find((l) => l.item.id === fixture.water.id);
      if (!cakeLine || !burgerLine || !waterLine) throw new Error("the order is missing a line");
      const before = await tillExpectedCash(fixture.admin);
      const no = orderLabel(order);

      // every refund request leaving the browser, to read its key and its client_uuid
      const sent: Request[] = [];
      page.on("request", (request) => {
        if (request.method() === "POST" && request.url().endsWith(`/orders/${order.id}/refund`)) sent.push(request);
      });

      await openAs(page, fixture.cashier.api, "en/till/");
      await page.getByRole("tab", { name: "Closed orders" }).click();
      await page.getByRole("button", { name: `Refund order ${no}`, exact: true }).click({ timeout: 30_000 });
      const dialog = page.getByRole("dialog", { name: `Refund order ${no}` });
      await expect(dialog).toBeVisible();

      // the lines, as the admin's settings make them
      const cake = dialog.getByRole("checkbox", { name: fixture.cake.name_en, exact: true });
      const burger = dialog.getByRole("checkbox", { name: fixture.burger.name_en, exact: true });
      const water = dialog.getByRole("checkbox", { name: fixture.water.name_en, exact: true });
      await expect(cake).toBeChecked({ timeout: 30_000 });
      await expect(cake).toHaveAccessibleDescription(/Goes back to stock/);
      await expect(burger).toBeChecked();
      await expect(burger).toHaveAccessibleDescription(/Not returned to stock/);
      await expect(water).toBeDisabled();
      await expect(water).not.toBeChecked();
      await expect(water).toHaveAccessibleDescription(/cannot be refunded/);
      await expect(dialog.getByRole("status", { name: "Refund amount" })).toContainText("150.00");
      // one cash payment holds the whole bill: it gives the 150.00 back
      await expect(dialog.getByRole("list", { name: "Paid back from" })).toContainText("Cash");
      await expect(dialog.getByRole("list", { name: "Paid back from" })).toContainText("150.00");

      // a wrong PIN is refused: nothing is refunded, the next try is a new request (a new key) for the same refund (the same client_uuid)
      const reason = `E2E refund ${run}: the customer brought it back`;
      await dialog.getByLabel("Reason for the refund").fill(reason);
      const wrong = fixture.manager.pin === "000000" ? "999999" : "000000";
      await dialog.getByLabel("Manager's PIN").fill(wrong);
      await dialog.getByRole("button", { name: "Refund", exact: true }).click();
      await expect(dialog.getByRole("alert")).toBeVisible({ timeout: 30_000 });
      await expect(dialog.getByLabel("Manager's PIN")).toHaveValue("");

      const answered = page.waitForResponse((r) => r.request().method() === "POST" && r.url().endsWith(`/orders/${order.id}/refund`) && r.status() === 201, { timeout: 60_000 });
      await dialog.getByLabel("Manager's PIN").fill(fixture.manager.pin);
      await dialog.getByRole("button", { name: "Refund", exact: true }).click();
      const answer = (await (await answered).json()) as RefundAnswer;
      expect(sent).toHaveLength(2);
      const [first, second] = sent;
      expect(first.headers()["idempotency-key"]).toBeTruthy();
      expect(second.headers()["idempotency-key"]).not.toBe(first.headers()["idempotency-key"]);
      const firstBody = first.postDataJSON() as { client_uuid: string; lines?: number[] };
      const secondBody = second.postDataJSON() as { client_uuid: string; lines?: number[]; reason: string };
      expect(secondBody.client_uuid).toBe(firstBody.client_uuid);
      // every line that may be refunded, chosen: the whole order, no lines named
      expect(secondBody.lines).toBeUndefined();
      expect(secondBody.reason).toBe(reason);

      // the result in the dialog
      const result = dialog.getByRole("region", { name: "Refund done" });
      await expect(result).toContainText("150.00");
      await expect(result).toContainText("Cash");
      await expect(result.getByRole("list", { name: "Back in stock" })).toContainText(fixture.cake.name_en);
      await expect(result.getByRole("list", { name: "Not returned to stock" })).toContainText(fixture.burger.name_en);

      // through the API: the money, the lines, the ledger and the drawer
      expect(answer.refund.amount).toBe("150.00");
      expect(answer.payments.map((p) => [p.channel_code, p.amount])).toEqual([["cash", "150.00"]]);
      expect(answer.refund.lines.map((l) => [l.line_id, l.disposition]).sort()).toEqual(
        [
          [cakeLine.id, "restock"],
          [burgerLine.id, "waste"],
        ].sort(),
      );
      expect(answer.movements).toHaveLength(1);
      const [returned] = answer.movements;
      expect(returned.item_id).toBe(fixture.cake.id);
      expect(returned.location_id).toBe(fixture.displayCase.id);
      const cakeLedger = await ledgerOf(fixture.admin, fixture.cake.id, fixture.displayCase.id);
      expect(cakeLedger.find((m) => m.id === returned.id)?.reason).toBe("return");
      expect(cakeLedger.find((m) => m.id === returned.reverses)?.reason).toBe("sale");
      expect(cakeLedger.filter((m) => m.reason === "return")).toHaveLength(1);
      // the burger's ingredients were used: nothing of it, or of its ingredient, came back
      const ingredientLedger = await ledgerOf(fixture.admin, fixture.burger.ingredientId, fixture.burger.kitchenLocationId);
      expect(ingredientLedger.filter((m) => m.reason === "return")).toHaveLength(0);

      const read = await fixture.admin.get<OrderRead>(`/orders/${order.id}`);
      expect(read.refunded_total).toBe("150.00");
      expect(read.lines.find((l) => l.id === waterLine.id)?.refunded_at).toBeNull();
      expect(read.lines.find((l) => l.id === cakeLine.id)?.refunded_at).toBeTruthy();
      expect(read.lines.find((l) => l.id === burgerLine.id)?.refunded_at).toBeTruthy();

      const after = await tillExpectedCash(fixture.admin);
      expect(after.shiftId).toBe(before.shiftId);
      expect(subtractDecimal(before.expected, after.expected, 2)).toBe("150.00");

      // the order's card at the till now says what came back; the water's line is all that is left and it cannot be refunded
      await dialog.getByRole("button", { name: "Close", exact: true }).click();
      await expect(page.getByRole("article", { name: `Order ${no}` })).toContainText("150.00");
    } finally {
      await retireRefundProducts(fixture);
    }
  });

  test("the admin's refund settings: saved on a new product, greyed out for one made to order, changed on a product's page @live", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const { api: admin } = await loginOnce(credentials.phone, credentials.password);
    const nameEn = `E2E refund toggles ${run}`;
    let createdId: number | null = null;
    // a product made to order, made by this run through the API with the natural settings (refundable, never back into stock)
    const madeToOrder = await admin.post<{ id: number; refundable: boolean; refund_restock: boolean }>("/items", {
      kind: "product",
      fulfilment: "to_order",
      name_en: `E2E refund made to order ${run}`,
      name_am: `የሙከራ በትዕዛዝ የሚሠራ ${run}`,
      unit_id: (await unitLookup(admin))("pcs"),
    });
    expect(madeToOrder.refundable).toBe(true);
    expect(madeToOrder.refund_restock).toBe(false);
    try {
      await openAs(page, admin, "en/admin/catalog/");
      const form = page.getByRole("form", { name: "New item" });
      await form.getByLabel("Kind").selectOption("product");
      await form.getByLabel("Fulfilment").selectOption("to_order");
      const refundable = form.getByRole("checkbox", { name: "Refundable" });
      const restock = form.getByRole("checkbox", { name: "Goes back to stock when refunded" });
      // made to order: its ingredients are used, nothing can go back into stock
      await expect(restock).toBeDisabled();
      await expect(restock).toHaveAccessibleDescription(/made to order/i);
      // sold from stock: both may be on
      await form.getByLabel("Fulfilment").selectOption("to_stock");
      await expect(restock).toBeEnabled();
      await refundable.check();
      await restock.check();
      await form.getByLabel("Name (English)").fill(nameEn);
      await form.getByLabel("Name (Amharic)").fill(`የሙከራ መቀያየሪያ ${run}`);
      const unit = form.getByLabel("Unit");
      const pcs = await unit.getByRole("option", { name: /\(pcs\)$/ }).first().getAttribute("value");
      await unit.selectOption(pcs ?? "");
      await form.getByRole("button", { name: "Create item" }).click();
      await expect(form.getByText(`Created “${nameEn}”.`)).toBeVisible({ timeout: 30_000 });

      const items = await admin.get<{ data: { id: number; name_en: string; refundable: boolean; refund_restock: boolean }[] }>("/items", { kind: "product" });
      const created = items.data.find((i) => i.name_en === nameEn);
      expect(created).toBeTruthy();
      createdId = created?.id ?? null;
      expect(created?.refundable).toBe(true);
      expect(created?.refund_restock).toBe(true);
      // the list shows the two settings beside the other flags
      const list = page.getByRole("table", { name: "Items" });
      await expect(list.getByRole("row").filter({ hasText: nameEn })).toContainText("Back to stock");

      // the product's page: refundable switched off and saved
      await openAs(page, admin, `en/admin/catalog/?item=${createdId}`);
      const settings = page.getByRole("form", { name: "Refund settings" });
      await expect(settings.getByRole("checkbox", { name: "Refundable" })).toBeChecked({ timeout: 30_000 });
      await settings.getByRole("checkbox", { name: "Refundable" }).uncheck();
      await settings.getByRole("button", { name: "Save refund settings" }).click();
      await expect(settings.getByRole("status")).toContainText("Saved", { timeout: 30_000 });
      const saved = await admin.get<{ refundable: boolean; refund_restock: boolean }>(`/items/${createdId}`);
      expect(saved.refundable).toBe(false);
      expect(saved.refund_restock).toBe(true);

      // the made-to-order product's page: refundable, and the restock toggle greyed out with its reason
      await openAs(page, admin, `en/admin/catalog/?item=${madeToOrder.id}`);
      const burger = page.getByRole("form", { name: "Refund settings" });
      await expect(burger.getByRole("checkbox", { name: "Refundable" })).toBeChecked({ timeout: 30_000 });
      await expect(burger.getByRole("checkbox", { name: "Goes back to stock when refunded" })).toBeDisabled();
      await expect(burger.getByRole("checkbox", { name: "Goes back to stock when refunded" })).not.toBeChecked();
    } finally {
      for (const id of [createdId, madeToOrder.id]) {
        if (id !== null) await admin.patch(`/items/${id}`, { is_active: false }).catch(() => undefined);
      }
    }
  });
});
