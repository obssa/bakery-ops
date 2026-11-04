import { randomUUID } from "node:crypto";
import { mkdirSync } from "node:fs";
import path from "node:path";
import { expect, test, type Locator, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { fontsReady, settledAnimations } from "../support/a11y";
import { uuid, type Api } from "../support/api";
import { demoPerson, demoProduction } from "../support/demoProduction";
import { freshDevice } from "../support/devices";
import { live } from "../support/env";
import { loginOnce } from "../support/fixture";
import { openAs, typeCode } from "../support/pad";
import { dropFile, FIXTURE_PHOTO } from "../support/productPhoto";
import { currentShift, ensureOpenShift, finishClosingCounts, resolvePendingCounts, settleStation } from "../support/shifts";

// Screenshots for review (brief "Design fidelity"; phase-6 plan section 7), in project p6-a11y, so they run in e2e-p6 on the demo bakery
// that SEED_PHASE=6 gives its demo photos. Each Phase 6 step that rebuilds a screen captures it here, at 1180 x 820 for a station screen
// (the tablet the design boards are drawn for) and 1440 x 900 for an admin page, in English and once in Amharic, under
// test-results/screens/<screen>-<size>-<locale>.png. e2e-p6 uploads the folder as the artifact "screens" on every run; the coordinator
// compares each picture with its board before the merge. Nothing is asserted about pixels (no golden images, plan 10.2 Q18): the specs
// only bring each screen to the state its board shows.
//
// Step 6.3a: the waiter pad (Pad.dc.html): the lock screen's keypad, the menu with an order of three lines at a table, the tables sheet
// open, My orders, and the menu in Amharic; and the station top bar every station screen now has (its Screens menu).
//
// Step 6.3b: the kitchen and bar displays (Kitchen.dc.html) with a ticket in each column, and the till (Till.dc.html) with an order chosen,
// its pay sheet beside the list, the cash received typed and the change shown. The tickets and the orders are sent here, in the demo
// bakery, by its waiter; the kitchen and the bar make some of them, so New, Cooking and Ready each hold one.
//
// Step 6.3c: the production board (Production.dc.html) with a card in each column, a request card open, New batch and the display case;
// and the count screen (the spec's primitives) with a line that differs and one not counted yet. The count closes or starts the cake
// room's shift, so it runs last of the station screens and leaves the shift open again.
//
// Step 6.4a: the admin frame and its first pages, as the demo bakery's admin, at 1440 x 900: Today (Admin.dc.html) in English and in
// Amharic, the catalog list as a table and as pad cards (CatalogList.dc.html), the Orders board in the new frame, and the sidebar as the
// drawer of an 820 px tablet held upright.
//
// Step 6.4b: the admin's product page at 1440 x 900 (ProductPhoto.dc.html, PhotoStates.dc.html): with a photo (en, am), the framing step,
// no photo yet and a refusal (the describe at the end).
//
// Step 6.4c: the Sell and Make screens on the admin primitives, at 1440 x 900 as the demo bakery's admin, each brought to the state the
// catalog list's board would draw it in (loaded, its first rows in view): the Orders board with a card's timeline open, an order's record,
// Payments, Payment methods, Reconciliation and Receipt settings, the stock list, the ledger, purchases with one open, suppliers,
// transfers, reorder and the planner, in English, and Payments and the stock list in Amharic.
//
// phase-6/shift-strip: the shift's attention strip (the describe at the end): the till with no shift, with a count in progress, with the shift
// open, and each with the panel open, at 1180 x 820 and at 390 x 844; the kitchen display at night with the strip, a refusal and the panel; the
// production board with the strip. It settles shifts, so it runs last, and it puts them back. The one thing it asserts about a picture: at the
// phone's width the page does not scroll sideways, on the demo bakery's real names and order cards, which are longer than the fixtures'.

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
/** `fullPage`: the whole page, for a screen taller than the window (6.4b's framing step, drawn on a 1180 px tall board). */
async function capture(page: Page, name: string, loading: string[] = [], fullPage = false): Promise<void> {
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
  await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage });
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

interface DemoUser {
  id: number;
  phone: string;
  roles: string[];
}

interface MenuEntry {
  item_id: number;
  station_id: number | null;
  available: string;
}

interface Stations {
  kitchen: number | null;
  bar: number | null;
  till: number | null;
}

interface DemoStations {
  device: string;
  /** a signed-in account of each role, on the capture tablet; the admin when the demo bakery has no one of that role */
  as: (role: "waiter" | "chef" | "barista" | "cashier") => Promise<Api>;
  admin: Api;
  stations: Stations;
  menu: MenuEntry[];
  tableIds: number[];
}

