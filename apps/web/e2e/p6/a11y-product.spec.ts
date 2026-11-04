import { expect, test } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { expectAccessible } from "../support/a11y";
import { requireAdmin } from "../support/env";
import { buildStationFixture } from "../support/fixture";
import { openAs } from "../support/pad";
import { attachPhoto, dropFile, fixtureFile, removePhoto } from "../support/productPhoto";

// Phase 6 gate project p6-a11y, step 6.4b (docs/phases/phase-6-plan.md 6.4b and section 7; fresh-counter.md 5): the admin's product page in
// English and in Amharic has no serious or critical axe violation (e2e/support/a11y.ts): with a photo, with the remove dialog open, without
// one (the dropzone), with a refusal under the card, in the framing step (the frame, the zoom, the quality line, the live preview), and with
// the recipe's drawer open. In its own file, beside a11y.spec.ts, so the steps that add screens there and here do not meet.

const MESSAGES = { en, am } as const;

test.describe("a11y: the product page", () => {
  for (const locale of ["en", "am"] as const) {
    test(`the product page (${locale}) with its photo card in each state has no serious or critical axe violation`, async ({ page }) => {
      test.setTimeout(180_000);
      const t = MESSAGES[locale];
      const credentials = requireAdmin(test.skip);
      const fixture = await buildStationFixture(credentials, "kitchen");
      const name = locale === "am" ? fixture.product.name_am : fixture.product.name_en;
      await attachPhoto(fixture.admin, fixture.product.id);
      try {
        await openAs(page, fixture.admin, `${locale}/admin/catalog/?item=${fixture.product.id}`);
        await expect(page.locator("html")).toHaveAttribute("lang", locale);
        await expect(page.getByRole("heading", { level: 1, name })).toBeVisible({ timeout: 30_000 });
        const card = page.getByRole("region", { name: t.photo.title, exact: true });
        await expect(card.getByRole("img", { name })).toBeVisible({ timeout: 15_000 });
        await expectAccessible(page, `${locale}/admin/catalog/?item (a photo)`);

        await card.getByRole("button", { name: t.photo.remove, exact: true }).click();
        await expect(page.getByRole("dialog", { name: t.photo.remove_title })).toBeVisible();
        await expectAccessible(page, `${locale}/admin/catalog/?item (the remove dialog)`);
        await page.getByRole("button", { name: t.photo.remove_keep }).click();

        await removePhoto(fixture.admin, fixture.product.id);
        await page.reload();
        const zone = card.getByTestId("photo-dropzone");
        await expect(zone).toBeVisible({ timeout: 30_000 });
        await expectAccessible(page, `${locale}/admin/catalog/?item (no photo)`);

        await dropFile(zone, { name: "counter.gif", mimeType: "image/gif", buffer: Buffer.from("GIF89a", "binary") });
        await expect(card.getByRole("alert")).toHaveText(t.photo.refused_type);
        await expectAccessible(page, `${locale}/admin/catalog/?item (a refusal)`);

        await dropFile(zone, fixtureFile());
        await expect(card.getByRole("group", { name: t.photo.frame_label })).toBeVisible({ timeout: 15_000 });
        await expect(card.getByRole("slider", { name: t.photo.zoom })).toBeVisible();
        await expectAccessible(page, `${locale}/admin/catalog/?item (the framing step)`);
        await card.getByRole("button", { name: t.photo.cancel }).click();

        await page.getByRole("button", { name: t.product.recipe }).click();
        await expect(page.getByRole("dialog", { name: t.product.recipe })).toBeVisible();
        await expectAccessible(page, `${locale}/admin/catalog/?item (the recipe drawer)`);
      } finally {
        await removePhoto(fixture.admin, fixture.product.id);
      }
    });
  }
});
