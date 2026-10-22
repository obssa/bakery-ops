import { test, expect } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildCatalogFixture, costOf, stationOf, versionsOf } from "../support/catalog";
import { openAs } from "../support/pad";
import { requestsOf, type RequestView } from "../support/production";

// Phase 2 gate project p2-stockout-production (the file name lands in it), step 2.8d: the admin Catalog and planning flows.
// Three @live tests, each building its fixture through the admin API of the isolated "E2E Test Branch"
// (e2e/support/catalog.ts): publishing recipe version 2 and reading the cost and margin change, switching the overseer
// requirement of the cake room in People, and scheduling and assigning a request in the planner and finding it on /production.

test.describe("admin catalog and planning", () => {
  test("@live publish recipe version 2 and see the cost and margin change", async ({ page }) => {
    const f = await buildCatalogFixture(requireAdmin(test.skip));

    await openAs(page, f.admin, `en/admin/catalog/?item=${f.cake.id}`);
    await expect(page.getByRole("heading", { level: 1, name: f.cake.name_en })).toBeVisible({ timeout: 30_000 });

    // version 1: cost and margin sit beside the price
    const money = page.getByRole("region", { name: "Price and cost" });
    await expect(money).toContainText(f.cake.price, { timeout: 15_000 });
    await expect(money).toContainText(f.v1.costPerUnit);
    await expect(money).toContainText(`${f.v1.marginPct}%`);
    const history = page.getByRole("table", { name: "Version history" });
    await expect(history.getByRole("row").filter({ hasText: "Active" })).toHaveCount(1);

    // a new version: more flour, a change note, and a scaled preview for 6 pieces
    const form = page.getByRole("region", { name: "Publish a new version" });
    await form.getByLabel("Quantity, line 1", { exact: true }).fill(f.v2.kg);
    await form.getByLabel("Change note").fill("Richer cake");
    const preview = page.getByRole("region", { name: "Scaled preview" });
    await preview.getByLabel("Preview quantity").fill("6");
    await expect(preview.getByRole("row").filter({ hasText: f.ingredient.name_en })).toContainText("2.4");
    await expect(preview).toContainText("computed in this browser");
    await form.getByRole("button", { name: "Publish version" }).click();

    // version 2 is active, with its note; the cost and margin moved
    await expect(history.getByRole("row").filter({ hasText: "Richer cake" })).toContainText("Active", { timeout: 15_000 });
    await expect(money).toContainText(f.v2.costPerUnit, { timeout: 15_000 });
    await expect(money).toContainText(`${f.v2.marginPct}%`);
    const versions = await versionsOf(f.admin, f.cake.id);
    expect(versions.map((v) => [v.version, v.is_active])).toEqual([
      [2, true],
      [1, false],
    ]);
    expect(await costOf(f.admin, f.cake.id)).toMatchObject({ complete: true, cost_per_unit: f.v2.costPerUnit, margin_pct: f.v2.marginPct });

    // version against version
    const compare = page.getByRole("region", { name: "Compare costs" });
    await compare.getByLabel("Compare with version").selectOption({ label: "Version 1" });
    await expect(compare).toContainText(f.v1.costPerUnit);
    await expect(compare).toContainText(f.v2.costPerUnit);
    await expect(compare).toContainText("4.0000"); // 16.0000 less 12.0000

    // the same recipe again is refused, in the page language, and nothing is written
    await form.getByRole("button", { name: "Publish version" }).click();
    await expect(form.getByRole("alert")).toContainText("same as the active version", { timeout: 15_000 });
    expect(await versionsOf(f.admin, f.cake.id)).toHaveLength(2);
  });

  test("@live switch requires_overseer in People and see it stick", async ({ page }) => {
    const f = await buildCatalogFixture(requireAdmin(test.skip));
    await f.admin.patch(`/stations/${f.cakeRoom.id}`, { requires_overseer: false });
    try {
      await openAs(page, f.admin, "en/admin/people/");
      const stations = page.getByRole("region", { name: "Stations" });
      const toggle = stations.getByRole("checkbox", { name: `Require an overseer at ${f.cakeRoom.name_en}` });
      await expect(toggle).not.toBeChecked({ timeout: 30_000 });
      await toggle.check();
      await expect(stations.getByRole("status")).toContainText("saved", { timeout: 15_000 });
      expect((await stationOf(f.admin, f.cakeRoom.id)).requires_overseer).toBe(true);

      await page.reload();
      await expect(page.getByRole("region", { name: "Stations" }).getByRole("checkbox", { name: `Require an overseer at ${f.cakeRoom.name_en}` })).toBeChecked({ timeout: 30_000 });
    } finally {
      await f.admin.patch(`/stations/${f.cakeRoom.id}`, { requires_overseer: false });
    }
  });

  test("@live schedule and assign a request in the planner and see it on the board", async ({ page }) => {
    const f = await buildCatalogFixture(requireAdmin(test.skip));
    const tomorrow = new Date(Date.now() + 24 * 3600 * 1000);
    const pad = (n: number) => String(n).padStart(2, "0");
    const due = `${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}T04:00`;

    await openAs(page, f.admin, "en/admin/planner/");
    await expect(page.getByRole("heading", { level: 1, name: "Production planner" })).toBeVisible({ timeout: 30_000 });

    const form = page.getByRole("region", { name: "Schedule a request" });
    await form.getByLabel("Product").selectOption({ label: f.cake.name_en });
    await form.getByLabel("Quantity").fill("6");
    await form.getByLabel("Due").fill(due);
    await form.getByLabel("Priority").selectOption({ label: "High" });
    await form.getByLabel("Note").fill("for the morning rush");
    await form.getByRole("button", { name: "Schedule request" }).click();

    const row = page.getByRole("table", { name: "Open requests" }).getByRole("row").filter({ hasText: f.cake.name_en });
    await expect(row).toBeVisible({ timeout: 15_000 });
    await expect(row).toContainText("High");
    await expect(row).toContainText("Requested");

    // assign to the baker of the producing station
    await row.getByLabel(`Baker for ${f.cake.name_en}`).selectOption({ label: f.baker.name });
    await row.getByRole("button", { name: `Assign ${f.cake.name_en}` }).click();
    await expect(row).toContainText(f.baker.name, { timeout: 15_000 });

    const requests = await requestsOf(f.admin, f.cake.id);
    expect(requests).toHaveLength(1);
    const made = requests[0] as RequestView & { priority: string; due_at: string | null };
    expect(made).toMatchObject({ status: "requested", reason: "schedule", requested_qty: "6.0000", priority: "high" });
    expect(made.assigned_to?.id).toBe(f.baker.id);
    expect(made.due_at).not.toBeNull();

    // it is on the production board with its reason, priority and baker
    await openAs(page, f.admin, "en/production/");
    const card = page.getByRole("region", { name: "Requested", exact: true }).getByRole("article").filter({ hasText: f.cake.name_en });
    await expect(card).toBeVisible({ timeout: 30_000 });
    await expect(card).toContainText("Scheduled");
    await expect(card).toContainText("High priority");
    await expect(card).toContainText(f.baker.name);
  });
});
