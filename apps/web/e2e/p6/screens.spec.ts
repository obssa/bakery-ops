import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { fontsReady } from "../support/a11y";
import { uuid, type Api } from "../support/api";
import { demoPerson, demoProduction } from "../support/demoProduction";
import { freshDevice } from "../support/devices";
import { live } from "../support/env";
import { loginOnce } from "../support/fixture";
import { openAs, typeCode } from "../support/pad";
import { currentShift, ensureOpenShift, finishClosingCounts, resolvePendingCounts } from "../support/shifts";

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
// Step 6.3c: the production board (Production.dc.html) with a card in each column, a request card open, New batch and the display case;
// and the count screen (the spec's primitives) with a line that differs and one not counted yet. The count closes or starts the cake
// room's shift, so it runs last and leaves the shift open again.

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

/** The demo admin's production board in `locale`, drawn: its four columns, a card in one of them and the product thumbs. */
async function openBoard(page: Page, locale: keyof typeof MESSAGES): Promise<void> {
  const t = MESSAGES[locale];
  const demo = await demoProduction();
  await openAs(page, demo.admin, `${locale}/production/`, { device: demo.device });
  await expect(page.getByRole("heading", { level: 1, name: t.production.title })).toBeVisible({ timeout: 30_000 });
  for (const column of ["col_requested", "col_in_progress", "col_pending_confirmation", "col_done_today"] as const) {
    await expect(page.getByRole("region", { name: t.production[column], exact: true })).toBeVisible({ timeout: 30_000 });
  }
  await expect(page.getByRole("article").first()).toBeVisible({ timeout: 30_000 });
}

/** A decimal string moved by `step` whole units, never below zero (fixture figures only; quantities stay strings, ADR 0004). */
function moved(qty: string, step: number): string {
  const [whole, fraction = ""] = qty.split(".");
  const units = BigInt(whole) * BigInt(10_000) + BigInt(fraction.padEnd(4, "0").slice(0, 4));
  let next = units + BigInt(step) * BigInt(10_000);
  if (next < BigInt(0)) next = units + BigInt(2 * Math.abs(step)) * BigInt(10_000);
  const digits = next.toString().padStart(5, "0");
  return `${digits.slice(0, -4)}.${digits.slice(-4)}`;
}

interface CountRow {
  id: number;
  type: string;
  status: string;
  cash: unknown;
  lines: { item: { id: number }; expected_qty: string | null }[];
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

  test("the station top bar on the kitchen display, in en", async ({ page }) => {
    test.setTimeout(120_000);
    const chef = await demoAccount("chef");
    await openAs(page, chef.api, "en/kds/kitchen/", { device: chef.device });
    await expect(page.getByRole("heading", { level: 1, name: "Kitchen" })).toBeVisible({ timeout: 30_000 });
    await capture(page, "kds-kitchen-1180x820-en");
    // the bar's menus open: the screens and the person's (the dates' calendar and Sign out)
    await page.getByRole("button", { name: en.nav.main, exact: true }).click();
    await expect(page.getByRole("navigation", { name: en.nav.main })).toBeVisible();
    await capture(page, "kds-kitchen-screens-menu-1180x820-en");
  });

  test("the production board (Production board): the board, a request card, New batch and the display case, in en", async ({ page }) => {
    test.setTimeout(120_000);
    const t = MESSAGES.en.production;
    const demo = await demoProduction();
    await openBoard(page, "en");
    await capture(page, "production-1180x820-en");

    // the request card in its drawer: the admin's batch in the oven, opened on its Done form by the card's own action
    const finish = demo.inOven ? page.getByRole("button", { name: `${t.action_finish}: ${demo.inOven.name_en}` }) : null;
    if (finish && (await finish.count()) > 0) await finish.click();
    else await page.getByRole("article").first().getByRole("button").first().click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await capture(page, "production-card-1180x820-en");
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog")).toHaveCount(0);

    await page.getByRole("button", { name: t.new_batch }).click();
    await expect(page.getByRole("dialog", { name: t.new_batch_title })).toBeVisible();
    await capture(page, "production-new-batch-1180x820-en");
    await page.keyboard.press("Escape");

    await page.getByRole("button", { name: t.display_case }).click();
    await expect(page.getByRole("dialog", { name: t.display_case })).toBeVisible();
    await capture(page, "production-display-case-1180x820-en");
  });

  test("the production board (Production board), in am", async ({ page }) => {
    test.setTimeout(120_000);
    await openBoard(page, "am");
    await capture(page, "production-1180x820-am");
  });

  test("the count screen (the spec's primitives): a line that differs and one not counted yet, in en and am", async ({ page }) => {
    test.setTimeout(180_000);
    const demo = await demoProduction();
    const cakeRoom = demo.stations.find((s) => s.code === "cake_room");
    if (!cakeRoom) throw new Error("the demo bakery has no cake room");
    // the cake baker counts her station; the admin, who never counted, co-signs and approves when the shift is put back
    const baker = await demoPerson(demo, "cake_baker");
    let shiftId: number | null = null;
    try {
      // a count to show: the closing of the cake room's open shift, or the opening of a new one
      let shift = await currentShift(demo.admin, cakeRoom);
      if (shift?.status === "open") await baker.api.post(`/shifts/${shift.id}/close`, {});
      if (!shift) await baker.api.post(`/stations/${cakeRoom.id}/shifts/start`, { client_uuid: uuid() });
      shift = await currentShift(demo.admin, cakeRoom);
      if (!shift) throw new Error("the cake room has no running shift to count");
      shiftId = shift.id;

      // one line counted short (it needs a reason), one counted as expected, the rest not counted yet
      const counts = (await baker.api.get<{ data: CountRow[] }>(`/shifts/${shift.id}/counts`)).data;
      const count = counts.find((c) => c.status === "draft" && !c.cash && c.lines.length > 0);
      if (count) {
        const [short, same] = count.lines;
        const lines = [{ item_id: short.item.id, counted_qty: moved(short.expected_qty ?? "0", -1), confirm: true }];
        if (same) lines.push({ item_id: same.item.id, counted_qty: same.expected_qty ?? "0", confirm: true });
        await baker.api.put(`/stock-counts/${count.id}/lines`, { lines });
      }

      for (const locale of ["en", "am"] as const) {
        const t = MESSAGES[locale].counts;
        await openAs(page, baker.api, `${locale}/shifts/count/?shift=${shift.id}`, { device: demo.device });
        await expect(page.getByRole("heading", { level: 1, name: t.title })).toBeVisible({ timeout: 30_000 });
        await expect(page.getByRole("button", { name: t.review_button })).toBeVisible({ timeout: 30_000 });
        await capture(page, `count-1180x820-${locale}`);
      }
    } finally {
      // the cake room's shift open again: the baker hands the counts in as they stand, the admin decides what waits, a carried start
      // (finishClosingCounts submits every draft of the shift, an opening's as well). Nothing after this capture needs the cake room, so a
      // failure here is said in the log rather than failing the screenshots.
      try {
        if (shiftId !== null) await finishClosingCounts(baker.api, shiftId);
        await resolvePendingCounts(demo.admin, cakeRoom);
        await ensureOpenShift(demo.admin, cakeRoom);
      } catch (error) {
        console.warn(`[screens] the cake room's shift was not put back: ${error instanceof Error ? error.message : String(error)}`);
      }
    }
  });
});
