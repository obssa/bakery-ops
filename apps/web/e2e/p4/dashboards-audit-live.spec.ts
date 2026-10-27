import { expect, test, type Locator } from "@playwright/test";
import { readFileSync } from "node:fs";
import { ApiFailure } from "../support/api";
import { live, requireAdmin, run } from "../support/env";
import { buildTillStaff, businessToday, loginOnce } from "../support/fixture";
import { openAs } from "../support/pad";
import { buildPayFixture, payBatch, removeChannel, serveAll, takeawayOrder, tidyOrder } from "../support/payments";
import { toEthiopian } from "../../src/lib/ethiopianDate";
import amMessages from "../../messages/am.json";
import enMessages from "../../messages/en.json";

// Phase 4 gate project p4-dashboards (the name matches /dashboards/), step 4.5f: the audit screen and the Ethiopian dates in the browser.
//
// 1. @live: the cashier is paid 120.00 for a run-unique order through the API (50.00 cash, 70.00 on the run's bank transfer with a run-unique
//    reference), then voids the transfer with the manager's PIN (step 4.3c): an audited write of its own, with an approver. The admin opens
//    the payment, follows its History link to the audit log filtered to that payment, finds the void, narrows it by the action prefix, opens
//    the entry: the status went from recorded to voided, the reference shows as redacted (its text is nowhere on the page, the raw JSON too),
//    the approver is named, and the address (?id=) opens the same entry again after a reload. The export of that filter is a CSV holding the
//    void and not the reference. The admin then switches the dates to the Ethiopian calendar: the entry's time reads in it, in English and
//    on the Amharic page, and back in Gregorian.
// 2. @live: the manager reads the log (audit.view) without the export (audit.export is the admin's); the cashier has no Audit tab, the
//    address says the page is not theirs, and the API answers 403.
// 3. CI only: the receipt settings form shows the "Ethiopian date" switch with today's date as the receipt would print it, and saves it
//    (on the live host this would change the E2E branch's receipts while other runs print them).
//
// Fixtures through the admin API of the isolated "E2E Test Branch" (e2e/support/payments.ts), run-unique names; the audit rows are found
// by the run's own payment, never by counts.

const PAYMENT = "App\\Modules\\Payments\\Models\\Payment";

interface AuditRow {
  id: number;
  at: string;
  action: string;
}

/** A row of a table by its row header (the inner locator is the page's: it is matched inside each row). */
function rowOf(table: Locator, name: string): Locator {
  return table.getByRole("row").filter({ has: table.page().getByRole("rowheader", { name, exact: true }) });
}

/** The local date (Africa/Addis_Ababa) of an instant, as the screens label it. */
function addisDate(iso: string): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Africa/Addis_Ababa" }).format(new Date(iso));
}

function ethiopianLabel(date: string, locale: "en" | "am"): string {
  const { year, month, day } = toEthiopian(date);
  const names = (locale === "am" ? amMessages : enMessages).dates.ethiopian_months as Record<string, string>;
  const name = names[String(month)];
  return locale === "am" ? `${name} ${day} ቀን ${year} ዓ.ም.` : `${name} ${day}, ${year}`;
}

