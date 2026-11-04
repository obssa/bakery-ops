import { expect, type Locator } from "@playwright/test";

// The shift bar's region "Shift" as the specs reach its buttons. Step 6.3b: on the kitchen and bar displays and the till the bar is a menu in
// the station top bar, as the boards show the shift there. It opens by itself while the shift needs someone (none running, a count in
// progress, a refusal) and stays open until it is closed; a screen opened on a running shift shows only its button. Step 6.3c: the production
// board and the count screen show it the same way (the count screen's opens by itself only on a refusal: the count in progress is the screen
// itself). Where the bar is not a menu, this does nothing.

/** Opens the region's menu when it is a closed menu, and answers the region. */
export async function openShiftBar(region: Locator): Promise<Locator> {
  await expect(region).toBeVisible({ timeout: 30_000 });
  const toggle = region.locator("button[data-shift-toggle]");
  if ((await toggle.count()) > 0 && (await toggle.getAttribute("aria-expanded")) === "false") {
    await toggle.click();
    await expect(toggle).toHaveAttribute("aria-expanded", "true");
  }
  return region;
}
