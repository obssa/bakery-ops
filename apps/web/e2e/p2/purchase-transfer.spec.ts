import { test, expect, type Browser, type Page } from "@playwright/test";
import { MULTI_CONTEXT_TEST_MS, requireAdmin } from "../support/env";
import { balanceAt, buildTransferFixture, ledgerOf, movingAverage, stockKitchen, transfersOf, type TransferFixture } from "../support/inventory";
import { retireStaleIngredients } from "../support/fixture";
import { uuid } from "../support/api";
import { openAs } from "../support/pad";

// Phase 2 gate project p2-purchase-transfer (manifest phases.2.gate.criteria; docs/phases/phase-2-plan.md section 7), the
// whole flow of step 2.8c (the admin screens of 2.8b are exercised by purchase-admin.spec.ts in the same project).
//
// The admin buys 10 kg at 50 on top of the 10 kg at 40 the fixture bought, through the API: the store balance rises to 20000 g
// (the ledger keeps the base unit) and the moving average becomes 0.0450 a gram. The chef, on /kds/kitchen, finds the
// ingredient in the stock strip (1000 g, Low), taps "Request transfer" and asks the store for 5000 g. Nothing has moved. The
// manager, who keeps the store, confirms it in the admin Transfers screen: the store falls to 15000 g, the kitchen rises to
// 6000 g, and the ledger holds two `transfer` rows (-5000 and +5000) that share one reference. A second request is rejected
// with a reason on the same screen and nothing moves. The fixture lives in the isolated "E2E Test Branch"
// (e2e/support/inventory.ts), so the flow carries @live.

const TABLET = { width: 1280, height: 800 };

async function runPurchase(f: TransferFixture) {
  expect((await balanceAt(f.admin, f.ingredient.id, f.store.id))?.on_hand).toBe(f.first.onHand);
  const draft = await f.admin.post<{ id: number }>("/purchases", {
    client_uuid: uuid(),
    supplier_id: f.supplier.id,
    invoice_no: f.second.invoice,
    purchase_date: new Date().toISOString().slice(0, 10),
    location_id: f.store.id,
    lines: [{ client_uuid: uuid(), item_id: f.ingredient.id, qty: "10.0000", unit_id: f.ingredient.unitId, unit_cost: "50.0000" }],
  });
  await f.admin.post(`/purchases/${draft.id}/confirm`);
  expect(await balanceAt(f.admin, f.ingredient.id, f.store.id)).toMatchObject({ on_hand: f.after.onHand });
  expect(await movingAverage(f.admin, f.ingredient.id)).toMatchObject({ moving_avg_cost: f.after.movingAverage });
}

/** The chef asks the store for `qty` of the fixture ingredient from the stock strip. */
async function requestFromStrip(f: TransferFixture, chef: Page, qty: string, note: string) {
  const item = f.ingredient.name_en;
  const row = chef.getByRole("region", { name: "Stock strip" }).getByRole("listitem").filter({ hasText: item });
  await row.getByRole("button", { name: `Request transfer of ${item}` }).click();
  const sheet = chef.getByRole("dialog", { name: `Request transfer: ${item}` });
  await expect(sheet).toBeVisible();
  await sheet.getByLabel("Quantity (g)").fill(qty);
  await sheet.getByLabel("Note").fill(note);
  await sheet.getByRole("button", { name: "Send request" }).click();
  await expect(sheet.getByRole("status")).toContainText("Transfer requested", { timeout: 15_000 });
  await sheet.getByRole("button", { name: "Close" }).click();
  await expect(sheet).toHaveCount(0);
}

async function runChefAsks(f: TransferFixture, chef: Page) {
  const item = f.ingredient.name_en;
  await openAs(chef, f.chef.api, "en/kds/kitchen/");
  await expect(chef.getByRole("heading", { level: 1, name: "Kitchen" })).toBeVisible({ timeout: 30_000 });

  // the strip lists the ingredient at the kitchen with its flag
  const strip = chef.getByRole("region", { name: "Stock strip" });
  const row = strip.getByRole("listitem").filter({ hasText: item });
  await expect(row).toBeVisible({ timeout: 30_000 });
  await expect(row).toContainText(f.kitchenStart.onHandText);
  await expect(row).toContainText("Low");

  await requestFromStrip(f, chef, f.ask.qty, f.ask.note);

  // a requested transfer from the store to the kitchen, and nothing has moved
  const [asked] = await transfersOf(f.admin, f.ingredient.id);
  expect(asked).toMatchObject({ status: "requested", qty: `${f.ask.qty}.0000`, note: f.ask.note });
  expect(asked.from_location.id).toBe(f.store.id);
  expect(asked.to_location.id).toBe(f.kitchenLocation.id);
  expect((await balanceAt(f.admin, f.ingredient.id, f.store.id))?.on_hand).toBe(f.after.onHand);
  expect((await balanceAt(f.admin, f.ingredient.id, f.kitchenLocation.id))?.on_hand).toBe(f.kitchenStart.onHand);
  return asked.id;
}

