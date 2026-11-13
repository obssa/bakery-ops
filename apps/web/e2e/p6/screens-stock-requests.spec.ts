import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test, type Locator, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { expectAccessible, fontsReady, settledAnimations } from "../support/a11y";
import { live, requireAdmin } from "../support/env";
import { buildWaiter, ensureStaff, findOrCreateLocation, findOrCreateStation, loginOnce } from "../support/fixture";
import { balanceAt, buildInventoryFixture, transfersOf, type InventoryFixture } from "../support/inventory";
import { openAs } from "../support/pad";
import { ensureOpenShift } from "../support/shifts";

// phase-6/stock-requests (Ramin: the kitchen display's stock strip shows only 8 ingredients and there was no way to ask for anything else, and
// no way to start a transfer in the admin). On the CI stack, in the E2E Test Branch:
//   - the manager starts a transfer from Admin > Inventory > Transfers ("New transfer"): a run-unique ingredient from the store to the bakery,
//     the source's figure shown for it; the row lands in Requested and the manager confirms it there, and the stock moves;
//   - the chef, on the kitchen display, asks for that ingredient (which the kitchen does not hold) through "Request stock" at the end of the
//     strip, with the kitchen fixed as the destination; the request waits for a confirm and nothing moves;
//   - the bread baker finds the same button on the production board, the bakery fixed as the destination;
//   - a waiter (no transfers.request) sees neither button.
// Captures at 1440 x 900 and 390 x 844 in English and Amharic with axe, and no sideways scroll. CI only: it writes transfers and stock.

const SHOTS = path.join("test-results", "screens");
const DESKTOP = { width: 1440, height: 900 };
const PHONE = { width: 390, height: 844 };

type Named = { id: number; name_en: string; name_am: string };

async function capture(page: Page, name: string): Promise<void> {
  await fontsReady(page);
  await settledAnimations(page);
  mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: true });
}

async function noSidewaysScroll(page: Page, what: string): Promise<void> {
  const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
  expect(scroll, `${what} scrolls sideways`).toBeLessThanOrEqual(client);
}

/** Finds the item in the sheet's picker by its run-unique name in the page language (the search reads both languages) and picks it. */
async function pickItem(sheet: Locator, name: string, findLabel: string): Promise<void> {
  await sheet.getByLabel(findLabel, { exact: true }).fill(name);
  const radio = sheet.getByRole("radio", { name });
  await expect(radio).toHaveCount(1, { timeout: 15_000 });
  await radio.check();
}

async function placeNamed(admin: InventoryFixture["admin"], id: number): Promise<Named> {
  const found = (await admin.get<{ data: Named[] }>("/locations")).data.find((location) => location.id === id);
  if (!found) throw new Error(`no location ${id}`);
  return found;
}

