import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { fontsReady, settledAnimations } from "../support/a11y";
import type { Api } from "../support/api";
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
// open, My orders, and the menu in Amharic; and the station top bar every station screen now has (the kitchen display's, before step
// 6.3b rebuilds the display under it).
//
// Step 6.4a: the admin frame and its first pages, as the demo bakery's admin, at 1440 x 900: Today (Admin.dc.html) in English and in
// Amharic, the catalog list as a table and as pad cards (CatalogList.dc.html), the Orders board in the new frame, and the sidebar as the
// drawer of an 820 px tablet held upright.

const SHOTS = path.join("test-results", "screens");
const TABLET = { width: 1180, height: 820 };
const MESSAGES = { en, am } as const;

interface DemoAccount {
  api: { token: string | null };
  device: string;
  code: string;
}

interface DemoBakery {
  admin: Api;
  device: string;
  users: { id: number; phone: string; roles: string[] }[];
}

let bakery: Promise<DemoBakery> | null = null;

/** The demo bakery as its admin sees it, and a tablet enrolled there for these captures. Once per worker. */
function demoBakery(): Promise<DemoBakery> {
  bakery ??= (async () => {
    const phone = process.env.DEMO_ADMIN_PHONE ?? "";
    const password = process.env.DEMO_ADMIN_PASSWORD ?? "";
    if (!phone || !password) throw new Error("DEMO_ADMIN_PHONE and DEMO_ADMIN_PASSWORD must be set (e2e.yml): the e2e job seeds the demo bakery.");
    const { api: admin } = await loginOnce(phone, password);
    const device = await freshDevice(admin, "screens");
    const users = (await admin.get<{ data: { id: number; phone: string; roles: string[] }[] }>("/users")).data;
    return { admin, device, users };
  })();
  bakery.catch(() => (bakery = null));
  return bakery;
}

/** The first demo account of `role`, signed in on the captures' tablet, with a fresh short code (every demo account has the demo password). */
async function demoAccount(role: string): Promise<DemoAccount> {
  const demo = await demoBakery();
  const user = demo.users.find((u) => u.roles.includes(role));
  if (!user) throw new Error(`the demo bakery has no ${role}`);
  const { api } = await loginOnce(user.phone, process.env.DEMO_ADMIN_PASSWORD ?? "");
  const { short_code: code } = await demo.admin.post<{ short_code: string }>(`/users/${user.id}/short-code/reset`);
  return { api, device: demo.device, code };
}

/**
 * Saves the page as it is now, once it shows its loaded state: none of the `loading` texts is on it any more (a screen's "Loading…" and
 * "Checking the shift…" lines), its fonts and the photos in view have loaded, and every animation that ends has ended (a sheet sliding in
 * would be caught half way; the skeletons' endless pulse is not waited for).
 */
