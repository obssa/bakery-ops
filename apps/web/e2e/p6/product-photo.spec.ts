import { expect, test, type Locator, type Page } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildStationFixture, clearStaleOrders, type StationFixture } from "../support/fixture";
import { openAs, openPadAndUnlock } from "../support/pad";
import { brokenImages, dropFile, endsWithPath, fixtureFile, mediaStatus, menuEtag, removePhoto } from "../support/productPhoto";

// Phase 6 gate project p6-product-photo, step 6.4b (docs/phases/phase-6-plan.md 6.4b; fresh-counter.md 4.3 "Photo card"; the ProductPhoto
// and PhotoStates boards): the admin's photo manager on the product page, end to end against the API.
//   1. the admin drops the fixture (a generated 1600 x 1200 test card) on the empty card's dropzone;
//   2. drags it and zooms it to 1.2x in the 4:3 frame, the quality line and the live pad-card preview following;
//   3. saves it: the upload's progress, then the saved photo with "Added by ... · W x H", the crop the server made;
//   4. the pad card and the order line show the new photo (the card and thumb renditions);
//   5. replaces it (zoomed to 2x: "may look soft"), which changes the URLs, the old ones answering 404;
//   6. removes it after the confirmation: the dropzone and the category placeholder are back, on the card and on the pad;
//   7. the menu's ETag moves each time.
// A second case: a file that is not a JPG, PNG or WebP is refused in the card, and a photo too small for the frame cannot be used.
//
// CI only (as product-photo-pad.spec.ts): it writes photos on the E2E branch.

/** The product page of the fixture's product, as its admin. */
async function openProductPage(page: Page, fixture: StationFixture): Promise<Locator> {
  await openAs(page, fixture.admin, `en/admin/catalog/?item=${fixture.product.id}`);
  await expect(page.getByRole("heading", { level: 1, name: fixture.product.name_en })).toBeVisible({ timeout: 30_000 });
  return page.getByRole("region", { name: "Photo", exact: true });
}

/** The photo's renditions as GET /items/{item} answers them now. */
async function storedImage(fixture: StationFixture) {
  const item = await fixture.admin.get<{ image: { thumb: string; card: string; large: string; width: number; height: number; origin: string } | null }>(`/items/${fixture.product.id}`);
  return item.image;
}

/** The pad of the fixture's waiter, unlocked, on the "Other" tab where the fixture's product is; returns its card. */
async function padCard(page: Page, fixture: StationFixture): Promise<Locator> {
  await openPadAndUnlock(page, fixture.waiter);
  await page.getByRole("tab", { name: "Other" }).click();
  const card = page.getByRole("button", { name: `Add ${fixture.product.name_en}` });
  await expect(card).toBeVisible({ timeout: 30_000 });
  return card;
}

/** Zooms with the slider's keyboard, as a person does: one step is 0.01x. */
async function zoomBy(slider: Locator, steps: number): Promise<void> {
  await slider.focus();
  for (let i = 0; i < steps; i += 1) await slider.press("ArrowRight");
}