test.describe("screens: stock requests", () => {
  test.skip(live, "CI only: the flows write transfers and move stock in the E2E branch, and the captures are for review");

  test("the manager starts a transfer from the store to the bakery in Admin > Transfers and confirms it", async ({ page }) => {
    test.setTimeout(240_000);
    const t = en.transfer;
    const credentials = requireAdmin(test.skip);
    const f = await buildInventoryFixture(credentials);
    const bakery = await findOrCreateStation(f.admin, "bakery");
    const bakeryPlace = await placeNamed(f.admin, (await findOrCreateLocation(f.admin, bakery)).id);
    await ensureOpenShift(f.admin, bakery);
    const managerStaff = await ensureStaff(f.admin, credentials.password, "manager");
    const { api: manager } = await loginOnce(managerStaff.phone, managerStaff.password, f.branchId);
    const item = f.ingredient.name_en;
    const note = `for the morning bake ${item}`;

    await page.setViewportSize(DESKTOP);
    await openAs(page, manager, "en/admin/inventory/transfers/");
    await expect(page.getByRole("heading", { level: 1, name: en.inventory.transfers.title })).toBeVisible({ timeout: 30_000 });
    await page.getByRole("button", { name: en.inventory.transfers.new, exact: true }).click();
    const sheet = page.getByRole("dialog", { name: en.inventory.transfers.new });
    await expect(sheet).toBeVisible();

    // the store is the source by default; the bakery is one of the places the manager may ask into (stations.all), never the source
    const from = sheet.getByLabel(t.from, { exact: true });
    await expect(from).toHaveValue(String(f.store.id));
    const to = sheet.getByLabel(t.to_label, { exact: true });
    await expect(to.locator(`option[value="${f.store.id}"]`)).toHaveCount(0);
    await to.selectOption(String(bakeryPlace.id));
    await pickItem(sheet, item, t.find_item);
    await expect(sheet.getByTestId("transfer-source-has")).toHaveText(`${f.store.name_en} has 10,000 g.`);
    await sheet.getByLabel("Quantity (g)").fill("2500");
    await sheet.getByLabel(t.note).fill(note);
    await capture(page, "stock-requests-admin-1440x900-en");
    await expectAccessible(page, "Admin > Transfers, the new transfer sheet (en)");

    // a quantity that is not a decimal string is refused before anything is sent
    await sheet.getByLabel("Quantity (g)").fill("2,5");
    await sheet.getByRole("button", { name: t.send }).click();
    await expect(sheet.getByText(t.need_qty)).toBeVisible();
    await sheet.getByLabel("Quantity (g)").fill("2500");
    await sheet.getByRole("button", { name: t.send }).click();
    await expect(sheet.getByRole("status")).toContainText(t.sent_from.replace("{from}", f.store.name_en), { timeout: 15_000 });
    await sheet.getByRole("button", { name: t.close, exact: true }).click();
    await expect(sheet).toHaveCount(0);

    // the row is in Requested; nothing has moved until it is confirmed
    const [asked] = (await transfersOf(f.admin, f.ingredient.id)).filter((row) => row.note === note);
    expect(asked).toMatchObject({ status: "requested", qty: "2500.0000" });
    expect(asked!.from_location.id).toBe(f.store.id);
    expect(asked!.to_location.id).toBe(bakeryPlace.id);
    expect((await balanceAt(f.admin, f.ingredient.id, f.store.id))?.on_hand).toBe("10000.0000");
    const row = page.getByRole("row").filter({ hasText: note });
    await expect(row).toContainText(en.inventory.transfer_status.requested, { timeout: 15_000 });
    await row.getByRole("button", { name: en.inventory.transfers.confirm_named.replace("{item}", item) }).click();
    await expect.poll(async () => (await transfersOf(f.admin, f.ingredient.id)).find((one) => one.id === asked!.id)?.status, { timeout: 15_000 }).toBe("confirmed");
    expect((await balanceAt(f.admin, f.ingredient.id, f.store.id))?.on_hand).toBe("7500.0000");
    expect((await balanceAt(f.admin, f.ingredient.id, bakeryPlace.id))?.on_hand).toBe("2500.0000");
    await openAs(page, manager, `en/admin/inventory/transfers/?transfer=${asked!.id}`);
    await expect(page.getByRole("row").filter({ hasText: note })).toContainText(en.inventory.transfer_status.confirmed, { timeout: 30_000 });

    // the sheet at 390, and in Amharic
    await page.setViewportSize(PHONE);
    await openAs(page, manager, "en/admin/inventory/transfers/");
    await page.getByRole("button", { name: en.inventory.transfers.new, exact: true }).click({ timeout: 30_000 });
    await pickItem(page.getByRole("dialog", { name: en.inventory.transfers.new }), item, t.find_item);
    await noSidewaysScroll(page, "the new transfer sheet at 390 px");
    await capture(page, "stock-requests-admin-390x844-en");
    for (const [size, viewport] of [["1440x900", DESKTOP], ["390x844", PHONE]] as const) {
      await page.setViewportSize(viewport);
      await openAs(page, manager, "am/admin/inventory/transfers/");
      await expect(page.getByRole("heading", { level: 1, name: am.inventory.transfers.title })).toBeVisible({ timeout: 30_000 });
      await page.getByRole("button", { name: am.inventory.transfers.new, exact: true }).click();
      const amSheet = page.getByRole("dialog", { name: am.inventory.transfers.new });
      await pickItem(amSheet, f.ingredient.name_am, am.transfer.find_item);
      await expect(amSheet.getByTestId("transfer-source-has")).toHaveText(am.transfer.place_has.replace("{place}", f.store.name_am).replace("{qty}", "7,500 g"));
      await noSidewaysScroll(page, `the new transfer sheet in Amharic at ${size}`);
      await capture(page, `stock-requests-admin-${size}-am`);
      await expectAccessible(page, `Admin > Transfers, the new transfer sheet (am, ${size})`);
    }
  });

  test("the chef asks for an ingredient the kitchen does not hold through Request stock at the end of the strip", async ({ page }) => {
    test.setTimeout(240_000);
    const t = en.transfer;
    const credentials = requireAdmin(test.skip);
    const f = await buildInventoryFixture(credentials);
    const kitchen = await findOrCreateStation(f.admin, "kitchen");
    const kitchenPlace = await placeNamed(f.admin, (await findOrCreateLocation(f.admin, kitchen)).id);
    await ensureOpenShift(f.admin, kitchen);
    const chefStaff = await ensureStaff(f.admin, credentials.password, "chef");
    await f.admin.put(`/users/${chefStaff.id}/stations`, { assignments: [{ station_id: kitchen.id, can_count: true, is_overseer: false }] });
    const { api: chef } = await loginOnce(chefStaff.phone, chefStaff.password, f.branchId);
    const item = f.ingredient.name_en;
    const note = `for the stew ${item}`;
    expect(await balanceAt(f.admin, f.ingredient.id, kitchenPlace.id)).toBeNull();

    await page.setViewportSize(DESKTOP);
    await openAs(page, chef, "en/kds/kitchen/");
    await expect(page.getByRole("heading", { level: 1, name: "Kitchen" })).toBeVisible({ timeout: 30_000 });
    const strip = page.getByRole("region", { name: en.stations.strip_title });
    // the ingredient is not in the strip (the kitchen holds none of it); the strip keeps its rows and the button leads to the rest
    await expect(strip.getByRole("listitem").filter({ hasText: item })).toHaveCount(0);
    expect(await strip.getByRole("listitem").count()).toBeLessThanOrEqual(8);
    await strip.getByRole("button", { name: en.stations.request_stock, exact: true }).click({ timeout: 30_000 });
    const sheet = page.getByRole("dialog", { name: en.stations.request_stock });
    await expect(sheet).toBeVisible();
    await expect(sheet).toContainText(t.to.replace("{place}", kitchenPlace.name_en));
    await expect(sheet.getByLabel(t.to_label, { exact: true })).toHaveCount(0);
    await expect(sheet.getByLabel(t.from, { exact: true })).toHaveValue(String(f.store.id));
    await pickItem(sheet, item, t.find_item);
    await expect(sheet.getByTestId("transfer-source-has")).toHaveText(`${f.store.name_en} has 10,000 g.`);
    await sheet.getByLabel("Quantity (g)").fill("1000");
    await sheet.getByLabel(t.note).fill(note);
    await capture(page, "stock-requests-kitchen-1440x900-en");
    await expectAccessible(page, "the kitchen display, the request stock sheet (en)");
    await sheet.getByRole("button", { name: t.send }).click();
    await expect(sheet.getByRole("status")).toContainText(t.sent_from.replace("{from}", f.store.name_en), { timeout: 15_000 });

    // a request from the store to the kitchen, by the chef, waiting for a confirm: nothing has moved
    const asked = (await transfersOf(f.admin, f.ingredient.id)).find((row) => row.note === note);
    expect(asked).toMatchObject({ status: "requested", qty: "1000.0000" });
    expect(asked!.from_location.id).toBe(f.store.id);
    expect(asked!.to_location.id).toBe(kitchenPlace.id);
    expect(await balanceAt(f.admin, f.ingredient.id, kitchenPlace.id)).toBeNull();
    await sheet.getByRole("button", { name: t.close, exact: true }).click();
    await expect(sheet).toHaveCount(0);

    await page.setViewportSize(PHONE);
    await page.reload();
    await page.getByRole("region", { name: en.stations.strip_title }).getByRole("button", { name: en.stations.request_stock, exact: true }).click({ timeout: 30_000 });
    await pickItem(page.getByRole("dialog", { name: en.stations.request_stock }), item, t.find_item);
    await noSidewaysScroll(page, "the request stock sheet at 390 px");
    await capture(page, "stock-requests-kitchen-390x844-en");
    for (const [size, viewport] of [["1440x900", DESKTOP], ["390x844", PHONE]] as const) {
      await page.setViewportSize(viewport);
      await openAs(page, chef, "am/kds/kitchen/");
      const amStrip = page.getByRole("region", { name: am.stations.strip_title });
      await amStrip.getByRole("button", { name: am.stations.request_stock, exact: true }).click({ timeout: 30_000 });
      const amSheet = page.getByRole("dialog", { name: am.stations.request_stock });
      await expect(amSheet).toContainText(am.transfer.to.replace("{place}", kitchenPlace.name_am));
      await pickItem(amSheet, f.ingredient.name_am, am.transfer.find_item);
      await noSidewaysScroll(page, `the request stock sheet in Amharic at ${size}`);
      await capture(page, `stock-requests-kitchen-${size}-am`);
      await expectAccessible(page, `the kitchen display, the request stock sheet (am, ${size})`);
    }
  });

  test("the bread baker finds Request stock on the production board, the bakery fixed as the destination", async ({ page }) => {
    test.setTimeout(120_000);
    const credentials = requireAdmin(test.skip);
    const { api: admin, login } = await loginOnce(credentials.phone, credentials.password);
    const bakery = await findOrCreateStation(admin, "bakery");
    const bakeryPlace = await placeNamed(admin, (await findOrCreateLocation(admin, bakery)).id);
    const bakerStaff = await ensureStaff(admin, credentials.password, "bread_baker");
    await admin.put(`/users/${bakerStaff.id}/stations`, { assignments: [{ station_id: bakery.id, can_count: true, is_overseer: false }] });
    const { api: baker } = await loginOnce(bakerStaff.phone, bakerStaff.password, login.user.branch_id);

    await page.setViewportSize(DESKTOP);
    await openAs(page, baker, "en/production/");
    await page.getByRole("button", { name: en.production.request_stock, exact: true }).click({ timeout: 30_000 });
    const sheet = page.getByRole("dialog", { name: en.production.request_stock });
    await expect(sheet).toContainText(en.transfer.to.replace("{place}", bakeryPlace.name_en), { timeout: 15_000 });
    await expect(sheet.getByLabel(en.transfer.to_label, { exact: true })).toHaveCount(0);
    await capture(page, "stock-requests-production-1440x900-en");
    await expectAccessible(page, "the production board, the request stock sheet (en)");
  });

  test("a waiter, who may not request transfers, sees no New transfer and no Request stock", async ({ page }) => {
    test.setTimeout(120_000);
    const credentials = requireAdmin(test.skip);
    const { waiter } = await buildWaiter(credentials);
    await page.setViewportSize(DESKTOP);
    for (const where of ["en/admin/inventory/transfers/", "en/kds/kitchen/", "en/production/"]) {
      await openAs(page, waiter.api, where);
      await expect(page.getByText(en.admin.no_access)).toBeVisible({ timeout: 30_000 });
      await expect(page.getByRole("button", { name: en.inventory.transfers.new, exact: true })).toHaveCount(0);
      await expect(page.getByRole("button", { name: en.stations.request_stock, exact: true })).toHaveCount(0);
    }
  });
});