async function capture(page: Page, name: string, loading: string[] = []): Promise<void> {
  for (const text of loading) await expect(page.getByText(text, { exact: true })).toHaveCount(0, { timeout: 30_000 });
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
  await settledAnimations(page);
  mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`) });
}

/** The pad of the demo waiter in `locale`, unlocked, with the first three products that can be sold in the order. */
async function padWithOrder(page: Page, locale: keyof typeof MESSAGES, shotLocked?: string): Promise<void> {
  const t = MESSAGES[locale];
  const who = await demoAccount("waiter");
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
    await capture(page, "pad-tables-1180x820-en", [t.pad.loading_menu]);
    await sheet.getByRole("button", { name: /^Table / }).first().click();
    await expect(sheet).toHaveCount(0);
    await capture(page, "pad-1180x820-en", [t.pad.loading_menu]);
    await page.getByRole("button", { name: /^My orders/ }).click();
    await expect(page.getByRole("dialog", { name: t.pad.orders_title })).toBeVisible();
    await capture(page, "pad-orders-1180x820-en", [t.pad.orders_loading]);
  });

  test("the waiter pad (Pad board) with an order, in am", async ({ page }) => {
    test.setTimeout(120_000);
    await padWithOrder(page, "am");
    await capture(page, "pad-1180x820-am", [MESSAGES.am.pad.loading_menu]);
  });

  test("the station top bar on the kitchen display, in en", async ({ page }) => {
    test.setTimeout(120_000);
    const chef = await demoAccount("chef");
    await openAs(page, chef.api, "en/kds/kitchen/", { device: chef.device });
    await expect(page.getByRole("heading", { level: 1, name: "Kitchen" })).toBeVisible({ timeout: 30_000 });
    // the display loaded: its shift checked and its tickets read
    const loaded = [en.kds.loading, en.shifts.checking];
    await capture(page, "kds-kitchen-1180x820-en", loaded);
    // the bar's menus open: the screens and the person's (the dates' calendar and Sign out)
    await page.getByRole("button", { name: en.nav.main, exact: true }).click();
    await expect(page.getByRole("navigation", { name: en.nav.main })).toBeVisible();
    await capture(page, "kds-kitchen-screens-menu-1180x820-en", loaded);
  });
});

// --- step 6.4a: the admin frame, Today, the catalog list --------------------------------------------------------------------------------

const DESKTOP = { width: 1440, height: 900 };
const PORTRAIT = { width: 820, height: 1180 };

/** An admin page of the demo bakery as its admin sees it, on the captures' tablet, once its h1 shows. */
async function openAdmin(page: Page, path: string, heading: string): Promise<void> {
  const demo = await demoBakery();
  await openAs(page, demo.admin, path, { device: demo.device });
  await expect(page.getByRole("heading", { level: 1, name: heading })).toBeVisible({ timeout: 30_000 });
}

test.describe("screens: admin", () => {
  test.skip(live, "CI only: the captures read the seeded demo bakery");
  test.use({ viewport: DESKTOP });

  for (const locale of ["en", "am"] as const) {
    test(`admin Today (Admin board), in ${locale}`, async ({ page }) => {
      test.setTimeout(90_000);
      const t = MESSAGES[locale];
      await openAdmin(page, `${locale}/admin/`, t.today.title);
      // the KPI row and the top sellers have answered (figures, or the line that says they are not built yet), and the shifts card shows
      await expect(page.getByRole("group", { name: t.today.kpi_sales, exact: true })).not.toHaveAttribute("aria-busy", "true", { timeout: 30_000 });
      await expect(page.getByRole("region", { name: t.today.top_title }).locator("li, p").first()).toBeVisible({ timeout: 30_000 });
      await expect(page.getByRole("region", { name: t.today.shifts_title, exact: true })).toBeVisible();
      await capture(page, `today-1440x900-${locale}`);
    });
  }

  test("the catalog list (CatalogList board) as a table and as pad cards, in en", async ({ page }) => {
    test.setTimeout(90_000);
    const t = MESSAGES.en;
    await openAdmin(page, "en/admin/catalog/", t.catalog.title);
    const table = page.getByRole("table", { name: t.catalog.items });
    // the first product's price has been read (the rows in view ask for theirs)
    await expect(table.getByRole("row").nth(1)).toContainText(/ETB|No price/, { timeout: 30_000 });
    await capture(page, "catalog-1440x900-en");

    await page.getByRole("button", { name: t.catalog.view_grid }).click();
    await expect(page).toHaveURL(/[?&]view=grid/);
    const grid = page.getByRole("region", { name: t.catalog.grid });
    await expect(grid.getByRole("listitem").first()).toContainText(/ETB/, { timeout: 30_000 });
    await capture(page, "catalog-grid-1440x900-en");

    // the drawer "Add product" opens: the new item form and the categories
    await page.getByRole("button", { name: t.catalog.add_product }).click();
    await expect(page.getByRole("dialog", { name: t.catalog.new_item })).toBeVisible();
    await capture(page, "catalog-new-item-1440x900-en");
  });

  test("the Orders board in the admin frame, in en", async ({ page }) => {
    test.setTimeout(90_000);
    const t = MESSAGES.en;
    await openAdmin(page, "en/admin/orders/", t.adminOrders.title);
    await expect(page.getByText(t.adminOrders.loading)).toHaveCount(0, { timeout: 30_000 });
    await capture(page, "orders-1440x900-en");
  });

  test("the admin sidebar as the drawer of an 820 px tablet held upright, in en", async ({ page }) => {
    test.setTimeout(90_000);
    const t = MESSAGES.en;
    await page.setViewportSize(PORTRAIT);
    await openAdmin(page, "en/admin/", t.today.title);
    await capture(page, "today-820x1180-en");
    await page.getByRole("button", { name: t.admin.open_sections }).click();
    await expect(page.getByRole("dialog", { name: t.admin.sections })).toBeVisible();
    await capture(page, "admin-drawer-820x1180-en");
  });
});
