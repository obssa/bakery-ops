import { test, expect, type Browser, type Page, type Request } from "@playwright/test";
import { requireAdmin, TICKET_VISIBLE_MS } from "../support/env";
import { buildFlowFixture, clearStaleOrders, ledgerReasonsOf, menuAvailable, type FlowFixture } from "../support/fixture";
import { openAs, openPadAndUnlock } from "../support/pad";

// Phase 1 gate project p1-order-flow (manifest phases.1.gate.criteria; docs/phases/phase-1-plan.md section 7), the full
// flow of step 1.6c with four browser contexts, one per screen: the waiter pad, the kitchen display, the bar display
// and the cashier till.
//
// The waiter unlocks the pad with the short code, picks a table, adds a chicken sandwich (kitchen), a latte (bar) and a
// display-case croissant, and sends. The kitchen and bar tickets are on their rails within 5 s through the polling
// transport (after the first load only tickets?since= requests with If-None-Match). Each station accepts and marks its
// line ready; the pad shows the ready badge; the waiter marks the lines served; the till takes cash with the change due
// and the order is closed. Then, through the API: the ledger holds reservation + production_consume for the sandwich's
// and the latte's ingredients, reservation + sale for the croissant, and available is stocked minus sold.
//
// Every device starts signed in (POST /login is throttled, so the token of each fixture session is placed where the app
// keeps it); the sign-in form has its own tests. The fixture is built through the admin API inside the isolated
// "E2E Test Branch" (e2e/support/fixture.ts), so the flow carries @live.

const TABLET = { width: 1280, height: 800 };

function ticketRequests(page: Page, stationId: number): Request[] {
  const seen: Request[] = [];
  page.on("request", (request) => {
    if (request.method() === "GET" && new URL(request.url()).pathname.endsWith(`/stations/${stationId}/tickets`)) seen.push(request);
  });
  return seen;
}

/** After the first load, every tickets request carries a cursor and If-None-Match (ADR 0009). */
async function expectPollingDiscipline(requests: Request[], label: string) {
  const headers = await Promise.all(requests.map((request) => request.allHeaders()));
  expect(requests.length, `${label}: the rail polled`).toBeGreaterThan(2);
  expect(new URL(requests[0].url()).searchParams.has("since"), `${label}: the first load has no cursor`).toBe(false);
  requests.slice(1).forEach((request, index) => {
    expect(new URL(request.url()).searchParams.get("since"), `${label}: request ${index + 2} carries a cursor`).toMatch(/^\d+$/);
    expect(headers[index + 1]["if-none-match"], `${label}: request ${index + 2} is conditional`).toBeTruthy();
  });
}

async function work(page: Page, product: string) {
  const ticket = page.getByRole("article").filter({ hasText: product });
  await expect(ticket).toBeVisible({ timeout: TICKET_VISIBLE_MS });
  await ticket.getByRole("button", { name: `Accept ${product}` }).click();
  await expect(ticket).toContainText("Accepted");
  await ticket.getByRole("button", { name: `Ready ${product}` }).click();
  await expect(ticket).toContainText("Ready for pickup");
}