let demo: Promise<DemoStations> | null = null;

/** The demo bakery's accounts and stations, on a tablet enrolled for these captures; each station with a shift running. Once per worker. */
function demoStations(): Promise<DemoStations> {
  demo ??= (async () => {
    const phone = process.env.DEMO_ADMIN_PHONE ?? "";
    const password = process.env.DEMO_ADMIN_PASSWORD ?? "";
    if (!phone || !password) throw new Error("DEMO_ADMIN_PHONE and DEMO_ADMIN_PASSWORD must be set (e2e.yml): the e2e job seeds the demo bakery.");
    const { api: signedIn } = await loginOnce(phone, password);
    const device = await freshDevice(signedIn, "screens-stations");
    const admin = signedIn.onDevice(device);
    const users = (await admin.get<{ data: DemoUser[] }>("/users")).data;
    const as = async (role: "waiter" | "chef" | "barista" | "cashier") => {
      const user = users.find((u) => u.roles.includes(role));
      if (!user) return admin;
      return (await loginOnce(user.phone, password)).api.onDevice(device);
    };
    const all = (await admin.get<{ data: { id: number; code: string }[] }>("/stations")).data;
    const first = (code: string) => all.filter((s) => s.code === code).sort((a, b) => a.id - b.id)[0]?.id ?? null;
    const stations = { kitchen: first("kitchen"), bar: first("bar"), till: first("till") };
    for (const id of [stations.kitchen, stations.bar, stations.till]) if (id !== null) await ensureOpenShift(admin, { id }).catch(() => undefined);
    const waiter = await as("waiter");
    const menu = (await waiter.get<{ items: MenuEntry[] }>("/menu")).items.filter((item) => !/^0(\.0+)?$/.test(item.available));
    const tables = await waiter.get<{ data: { id: number }[] } | { id: number }[]>("/dining-tables");
    const tableIds = (Array.isArray(tables) ? tables : tables.data).map((t) => t.id);
    return { device, as, admin, stations, menu, tableIds };
  })();
  demo.catch(() => (demo = null));
  return demo;
}

interface Sent {
  id: number;
  lines: { id: number }[];
}

/** The demo waiter sends an order of `count` different products made at `station` (any station when null), at the n-th table. */
async function send(d: DemoStations, station: number | null, count: number, table: number): Promise<Sent | null> {
  const items = d.menu.filter((item) => (station === null ? item.station_id !== null : item.station_id === station)).slice(0, count);
  if (items.length === 0) return null;
  const waiter = await d.as("waiter");
  const tableId = d.tableIds[table % Math.max(1, d.tableIds.length)];
  return waiter
    .post<Sent>("/orders", {
      client_uuid: randomUUID(),
      ...(tableId !== undefined ? { type: "dine_in", dining_table_id: tableId } : { type: "takeaway" }),
      send: true,
      lines: items.map((item) => ({ client_uuid: randomUUID(), item_id: item.item_id, qty: "1" })),
    })
    .catch(() => null);
}

/** A ticket in each column of a display: one new, one being made, one waiting for pickup. */
async function fillRail(d: DemoStations, station: number | null, worker: Api): Promise<void> {
  if (station === null) return;
  const sent = [await send(d, station, 2, 1), await send(d, station, 1, 2), await send(d, station, 1, 3)];
  for (const [index, order] of sent.entries()) {
    if (!order || index === 0) continue;
    for (const line of order.lines) {
      await worker.post(`/order-lines/${line.id}/accept`, {}).catch(() => undefined);
      if (index === 2) await worker.post(`/order-lines/${line.id}/ready`, {}).catch(() => undefined);
    }
  }
}

async function openDisplay(page: Page, d: DemoStations, kind: "kitchen" | "bar", locale: keyof typeof MESSAGES, worker: Api): Promise<void> {
  const t = MESSAGES[locale];
  await openAs(page, worker, `${locale}/kds/${kind}/`, { device: d.device });
  await expect(page.getByRole("heading", { level: 1, name: kind === "kitchen" ? t.kds.kitchen_title : t.kds.bar_title })).toBeVisible({ timeout: 30_000 });
  // the rail and the strip as the demo bakery has them: a capture is taken even if the seed left one empty (it is a picture, not a gate)
  await expect(page.getByRole("article").first())
    .toBeVisible({ timeout: 30_000 })
    .catch(() => undefined);
  await expect(page.getByRole("region", { name: t.stations.strip_title }).getByRole("listitem").first())
    .toBeVisible({ timeout: 15_000 })
    .catch(() => undefined);
}

