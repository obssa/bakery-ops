import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { fontsReady } from "../support/a11y";
import { freshDevice } from "../support/devices";
import { live } from "../support/env";
import { loginOnce } from "../support/fixture";
import { openAs, typeCode } from "../support/pad";

// Screenshots for review (brief "Design fidelity"; phase-6 plan section 7), in project p6-a11y, so they run in e2e-p6 on the demo bakery
// that SEED_PHASE=6 gives its demo photos. Each Phase 6 step that rebuilds a screen captures it here, at 1180 x 820 for a station screen
// (the tablet the design boards are drawn for) and 1440 x 900 for an admin page, in English and once in Amharic, under
// test-results/screens/<screen>-<size>-<locale>.png. e2e-p6 uploads the folder as the artifact "screens" on every run; the coordinator
// compares each picture with its board before the merge. Nothing is asserted about pixels (no golden images, plan 10.2 Q18): the specs
// only bring each screen to the state its board shows.
//
// Step 6.3a: the waiter pad (Pad.dc.html): the lock screen's keypad, the menu with an order of three lines at a table, the tables sheet
// open, and the menu in Amharic.

const SHOTS = path.join("test-results", "screens");
const TABLET = { width: 1180, height: 820 };
const MESSAGES = { en, am } as const;

interface DemoWaiter {
  api: { token: string | null };
  device: string;
  code: string;
}

let waiter: Promise<DemoWaiter> | null = null;

/** The demo bakery's waiter, signed in on a tablet enrolled for these captures, with a fresh short code. Once per worker. */
function demoWaiter(): Promise<DemoWaiter> {
  waiter ??= (async () => {
    const phone = process.env.DEMO_ADMIN_PHONE ?? "";
    const password = process.env.DEMO_ADMIN_PASSWORD ?? "";
    if (!phone || !password) throw new Error("DEMO_ADMIN_PHONE and DEMO_ADMIN_PASSWORD must be set (e2e.yml): the e2e job seeds the demo bakery.");
    const { api: admin } = await loginOnce(phone, password);
    const device = await freshDevice(admin, "screens");
    const users = (await admin.get<{ data: { id: number; phone: string; roles: string[] }[] }>("/users")).data;
    const user = users.find((u) => u.roles.includes("waiter"));
    if (!user) throw new Error("the demo bakery has no waiter");
    const { api } = await loginOnce(user.phone, password);
    const { short_code: code } = await admin.post<{ short_code: string }>(`/users/${user.id}/short-code/reset`);
    return { api, device, code };
  })();
  waiter.catch(() => (waiter = null));
  return waiter;
}

/** Saves the page as it is now, once its fonts and the photos in view have loaded. */
async function capture(page: Page, name: string): Promise<void> {
  await fontsReady(page);
  await page
    .waitForFunction(
      () =>
        Array.from(document.images).every((img) => {
          const box = img.getBoundingClientRect();
          const inView = box.bottom > 0 && box.top < window.innerHeight && box.right > 0 && box.left < window.innerWidth;
          return !inView || img.complete;
        }),
      null,
      { timeout: 15_000 },
    )
    .catch(() => undefined);
  mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`) });
}

/** The pad of the demo waiter in `locale`, unlocked, with the first three products that can be sold in the order. */
async function padWithOrder(page: Page, locale: keyof typeof MESSAGES, shotLocked?: string): Promise<void> {
  const t = MESSAGES[locale];
  const who = await demoWaiter();
  await openAs(page, who.api, `${locale}/pad/`, { device: who.device });
  await expect(page.getByRole("heading", { name: t.pad.enter_code })).toBeVisible({ timeout: 30_000 });
  if (shotLocked) await capture(page, shotLocked);
  await typeCode(page, who.code, t.pad.unlock);
  await expect(page.getByRole("heading", { name: t.pad.enter_code })).toBeHidden({ timeout: 30_000 });
  const add = page.getByRole("tabpanel").getByRole("button", { name: locale === "am" ? /ን ጨምር፣/ : /^Add / });
  await expect(add.first()).toBeVisible({ timeout: 30_000 });
  for (const index of [0, 0, 1, 2]) await add.nth(index).click();
}

test.describe("screens", () => {
  test.skip(live, "CI only: the captures read the seeded demo bakery");
  test.use({ viewport: TABLET });

  test("the waiter pad (Pad board): locked, with an order, the tables sheet, in en", async ({ page }) => {
    test.setTimeout(120_000);
    const t = MESSAGES.en;
    await padWithOrder(page, "en", "pad-locked-1180x820-en");
    // a table from the sheet, as the board's "Table 7"
    await page.getByRole("button", { name: t.pad.change_table_aria }).click();
    const sheet = page.getByRole("dialog", { name: t.pad.tables });
    await expect(sheet.getByRole("button", { name: /^Table / }).first()).toBeVisible({ timeout: 30_000 });
    await capture(page, "pad-tables-1180x820-en");
    await sheet.getByRole("button", { name: /^Table / }).first().click();
    await expect(sheet).toHaveCount(0);
    await capture(page, "pad-1180x820-en");
    await page.getByRole("button", { name: /^My orders/ }).click();
    await expect(page.getByRole("dialog", { name: t.pad.orders_title })).toBeVisible();
    await capture(page, "pad-orders-1180x820-en");
  });

  test("the waiter pad (Pad board) with an order, in am", async ({ page }) => {
    test.setTimeout(120_000);
    await padWithOrder(page, "am");
    await capture(page, "pad-1180x820-am");
  });
});
