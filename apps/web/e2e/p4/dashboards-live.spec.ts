import { expect, test, type Locator } from "@playwright/test";
import { createHash } from "node:crypto";
import { rebuildDays, shiftDay } from "../support/analytics";
import { ApiFailure } from "../support/api";
import { live, requireAdmin, run } from "../support/env";
import { businessToday, loginOnce } from "../support/fixture";
import { openAs } from "../support/pad";
import { buildPayFixture, payBatch, removeChannel, serveAll, takeawayOrder, tidyOrder } from "../support/payments";

// Phase 4 gate project p4-dashboards (docs/phases/phase-4-plan.md section 7), step 4.5e: the analytics dashboards in the browser.
//
// 1. @live: the cashier sells two of a run-unique display-case product (125.00 each) and is paid 250.00 on the run's bank transfer (a
//    reference, no photo). The admin rebuilds today inline (POST /analytics/rebuild {from: today, to: today, sync: true}, what the live E2E
//    uses: no wait for the queue's touch of today or the nightly run). The overview's first figure paints from the overview query alone; the
//    time is logged against the soft budget of 2500 ms (a measure, not a failure: the hard budget is p4-dashboards' 90-day seed in 4.6b).
//    The payment methods page shows exactly the run's channel: one receipt, 250.00, no photo (its count opens the payments list of that
//    method without a photo). The product page shows the run's product: two sold, 250.00, new (its is_new_until is set two weeks out),
//    its first week since launch, and "cost incomplete" instead of a margin (a display-case product made in the house has no cost of
//    sales yet, 4.4a). The cashier's own page (analytics.view_own) holds only the cashier's rows, and the cashier's Analytics tab leads
//    there.
// 2. CI only: a range of past days nobody built is named as not built, and the admin's Rebuild builds it inline (on the live host this
//    would rebuild days of the shared E2E branch that nothing needs; the rebuild endpoint itself is proved there by flow 1).
//
// Fixtures through the admin API of the isolated "E2E Test Branch" (e2e/support/payments.ts, e2e/support/analytics.ts), run-unique names,
// assertions only on the run's own channel and product (the branch keeps every run's data).

const SOFT_BUDGET_MS = 2500;

/** A row of a table by its row header (the inner locator is the page's: it is matched inside each row). */
function rowOf(table: Locator, name: string): Locator {
  return table.getByRole("row").filter({ has: table.page().getByRole("rowheader", { name, exact: true }) });
}

