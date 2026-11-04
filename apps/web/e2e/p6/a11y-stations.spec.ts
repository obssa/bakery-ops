import { expect, test, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { expectAccessible } from "../support/a11y";
import { requireAdmin } from "../support/env";
import { buildStationFixture, loginOnce, orderLabel, sendOrder, type StationKind } from "../support/fixture";
import { openAs } from "../support/pad";
import { buildPayFixture, serveAll, takeawayOrder, tidyOrder } from "../support/payments";

// Phase 6 gate project p6-a11y, step 6.3b (docs/phases/phase-6-plan.md 6.3b and D7; fresh-counter.md 3.3, 3.4 and 5): the kitchen and bar
// displays in kitchen night and the till's pay screen, in English and in Amharic, with no serious or critical axe violation of WCAG 2.0 and
// 2.1 A and AA, contrast included (e2e/support/a11y.ts, beside the sign-in screen's and the pad's cases in a11y.spec.ts). Each screen is
// checked as it is drawn with work on it: a display with a ticket new, then made and waiting for pickup, so the timer chip, the accent
// buttons and the Ready strip are measured on the night ground; the till with an order in the waiting list, its pay sheet open beside it,
// and the receipt after the payment. Everything is built in the isolated "E2E Test Branch" through the API.

const MESSAGES = { en, am } as const;

/** A message with its placeholders filled, as next-intl writes it. */
function fill(message: string, values: Record<string, string>): string {
  return message.replace(/\{(\w+)\}/g, (whole, key: string) => values[key] ?? whole);
}

async function openDisplay(page: Page, kind: StationKind, locale: keyof typeof MESSAGES) {
  const t = MESSAGES[locale];
  const credentials = requireAdmin(test.skip);
  const fixture = await buildStationFixture(credentials, kind);
  const worker = await loginOnce(fixture.worker.phone, fixture.worker.password, fixture.branchId);
  const order = await sendOrder(fixture);
  await openAs(page, worker.api, `${locale}/kds/${kind}/`);
  await expect(page.locator("html")).toHaveAttribute("lang", locale);
  await expect(page.getByRole("heading", { level: 1, name: kind === "kitchen" ? t.kds.kitchen_title : t.kds.bar_title })).toBeVisible({ timeout: 30_000 });
  return { fixture, order };
}

test.describe("a11y: the stations of step 6.3b", () => {
  for (const kind of ["kitchen", "bar"] as const) {
    for (const locale of ["en", "am"] as const) {
      test(`the ${kind} display (${locale}) in kitchen night, with a ticket new and then waiting for pickup, has no serious or critical axe violation`, async ({ page }) => {
        test.setTimeout(150_000);
        const t = MESSAGES[locale];
        const { fixture, order } = await openDisplay(page, kind, locale);
        const name = locale === "am" ? fixture.product.name_am : fixture.product.name_en;

        // the whole frame is night, the top bar included, and only on the display
        await expect(page.locator(".kitchen-night")).toHaveCount(1);
        const ticket = page.getByRole("article").filter({ hasText: name });
        await expect(ticket).toBeVisible({ timeout: 30_000 });
        await expect(ticket).toContainText(t.kds.status_queued);
        // no product photo on a ticket: the lines are text on purpose (phase-6 plan 10.2 Q17)
        await expect(ticket.locator("img")).toHaveCount(0);
        await expect(page.getByRole("region", { name: t.stations.strip_title })).toBeVisible();
        await expectAccessible(page, `${locale}/kds/${kind}/ (a new ticket)`);

        await ticket.getByRole("button", { name: fill(t.kds.accept_line, { name }) }).click();
        await expect(ticket).toContainText(t.kds.status_accepted, { timeout: 30_000 });
        await ticket.getByRole("button", { name: fill(t.kds.ready_line, { name }) }).click();
        await expect(ticket).toContainText(t.kds.status_ready, { timeout: 30_000 });
        await expectAccessible(page, `${locale}/kds/${kind}/ (waiting for pickup)`);

        // served through the API, so the ticket leaves the rail for the next run
        for (const line of order.lines) await fixture.waiter.api.post(`/order-lines/${line.id}/served`);
      });
    }
  }

  test("kitchen night stays on the displays: the till and the start page are drawn on the light ground", async ({ page }) => {
    test.setTimeout(90_000);
    const fixture = await buildPayFixture(requireAdmin(test.skip), "60.00");
    await openAs(page, fixture.cashier.api, "en/till/");
    await expect(page.getByRole("heading", { level: 1, name: en.till.title })).toBeVisible({ timeout: 30_000 });
    await expect(page.locator(".kitchen-night")).toHaveCount(0);
    const ground = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
    expect(ground).toBe("rgb(244, 245, 247)");
  });

  for (const locale of ["en", "am"] as const) {
    test(`the till (${locale}), its waiting list, the pay sheet beside it and the receipt, has no serious or critical axe violation`, async ({ page }) => {
      test.setTimeout(180_000);
      const t = MESSAGES[locale];
      const fixture = await buildPayFixture(requireAdmin(test.skip), "60.00");
      const sent = await takeawayOrder(fixture, "2");
      try {
        await serveAll(fixture, sent.id);
        const no = orderLabel(sent);
        await openAs(page, fixture.cashier.api, `${locale}/till/`);
        await expect(page.locator("html")).toHaveAttribute("lang", locale);
        await expect(page.getByRole("heading", { level: 1, name: t.till.title })).toBeVisible({ timeout: 30_000 });
        const queue = page.getByRole("region", { name: t.till.queue_title });
        const take = queue.getByRole("button", { name: fill(t.till.take_payment_aria, { no }), exact: true });
        await expect(take).toBeEnabled({ timeout: 30_000 });
        await expectAccessible(page, `${locale}/till/ (the waiting list)`);

        await take.click();
        const sheet = page.getByRole("dialog", { name: fill(t.till.pay_title, { no }) });
        await expect(sheet.getByRole("status", { name: t.till.amount_due })).toHaveText("120.00 ETB");
        // from 1024 px the sheet is beside the list, not over it: the list stays usable
        await expect(queue).toBeVisible();
        await expect(sheet).not.toHaveAttribute("aria-modal", "true");
        const row = sheet.getByRole("group", { name: fill(t.till.row_title, { n: "1" }) });
        await expect(row.getByRole("button", { name: "1,000", exact: true })).toBeEnabled();
        await expectAccessible(page, `${locale}/till/ (the pay sheet)`);

        // a quick cash key fills the cash received: 500 against 120.00 is 380.00 change
        await row.getByRole("button", { name: "500", exact: true }).click();
        await expect(row).toContainText(fill(t.till.change_due, { amount: "380.00", currency: "ETB" }).replace(/<\/?em>/g, ""));
        await sheet.getByRole("button", { name: t.till.complete }).click();
        await expect(sheet.getByRole("status").filter({ hasText: t.till.paid })).toBeVisible({ timeout: 30_000 });
        await expect(sheet.getByRole("region", { name: t.till.receipt_preview })).toBeVisible();
        await expectAccessible(page, `${locale}/till/ (paid, the receipt)`);
        await sheet.getByRole("button", { name: t.till.done }).click();
        await expect(take).toHaveCount(0, { timeout: 15_000 });
      } finally {
        await tidyOrder(fixture, sent.id);
      }
    });
  }
});