/** The till of the demo bakery with the order of the most products chosen: its pay sheet open beside the waiting list. */
async function openTill(page: Page, d: DemoStations, locale: keyof typeof MESSAGES, cashier: Api): Promise<void> {
  const t = MESSAGES[locale];
  await openAs(page, cashier, `${locale}/till/`, { device: d.device });
  await expect(page.getByRole("heading", { level: 1, name: t.till.title })).toBeVisible({ timeout: 30_000 });
  const queue = page.getByRole("region", { name: t.till.queue_title });
  const cards = queue.getByRole("listitem");
  await expect(cards.first()).toBeVisible({ timeout: 30_000 });
  // the card whose stack shows the most thumbs, as the board's chosen one
  let best = 0;
  let most = -1;
  const count = await cards.count();
  for (let i = 0; i < count; i += 1) {
    const thumbs = await cards.nth(i).locator("img").count();
    if (thumbs > most) {
      most = thumbs;
      best = i;
    }
  }
  await cards.nth(best).getByRole("button").click();
  await expect(page.getByRole("dialog").getByRole("status", { name: t.till.amount_due })).toBeVisible({ timeout: 30_000 });
}

/**
 * Brings the screen to its board's state and saves it as `name`, whatever happens on the way: a capture is a picture for review, not a
 * gate, so a step that cannot be done (the seed lacks a ticket, a key is not there) is noted on the test and the screen is saved as it is.
 */
