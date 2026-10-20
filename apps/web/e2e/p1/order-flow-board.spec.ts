import { test, expect } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildStationFixture, sendOrder } from "../support/fixture";
import { openAs } from "../support/pad";

// Phase 1 gate project p1-order-flow, the admin Orders live board (step 1.6c). A waiter sends an order through the
// API; the admin, signed in on /admin/orders, sees it in the "Sent" column with its line, station, owner and table,
// opens its timeline, and finds it again through the table filter. The board is read-only apart from Void.
//
// The fixture is built through the admin API inside the isolated "E2E Test Branch" (e2e/support/fixture.ts), so the
// flow carries @live.

test.describe("p1 order flow: admin orders board", () => {
  test("a sent order shows on the live board with its timeline @live", async ({ page }) => {
    test.setTimeout(120_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildStationFixture(credentials, "kitchen");
    const product = fixture.product.name_en;

    await openAs(page, fixture.admin, "en/admin/orders/");
    await expect(page.getByRole("heading", { level: 1, name: "Orders" })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole("link", { name: "Orders", exact: true })).toHaveAttribute("aria-current", "page");

    // sent while the board is open: it arrives through the live feed, in the Sent column
    const order = await sendOrder(fixture);
    const sent = page.getByRole("region", { name: "Sent", exact: true });
    const card = sent.getByRole("article").filter({ hasText: product });
    await expect(card).toBeVisible({ timeout: 10_000 });
    await expect(card).toContainText(fixture.waiter.name);
    await expect(card).toContainText(`Table ${fixture.table.code}`);
    await expect(card).toContainText(fixture.station.name_en);

    // its timeline
    await card.getByRole("button", { name: `Open order ${order.order_no}`, exact: true }).click();
    const timeline = page.getByRole("region", { name: `Order ${order.order_no} timeline` });
    await expect(timeline).toContainText("Order created");
    await expect(timeline).toContainText("Order sent");

    // the filters: another status hides it, the right one and its table show it
    await page.getByLabel("Status").selectOption("ready");
    await expect(card).toHaveCount(0);
    await page.getByLabel("Status").selectOption("sent");
    await page.getByLabel("Table").selectOption({ label: fixture.table.code });
    await expect(sent.getByRole("article").filter({ hasText: product })).toBeVisible();

    // the same board in Amharic
    await page.getByRole("link", { name: "አማርኛ" }).click();
    await expect(page).toHaveURL(/\/app\/am\/admin\/orders\/$/);
    await expect(page.getByRole("heading", { level: 1, name: "ትዕዛዞች" })).toBeVisible();
  });
});
