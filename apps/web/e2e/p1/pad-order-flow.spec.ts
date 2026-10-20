import { test, expect } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildStationFixture } from "../support/fixture";
import { openPad, openPadAndUnlock, typeCode } from "../support/pad";

// Phase 1 gate project p1-order-flow, the pad lock screen (step 1.6b; the full flow arrives in step 1.6c). A shared pad
// is signed in as the pad account and locked by a short code (docs/architecture.md section 6, "Waiter pad"):
// - a wrong code is refused in the page language, and after five wrong codes the API lockout message (423) shows, in
//   English and in Amharic;
// - after 60 s without a touch the pad locks: the waiter name and the cart leave the screen; the same waiter code
//   brings that waiter parked cart back.

const WRONG = "987654"; // six digits: generated codes have four, so this never belongs to anybody

test.describe("p1 order flow: pad lock", () => {
  test("a wrong code is refused and the sixth attempt is locked out, in both languages @live", async ({ page }) => {
    test.setTimeout(120_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildStationFixture(credentials, "kitchen");

    await openPad(page, fixture.waiter);
    await expect(page.getByRole("heading", { name: "Enter your short code" })).toBeVisible({ timeout: 30_000 });

    await typeCode(page, WRONG);
    await expect(page.getByRole("alert").filter({ hasText: "That short code is not right." })).toBeVisible();
    for (let attempt = 2; attempt <= 5; attempt++) await typeCode(page, WRONG);
    await typeCode(page, WRONG);
    await expect(page.getByRole("alert").filter({ hasText: "Too many wrong codes; try again in a few minutes." })).toBeVisible();
    // even the right code is refused while locked out
    await typeCode(page, fixture.waiter.shortCode);
    await expect(page.getByRole("alert").filter({ hasText: "Too many wrong codes" })).toBeVisible();
    await expect(page.getByText(`Acting: ${fixture.waiter.name}`)).toHaveCount(0);

    // the same device on the Amharic page: the lockout message comes in Amharic
    await page.getByRole("link", { name: "አማርኛ" }).click();
    await expect(page).toHaveURL(/\/app\/am\/pad\/$/);
    await typeCode(page, fixture.waiter.shortCode, "ክፈት");
    await expect(page.getByRole("alert").filter({ hasText: "በጣም ብዙ የተሳሳቱ ኮዶች" })).toBeVisible();
  });

  test("the pad locks after 60 s without a touch and parks the waiter cart @live", async ({ page }) => {
    test.setTimeout(120_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildStationFixture(credentials, "kitchen");
    const product = fixture.product.name_en;

    await page.clock.install();
    await openPadAndUnlock(page, fixture.waiter);
    await page.getByRole("tab", { name: "Other" }).click();
    await page.getByRole("button", { name: `Add ${product}` }).click();
    await expect(page.getByRole("region", { name: "Cart" }).getByRole("listitem").filter({ hasText: product })).toBeVisible();

    // a touch every 40 s keeps it open
    await page.clock.fastForward(40_000);
    await page.getByRole("tab", { name: "My orders" }).click();
    await page.getByRole("tab", { name: "New order" }).click();
    await page.clock.fastForward(40_000);
    await expect(page.getByText(`Acting: ${fixture.waiter.name}`)).toBeVisible();

    // 61 s of nothing: locked, and nothing of the waiter session stays on the screen
    await page.clock.fastForward(61_000);
    await expect(page.getByRole("heading", { name: "Enter your short code" })).toBeVisible();
    await expect(page.getByText(`Acting: ${fixture.waiter.name}`)).toHaveCount(0);
    await expect(page.getByText(product)).toHaveCount(0);
    await expect(page.getByRole("region", { name: "Cart" })).toHaveCount(0);

    // the same waiter code brings their cart back
    await typeCode(page, fixture.waiter.shortCode);
    await expect(page.getByText(`Acting: ${fixture.waiter.name}`)).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole("region", { name: "Cart" }).getByRole("listitem").filter({ hasText: product })).toBeVisible();
  });
});
