import { test, expect } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildStationFixture, ledgerReasons } from "../support/fixture";
import { openPadAndUnlock } from "../support/pad";

// Phase 1 gate project p1-shortfall (manifest phases.1.gate.criteria; docs/phases/phase-1-plan.md section 7), built in
// step 1.6b.
//
// An ingredient is stocked for exactly 2 portions and a product uses one portion each. On the pad the waiter unlocks
// with the short code, orders 3 and presses Send: the API refuses (409 orders.send.shortfall), the order stays open and
// the pad shows the shortfall inline on that exact line (ingredient, quantity short, station), in English and then in
// Amharic. The waiter reduces the line to 2 and sends again: the same order is sent, and the ledger holds exactly one
// reservation for the ingredient.
//
// The fixture is built through the admin API inside the isolated "E2E Test Branch" (e2e/support/fixture.ts), so the
// flow carries @live (admin-smoke.spec.ts explains how each environment provides the admin).

test.describe("p1 shortfall", () => {
  test("selling more than is available shows the shortfall on the exact line, and the reduced order sends @live", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildStationFixture(credentials, "kitchen", { stock: "2.0000", perUnit: "1.0000" });
    const product = fixture.product.name_en;

    await openPadAndUnlock(page, fixture.waiter);

    // dine-in at the fixture table, the product from the menu
    await page.getByRole("button", { name: `Table ${fixture.table.code}` }).click();
    await page.getByRole("tab", { name: "Other" }).click();
    await page.getByRole("button", { name: `Add ${product}` }).click();

    const cart = page.getByRole("region", { name: "Cart" });
    const line = cart.getByRole("listitem").filter({ hasText: product });
    await expect(line).toBeVisible();
    await line.getByRole("button", { name: `Increase quantity of ${product}` }).click();
    await line.getByRole("button", { name: `Increase quantity of ${product}` }).click();
    await expect(line.getByRole("textbox", { name: `Quantity of ${product}` })).toHaveValue("3");

    // 3 > 2: nothing is sent, the shortfall sits on that line
    await page.getByRole("button", { name: "Send order" }).click();
    const short = line.getByRole("alert");
    await expect(short).toBeVisible({ timeout: 30_000 });
    await expect(short).toContainText(fixture.ingredient.name_en);
    await expect(short).toContainText("1 kg");
    await expect(short).toContainText(fixture.station.name_en);
    await expect(line.getByRole("textbox", { name: `Quantity of ${product}` })).toHaveValue("3");

    // the same shortfall in Amharic (the unlocked pad and its cart survive the language switch)
    await page.getByRole("link", { name: "አማርኛ" }).click();
    await expect(page).toHaveURL(/\/app\/am\/pad\/$/);
    const lineAm = page.getByRole("region", { name: "የትዕዛዝ ዝርዝር" }).getByRole("listitem").filter({ hasText: fixture.product.name_am });
    const shortAm = lineAm.getByRole("alert");
    await expect(shortAm).toBeVisible({ timeout: 30_000 });
    await expect(shortAm).toContainText(fixture.ingredient.name_am);
    await expect(shortAm).toContainText("1 kg");
    await expect(shortAm).toContainText(fixture.station.name_am);

    // back in English: reduce to 2 and send again, on the same order
    await page.getByRole("link", { name: "English" }).click();
    await expect(page).toHaveURL(/\/app\/en\/pad\/$/);
    const again = page.getByRole("region", { name: "Cart" }).getByRole("listitem").filter({ hasText: product });
    await again.getByRole("button", { name: `Decrease quantity of ${product}` }).click();
    await expect(again.getByRole("textbox", { name: `Quantity of ${product}` })).toHaveValue("2");
    await page.getByRole("button", { name: "Send order" }).click();
    await expect(page.getByRole("status").filter({ hasText: "Order sent" })).toBeVisible({ timeout: 30_000 });

    // post-conditions through the API: one order with this product, sent, and exactly one reservation of the ingredient
    const orders = await fixture.waiter.api.get<{ data: { id: number; status: string; lines: { item: { id: number }; qty: string }[] }[] }>("/orders", { mine: 1 });
    const mine = orders.data.filter((order) => order.lines.some((l) => l.item.id === fixture.product.id));
    expect(mine).toHaveLength(1);
    expect(mine[0].status).toBe("sent");
    expect(mine[0].lines.map((l) => l.qty)).toEqual(["2.0000"]);
    expect(await ledgerReasons(fixture)).toEqual(["purchase_in", "reservation"]);

    // and it is on the waiter's own list with its line status
    await page.getByRole("tab", { name: "My orders" }).click();
    await expect(page.getByRole("article").filter({ hasText: product })).toContainText("Sent");
  });
});
