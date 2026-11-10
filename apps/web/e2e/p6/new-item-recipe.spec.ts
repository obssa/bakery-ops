import { expect, test } from "@playwright/test";
import type { Api } from "../support/api";
import { live, requireAdmin, run } from "../support/env";
import { loginOnce, unitLookup } from "../support/fixture";
import { openAs } from "../support/pad";

// phase-6/new-item-recipe (Ramin, 2026-11-10: a product like a chicken sandwich needs its ingredients and how much of each, while it is
// added), in project p6-a11y. The new item page carries the recipe: a product made to order cannot be created without one (it could
// never be sold: the send holds each ingredient and refuses when one is short), and the recipe it is created with is its version 1.
// @live: against the deployed site in the E2E Test Branch, with two ingredients and a product unique to the run, all three switched off
// at the end whatever happens, so the live catalog and pads stay as they were. In CI only: a recipe the API refuses (a unit that does
// not convert) leaves the product created once, and its page opens the recipe drawer on the recipe as typed, saying why.

interface RecipeRead {
  version: number | null;
  yield_qty: string | null;
  lines: { ingredient_item_id: number; qty: string; unit_code: string }[];
}

async function ingredient(admin: Api, unitId: number, en: string, am: string): Promise<number> {
  return (await admin.post<{ id: number }>("/items", { kind: "ingredient", name_en: en, name_am: am, unit_id: unitId })).id;
}

async function switchOff(admin: Api, ids: (number | null)[]): Promise<void> {
  for (const id of ids) if (id !== null) await admin.patch(`/items/${id}`, { is_active: false }).catch(() => undefined);
}

async function productNamed(admin: Api, nameEn: string): Promise<number | null> {
  const items = await admin.get<{ data: { id: number; name_en: string }[] }>("/items", { kind: "product" }).catch(() => ({ data: [] }));
  return items.data.find((item) => item.name_en === nameEn)?.id ?? null;
}