async function runManagerConfirms(f: TransferFixture, manager: Page, transferId: number) {
  const item = f.ingredient.name_en;
  await openAs(manager, f.manager.api, `en/admin/inventory/transfers/?transfer=${transferId}`);
  await expect(manager.getByRole("heading", { level: 1, name: "Transfers" })).toBeVisible({ timeout: 30_000 });
  const row = manager.getByRole("row").filter({ hasText: item });
  await expect(row).toContainText(f.ask.text, { timeout: 15_000 });
  await expect(row).toContainText("Requested");
  await row.getByRole("button", { name: `Confirm transfer ${item}` }).click();
  await expect(row).toContainText("Confirmed", { timeout: 15_000 });

  // the stock moved: the store down, the kitchen up, two transfer rows with one reference
  expect((await balanceAt(f.admin, f.ingredient.id, f.store.id))?.on_hand).toBe("15000.0000");
  expect((await balanceAt(f.admin, f.ingredient.id, f.kitchenLocation.id))?.on_hand).toBe("6000.0000");
  const out = (await ledgerOf(f.admin, f.ingredient.id, f.store.id)).filter((m) => m.reason === "transfer");
  const into = (await ledgerOf(f.admin, f.ingredient.id, f.kitchenLocation.id)).filter((m) => m.reason === "transfer");
  expect(out.map((m) => m.qty)).toEqual(["-5000.0000"]);
  expect(into.map((m) => m.qty)).toEqual(["5000.0000"]);
  expect(out[0].ref_id).not.toBeNull();
  expect(out[0].ref_id).toBe(into[0].ref_id);
  expect(out[0].ref_type).toBe(into[0].ref_type);
}

async function runSecondRejected(f: TransferFixture, chef: Page, manager: Page) {
  const item = f.ingredient.name_en;
  const note = `one more ${f.ingredient.name_en}`; // run-unique: earlier runs leave their transfers in the E2E branch
  const onStrip = chef.getByRole("region", { name: "Stock strip" }).getByRole("listitem").filter({ hasText: item });
  if (await onStrip.count()) {
    await requestFromStrip(f, chef, "2000", note);
  } else {
    // the strip is cut to a few rows and other items may rank ahead of this one: the flow does not depend on its rank, the first
    // request already went through the strip
    await f.chef.api.post("/transfers", { client_uuid: uuid(), item_id: f.ingredient.id, to_location_id: f.kitchenLocation.id, qty: "2000", note });
  }
  const second = (await transfersOf(f.admin, f.ingredient.id)).find((t) => t.status === "requested" && t.note === note);
  if (!second) throw new Error("the second request is not on the list");

  await openAs(manager, f.manager.api, `en/admin/inventory/transfers/?transfer=${second.id}`);
  const row = manager.getByRole("row").filter({ hasText: note });
  await expect(row).toContainText("Requested", { timeout: 30_000 });
  await row.getByRole("button", { name: `Reject transfer ${item}` }).click();
  const dialog = manager.getByRole("dialog", { name: "Reject this transfer" });
  await dialog.getByLabel("Reason").fill("not today");
  await dialog.getByRole("button", { name: "Reject transfer" }).click();
  await expect(row).toContainText("Rejected", { timeout: 15_000 });
  await expect(row).toContainText("not today");

  const after = (await transfersOf(f.admin, f.ingredient.id)).find((t) => t.id === second.id);
  expect(after).toMatchObject({ status: "rejected", reject_reason: "not today" });
  expect((await balanceAt(f.admin, f.ingredient.id, f.store.id))?.on_hand).toBe("15000.0000");
  expect((await balanceAt(f.admin, f.ingredient.id, f.kitchenLocation.id))?.on_hand).toBe("6000.0000");
}

async function openContexts(browser: Browser, baseURL: string | undefined) {
  const make = () => browser.newContext({ baseURL, viewport: TABLET, serviceWorkers: "block" });
  const contexts = await Promise.all([make(), make()]);
  const [chef, manager] = await Promise.all(contexts.map((context) => context.newPage()));
  return { contexts, pages: { chef, manager } };
}

test.describe("p2 purchase and transfer", () => {
  test("a purchase raises the store, and a transfer asked for in the kitchen strip moves stock once the store confirms it @live", async ({ browser }, testInfo) => {
    test.setTimeout(MULTI_CONTEXT_TEST_MS);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildTransferFixture(credentials);
    await retireStaleIngredients(fixture.admin);
    const { contexts, pages } = await openContexts(browser, testInfo.project.use.baseURL);
    try {
      await runPurchase(fixture);
      await stockKitchen(fixture);
      const transferId = await runChefAsks(fixture, pages.chef);
      await runManagerConfirms(fixture, pages.manager, transferId);
      await runSecondRejected(fixture, pages.chef, pages.manager);
    } catch (error) {
      for (const [name, page] of Object.entries(pages)) {
        await testInfo.attach(`${name}.png`, { body: await page.screenshot().catch(() => Buffer.alloc(0)), contentType: "image/png" });
      }
      throw error;
    } finally {
      await Promise.all(contexts.map((context) => context.close()));
    }
  });
});