async function runFlow(f: FlowFixture, pages: { pad: Page; kitchen: Page; bar: Page; till: Page }) {
  const { pad, kitchen, bar, till } = pages;
  const sandwich = f.kitchen.product.name_en;
  const latte = f.bar.product.name_en;
  const croissant = f.croissant.name_en;

  // the kitchen and bar displays are open and polling before anything is sent
  const kitchenRequests = ticketRequests(kitchen, f.kitchen.station.id);
  const barRequests = ticketRequests(bar, f.bar.station.id);
  await openAs(kitchen, f.chef.api, "en/kds/kitchen/");
  await openAs(bar, f.barista.api, "en/kds/bar/");
  await expect(kitchen.getByRole("heading", { level: 1, name: "Kitchen" })).toBeVisible({ timeout: 30_000 });
  await expect(bar.getByRole("heading", { level: 1, name: "Bar" })).toBeVisible({ timeout: 30_000 });

  // the till is open on its queue
  await openAs(till, f.cashier.api, "en/till/");
  await expect(till.getByRole("heading", { level: 1, name: "Cashier till" })).toBeVisible({ timeout: 30_000 });

  // the waiter: short code, table, three products, send
  await openPadAndUnlock(pad, f.waiter);
  await pad.getByRole("button", { name: `Table ${f.table.code}` }).click();
  await pad.getByRole("tab", { name: "Other" }).click();
  for (const product of [sandwich, latte, croissant]) await pad.getByRole("button", { name: `Add ${product}` }).click();
  await expect(pad.getByRole("region", { name: "Cart" })).toContainText("330.00 ETB");
  await pad.getByRole("button", { name: "Send order" }).click();
  await expect(pad.getByRole("status").filter({ hasText: "Order sent" })).toBeVisible({ timeout: 30_000 });

  // the kitchen and the bar see their ticket within 5 s; the latte is not on the kitchen rail
  await expect(kitchen.getByRole("article").filter({ hasText: sandwich })).toBeVisible({ timeout: TICKET_VISIBLE_MS });
  await expect(bar.getByRole("article").filter({ hasText: latte })).toBeVisible({ timeout: TICKET_VISIBLE_MS });
  await expect(kitchen.getByRole("article").filter({ hasText: latte })).toHaveCount(0);
  await work(kitchen, sandwich);
  await work(bar, latte);

  // the pad: three lines ready (the croissant was ready at send), then the waiter serves each
  await pad.getByRole("tab", { name: /My orders/ }).click();
  // this run's own order shows its three lines ready (the badge also counts any other open order of the shared waiter)
  const card = pad.getByRole("article").filter({ hasText: sandwich });
  for (const product of [sandwich, latte, croissant]) await expect(card.getByRole("button", { name: `Mark ${product} served` })).toBeVisible({ timeout: 15_000 });
  for (const product of [sandwich, latte, croissant]) await card.getByRole("button", { name: `Mark ${product} served` }).click();
  await expect(card.getByText("Served", { exact: true })).toHaveCount(3, { timeout: 15_000 });

  const mine = await f.waiter.api.get<{ data: { id: number; order_no: string | number; status: string; created_via: string; lines: { item: { id: number } }[] }[] }>("/orders", { mine: 1 });
  const order = mine.data.find((o) => o.lines.some((l) => l.item.id === f.kitchen.product.id));
  if (!order) throw new Error("the sent order is not on the waiter's list");
  await expect.poll(async () => (await f.waiter.api.get<{ status: string }>(`/orders/${order.id}`)).status, { timeout: 15_000 }).toBe("served");
  expect(order.created_via).toBe("waiter_pad");

  // the till: the order is in the queue; cash 400 against 330.00 gives 70.00 change
  await expect(till.getByRole("region", { name: "Orders awaiting payment" })).toBeVisible();
  await till.getByRole("button", { name: `Take payment for order ${order.order_no}`, exact: true }).click({ timeout: 15_000 });
  const sheet = till.getByRole("dialog", { name: `Payment for order ${order.order_no}` });
  await expect(sheet).toContainText("330.00 ETB");
  // digital channels are listed but cannot be used until Phase 4
  await expect(sheet.getByRole("button", { name: /Telebirr/ })).toBeDisabled();
  await expect(sheet).toContainText("Photo required, available in Phase 4");
  await sheet.getByRole("button", { name: "Cash", exact: true }).click();
  for (const digit of "400") await sheet.getByRole("button", { name: digit, exact: true }).click();
  await expect(sheet).toContainText("Change due: 70.00 ETB");
  await sheet.getByRole("button", { name: "Confirm payment" }).click();
  await expect(sheet).toContainText("Payment recorded", { timeout: 30_000 });
  await expect(sheet).toContainText("Give change: 70.00 ETB");
  await sheet.getByRole("button", { name: "Done" }).click();
  await expect(till.getByRole("button", { name: `Take payment for order ${order.order_no}`, exact: true })).toHaveCount(0, { timeout: 15_000 });

  // closed, and paid in full
  await expect.poll(async () => (await f.waiter.api.get<{ status: string }>(`/orders/${order.id}`)).status, { timeout: 15_000 }).toBe("closed");
  const closed = await f.waiter.api.get<{ totals: { total: string; paid: string; balance: string } }>(`/orders/${order.id}`);
  expect(closed.totals).toMatchObject({ total: "330.00", paid: "330.00", balance: "0.00" });

  // the ledger: reserved at send, consumed at ready (made to order), sold when the order closed (display case)
  expect(await ledgerReasonsOf(f.admin, f.kitchen.ingredient.id, f.kitchen.location.id)).toEqual(["purchase_in", "reservation", "production_consume"]);
  expect(await ledgerReasonsOf(f.admin, f.bar.ingredient.id, f.bar.location.id)).toEqual(["purchase_in", "reservation", "production_consume"]);
  expect(await ledgerReasonsOf(f.admin, f.croissant.id, f.displayCase.id)).toEqual(["purchase_in", "reservation", "sale"]);

  // available = stocked minus sold: 10 kg at 0.2 kg each is 50 portions, one sold; 5 croissants, one sold
  expect(await menuAvailable(f.waiter.api, f.kitchen.product.id)).toBe("49.0000");
  expect(await menuAvailable(f.waiter.api, f.bar.product.id)).toBe("49.0000");
  expect(await menuAvailable(f.waiter.api, f.croissant.id)).toBe("4.0000");

  // polling discipline on both rails
  await expectPollingDiscipline(kitchenRequests, "kitchen");
  await expectPollingDiscipline(barRequests, "bar");
}

async function openContexts(browser: Browser, baseURL: string | undefined) {
  const make = () => browser.newContext({ baseURL, viewport: TABLET, serviceWorkers: "block" });
  const contexts = await Promise.all([make(), make(), make(), make()]);
  const [pad, kitchen, bar, till] = await Promise.all(contexts.map((context) => context.newPage()));
  return { contexts, pages: { pad, kitchen, bar, till } };
}

test.describe("p1 order flow", () => {
  test("pad to kitchen and bar displays to ready, served, cash paid and closed @live", async ({ browser }, testInfo) => {
    test.setTimeout(240_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    const fixture = await buildFlowFixture(credentials);
    const { contexts, pages } = await openContexts(browser, testInfo.project.use.baseURL);
    try {
      await runFlow(fixture, pages);
    } catch (error) {
      // the extra contexts have no automatic screenshots: keep one per screen for the CI report
      for (const [name, page] of Object.entries(pages)) {
        await testInfo.attach(`${name}.png`, { body: await page.screenshot().catch(() => Buffer.alloc(0)), contentType: "image/png" });
      }
      throw error;
    } finally {
      await Promise.all(contexts.map((context) => context.close()));
    }
  });
});
