import { test, expect } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildStationFixture, buildTillStaff, clearStaleOrders, ledgerReasons, orderLabel } from "../support/fixture";
import { openAs } from "../support/pad";

// Phase 1 gate project p1-order-flow, the till's void (step 1.6c). The cashier is signed in on the till (no short code),
// takes an order on the same ordering surface the pad uses (so the API stamps it created_via = till), sees it in the
// queue of orders awaiting payment and voids it: a wrong PIN is refused with the API message, the PIN of a manager is
// accepted, the order leaves the queue, and the ledger shows the reservation released.
//
// The fixture is built through the admin API inside the isolated "E2E Test Branch" (e2e/support/fixture.ts), so the
// flow carries @live.

test.describe("p1 order flow: till void", () => {
  test("an unpaid order is voided with a manager's PIN and its stock is released @live", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    const fixture = await buildStationFixture(credentials, "kitchen");
    const staff = await buildTillStaff(credentials);
    const product = fixture.product.name_en;

    await openAs(page, staff.cashier.api, "en/till/");
    await expect(page.getByRole("heading", { level: 1, name: "Cashier till" })).toBeVisible({ timeout: 30_000 });

    // the till takes an order itself
    await page.getByRole("tab", { name: "New order" }).click();
    await page.getByRole("button", { name: `Table ${fixture.table.code}` }).click();
    await page.getByRole("tab", { name: "Other" }).click();
    await page.getByRole("button", { name: `Add ${product}` }).click();
    await page.getByRole("button", { name: "Send order" }).click();
    await expect(page.getByRole("status").filter({ hasText: "Order sent" })).toBeVisible({ timeout: 30_000 });

    const all = await staff.cashier.api.get<{ data: { id: number; order_no: string | number; business_date: string | null; created_via: string; lines: { item: { id: number } }[] }[] }>("/orders");
    const order = all.data.find((o) => o.lines.some((l) => l.item.id === fixture.product.id));
    if (!order) throw new Error("the till order is not in the list");
    expect(order.created_via).toBe("till");
    // an order is named by its number, and by its date too when it is not of today (numbers restart each business day)
    const label = orderLabel(order);

    // it is in the queue; the void asks for a reason and a manager's PIN
    await page.getByRole("tab", { name: /Unpaid orders/ }).click();
    const queue = page.getByRole("region", { name: "Orders awaiting payment" });
    await expect(queue.getByRole("button", { name: `Void order ${label}`, exact: true })).toBeVisible({ timeout: 15_000 });
    await queue.getByRole("button", { name: `Void order ${label}`, exact: true }).click();
    const dialog = page.getByRole("dialog", { name: `Void order ${label}` });
    await dialog.getByLabel("Reason").fill("Customer left before it was made");

    // a PIN that is nobody's is refused with the API's own message
    await dialog.getByLabel("Manager PIN").fill("000000");
    await dialog.getByRole("button", { name: "Void this order" }).click();
    await expect(dialog.getByRole("alert")).toContainText("That PIN does not belong to someone allowed to approve this.", { timeout: 30_000 });
    await expect(queue.getByRole("button", { name: `Void order ${label}`, exact: true })).toBeVisible();

    // the manager's PIN approves it
    await dialog.getByLabel("Manager PIN").fill(staff.manager.pin);
    await dialog.getByRole("button", { name: "Void this order" }).click();
    await expect(dialog).toHaveCount(0, { timeout: 30_000 });
    await expect(queue.getByRole("button", { name: `Void order ${label}`, exact: true })).toHaveCount(0, { timeout: 15_000 });

    // through the API: voided with the reason, and the stock reserved at send is released
    const voided = await staff.cashier.api.get<{ status: string; voided: { reason: string; approved_by: number | null } | null }>(`/orders/${order.id}`);
    expect(voided.status).toBe("voided");
    expect(voided.voided?.reason).toBe("Customer left before it was made");
    expect(voided.voided?.approved_by).toBe(staff.manager.id);
    expect(await ledgerReasons(fixture)).toEqual(["purchase_in", "reservation", "reservation_release"]);
  });
});
