import { expect, test, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { expectAccessible } from "../support/a11y";

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
});
