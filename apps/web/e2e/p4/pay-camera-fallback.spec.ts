import { expect, test } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { orderLabel } from "../support/fixture";
import { openAs } from "../support/pad";
import { buildPayFixture, removeChannel, takeawayOrder, tidyOrder } from "../support/payments";
import { fetchBytes, hasExif, jpegSize, receiptPhoto } from "../support/photo";

// Phase 4 project p4-pay-photo-split (docs/phases/phase-4-plan.md section 7), step 4.5b: the pay sheet's camera button on a device without a
// camera, and a browser that cannot decode the picture.
//
// The camera button is a plain file input (accept="image/*", capture="environment"): on a phone it opens the camera, and where there is
// none (this desktop browser) the same tap opens the file chooser, so a picture picked from the files is attached the same way. Here the
// browser's decoder is also broken (createImageBitmap rejects, as it does for a format the browser cannot read), so the sheet cannot shrink
// the photo and uploads the original file as it is; the server still stores it without EXIF at 1600 px. The order is voided at the end.

test.describe("p4 pay sheet: camera fallback", () => {
  test("without a camera the button opens the file chooser, and a photo the browser cannot shrink is uploaded as it is and stored shrunk", async ({ page }) => {
    test.setTimeout(150_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildPayFixture(credentials, "90.00");
    const { wallet } = fixture.channels;
    let orderId: number | null = null;
    try {
      const sent = await takeawayOrder(fixture, "1");
      orderId = sent.id;
      const label = orderLabel(sent);

      // the decoder of this page fails, as for a picture the browser cannot read
      await page.addInitScript(() => {
        Object.defineProperty(window, "createImageBitmap", { value: () => Promise.reject(new DOMException("cannot decode", "InvalidStateError")), configurable: true });
      });
      await openAs(page, fixture.cashier.api, "en/till/");
      const pay = page.getByRole("region", { name: "Orders awaiting payment" }).getByRole("button", { name: `Take payment for order ${label}`, exact: true });
      await expect(pay).toBeEnabled({ timeout: 30_000 });
      await pay.click();
      const sheet = page.getByRole("dialog", { name: `Payment for order ${label}` });

      // the one payment goes on the wallet
      const first = sheet.getByRole("group", { name: "Payment 1" });
      await first.getByRole("button", { name: wallet.name_en, exact: true }).click();
      await first.getByLabel("Reference number").fill("AB654321");

      // the camera button opens a chooser of files where there is no camera
      const photo = await receiptPhoto(page);
      const chooser = page.waitForEvent("filechooser");
      await first.getByRole("button", { name: "Take a photo of the receipt" }).click();
      const picked = await chooser;
      expect(picked.isMultiple()).toBe(false);
      expect(await picked.element().getAttribute("accept")).toBe("image/*");
      expect(await picked.element().getAttribute("capture")).toBe("environment");

      const upload = page.waitForResponse((r) => r.url().endsWith("/api/v1/files") && r.request().method() === "POST");
      await picked.setFiles(photo);
      const uploaded = await upload;
      expect(uploaded.status()).toBe(201);
      const file = (await uploaded.json()) as { id: number; width: number; height: number };
      // the original went up (the browser could not shrink it), and the server shrank it
      const body = uploaded.request().postDataBuffer();
      if (!body) throw new Error("the upload's body is not readable");
      const original = body.subarray(body.indexOf(Buffer.from([0xff, 0xd8, 0xff])));
      expect(jpegSize(original)).toEqual({ width: 2400, height: 1800 });
      expect(hasExif(original)).toBe(true);
      expect(file).toMatchObject({ width: 1600, height: 1200 });

      await expect(first.getByRole("img", { name: "Receipt photo of payment 1" })).toBeVisible();
      await expect(sheet.getByRole("button", { name: "Complete payment" })).toBeEnabled();

      // what the server keeps has no EXIF (no GPS position) and a longest side of 1600 px; the uploader may read their own photo
      const { url } = await fixture.cashier.api.get<{ url: string }>(`/files/${file.id}/url`);
      const stored = await fetchBytes(url);
      expect(stored.status).toBe(200);
      expect(hasExif(stored.bytes)).toBe(false);
      expect(jpegSize(stored.bytes)).toEqual({ width: 1600, height: 1200 });

      await sheet.getByRole("button", { name: "Close" }).click();
    } finally {
      if (orderId !== null) await tidyOrder(fixture, orderId);
      await removeChannel(fixture.admin, fixture.channels.wallet);
      await removeChannel(fixture.admin, fixture.channels.bank);
    }
  });
});