test.describe("product photo: the admin's photo manager", () => {
  test("drop, frame, save; the pad shows it; replace changes the URLs; remove brings the placeholder back; the menu's ETag moves", async ({ page }) => {
    test.setTimeout(300_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    const fixture = await buildStationFixture(credentials, "kitchen");
    const product = fixture.product.name_en;
    await removePhoto(fixture.admin, fixture.product.id);
    try {
      const etags = [await menuEtag(fixture.waiter.api)];
      const etagMoved = async () => {
        await expect.poll(() => menuEtag(fixture.waiter.api), { timeout: 15_000 }).not.toBe(etags[etags.length - 1]);
        etags.push(await menuEtag(fixture.waiter.api));
      };

      // 1. the empty card: the dropzone, the rules line and the placeholder the pad shows meanwhile
      let card = await openProductPage(page, fixture);
      await expect(card).toContainText("No photo yet");
      await expect(card).toContainText("JPG, PNG or WebP · best at 1200 × 900 px or larger");
      const zone = card.getByText("Drop a photo here", { exact: true });
      await dropFile(zone, fixtureFile(), { hold: true });
      await expect(card).toContainText("Drop to upload");
      await expect(card).toContainText("Let go to add it");
      await dropFile(zone, fixtureFile());

      // 2. the framing step: the whole 4:3 photo at 1x, sharp; zoomed to 1.2x and dragged
      await expect(card).toContainText("Step 2 of 2 · frame it", { timeout: 15_000 });
      const frame = card.getByRole("group", { name: "Photo in the 4:3 frame" });
      await expect(frame.locator("img")).toHaveAttribute("alt", `New photo of ${product}, being framed`);
      await expect(card).toContainText("Sharp on every screen · crop 1,600 × 1,200 px");
      const slider = card.getByRole("slider", { name: "Zoom" });
      await zoomBy(slider, 20);
      await expect(card).toContainText("1.2×");
      await expect(card).toContainText("Sharp on every screen · crop 1,332 × 999 px");
      const before = await frame.locator("img").getAttribute("style");
      const box = await frame.boundingBox();
      if (!box) throw new Error("the frame is not drawn");
      await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
      await page.mouse.down();
      await page.mouse.move(box.x + box.width / 2 + 40, box.y + box.height / 2 + 30, { steps: 6 });
      await page.mouse.up();
      await expect.poll(() => frame.locator("img").getAttribute("style")).not.toBe(before);
      // the live preview: the product as a pad card (its name and price) and as the 48 px order-line thumb, both drawn from the frame
      const preview = card.getByRole("region", { name: "How it will look" });
      await expect(preview).toContainText(product);
      await expect(preview).toContainText(fixture.product.price);

      // 3. saved: the upload, then the photo the server made from the crop (1332 x 999 is under 1600 x 1200, so large keeps it)
      await card.getByRole("button", { name: "Use this photo" }).click();
      await expect(card.getByText(/^Added by .+ · .+ · 1332 × 999$/)).toBeVisible({ timeout: 60_000 });
      await expect(card.getByRole("img", { name: product })).toBeVisible();
      const first = await storedImage(fixture);
      expect(first).toMatchObject({ width: 1332, height: 999, origin: "upload" });
      await etagMoved();

      // 4. the pad: the card draws the card rendition, the order line the thumb
      const onPad = await padCard(page, fixture);
      await expect(onPad.locator("img")).toHaveAttribute("src", endsWithPath(first?.card ?? "-"));
      await onPad.click();
      const line = page.getByRole("region", { name: "Cart" }).getByRole("listitem").filter({ hasText: product });
      await expect(line.locator("img")).toHaveAttribute("src", endsWithPath(first?.thumb ?? "-"));
      await page.getByRole("button", { name: "Clear cart" }).click();

      // 5. replaced through the file chooser, zoomed to 2x: the server's minimum, so the quality line warns; new URLs, the old ones gone
      card = await openProductPage(page, fixture);
      const chooser = page.waitForEvent("filechooser");
      await card.getByRole("button", { name: "Replace" }).click();
      await (await chooser).setFiles(fixtureFile());
      await expect(card).toContainText("Step 2 of 2 · frame it", { timeout: 15_000 });
      await zoomBy(card.getByRole("slider", { name: "Zoom" }), 100);
      await expect(card).toContainText("2.0×");
      await expect(card).toContainText("This photo may look soft on big screens · crop 800 × 600 px");
      await card.getByRole("button", { name: "Use this photo" }).click();
      await expect(card.getByText(/^Added by .+ · .+ · 800 × 600$/)).toBeVisible({ timeout: 60_000 });
      const second = await storedImage(fixture);
      expect(second).toMatchObject({ width: 800, height: 600 });
      for (const rendition of ["thumb", "card", "large"] as const) expect(second?.[rendition]).not.toBe(first?.[rendition]);
      expect(await mediaStatus(fixture.admin, first?.card ?? "")).toBe(404);
      expect(await mediaStatus(fixture.admin, second?.card ?? "")).toBe(200);
      await etagMoved();

      // 6. removed, after the confirmation: the dropzone and the placeholder again, here and on the pad
      await card.getByRole("button", { name: "Remove", exact: true }).click();
      const confirm = page.getByRole("dialog", { name: "Remove the photo" });
      await expect(confirm).toContainText(product);
      await confirm.getByRole("button", { name: "Remove photo" }).click();
      await expect(confirm).toHaveCount(0);
      await expect(card).toContainText("No photo yet", { timeout: 30_000 });
      await expect(card.getByText("Drop a photo here", { exact: true })).toBeVisible();
      expect(await storedImage(fixture)).toBeNull();
      expect(await mediaStatus(fixture.admin, second?.card ?? "")).toBe(404);
      await etagMoved();
      const gone = await padCard(page, fixture);
      await expect(gone.locator("img")).toHaveCount(0);
      expect(await brokenImages(page)).toEqual([]);

      // 7. three changes, three new tags
      expect(new Set(etags).size).toBe(4);
    } finally {
      await removePhoto(fixture.admin, fixture.product.id);
    }
  });

  test("a file that is not a JPG, PNG or WebP is refused in the card, and a photo too small for the frame cannot be used", async ({ page }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildStationFixture(credentials, "kitchen");
    await removePhoto(fixture.admin, fixture.product.id);
    const card = await openProductPage(page, fixture);
    const zone = card.getByText("Drop a photo here", { exact: true });

    // a GIF: the card says why in its refusal box, in the server's words, and stays empty
    await dropFile(zone, { name: "counter.gif", mimeType: "image/gif", buffer: Buffer.from("GIF89a\x01\x00\x01\x00\x00\x00\x00;", "binary") });
    await expect(card.getByRole("alert")).toContainText("This kind of picture is not accepted. Use JPG, PNG or WebP.");
    await expect(card).toContainText("No photo yet");

    // a 640 x 480 photo (drawn here on a canvas): framed, but under the server's 800 x 600 at any zoom, so "Use this photo" is off
    const small = await page.evaluate(async () => {
      const canvas = document.createElement("canvas");
      canvas.width = 640;
      canvas.height = 480;
      const context = canvas.getContext("2d");
      if (context) {
        context.fillStyle = "#0E7C66";
        context.fillRect(0, 0, 640, 480);
        context.fillStyle = "#FFFFFF";
        context.fillRect(160, 120, 320, 240);
      }
      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.9));
      const bytes = new Uint8Array(await (blob ?? new Blob()).arrayBuffer());
      let binary = "";
      for (const byte of bytes) binary += String.fromCharCode(byte);
      return btoa(binary);
    });
    await dropFile(zone, { name: "small.jpg", mimeType: "image/jpeg", buffer: Buffer.from(small, "base64") });
    await expect(card).toContainText("Step 2 of 2 · frame it", { timeout: 15_000 });
    await expect(card).toContainText("Too small for the pad · crop 640 × 480 px. Use a photo of at least 800 × 600 px.");
    await expect(card.getByRole("button", { name: "Use this photo" })).toBeDisabled();
    await card.getByRole("button", { name: "Cancel" }).click();
    await expect(card).toContainText("No photo yet");
    expect(await storedImage(fixture)).toBeNull();
  });
});