async function shoot(page: Page, name: string, steps: () => Promise<void>): Promise<void> {
  try {
    await steps();
  } catch (error) {
    test.info().annotations.push({ type: "screen", description: `${name}: ${error instanceof Error ? error.message.split("\n")[0] : String(error)}` });
  }
  await capture(page, name).catch(() => undefined);
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

  test("the kitchen and bar displays (Kitchen board): New, Cooking and Ready, in en and am", async ({ page }) => {
    test.setTimeout(240_000);
    const d = await demoStations();
    const chef = await d.as("chef");
    const barista = await d.as("barista");
    await fillRail(d, d.stations.kitchen, chef).catch(() => undefined);
    await fillRail(d, d.stations.bar, barista).catch(() => undefined);

    await shoot(page, "kds-kitchen-1180x820-en", () => openDisplay(page, d, "kitchen", "en", chef));
    await shoot(page, "kds-kitchen-1180x820-am", () => openDisplay(page, d, "kitchen", "am", chef));
    await shoot(page, "kds-bar-1180x820-en", () => openDisplay(page, d, "bar", "en", barista));
  });

  test("the till (Till board): an order chosen with its pay sheet, the cash received and the change, in en and am", async ({ page }) => {
    test.setTimeout(240_000);
    const d = await demoStations();
    // the board's list: a few orders waiting, the chosen one with four products (three thumbs and +1)
    await send(d, null, 4, 4);
    await send(d, null, 2, 5);
    await send(d, null, 3, 6);
    const cashier = await d.as("cashier");
    const t = MESSAGES.en;

    await shoot(page, "till-1180x820-en", () => openTill(page, d, "en", cashier));
    await shoot(page, "till-cash-1180x820-en", async () => {
      const row = page.getByRole("dialog").getByRole("group").first();
      // the board's Received and Change: the smallest quick key that covers the bill, else the exact amount
      let pressed = false;
      for (const key of ["300", "500", "1,000"]) {
        const button = row.getByRole("button", { name: key, exact: true });
        if ((await button.count()) > 0 && (await button.isEnabled())) {
          await button.click();
          pressed = true;
          break;
        }
      }
      if (!pressed) await row.getByRole("button", { name: t.till.exact }).click();
      await expect(row).toContainText(t.till.change_label);
    });
    await shoot(page, "till-1180x820-am", () => openTill(page, d, "am", cashier));
  });

  test("the production board (Production board): the board, a request card, New batch and the display case, in en", async ({ page }) => {
    test.setTimeout(120_000);
    const t = MESSAGES.en.production;
    const demo = await demoProduction();
    await openBoard(page, "en");
    await capture(page, "production-1180x820-en");

    // the request card in its panel beside the board: the admin's batch in the oven, opened on its Done form by the card's own action
    const card = page.getByRole("region", { name: /^Request card: / });
    const finish = demo.inOven ? page.getByRole("button", { name: `${t.action_finish}: ${demo.inOven.name_en}` }) : null;
    if (finish && (await finish.count()) > 0) await finish.click();
    else await page.getByRole("article").first().getByRole("button").first().click();
    await expect(card).toBeVisible();
    await capture(page, "production-card-1180x820-en");
    await page.keyboard.press("Escape");
    await expect(card).toHaveCount(0);

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
      // the KPI row and the top sellers have answered (figures, or the line that says there are none yet), and the shifts card shows
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

// --- step 6.4c: the Sell and Make screens ---------------------------------------------------------------------------------------------

interface DemoPayment {
  id: number;
  order: { id: number } | null;
  receipt: unknown;
}

test.describe("screens: sell and make", () => {
  test.skip(live, "CI only: the captures read the seeded demo bakery");
  test.use({ viewport: DESKTOP });

  test("the Orders board with a card's timeline open, and an order's record, in en", async ({ page }) => {
    test.setTimeout(120_000);
    const t = MESSAGES.en;
    await openAdmin(page, "en/admin/orders/", t.adminOrders.title);
    await expect(page.getByText(t.adminOrders.loading)).toHaveCount(0, { timeout: 30_000 });
    const open = page.getByRole("button", { name: /^Open order / });
    if ((await open.count()) > 0) {
      await open.first().click();
      await expect(page.getByRole("region", { name: /^Order .* timeline$/ })).toBeVisible();
      await capture(page, "orders-timeline-1440x900-en", [t.adminOrders.timeline_loading]);
    }

    // the record of an order the demo bakery was paid for, one with a receipt photo when there is one
    const demo = await demoBakery();
    const payments = (await demo.admin.get<{ data: DemoPayment[] }>("/payments")).data.filter((p) => p.order !== null);
    const paid = payments.find((p) => p.receipt !== null) ?? payments[0];
    test.skip(!paid, "the demo bakery has no paid order to show");
    await page.goto(`en/admin/orders/record/?id=${paid.order?.id}`);
    await expect(page.getByRole("heading", { level: 1, name: /^Order / })).toBeVisible({ timeout: 30_000 });
    await capture(page, "order-record-1440x900-en", [t.orderRecord.loading, t.orderRecord.payments_loading]);
  });

  for (const locale of ["en", "am"] as const) {
    test(`Payments, in ${locale}`, async ({ page }) => {
      test.setTimeout(90_000);
      const t = MESSAGES[locale];
      await openAdmin(page, `${locale}/admin/payments/`, t.adminPayments.list.title);
      await capture(page, `payments-1440x900-${locale}`, [t.adminPayments.list.loading]);
    });

    test(`the stock list, in ${locale}`, async ({ page }) => {
      test.setTimeout(90_000);
      const t = MESSAGES[locale];
      await openAdmin(page, `${locale}/admin/inventory/`, t.inventory.stock.title);
      await capture(page, `inventory-1440x900-${locale}`, [t.common.loading]);
    });
  }

  test("Payment methods, Reconciliation and Receipt settings, in en", async ({ page }) => {
    test.setTimeout(120_000);
    const t = MESSAGES.en;
    const sections = page.getByRole("navigation", { name: t.adminPayments.sections });
    await openAdmin(page, "en/admin/payments/channels/", t.adminPayments.channels.title);
    await capture(page, "payments-channels-1440x900-en", [t.common.loading]);
    await sections.getByRole("link", { name: t.adminPayments.nav.reconciliation, exact: true }).click();
    await expect(page.getByRole("heading", { level: 1, name: t.adminPayments.reconciliation.title })).toBeVisible({ timeout: 30_000 });
    await capture(page, "payments-reconciliation-1440x900-en", [t.adminPayments.reconciliation.loading]);
    await sections.getByRole("link", { name: t.adminPayments.nav.receipts, exact: true }).click();
    await expect(page.getByRole("heading", { level: 1, name: t.adminPayments.receipts.title })).toBeVisible({ timeout: 30_000 });
    await capture(page, "payments-receipts-1440x900-en", [t.common.loading]);
  });

  test("the ledger, purchases with one open, suppliers, transfers and reorder, in en", async ({ page }) => {
    test.setTimeout(150_000);
    const t = MESSAGES.en;
    const sections = page.getByRole("navigation", { name: t.inventory.sections });
    await openAdmin(page, "en/admin/inventory/ledger/", t.inventory.ledger.title);
    await capture(page, "inventory-ledger-1440x900-en", [t.common.loading]);

    await sections.getByRole("link", { name: t.inventory.nav.purchases, exact: true }).click();
    await expect(page.getByRole("heading", { level: 1, name: t.inventory.purchases.title })).toBeVisible({ timeout: 30_000 });
    await capture(page, "inventory-purchases-1440x900-en", [t.common.loading]);
    const open = page.getByRole("button", { name: /^Open purchase / });
    if ((await open.count()) > 0) {
      await open.first().click();
      await expect(page.getByRole("region", { name: /^Purchase / })).toBeVisible({ timeout: 30_000 });
      await page.evaluate(() => window.scrollTo(0, 0));
      await capture(page, "inventory-purchase-1440x900-en", [t.common.loading]);
    }

    await sections.getByRole("link", { name: t.inventory.nav.suppliers, exact: true }).click();
    await expect(page.getByRole("heading", { level: 1, name: t.inventory.suppliers.title })).toBeVisible({ timeout: 30_000 });
    await capture(page, "inventory-suppliers-1440x900-en", [t.common.loading]);

    await sections.getByRole("link", { name: t.inventory.nav.transfers, exact: true }).click();
    await expect(page.getByRole("heading", { level: 1, name: t.inventory.transfers.title })).toBeVisible({ timeout: 30_000 });
    // every status, so the demo's confirmed and rejected transfers show beside the requested ones
    await page.getByRole("group", { name: t.inventory.transfers.status }).getByRole("button", { name: t.inventory.transfers.all, exact: true }).click();
    await capture(page, "inventory-transfers-1440x900-en", [t.common.loading]);

    await sections.getByRole("link", { name: t.inventory.nav.reorder, exact: true }).click();
    await expect(page.getByRole("heading", { level: 1, name: t.inventory.reorder.title })).toBeVisible({ timeout: 30_000 });
    await capture(page, "inventory-reorder-1440x900-en", [t.common.loading]);
  });

  test("the planner, in en", async ({ page }) => {
    test.setTimeout(90_000);
    const t = MESSAGES.en;
    await openAdmin(page, "en/admin/planner/", t.planner.title);
    await capture(page, "planner-1440x900-en", [t.common.loading]);
  });
});

// --- step 6.4d: the Team, Insight and System screens -------------------------------------------------------------------------------------
//
// As the demo bakery's admin at 1440 x 900, each in its loaded state: People and Devices; the Shifts overview, the roster and the history;
// the analytics overview and its Sales page; the audit log; System. People and the analytics overview in Amharic too. A step that cannot be
// done (the seed lacks a row) is noted and the screen is saved as it is (shoot).

test.describe("screens: team, insight and system", () => {
  test.skip(live, "CI only: the captures read the seeded demo bakery");
  test.use({ viewport: DESKTOP });

  for (const locale of ["en", "am"] as const) {
    test(`People (the admin's table, form and stations), in ${locale}`, async ({ page }) => {
      test.setTimeout(90_000);
      const t = MESSAGES[locale];
      await shoot(page, `people-1440x900-${locale}`, async () => {
        await openAdmin(page, `${locale}/admin/people/`, t.people.title);
        await expect(page.getByRole("table", { name: t.people.list }).getByRole("row").nth(1).getByRole("checkbox")).toBeVisible({ timeout: 30_000 });
      });
    });

    test(`the analytics overview (the KPI row, the share bars, the best sellers), in ${locale}`, async ({ page }) => {
      test.setTimeout(90_000);
      const t = MESSAGES[locale];
      await shoot(page, `analytics-1440x900-${locale}`, async () => {
        await openAdmin(page, `${locale}/admin/analytics/`, t.analytics.overview.title);
        await expect(page.getByRole("group", { name: t.analytics.kpi.revenue, exact: true })).not.toHaveAttribute("aria-busy", "true", { timeout: 30_000 });
      });
    });
  }

  test("Devices, in en", async ({ page }) => {
    test.setTimeout(90_000);
    const t = MESSAGES.en;
    await shoot(page, "devices-1440x900-en", async () => {
      await openAdmin(page, "en/admin/people/devices/", t.devices.title);
      await expect(page.getByRole("table", { name: t.devices.list }).getByRole("row").nth(1)).toContainText(/\S/, { timeout: 30_000 });
      await expect(page.getByText(t.common.loading, { exact: true })).toHaveCount(0, { timeout: 30_000 });
    });
  });

  test("the Shifts overview, the roster and the history, in en", async ({ page }) => {
    test.setTimeout(120_000);
    const t = MESSAGES.en;
    await shoot(page, "shifts-1440x900-en", async () => {
      await openAdmin(page, "en/admin/shifts/", t.counts.admin_title);
      await expect(page.getByRole("region", { name: t.adminShifts.setup.title }).getByText(t.adminShifts.setup.checking)).toHaveCount(0, { timeout: 30_000 });
      await expect(page.getByText(t.adminShifts.running.loading)).toHaveCount(0, { timeout: 30_000 });
    });
    await shoot(page, "shifts-roster-1440x900-en", async () => {
      await page.getByRole("navigation", { name: t.adminShifts.sections }).getByRole("link", { name: t.adminShifts.nav.roster, exact: true }).click();
      await expect(page.getByRole("heading", { level: 1, name: t.adminShifts.title_roster })).toBeVisible({ timeout: 30_000 });
      await expect(page.getByText(t.adminShifts.roster.loading, { exact: true })).toHaveCount(0, { timeout: 30_000 });
      await expect(page.getByRole("table").first()).toBeVisible({ timeout: 30_000 });
    });
    await shoot(page, "shifts-history-1440x900-en", async () => {
      await page.getByRole("navigation", { name: t.adminShifts.sections }).getByRole("link", { name: t.adminShifts.nav.history, exact: true }).click();
      await expect(page.getByRole("heading", { level: 1, name: t.adminShifts.title_history })).toBeVisible({ timeout: 30_000 });
      await expect(page.getByRole("table", { name: t.adminShifts.history.shifts_title, exact: true }).getByRole("row").nth(1).getByRole("cell").first()).toContainText(/\d/, { timeout: 30_000 });
    });
  });

  test("the analytics Sales page (the line, its days, the hours), in en", async ({ page }) => {
    test.setTimeout(90_000);
    const t = MESSAGES.en;
    await shoot(page, "analytics-sales-1440x900-en", async () => {
      await openAdmin(page, "en/admin/analytics/sales/", t.analytics.sales.title);
      await expect(page.getByRole("region", { name: t.analytics.sales.hourly }).getByRole("status", { name: t.analytics.loading })).toHaveCount(0, { timeout: 30_000 });
    });
  });

  test("the audit log, in en", async ({ page }) => {
    test.setTimeout(90_000);
    const t = MESSAGES.en;
    await shoot(page, "audit-1440x900-en", async () => {
      await openAdmin(page, "en/admin/audit/", t.audit.title);
      await expect(page.getByRole("table", { name: t.audit.list.table, exact: true }).getByRole("button", { name: /^Open entry / }).first()).toBeVisible({ timeout: 30_000 });
    });
  });

  test("System, in en", async ({ page }) => {
    test.setTimeout(90_000);
    const t = MESSAGES.en;
    await shoot(page, "system-1440x900-en", async () => {
      await openAdmin(page, "en/admin/system/", t.system.title);
      await expect(page.getByTestId("system-scheduler")).toBeVisible({ timeout: 30_000 });
    });
  });
});

// Step 6.4b: the product page (ProductPhoto.dc.html and PhotoStates.dc.html) at 1440 x 900, an admin's size. A demo product with its demo
// photo, in en and am; the framing step, the fixture chosen through "Replace", zoomed to 1.2x and dragged, then cancelled so the demo photo
// stays; and a second demo product without its photo (removed here, put back after from its own large rendition): the empty dropzone, and
// a refusal under the card (a GIF dropped on it).
interface DemoProduct {
  id: number;
  name_en: string;
  name_am: string;
  image: { large: string } | null;
}

async function demoProduct(name: string): Promise<{ demo: DemoBakery; product: DemoProduct }> {
  const demo = await demoBakery();
  const products = (await demo.admin.get<{ data: DemoProduct[] }>("/items", { kind: "product" })).data;
  const product = products.find((p) => p.name_en === name) ?? products.find((p) => p.image !== null) ?? products[0];
  if (!product) throw new Error("the demo bakery has no product");
  return { demo, product };
}

async function openProduct(page: Page, demo: DemoBakery, product: DemoProduct, locale: keyof typeof MESSAGES): Promise<void> {
  const t = MESSAGES[locale];
  // the stock chip and the preview's card come from the pad's menu: the capture waits for its answer
  const menu = page.waitForResponse((response) => response.url().includes("/api/v1/menu"), { timeout: 30_000 }).catch(() => undefined);
  await openAs(page, demo.admin, `${locale}/admin/catalog/?item=${product.id}`, { device: demo.device });
  await expect(page.getByRole("heading", { level: 1, name: locale === "am" ? product.name_am : product.name_en })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText(t.common.loading, { exact: true })).toHaveCount(0, { timeout: 30_000 });
  await menu;
}

test.describe("screens: the product page", () => {
  test.skip(live, "CI only: the captures read the seeded demo bakery");
  test.use({ viewport: DESKTOP });

  test("the product page (ProductPhoto board): with its photo in en and am, and the framing step", async ({ page }) => {
    test.setTimeout(180_000);
    const { demo, product } = await demoProduct("Croissant");
    const card = page.getByRole("region", { name: en.photo.title, exact: true });
    await shoot(page, "product-photo-1440x900-en", async () => {
      await openProduct(page, demo, product, "en");
      await expect(card.getByRole("img", { name: product.name_en })).toBeVisible({ timeout: 15_000 });
    });
    await shoot(page, "product-photo-1440x900-am", async () => {
      await openProduct(page, demo, product, "am");
      await expect(page.getByRole("region", { name: am.photo.title, exact: true }).getByRole("img", { name: product.name_am })).toBeVisible({ timeout: 15_000 });
    });
    await shoot(page, "product-framing-1440x900-en", async () => {
      await openProduct(page, demo, product, "en");
      const chooser = page.waitForEvent("filechooser");
      await card.getByRole("button", { name: en.photo.replace }).click();
      await (await chooser).setFiles(FIXTURE_PHOTO);
      const slider = card.getByRole("slider", { name: en.photo.zoom });
      await expect(slider).toBeVisible({ timeout: 15_000 });
      await slider.focus();
      for (let i = 0; i < 20; i += 1) await slider.press("ArrowRight");
      const frame = card.getByRole("group", { name: en.photo.frame_label });
      const box = await frame.boundingBox();
      if (box) {
        await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
        await page.mouse.down();
        await page.mouse.move(box.x + box.width / 2 - 40, box.y + box.height / 2 - 30, { steps: 5 });
        await page.mouse.up();
      }
      await frame.focus();
    });
    // the whole framing step, as the board draws it: the preview and the buttons under the frame
    await capture(page, "product-framing-page-1440x900-en", [], true).catch(() => undefined);
    // cancelled: the demo photo stays as it was
    await card.getByRole("button", { name: en.photo.cancel }).click().catch(() => undefined);
  });

  test("the product page (PhotoStates board): no photo yet, and a refusal under the card", async ({ page }) => {
    test.setTimeout(180_000);
    const { demo, product } = await demoProduct("Bottled water 0.5 L");
    const large = product.image ? Buffer.from(await (await fetch(`${demo.admin.origin}${product.image.large}`)).arrayBuffer()) : null;
    await demo.admin.delete(`/items/${product.id}/image`);
    try {
      const card = page.getByRole("region", { name: en.photo.title, exact: true });
      const zone = card.getByTestId("photo-dropzone");
      await shoot(page, "product-empty-1440x900-en", async () => {
        await openProduct(page, demo, product, "en");
        await expect(zone).toBeVisible({ timeout: 15_000 });
      });
      await shoot(page, "product-refusal-1440x900-en", async () => {
        await dropFile(zone, { name: "counter.gif", mimeType: "image/gif", buffer: Buffer.from("GIF89a", "binary") });
        await expect(card.getByRole("alert")).toBeVisible();
      });
    } finally {
      if (large) {
        const form = new FormData();
        form.append("photo", new Blob([large], { type: "image/webp" }), "large.webp");
        form.append("client_uuid", randomUUID());
        await demo.admin.postForm(`/items/${product.id}/image`, form).catch(() => undefined);
      }
    }
  });
});

// --- phase-6/shift-strip: the shift's prompt under the bar, covering nothing ---------------------------------------------------------------

const PHONE = { width: 390, height: 844 };

test.describe("screens: the shift's strip", () => {
  test.skip(live, "CI only: the captures read the seeded demo bakery");
  test.use({ viewport: TABLET });

  /** The page as it stands, at the tablet's size and at the phone's, as <name>-<size>-<locale>.png. */
  async function both(page: Page, name: string, locale: keyof typeof MESSAGES = "en"): Promise<void> {
    await page.setViewportSize(TABLET);
    await capture(page, `${name}-1180x820-${locale}`);
    await page.setViewportSize(PHONE);
    await capture(page, `${name}-390x844-${locale}`);
    const { scroll, client } = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
    expect(scroll, `${name} at 390 x 844 scrolls sideways`).toBeLessThanOrEqual(client);
    await page.setViewportSize(TABLET);
  }

  /** What the page reads after its first paint has come, so the picture is of the screen at work: `what` shows, or the capture goes on without it. */
  async function settled(what: Locator): Promise<void> {
    await expect(what).toBeVisible({ timeout: 30_000 }).catch(() => undefined);
  }

  test("the till: no shift, a count in progress and the shift open, with the panel closed and open, at 1180 x 820 and 390 x 844", async ({ page }) => {
    test.setTimeout(240_000);
    const t = MESSAGES.en.shifts;
    const d = await demoStations();
    if (d.stations.till === null) throw new Error("the demo bakery has no till");
    const till = { id: d.stations.till };
    const cashier = await d.as("cashier");
    const toggle = page.getByRole("region", { name: t.region, exact: true }).locator("button[data-shift-toggle]");
    const strip = page.getByTestId("shift-strip");
    try {
      await settleStation(d.admin, till);
      await resolvePendingCounts(d.admin, till);
      await openAs(page, cashier, "en/till/", { device: d.device });
      await expect(strip).toContainText(t.idle, { timeout: 30_000 });
      await settled(page.getByRole("region", { name: MESSAGES.en.till.queue_title }).locator("img").first());
      await both(page, "till-no-shift");
      await toggle.click();
      await both(page, "till-no-shift-panel");
      await page.keyboard.press("Escape");

      await strip.getByRole("button", { name: t.start, exact: true }).click();
      await expect(strip).toContainText(t.opening, { timeout: 15_000 });
      await both(page, "till-count");
      await toggle.click();
      await both(page, "till-count-panel");
      await page.keyboard.press("Escape");

      await ensureOpenShift(d.admin, till);
      await expect(strip).toBeHidden({ timeout: 30_000 });
      await both(page, "till-shift-open");
      await toggle.click();
      await both(page, "till-shift-open-panel");
    } finally {
      await ensureOpenShift(d.admin, till);
    }
  });

  test("the till in Amharic: the strip with no shift, at 1180 x 820 and 390 x 844", async ({ page }) => {
    test.setTimeout(120_000);
    const t = MESSAGES.am.shifts;
    const d = await demoStations();
    if (d.stations.till === null) throw new Error("the demo bakery has no till");
    const till = { id: d.stations.till };
    const cashier = await d.as("cashier");
    try {
      await settleStation(d.admin, till);
      await resolvePendingCounts(d.admin, till);
      await openAs(page, cashier, "am/till/", { device: d.device });
      await expect(page.getByTestId("shift-strip")).toContainText(t.idle, { timeout: 30_000 });
      await settled(page.getByRole("region", { name: MESSAGES.am.till.queue_title }).locator("img").first());
      await both(page, "till-no-shift", "am");
    } finally {
      await ensureOpenShift(d.admin, till);
    }
  });

  test("the kitchen display at night: the strip, a refusal in it and the panel, in en, and the strip in am", async ({ page }) => {
    test.setTimeout(240_000);
    const d = await demoStations();
    if (d.stations.kitchen === null) throw new Error("the demo bakery has no kitchen");
    const kitchen = { id: d.stations.kitchen };
    const chef = await d.as("chef");
    const strip = page.getByTestId("shift-strip");
    const said = "No shift is open at this station. Start a shift here before moving its stock.";
    try {
      await settleStation(d.admin, kitchen);
      await resolvePendingCounts(d.admin, kitchen);
      await openDisplay(page, d, "kitchen", "am", chef);
      await expect(strip).toContainText(MESSAGES.am.shifts.idle, { timeout: 30_000 });
      await capture(page, "kds-kitchen-strip-1180x820-am");

      await openDisplay(page, d, "kitchen", "en", chef);
      await expect(strip).toContainText(MESSAGES.en.shifts.idle, { timeout: 30_000 });
      await both(page, "kds-kitchen-strip");

      await page.route("**/stock-strip*", (route) =>
        route.fulfill({
          status: 409,
          contentType: "application/json",
          body: JSON.stringify({ message_key: "inventory.shift.required", message_en: said, message_am: said, station_id: kitchen.id, location_id: null }),
        }),
      );
      await page.reload();
      await expect(strip).toContainText(said, { timeout: 30_000 });
      await settled(page.getByRole("article").first());
      await both(page, "kds-kitchen-strip-refusal");
      await strip.getByRole("button", { name: MESSAGES.en.shifts.strip_more, exact: true }).click();
      await both(page, "kds-kitchen-strip-panel");
    } finally {
      await page.unroute("**/stock-strip*");
      await ensureOpenShift(d.admin, kitchen);
    }
  });

  test("the production board: the strip above its columns, as the cake baker with no shift, in en", async ({ page }) => {
    test.setTimeout(180_000);
    const t = MESSAGES.en;
    const demo = await demoProduction();
    const cakeRoom = demo.stations.find((s) => s.code === "cake_room");
    if (!cakeRoom) throw new Error("the demo bakery has no cake room");
    const baker = await demoPerson(demo, "cake_baker");
    try {
      await settleStation(demo.admin, cakeRoom);
      await resolvePendingCounts(demo.admin, cakeRoom);
      await openAs(page, baker.api, "en/production/", { device: demo.device });
      await expect(page.getByRole("heading", { level: 1, name: t.production.title })).toBeVisible({ timeout: 30_000 });
      await expect(page.getByTestId("shift-strip")).toContainText(t.shifts.idle, { timeout: 30_000 });
      await settled(page.getByRole("article").first().locator("img").first());
      await both(page, "production-strip");
    } finally {
      await ensureOpenShift(demo.admin, cakeRoom);
    }
  });
});
