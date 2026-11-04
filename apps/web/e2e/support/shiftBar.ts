import { expect, type Locator } from "@playwright/test";

// The shift bar's region "Shift" as the specs reach its buttons. Steps 6.3b and 6.3c: on the kitchen and bar displays, the till, the production
// board and the count screen the bar is a menu in the station top bar, as the boards show the shift there. The panel never opens by itself: a
// page always starts with only the chip, whatever state the shift is in, and every spec that reads the panel's words or presses its buttons
// comes through here, after each page load. While it is open it stays open, through the shift's changes and its dialogs, until the chip, a tap
// outside it or Escape closes it. The prompt that needs no tap is the attention strip under the bar (`shift-strip`), which is outside this
// region and holds the state's one main action; e2e/p6/shift-strip.spec.ts reads it. Where the bar is not a menu, this does nothing.

/** Opens the region's panel when it is a closed menu, and answers the region. */
export async function openShiftBar(region: Locator): Promise<Locator> {
  await expect(region).toBeVisible({ timeout: 30_000 });
  const toggle = region.locator("button[data-shift-toggle]");
  if ((await toggle.count()) > 0 && (await toggle.getAttribute("aria-expanded")) === "false") {
    await toggle.click();
    await expect(toggle).toHaveAttribute("aria-expanded", "true");
  }
  return region;
}