test.describe("p6 new item recipe", () => {
  test("a product made to order is created with its ingredients and amounts, as its recipe's version 1 @live", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const { api: admin } = await loginOnce(credentials.phone, credentials.password);
    const unit = await unitLookup(admin);
    const bread = { en: `E2E bread roll ${run}`, am: `የሙከራ ዳቦ ${run}` };
    const chicken = { en: `E2E chicken ${run}`, am: `የሙከራ ዶሮ ${run}` };
    const nameEn = `E2E chicken sandwich ${run}`;
    const ids: (number | null)[] = [];
    try {
      ids.push(await ingredient(admin, unit("pcs"), bread.en, bread.am));
      ids.push(await ingredient(admin, unit("kg"), chicken.en, chicken.am));

      await openAs(page, admin, "en/admin/catalog/new/?kind=product");
      const form = page.getByRole("form", { name: "New product" });
      await expect(form.getByRole("radio", { name: "Made to order", exact: true })).toBeChecked({ timeout: 30_000 });
      await form.getByLabel("Name (English)").fill(nameEn);
      await form.getByLabel("Name (Amharic)").fill(`የሙከራ የዶሮ ሳንድዊች ${run}`);
      const units = form.getByLabel("Unit", { exact: true });
      const pcs = await units.getByRole("option", { name: /\(pcs\)$/ }).first().getAttribute("value");
      await units.selectOption(pcs ?? "");
      const recipe = form.getByRole("region", { name: "Recipe" });
      await expect(recipe).toContainText("For 1 pcs.");

      // without an ingredient the page refuses it, under the recipe
      await form.getByRole("button", { name: "Create product" }).click();
      await expect(recipe).toContainText("Add at least one ingredient with its quantity.");
      await expect(page).toHaveURL(/\/admin\/catalog\/new\//);

      // one bread roll and 120 g of chicken: choosing an ingredient sets its own unit, which the line can change
      await recipe.getByLabel("Ingredient, line 1").selectOption({ label: bread.en });
      await expect(recipe.getByLabel("Unit, line 1")).toHaveValue(String(unit("pcs")));
      await recipe.getByLabel("Quantity, line 1").fill("1");
      await recipe.getByRole("button", { name: "Add ingredient" }).click();
      await recipe.getByLabel("Ingredient, line 2").selectOption({ label: chicken.en });
      await expect(recipe.getByLabel("Unit, line 2")).toHaveValue(String(unit("kg")));
      await recipe.getByLabel("Unit, line 2").selectOption(String(unit("g")));
      await recipe.getByLabel("Quantity, line 2").fill("120");
      await form.getByRole("textbox", { name: "Price" }).fill("150.00");
      await form.getByRole("button", { name: "Create product" }).click();
      await expect(page.getByRole("status").filter({ hasText: `Created “${nameEn}” with its recipe.` })).toBeVisible({ timeout: 30_000 });
      const id = Number(new URL(page.url()).searchParams.get("item"));
      ids.push(id);

      const saved = await admin.get<RecipeRead>(`/items/${id}/recipe`);
      expect(saved.version).toBe(1);
      // quantities are decimal strings (ADR 0004): compared as the API writes them, trailing zeros dropped
      const plain = (qty: string | null): string => (qty ?? "").replace(/(\.\d*?)0+$/, "$1").replace(/\.$/, "");
      expect(plain(saved.yield_qty)).toBe("1");
      expect(saved.lines.map((line) => [line.ingredient_item_id, plain(line.qty), line.unit_code])).toEqual([
        [ids[0], "1", "pcs"],
        [ids[1], "120", "g"],
      ]);
    } finally {
      if (ids.length < 3) ids.push(await productNamed(admin, nameEn));
      await switchOff(admin, ids);
    }
  });

  test("a recipe the API refuses leaves the product created once, and its page opens the recipe on it, saying why", async ({ page }) => {
    test.skip(live, "CI only: it leaves a product without a recipe");
    test.setTimeout(150_000);
    const credentials = requireAdmin(test.skip);
    const { api: admin } = await loginOnce(credentials.phone, credentials.password);
    const unit = await unitLookup(admin);
    const flour = { en: `E2E flour ${run}`, am: `የሙከራ ዱቄት ${run}` };
    const nameEn = `E2E refused recipe ${run}`;
    await ingredient(admin, unit("kg"), flour.en, flour.am);

    await openAs(page, admin, "en/admin/catalog/new/?kind=product");
    const form = page.getByRole("form", { name: "New product" });
    await form.getByLabel("Name (English)").fill(nameEn);
    await form.getByLabel("Name (Amharic)").fill(`የሙከራ ያልተቀመጠ ${run}`);
    const units = form.getByLabel("Unit", { exact: true });
    const pcs = await units.getByRole("option", { name: /\(pcs\)$/ }).first().getAttribute("value");
    await units.selectOption(pcs ?? "");
    const recipe = form.getByRole("region", { name: "Recipe" });
    // flour is counted in kg: a quantity in pieces does not convert, which only the API knows
    await recipe.getByLabel("Ingredient, line 1").selectOption({ label: flour.en });
    await recipe.getByLabel("Unit, line 1").selectOption(String(unit("pcs")));
    await recipe.getByLabel("Quantity, line 1").fill("2");
    await form.getByRole("button", { name: "Create product" }).click();

    await expect(page.getByRole("alert").filter({ hasText: "its recipe was not saved" })).toBeVisible({ timeout: 30_000 });
    const drawer = page.getByRole("dialog", { name: "Recipe and cost" });
    await expect(drawer).toBeVisible();
    await expect(drawer.getByLabel("Ingredient, line 1")).toHaveValue(/\d+/);
    await expect(drawer.getByLabel("Quantity, line 1")).toHaveValue("2");
    const items = await admin.get<{ data: { name_en: string }[] }>("/items", { kind: "product" });
    expect(items.data.filter((item) => item.name_en === nameEn)).toHaveLength(1);
  });
});