test.describe("p4 analytics dashboards", () => {
  test("after a rebuild of today the payment methods page shows exactly the run's channel and the product page the run's product @live", async ({ page, browser }, testInfo) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildPayFixture(credentials, "125.00");
    const { wallet, bank } = fixture.channels;
    let orderId: number | null = null;
    try {
      // the run's product is new for two weeks (Catalog's is_new_until, in the branch's calendar): the product page flags it
      const today = businessToday();
      await fixture.admin.patch(`/items/${fixture.product.id}`, { is_new_until: shiftDay(today, 13) });
      const sent = await takeawayOrder(fixture, "2");
      orderId = sent.id;
      expect(sent.totals.total).toBe("250.00");
      await serveAll(fixture, sent.id);
      const [payment] = await payBatch(fixture, sent.id, [{ channel_code: bank.code, amount: "250.00", reference_no: `E2E-${run}` }]);
      expect(payment.amount).toBe("250.00");

      const rebuilt = await rebuildDays(fixture.admin, today);
      expect(rebuilt.map((d) => d.day)).toEqual([today]);

      // the overview: the first figure paints from the overview query alone (soft budget, logged)
      const started = Date.now();
      await openAs(page, fixture.admin, "en/admin/analytics/?range=today");
      const revenue = page.getByRole("group", { name: "Revenue", exact: true });
      await expect(revenue).toContainText("ETB", { timeout: 30_000 });
      const firstPaint = Date.now() - started;
      testInfo.annotations.push({ type: "first-kpi-ms", description: String(firstPaint) });
      console.log(`[p4-dashboards] first KPI painted in ${firstPaint} ms (soft budget ${SOFT_BUDGET_MS} ms${firstPaint > SOFT_BUDGET_MS ? ", OVER" : ""})`);
      await expect(page.getByRole("heading", { level: 1, name: "Analytics" })).toBeVisible();
      await expect(page.getByRole("button", { name: "Today", exact: true })).toHaveAttribute("aria-pressed", "true");
      await expect(page.getByText(/^Updated /)).toBeVisible({ timeout: 30_000 });

      // the payment methods page keeps the range, and shows the run's bank exactly
      await page.getByRole("navigation", { name: "Analytics sections" }).getByRole("link", { name: "Payment methods" }).click();
      await expect(page.getByRole("heading", { level: 1, name: "Payment methods" })).toBeVisible();
      await expect(page).toHaveURL(/range=today/);
      const table = page.getByRole("table", { name: "Takings by payment method" });
      const row = rowOf(table, bank.name_en);
      // receipts, amount, share, refunds, refunded, net, without reference, without photo, verified, disputed, duplicate references
      await expect(row.getByRole("cell")).toHaveText(["1", "250.00", /%$/, "0", "0.00", "250.00", "0", "1", "0", "0", "0"], { timeout: 30_000 });
      await expect(rowOf(table, wallet.name_en)).toHaveCount(0); // nothing was paid on the run's wallet

      // its "without photo" count opens the payments list of that day, that method, without a photo
      await row.getByRole("link", { name: `Show the payments without a photo: ${bank.name_en}` }).click();
      await expect(page.getByRole("heading", { level: 1, name: "Payments" })).toBeVisible({ timeout: 30_000 });
      await expect(page).toHaveURL(/missing_photo=1/);
      await expect(page).toHaveURL(new RegExp(`channel_id=${bank.id}`));
      const list = page.getByRole("table", { name: "Payments, newest first" });
      await expect(list.getByRole("button", { name: `Open payment ${payment.id}`, exact: true })).toBeVisible({ timeout: 30_000 });
      await expect(list.getByRole("button", { name: /^Open payment / })).toHaveCount(1);

      // the product page of the run's product: its day, new, and its cost said to be incomplete instead of a margin
      await page.goto(`en/admin/analytics/product/?id=${fixture.product.id}&range=today`);
      await expect(page.getByRole("heading", { level: 1, name: fixture.product.name_en })).toBeVisible({ timeout: 30_000 });
      await expect(page.getByText("New", { exact: true })).toBeVisible();
      await expect(page.getByRole("group", { name: "Units sold", exact: true })).toContainText("2", { timeout: 30_000 });
      await expect(page.getByRole("group", { name: "Revenue", exact: true })).toContainText("250.00 ETB");
      await expect(page.getByRole("group", { name: "Margin", exact: true })).toContainText("Cost incomplete");
      await expect(page.getByRole("note").filter({ hasText: "Cost incomplete" })).toBeVisible();
      // its first sale is today, so its first week since launch is today alone (cut at today)
      const launch = page.getByRole("table", { name: "Units by week since launch" });
      const todayLabel = new Date(`${today}T12:00:00Z`).toLocaleDateString("en-GB", { day: "numeric", month: "short", timeZone: "UTC" });
      await expect(rowOf(launch, "Week 1").getByRole("cell")).toHaveText([todayLabel, "2", "250.00"]);

      // the cashier's own figures (analytics.view_own): one row, the cashier's own; the Analytics tab leads there
      const context = await browser.newContext({ baseURL: testInfo.project.use.baseURL, viewport: { width: 1280, height: 800 }, serviceWorkers: "block" });
      try {
        const own = await context.newPage();
        await openAs(own, fixture.cashier.api, "en/admin/");
        const tab = own.getByRole("navigation", { name: "Admin sections" }).getByRole("link", { name: "Analytics" });
        await expect(tab).toHaveAttribute("href", /\/admin\/analytics\/me\/?$/, { timeout: 30_000 });
        await tab.click();
        await expect(own.getByRole("heading", { level: 1, name: "My figures" })).toBeVisible({ timeout: 30_000 });
        await own.getByRole("button", { name: "Today", exact: true }).click();
        // a row per kind of work (the orders the cashier took as a waiter's, the payments as a cashier's), every one the cashier's own
        const mine = own.getByRole("table", { name: "My work" });
        await expect(rowOf(mine, fixture.cashier.name).filter({ has: own.getByRole("cell", { name: "Cashier", exact: true }) })).toBeVisible({ timeout: 30_000 });
        const people = (await mine.getByRole("rowheader").allTextContents()).map((text) => text.trim());
        expect([...new Set(people)]).toEqual([fixture.cashier.name]);
      } finally {
        await context.close();
      }
    } catch (error) {
      if (error instanceof ApiFailure) console.error(error.message);
      throw error;
    } finally {
      // the product stops being new, so it does not give later runs' pads a "New" menu tab (they open on their first tab);
      // a failed clean-up must not hide the test's own result
      await fixture.admin.patch(`/items/${fixture.product.id}`, { is_new_until: null }).catch(() => undefined);
      if (orderId !== null) await tidyOrder(fixture, orderId);
      await removeChannel(fixture.admin, wallet);
      await removeChannel(fixture.admin, bank);
    }
  });

  test("days nobody built are named as not built, and the admin's Rebuild builds them inline (CI only)", async ({ page }) => {
    test.skip(live, "rebuilds past days of the shared E2E branch that nothing needs; flow 1 proves the rebuild on the live host");
    test.setTimeout(120_000);
    const credentials = requireAdmin(test.skip);
    const { api: admin } = await loginOnce(credentials.phone, credentials.password);
    // seven past days this process picks (a retry runs in a new process and picks others): nothing has built them
    const back = 40 + (parseInt(createHash("sha1").update(run).digest("hex").slice(0, 6), 16) % 300);
    const to = shiftDay(businessToday(), -back);
    const from = shiftDay(to, -6);

    await openAs(page, admin, `en/admin/analytics/?from=${from}&to=${to}`);
    await expect(page.getByRole("heading", { level: 1, name: "Analytics" })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole("button", { name: "Custom range", exact: true })).toHaveAttribute("aria-pressed", "true");
    const notice = page.getByRole("region", { name: "Days not built" });
    await expect(notice).toContainText("7 days of this range are not built yet", { timeout: 30_000 });
    // nothing built is never shown as zero
    await expect(page.getByRole("group", { name: "Revenue", exact: true })).toContainText("—");

    const request = page.waitForRequest((r) => r.url().endsWith("/api/v1/analytics/rebuild") && r.method() === "POST");
    const answer = page.waitForResponse((r) => r.url().endsWith("/api/v1/analytics/rebuild") && r.request().method() === "POST");
    await notice.getByRole("button", { name: "Rebuild these days" }).click();
    const sent = await request;
    expect(sent.postDataJSON()).toEqual({ from, to, sync: true });
    expect(sent.headers()["idempotency-key"]).toBeTruthy();
    expect((await answer).status()).toBe(200);
    await expect(page.getByRole("status").filter({ hasText: "Rebuilt 7 days." })).toBeVisible({ timeout: 30_000 });
    // the freshness feed sees the new versions: the notice goes and the figures are read again, true zeros now
    await expect(notice).toHaveCount(0, { timeout: 30_000 });
    await expect(page.getByRole("group", { name: "Revenue", exact: true })).toContainText("0.00 ETB", { timeout: 30_000 });
  });
});
