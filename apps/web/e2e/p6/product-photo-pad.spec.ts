import { expect, test } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildStationFixture, clearStaleOrders } from "../support/fixture";
import { openPadAndUnlock } from "../support/pad";
import { attachPhoto, brokenImages, endsWithPath, removePhoto } from "../support/productPhoto";

// Phase 6 gate project p6-product-photo, step 6.3a (docs/phases/phase-6-plan.md 6.3a; fresh-counter.md 3.2): the waiter pad sells from a
// photo grid. A product of the E2E branch gets the generated test card as its photo through the API; on the pad its card draws the `card`
// rendition (the srcSet lists the three with their widths) and its order line the 48 px `thumb`. Once the photo is removed the menu's
// next answer (a new ETag) brings the placeholder back on both, and no image on the page is left broken.
//
// CI only: it writes a photo to the server. The service worker's media cache is not exercised here (CI serves `next start`, which has no
// sw.js; trap "Playwright"): web-build checks the export's sw.js for the route instead.

test.describe("product photo: the pad", () => {
  test("a product's photo shows on its pad card and its order line, and the placeholder comes back when it is removed", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    const fixture = await buildStationFixture(credentials, "kitchen");
    const product = fixture.product.name_en;

    const photo = await attachPhoto(fixture.admin, fixture.product.id);
    // a 1600 x 1200 picture is kept whole: the large rendition is the nominal size
    expect(photo).toMatchObject({ width: 1600, height: 1200 });
    for (const rendition of ["thumb", "card", "large"] as const) expect(photo[rendition]).toMatch(/^\/api\/v1\/media\/items\/[0-9a-f]{64}\//);

    await openPadAndUnlock(page, fixture.waiter);
    await page.getByRole("tab", { name: "Other" }).click();

    // the card: the add button keeps its name (name, price, availability) and holds the photo, named by the product
    const card = page.getByRole("button", { name: `Add ${product}` });
    const cardPhoto = card.locator("img");
    await expect(cardPhoto).toHaveAttribute("src", endsWithPath(photo.card));
    await expect(cardPhoto).toHaveAttribute("alt", product);
    const srcset = (await cardPhoto.getAttribute("srcset")) ?? "";
    expect(srcset).toContain(`${photo.thumb} 320w`);
    expect(srcset).toContain(`${photo.card} 800w`);
    expect(srcset).toContain(`${photo.large} 1600w`);
    await cardPhoto.scrollIntoViewIfNeeded();
    await expect.poll(() => cardPhoto.evaluate((img) => (img as HTMLImageElement).naturalWidth), { timeout: 15_000 }).toBeGreaterThan(0);

    // the order line: the thumb rendition, beside the name
    await card.click();
    const line = page.getByRole("region", { name: "Cart" }).getByRole("listitem").filter({ hasText: product });
    const linePhoto = line.locator("img");
    await expect(linePhoto).toHaveAttribute("src", endsWithPath(photo.thumb));
    await expect.poll(() => linePhoto.evaluate((img) => (img as HTMLImageElement).naturalWidth), { timeout: 15_000 }).toBeGreaterThan(0);

    // removed: the menu is polled every 5 s and its ETag moved, so the card and the line draw the placeholder (an aria-hidden icon, no img)
    await removePhoto(fixture.admin, fixture.product.id);
    await expect(cardPhoto).toHaveCount(0, { timeout: 30_000 });
    await expect(linePhoto).toHaveCount(0);
    await expect(card).toBeVisible();
    await expect(line).toBeVisible();
    expect(await brokenImages(page)).toEqual([]);
  });
});
