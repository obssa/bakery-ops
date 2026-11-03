import { expect, test, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { expectAccessible } from "../support/a11y";
import { requireAdmin } from "../support/env";
import { buildStationFixture } from "../support/fixture";
import { chooseTable, openPad, typeCode } from "../support/pad";
import { attachPhoto, removePhoto } from "../support/productPhoto";

// Phase 6 gate project p6-a11y (docs/phases/phase-6-plan.md D7 and section 7; fresh-counter.md 5): on each restyled screen, in English and
// in Amharic, axe finds no serious or critical violation of WCAG 2.0 and 2.1 A and AA, contrast included (e2e/support/a11y.ts). Each step
// adds its screens here: the sign-in screen (6.2b), the pad (6.3a), the kitchen and bar displays and the till (6.3b), the production board
// (6.3c), the admin's Today and the catalog list (6.4a), the product page (6.4b).

const MESSAGES = { en, am } as const;

/** The sign-in screen, drawn: its heading, both fields, the button and the photo beside them. */
async function openLogin(page: Page, locale: keyof typeof MESSAGES): Promise<void> {
  const t = MESSAGES[locale];
  await page.goto(`${locale}/login/`);
  await expect(page.locator("html")).toHaveAttribute("lang", locale);
  await expect(page.getByRole("heading", { level: 1, name: t.login.title })).toBeVisible();
  await expect(page.getByLabel(t.login.phone)).toBeVisible();
  await expect(page.getByLabel(t.login.password)).toBeVisible();
  await expect(page.getByRole("button", { name: t.login.submit })).toBeVisible();
}

/** A message with its placeholders filled, as next-intl writes it. */
function fill(message: string, values: Record<string, string>): string {
  return message.replace(/\{(\w+)\}/g, (whole, key: string) => values[key] ?? whole);
}

/** The text of a message up to its first placeholder that is not given (a prefix the accessible name starts with). */
function prefix(message: string, values: Record<string, string>): string {
  const open = [...message.matchAll(/\{(\w+)\}/g)].find((match) => !(match[1] in values));
  return fill(open?.index === undefined ? message : message.slice(0, open.index), values);
}

test.describe("a11y", () => {
  for (const locale of ["en", "am"] as const) {
    test(`the sign-in screen (${locale}) has no serious or critical axe violation`, async ({ page }) => {
      await openLogin(page, locale);
      // the bakery photo is drawn, not a broken image (it is decorative: an empty alt)
      const photo = page.locator('img[src$="/images/login-counter.webp"]');
      await expect(photo).toHaveAttribute("alt", "");
      await expect.poll(() => photo.evaluate((img) => (img as HTMLImageElement).naturalWidth)).toBeGreaterThan(0);
      await expectAccessible(page, `${locale}/login/`);
    });

    test(`the sign-in screen (${locale}) shows a refused sign-in in the form, still with no serious or critical axe violation`, async ({ page }) => {
      const t = MESSAGES[locale];
      // the API's answer to a wrong password (Identity's IdentityError, lang/{en,am}/identity.php), stubbed so the run spends none of the
      // server's sign-in attempts
      const refusal = {
        message_key: "identity.login.invalid",
        message_en: "The phone number or password is not right.",
        message_am: "የስልክ ቁጥሩ ወይም የይለፍ ቃሉ ትክክል አይደለም።",
      };
      await page.route("**/api/v1/login", (route) =>
        route.fulfill({ status: 401, contentType: "application/json", body: JSON.stringify({ message: refusal.message_en, ...refusal }) }),
      );
      await openLogin(page, locale);
      await page.getByLabel(t.login.phone).fill("0900000000");
      await page.getByLabel(t.login.password).fill("not-the-password");
      await page.getByRole("button", { name: t.login.submit }).click();
      // in the form, never a toast (fresh-counter.md 3.1), in the page's language
      await expect(page.getByRole("main").getByRole("alert")).toHaveText(locale === "am" ? refusal.message_am : refusal.message_en);
      await expectAccessible(page, `${locale}/login/ (refused)`);
    });
  }

  // Step 6.3a: the waiter pad, locked (the keypad) and unlocked (the photo grid, the order panel with a line, its thumb and the table
  // picker), with a product photo on the fixture's product so a drawn photo is checked as well as the placeholders.
  for (const locale of ["en", "am"] as const) {
    test(`the waiter pad (${locale}), locked and with an order on it, has no serious or critical axe violation`, async ({ page }) => {
      test.setTimeout(120_000);
      const t = MESSAGES[locale];
      const credentials = requireAdmin(test.skip);
      const fixture = await buildStationFixture(credentials, "kitchen");
      const name = locale === "am" ? fixture.product.name_am : fixture.product.name_en;
      await attachPhoto(fixture.admin, fixture.product.id);
      try {
        await openPad(page, fixture.waiter, `${locale}/pad/`);
        await expect(page.locator("html")).toHaveAttribute("lang", locale);
        await expect(page.getByRole("heading", { name: t.pad.enter_code })).toBeVisible({ timeout: 30_000 });
        await expectAccessible(page, `${locale}/pad/ (locked)`);

        await typeCode(page, fixture.waiter.shortCode, t.pad.unlock);
        await expect(page.getByText(`${t.pad.acting_prefix} ${fixture.waiter.name}`)).toBeVisible({ timeout: 30_000 });
        await page.getByRole("tab", { name: t.pad.tab_other }).click();
        const card = page.getByRole("button", { name: prefix(t.pad.add_item, { name }) });
        await expect(card.locator("img")).toHaveAttribute("alt", name);
        await expectAccessible(page, `${locale}/pad/ (the menu, nothing in the order)`);

        await card.click();
        // the tables sheet, open (step 6.3a: "Change" opens it), then the table chosen in it
        await page.getByRole("button", { name: t.pad.change_table_aria }).click();
        await expect(page.getByRole("dialog", { name: t.pad.tables })).toBeVisible();
        await expectAccessible(page, `${locale}/pad/ (the tables sheet)`);
        await page.keyboard.press("Escape");
        await chooseTable(page, fixture.table.code, { change: t.pad.change_table_aria, sheet: t.pad.tables, table: prefix(t.pad.table_button, { code: fixture.table.code }) });
        await expect(page.getByRole("region", { name: t.pad.cart }).getByRole("listitem").filter({ hasText: name })).toBeVisible();
        await expectAccessible(page, `${locale}/pad/ (an order with a line)`);
      } finally {
        await removePhoto(fixture.admin, fixture.product.id);
      }
    });
  }
});
