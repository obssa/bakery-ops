import { test, expect, type Page } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { balanceAt, buildInventoryFixture, movingAverage, purchasesOf, reorderListOf, type InventoryFixture } from "../support/inventory";
import { openAs } from "../support/pad";

// Phase 2 gate project p2-purchase-transfer (manifest phases.2.gate.criteria; docs/phases/phase-2-plan.md section 7), the
// admin half built in step 2.8b. The station side (the chef's transfer request, the manager's confirmation) joins this
// project in step 2.8c; the placeholder in purchase-transfer.spec.ts stays until then.
//
// The admin records a purchase of 10 kg at 50 on the Purchases screen on top of 10 kg at 40 bought through the API: the
// draft is saved, confirmed, and the screen names the cost change (40.0000 to 45.0000). The same invoice number on a new
// draft is refused with the duplicate warning. The store balance reads 20 kg on the Stock screen, the moving average is
// 45.0000 through the API, and a reorder rule above the balance puts the item on the reorder list with its suggested
// quantity. The fixture lives in the isolated "E2E Test Branch" (e2e/support/inventory.ts), so the flow carries @live.

async function runPurchase(f: InventoryFixture, page: Page) {
  const item = f.ingredient.name_en;

  // the purchases screen lists the purchase made through the API, confirmed
  await openAs(page, f.admin, "en/admin/inventory/purchases/");
  await expect(page.getByRole("heading", { level: 1, name: "Purchases" })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole("table", { name: "Purchases" }).getByRole("row").filter({ hasText: f.first.invoice })).toContainText("Confirmed", { timeout: 15_000 });

  // a new draft with one line
  await page.getByRole("button", { name: "New purchase" }).click();
  const form = page.getByRole("region", { name: "New purchase" });
  await form.getByLabel("Supplier", { exact: true }).selectOption({ label: f.supplier.name });
  await form.getByLabel("Invoice number").fill(f.second.invoice);
  await form.getByLabel("Receiving location").selectOption({ label: f.store.name_en });
  await form.getByLabel("Item, line 1", { exact: true }).selectOption({ label: item });
  await form.getByLabel("Quantity, line 1", { exact: true }).fill(f.second.qty);
  await expect(form.getByLabel("Unit, line 1", { exact: true })).toHaveValue(String(f.ingredient.unitId));
  await form.getByLabel("Unit cost, line 1", { exact: true }).fill(f.second.unitCost);
  await form.getByRole("button", { name: "Save draft" }).click();

  // the draft opens with its total; nothing has moved yet
  const detail = page.getByRole("region", { name: `Purchase ${f.second.invoice}` });
  await expect(detail).toBeVisible({ timeout: 15_000 });
  await expect(detail).toContainText("Draft");
  await expect(detail).toContainText(f.second.total);
  expect((await balanceAt(f.admin, f.ingredient.id, f.store.id))?.on_hand).toBe(f.first.qty);

  // confirm: the stock moves and the screen names the cost change
  await detail.getByRole("button", { name: "Confirm purchase" }).click();
  await expect(detail).toContainText("Confirmed", { timeout: 15_000 });
  const changes = page.getByRole("region", { name: "Cost changes" });
  await expect(changes).toBeVisible();
  const change = changes.getByRole("row").filter({ hasText: item });
  await expect(change).toContainText(f.first.unitCost);
  await expect(change).toContainText(f.after.movingAverage);

  // the same invoice number again is refused with the duplicate warning, which can be allowed on purpose
  await page.getByRole("button", { name: "New purchase" }).click();
  const again = page.getByRole("region", { name: "New purchase" });
  await again.getByLabel("Supplier", { exact: true }).selectOption({ label: f.supplier.name });
  await again.getByLabel("Invoice number").fill(f.second.invoice);
  await again.getByLabel("Item, line 1", { exact: true }).selectOption({ label: item });
  await again.getByLabel("Quantity, line 1", { exact: true }).fill("1");
  await again.getByLabel("Unit cost, line 1", { exact: true }).fill("50");
  await again.getByRole("button", { name: "Save draft" }).click();
  await expect(again.getByRole("alert")).toContainText("already has a purchase with this invoice number", { timeout: 15_000 });
  await expect(again.getByRole("checkbox", { name: "This is a different delivery: allow the duplicate invoice number" })).toBeVisible();
  expect((await purchasesOf(f.admin, f.supplier.id)).filter((p) => p.invoice_no === f.second.invoice)).toHaveLength(1);

  // post-conditions through the API
  expect(await balanceAt(f.admin, f.ingredient.id, f.store.id)).toMatchObject({ on_hand: f.after.onHand });
  expect(await movingAverage(f.admin, f.ingredient.id)).toMatchObject({ moving_avg_cost: f.after.movingAverage, qty_basis: f.after.onHand });
}

async function runStockAndReorder(f: InventoryFixture, page: Page) {
  const item = f.ingredient.name_en;
  const sections = page.getByRole("navigation", { name: "Inventory sections" });

  // the stock screen: 20 kg at the store
  await sections.getByRole("link", { name: "Stock", exact: true }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Stock" })).toBeVisible();
  await page.getByLabel("Location", { exact: true }).selectOption({ label: f.store.name_en });
  await page.getByLabel("Find an item").fill(item);
  const row = page.getByRole("table", { name: "Stock at this place" }).getByRole("row").filter({ hasText: item });
  await expect(row).toContainText("20 kg", { timeout: 15_000 });

  // a rule above the balance puts the item on the reorder list with its suggested quantity
  await sections.getByRole("link", { name: "Reorder", exact: true }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Reorder" })).toBeVisible();
  const form = page.getByRole("region", { name: "New reorder rule" });
  await form.getByLabel("Item", { exact: true }).selectOption({ label: item });
  await form.getByLabel("Minimum quantity").fill("25");
  await form.getByLabel("Reorder quantity").fill("50");
  await form.getByRole("button", { name: "Save rule" }).click();
  await expect(page.getByRole("table", { name: "Reorder rules" }).getByRole("row").filter({ hasText: item })).toContainText("Whole branch", { timeout: 15_000 });
  const listed = page.getByRole("table", { name: "Reorder list" }).getByRole("row").filter({ hasText: item });
  await expect(listed).toBeVisible({ timeout: 15_000 });
  await expect(listed).toContainText("Low");
  await expect(listed).toContainText("20 kg");
  await expect(listed).toContainText("50 kg");

  const due = (await reorderListOf(f.admin)).find((r) => r.item.id === f.ingredient.id);
  expect(due).toMatchObject({ state: "low", suggested_qty: "50.0000", available: f.after.onHand });
}

test.describe("p2 purchase and reorder, admin side", () => {
  test("a purchase moves the balance and the moving average, and a reorder rule lists the item @live", async ({ browser }, testInfo) => {
    const credentials = requireAdmin(test.skip);
    const fixture = await buildInventoryFixture(credentials);
    const context = await browser.newContext({ baseURL: testInfo.project.use.baseURL, viewport: { width: 1280, height: 800 }, serviceWorkers: "block" });
    const page = await context.newPage();
    try {
      await runPurchase(fixture, page);
      await runStockAndReorder(fixture, page);
    } catch (error) {
      await testInfo.attach("admin.png", { body: await page.screenshot().catch(() => Buffer.alloc(0)), contentType: "image/png" });
      throw error;
    } finally {
      await context.close();
    }
  });
});