test.describe("p4 audit log", () => {
  test("a voided payment's audit entry is found from the payment, shows its before and after with the reference redacted and its approver, exports, and reads in the Ethiopian calendar @live", async ({ page }) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildPayFixture(credentials, "60.00");
    const { bank } = fixture.channels;
    const reference = `AUD-${run}`.toUpperCase();
    let orderId: number | null = null;
    try {
      // paid before it is served (so it waits, settled, and a payment may still be voided), then the transfer is voided with the PIN
      const sent = await takeawayOrder(fixture, "2");
      orderId = sent.id;
      expect(sent.totals.total).toBe("120.00");
      const [, transfer] = await payBatch(fixture, sent.id, [
        { channel_code: "cash", amount: "50.00", tendered_amount: "50.00" },
        { channel_code: bank.code, amount: "70.00", reference_no: reference },
      ]);
      const reason = `E2E audit ${run}: the transfer was for another order`;
      await fixture.cashier.api.post(`/payments/${transfer.id}/void`, { reason, pin: fixture.manager.pin });
      // the order is owed the 70.00 again: taken in cash, served, it closes (nothing of the flow stays in the till's queue)
      await payBatch(fixture, sent.id, [{ channel_code: "cash", amount: "70.00", tendered_amount: "70.00" }]);
      await serveAll(fixture, sent.id);

      const voids = await fixture.admin.get<{ data: AuditRow[] }>("/audit-log", { subject_type: PAYMENT, subject_id: transfer.id, action: "payment.void" });
      expect(voids.data).toHaveLength(1);
      const voidRow = voids.data[0];
      const day = addisDate(voidRow.at);

      // the payment's History link opens the audit log filtered to it
      await openAs(page, fixture.admin, `en/admin/payments/?payment=${transfer.id}`);
      const payment = page.getByRole("dialog", { name: `Payment ${transfer.id}` });
      await expect(payment).toBeVisible({ timeout: 30_000 });
      await payment.getByRole("link", { name: "Audit history", exact: true }).click();
      await expect(page.getByRole("heading", { level: 1, name: "Audit log" })).toBeVisible({ timeout: 30_000 });
      await expect(page).toHaveURL(new RegExp(`subject_id=${transfer.id}`));
      const filters = page.getByRole("search", { name: "Audit log filters" });
      await expect(filters.getByLabel("Subject ID")).toHaveValue(String(transfer.id));
      const log = page.getByRole("table", { name: "Audit log", exact: true });
      const entry = log.getByRole("row").filter({ hasText: "payment.void" });
      await expect(entry).toHaveCount(1, { timeout: 30_000 });
      await expect(entry).toContainText(fixture.cashier.name);
      await expect(entry).toContainText(reason);

      // narrowed by the action prefix, picked from the list: the address keeps it
      await filters.getByLabel("Action", { exact: true }).selectOption("payment.");
      await filters.getByRole("button", { name: "Apply" }).click();
      await expect(page).toHaveURL(/action=payment\./);
      await expect(entry).toHaveCount(1, { timeout: 30_000 });

      // the entry: before and after, the reference redacted, the approver named
      await entry.getByRole("button", { name: `Open entry ${voidRow.id}` }).click();
      const drawer = page.getByRole("dialog", { name: `Audit entry ${voidRow.id}` });
      await expect(drawer).toBeVisible({ timeout: 30_000 });
      await expect(page).toHaveURL(new RegExp(`id=${voidRow.id}`));
      const diff = drawer.getByRole("table", { name: "Before and after" });
      const status = rowOf(diff, "status");
      await expect(status).toContainText("Changed");
      await expect(status).toContainText("recorded");
      await expect(status).toContainText("voided");
      await expect(rowOf(diff, "reference_no")).toContainText("Redacted");
      await expect(rowOf(diff, "amount")).toContainText("70.00");
      const context = drawer.getByRole("table", { name: "Context" });
      await expect(rowOf(context, "reason")).toContainText(reason);
      await expect(rowOf(context, "approved_by_user_id")).toContainText(fixture.manager.name);
      await expect(page.getByText(reference)).toHaveCount(0);
      // the raw JSON is there on request, redacted as well
      await drawer.getByRole("button", { name: "Show the raw JSON" }).click();
      const raw = drawer.getByRole("region", { name: "Raw JSON" });
      await expect(raw).toContainText('"reference_no": "[redacted]"');
      await expect(raw).not.toContainText(reference);

      // the address opens the same entry again
      await page.reload();
      await expect(page.getByRole("dialog", { name: `Audit entry ${voidRow.id}` })).toBeVisible({ timeout: 30_000 });
      await page.getByRole("dialog", { name: `Audit entry ${voidRow.id}` }).getByRole("button", { name: "Close" }).click();
      await expect(page).not.toHaveURL(new RegExp(`[?&]id=${voidRow.id}`));

      // the export of the same filter, named in the browser as the API names it
      const [download] = await Promise.all([page.waitForEvent("download"), page.getByRole("button", { name: "Export CSV" }).click()]);
      expect(download.suggestedFilename()).toBe(`audit-log-all-${businessToday()}.csv`);
      const csv = readFileSync(await download.path(), "utf8");
      expect(csv).toContain("payment.void");
      expect(csv).toContain(reason);
      expect(csv).not.toContain(reference);

      // the Ethiopian calendar: a label only, the filters keep their Gregorian dates
      await page.getByLabel("Dates", { exact: true }).selectOption("ethiopian");
      await expect(entry).toContainText(ethiopianLabel(day, "en"));
      await page.goto(`am/admin/audit/?subject_type=${encodeURIComponent(PAYMENT)}&subject_id=${transfer.id}&action=payment.`);
      const amLog = page.getByRole("table", { name: amMessages.audit.list.table, exact: true });
      await expect(amLog.getByRole("row").filter({ hasText: "payment.void" })).toContainText(ethiopianLabel(day, "am"), { timeout: 30_000 });
      await page.goto(`en/admin/audit/?subject_type=${encodeURIComponent(PAYMENT)}&subject_id=${transfer.id}&action=payment.`);
      await expect(entry).toContainText(ethiopianLabel(day, "en"), { timeout: 30_000 });
      await page.getByLabel("Dates", { exact: true }).selectOption("both");
      await expect(entry).toContainText(day);
      await expect(entry).toContainText(ethiopianLabel(day, "en"));
      await page.getByLabel("Dates", { exact: true }).selectOption("gregorian");
      await expect(entry).toContainText(day);
      await expect(entry).not.toContainText(ethiopianLabel(day, "en"));
    } catch (error) {
      if (error instanceof ApiFailure) console.error(error.message);
      throw error;
    } finally {
      if (orderId !== null) await tidyOrder(fixture, orderId);
      await removeChannel(fixture.admin, fixture.channels.wallet);
      await removeChannel(fixture.admin, fixture.channels.bank);
    }
  });

  test("the manager reads the audit log without its export; the cashier has no Audit tab, the page is not theirs, and the API says 403 @live", async ({ browser }) => {
    test.setTimeout(120_000);
    const credentials = requireAdmin(test.skip);
    const staff = await buildTillStaff(credentials);

    const managerContext = await browser.newContext();
    try {
      const { api: manager } = await loginOnce(staff.manager.phone, staff.manager.password, staff.branchId);
      const page = await managerContext.newPage();
      await openAs(page, manager, "en/admin/");
      await page.getByRole("navigation", { name: "Admin sections" }).getByRole("link", { name: "Audit log" }).click();
      await expect(page.getByRole("heading", { level: 1, name: "Audit log" })).toBeVisible({ timeout: 30_000 });
      await expect(page.getByRole("table", { name: "Audit log", exact: true })).toBeVisible({ timeout: 30_000 });
      await expect(page.getByRole("button", { name: "Export CSV" })).toHaveCount(0);
      await expect(page.getByRole("button", { name: "Export Excel" })).toHaveCount(0);
    } finally {
      await managerContext.close();
    }

    const cashierContext = await browser.newContext();
    try {
      const page = await cashierContext.newPage();
      await openAs(page, staff.cashier.api, "en/admin/");
      const sections = page.getByRole("navigation", { name: "Admin sections" });
      await expect(sections.getByRole("link", { name: "Catalog" })).toBeVisible({ timeout: 30_000 });
      await expect(sections.getByRole("link", { name: "Audit log" })).toHaveCount(0);
      await expect(page.getByRole("link", { name: /^Audit log/ })).toHaveCount(0);
      await page.goto("en/admin/audit/");
      await expect(page.getByRole("alert")).toContainText("Your account cannot open this page.", { timeout: 30_000 });
      await expect(page.getByRole("table", { name: "Audit log", exact: true })).toHaveCount(0);
    } finally {
      await cashierContext.close();
    }
    const refused = await staff.cashier.api.get("/audit-log").then(
      () => null,
      (error: unknown) => error,
    );
    expect(refused).toBeInstanceOf(ApiFailure);
    expect((refused as ApiFailure).status).toBe(403);
  });

  test("the receipt settings show the Ethiopian date switch with today's date as the receipt prints it, and save it (CI only)", async ({ page }) => {
    test.skip(live, "changes the E2E branch's receipt settings while other runs print receipts");
    const credentials = requireAdmin(test.skip);
    const { api: admin } = await loginOnce(credentials.phone, credentials.password);
    const before = await admin.get<{ ethiopian_dates: boolean }>("/receipt-settings");
    await openAs(page, admin, "en/admin/payments/receipts/");
    const toggle = page.getByLabel("Print the Ethiopian date too");
    await expect(toggle).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(`Today it prints ${ethiopianLabel(businessToday(), "en")}`)).toBeVisible();
    if (before.ethiopian_dates) await toggle.uncheck();
    else await toggle.check();
    await page.getByRole("button", { name: "Save receipt settings" }).click();
    await expect(page.getByRole("status").filter({ hasText: "Receipt settings saved." })).toBeVisible({ timeout: 30_000 });
    await expect.poll(async () => (await admin.get<{ ethiopian_dates: boolean }>("/receipt-settings")).ethiopian_dates).toBe(!before.ethiopian_dates);
  });
});
