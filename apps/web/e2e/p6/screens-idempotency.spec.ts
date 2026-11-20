import { expect, test } from "@playwright/test";
import en from "../../messages/en.json";
import { uuid } from "../support/api";
import { live, requireAdmin } from "../support/env";
import { ensureStaff, findOrCreateLocation, findOrCreateStation, loginOnce } from "../support/fixture";
import { balanceAt, buildInventoryFixture, transfersOf } from "../support/inventory";
import { openAs } from "../support/pad";
import { ensureOpenShift } from "../support/shifts";

// phase-6/idempotency-keys (ADR 0030), on the CI stack in the E2E Test Branch:
//   - a refusal is not replayed once its cause is fixed: the manager confirms a transfer the store cannot cover (409, not enough), the store
//     buys more, and the same Confirm on the same screen goes through. Before, the form kept the refused request's key and the API replayed
//     the stored refusal until the page was reloaded;
//   - a request whose answer was lost is not asked twice, and a change made after it is not shown as sent: the first POST /transfers
//     reaches the API but its answer never reaches the page; the manager changes the quantity and sends again; the API answers with the
//     first transfer (its client_uuid is known) and the sheet says the change was not sent. One transfer exists, for the first quantity.
// CI only: it writes transfers and stock.

function today(): string {
  const now = new Date();
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

test.describe("screens: idempotency keys", () => {
  test.skip(live, "CI only: the flows write transfers and move stock in the E2E branch");

  test("a confirm refused for want of stock goes through on the same tap once the store has bought more", async ({ page }) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    const f = await buildInventoryFixture(credentials);
    const bakery = await findOrCreateStation(f.admin, "bakery");
    const bakeryPlace = await findOrCreateLocation(f.admin, bakery);
    await ensureOpenShift(f.admin, bakery);
    const managerStaff = await ensureStaff(f.admin, credentials.password, "manager");
    const { api: manager } = await loginOnce(managerStaff.phone, managerStaff.password, f.branchId);
    const note = `more than the store holds ${f.ingredient.name_en}`;
    // 12 kg asked of a store that holds 10 kg: a request is allowed, its confirm is refused until the store has more
    await f.admin.post("/transfers", { client_uuid: uuid(), item_id: f.ingredient.id, to_location_id: bakeryPlace.id, from_location_id: f.store.id, qty: "12000", note });

    await openAs(page, manager, "en/admin/inventory/transfers/");
    await expect(page.getByRole("heading", { level: 1, name: en.inventory.transfers.title })).toBeVisible({ timeout: 30_000 });
    const row = page.getByRole("row").filter({ hasText: note });
    const confirm = row.getByRole("button", { name: en.inventory.transfers.confirm_named.replace("{item}", f.ingredient.name_en) });
    await confirm.click({ timeout: 30_000 });
    // the refusal shows under its row
    const refusal = page.locator("tbody").getByRole("alert");
    await expect(refusal).toBeVisible({ timeout: 15_000 });
    const [asked] = (await transfersOf(f.admin, f.ingredient.id)).filter((one) => one.note === note);
    expect(asked).toMatchObject({ status: "requested" });

    // the store buys 5 kg more; the refusal's cause is gone
    const draft = await f.admin.post<{ id: number }>("/purchases", {
      client_uuid: uuid(),
      supplier_id: f.supplier.id,
      invoice_no: `E2E-${f.ingredient.id}-MORE`,
      purchase_date: today(),
      location_id: f.store.id,
      lines: [{ client_uuid: uuid(), item_id: f.ingredient.id, qty: "5.0000", unit_id: f.ingredient.unitId, unit_cost: "40.0000" }],
    });
    await f.admin.post(`/purchases/${draft.id}/confirm`);
    expect((await balanceAt(f.admin, f.ingredient.id, f.store.id))?.on_hand).toBe("15000.0000");

    // the same button, no reload: a new request the server checks again, not the stored refusal
    await confirm.click();
    await expect.poll(async () => (await transfersOf(f.admin, f.ingredient.id)).find((one) => one.id === asked!.id)?.status, { timeout: 15_000 }).toBe("confirmed");
    await expect(refusal).toHaveCount(0);
    expect((await balanceAt(f.admin, f.ingredient.id, f.store.id))?.on_hand).toBe("3000.0000");
    expect((await balanceAt(f.admin, f.ingredient.id, bakeryPlace.id))?.on_hand).toBe("12000.0000");
  });

  test("a request whose answer was lost is asked once, and a change sent after it is said not to be sent", async ({ page }) => {
    test.setTimeout(240_000);
    const t = en.transfer;
    const credentials = requireAdmin(test.skip);
    const f = await buildInventoryFixture(credentials);
    const bakery = await findOrCreateStation(f.admin, "bakery");
    const bakeryPlace = await findOrCreateLocation(f.admin, bakery);
    await ensureOpenShift(f.admin, bakery);
    const managerStaff = await ensureStaff(f.admin, credentials.password, "manager");
    const { api: manager } = await loginOnce(managerStaff.phone, managerStaff.password, f.branchId);
    const note = `answer lost ${f.ingredient.name_en}`;

    // the first POST /transfers reaches the API, its answer never reaches the page (a dropped connection)
    let lost = false;
    await page.route(/\/api\/v1\/transfers$/, async (route) => {
      if (route.request().method() !== "POST" || lost) return route.continue();
      lost = true;
      await route.fetch();
      await route.abort("failed");
    });

    await openAs(page, manager, "en/admin/inventory/transfers/");
    await page.getByRole("button", { name: en.inventory.transfers.new, exact: true }).click({ timeout: 30_000 });
    const sheet = page.getByRole("dialog", { name: en.inventory.transfers.new });
    await sheet.getByLabel(t.to_label, { exact: true }).selectOption(String(bakeryPlace.id));
    await sheet.getByLabel(t.find_item, { exact: true }).fill(f.ingredient.name_en);
    await sheet.getByRole("radio", { name: f.ingredient.name_en }).check({ timeout: 15_000 });
    await sheet.getByLabel("Quantity (g)").fill("1000");
    await sheet.getByLabel(t.note).fill(note);
    await sheet.getByRole("button", { name: t.send }).click();
    await expect(sheet.getByRole("alert")).toBeVisible({ timeout: 30_000 });
    expect(lost).toBe(true);
    // the API did write it
    await expect.poll(async () => (await transfersOf(f.admin, f.ingredient.id)).filter((one) => one.note === note).length, { timeout: 15_000 }).toBe(1);

    // the person changes the quantity and sends again: the API answers with the first request, and the sheet says the change was not sent
    await sheet.getByLabel("Quantity (g)").fill("1500");
    await sheet.getByRole("button", { name: t.send }).click();
    await expect(sheet.getByRole("status")).toContainText("1,000 g", { timeout: 15_000 });
    await expect(sheet.getByTestId("transfer-sent-earlier")).toHaveText(t.sent_earlier.replace("{qty}", "1,000 g"));
    const rows = (await transfersOf(f.admin, f.ingredient.id)).filter((one) => one.note === note);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatchObject({ status: "requested", qty: "1000.0000" });
  });
});
