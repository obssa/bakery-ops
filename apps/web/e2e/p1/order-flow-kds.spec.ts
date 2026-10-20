import { test, expect, type Page, type Request, type Response } from "@playwright/test";
import { requireAdmin } from "../support/env";
import { buildStationFixture, ledgerReasons, sendOrder, type StationFixture } from "../support/fixture";

// Phase 1 gate project p1-order-flow, the kitchen half (step 1.6a; step 1.6c adds the pad, the bar and the till to the
// full flow and replaces the fixme in order-flow.spec.ts). docs/phases/phase-1-plan.md section 7, ADR 0009.
//
// A chef signs in on the kitchen display. A waiter (through the API; the pad arrives in step 1.6b) sends an order for a
// chicken sandwich. The ticket appears on the chef's rail within 5 s through the polling transport; after the first
// load the display only issues tickets?since=... requests that carry If-None-Match, and an idle rail is answered 304.
// The chef accepts and marks the line ready; once the waiter serves it (through the API) the ticket leaves the rail.
// The ledger then shows the sandwich's ingredient reserved, then consumed.
//
// Everything is built through the admin API inside the isolated "E2E Test Branch" (e2e/support/fixture.ts), so the
// flow carries @live (admin-smoke.spec.ts explains how each environment provides the admin).

async function signInAs(page: Page, phone: string, password: string, next: string) {
  await page.goto(`en/login/?next=${encodeURIComponent(next)}`);
  await page.getByLabel("Phone number").fill(phone);
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Sign in" }).click();
}

function isTicketsRequest(fixture: StationFixture, url: string): boolean {
  return new URL(url).pathname.endsWith(`/stations/${fixture.station.id}/tickets`);
}

test.describe("p1 order flow: kitchen display", () => {
  test("a sent order reaches the kitchen rail, is accepted and made ready, and leaves when served @live", async ({ page }) => {
    test.setTimeout(120_000);
    const credentials = requireAdmin(test.skip);
    const fixture = await buildStationFixture(credentials, "kitchen");

    const ticketRequests: Request[] = [];
    const ticketResponses: Response[] = [];
    page.on("request", (request) => {
      if (request.method() === "GET" && isTicketsRequest(fixture, request.url())) ticketRequests.push(request);
    });
    page.on("response", (response) => {
      if (response.request().method() === "GET" && isTicketsRequest(fixture, response.url())) ticketResponses.push(response);
    });

    // the chef signs in and lands on the kitchen display, which picks the one station assigned to them
    await signInAs(page, fixture.worker.phone, fixture.worker.password, "/kds/kitchen");
    await expect(page).toHaveURL(/\/app\/en\/kds\/kitchen\/$/, { timeout: 30_000 });
    await expect(page.getByRole("heading", { level: 1, name: "Kitchen" })).toBeVisible();

    // the chime can be muted, and the choice shows
    await page.getByRole("button", { name: "Mute chime" }).click();
    await expect(page.getByRole("button", { name: "Unmute chime" })).toHaveAttribute("aria-pressed", "true");
    await page.getByRole("button", { name: "Unmute chime" }).click();
    await expect(page.getByRole("button", { name: "Mute chime" })).toHaveAttribute("aria-pressed", "false");

    // an idle rail is polled with a cursor and answered 304 (nothing changes, nothing is re-read)
    await expect.poll(() => ticketResponses.filter((r) => r.status() === 304).length, { timeout: 40_000 }).toBeGreaterThan(0);

    // the waiter sends; the ticket is on the rail within 5 s
    const order = await sendOrder(fixture);
    const line = order.lines[0];
    const ticket = page.getByRole("article").filter({ hasText: fixture.product.name_en });
    await expect(ticket).toBeVisible({ timeout: 5_000 });
    await expect(ticket).toContainText(`Table ${fixture.table.code}`);
    await expect(ticket).toContainText(fixture.waiter.name);
    await expect(ticket).toContainText("New");

    // accept, then ready
    await ticket.getByRole("button", { name: `Accept ${fixture.product.name_en}` }).click();
    await expect(ticket).toContainText("Accepted");
    await ticket.getByRole("button", { name: `Ready ${fixture.product.name_en}` }).click();
    await expect(ticket).toContainText("Ready for pickup");
    await expect.poll(async () => (await fixture.waiter.api.get<{ lines: { id: number; status: string }[] }>(`/orders/${order.id}`)).lines[0].status).toBe("ready");

    // served through the API (the pad does it in 1.6b): the ticket leaves the rail
    await fixture.waiter.api.post(`/order-lines/${line.id}/served`);
    await expect(ticket).toHaveCount(0, { timeout: 10_000 });

    // the ledger: the ingredient was reserved at send and consumed at ready
    expect(await ledgerReasons(fixture)).toEqual(["purchase_in", "reservation", "production_consume"]);

    // polling discipline (ADR 0009): the first load has no cursor, every later request has one and If-None-Match
    const headers = await Promise.all(ticketRequests.map((request) => request.allHeaders()));
    expect(ticketRequests.length).toBeGreaterThan(3);
    expect(new URL(ticketRequests[0].url()).searchParams.has("since")).toBe(false);
    ticketRequests.slice(1).forEach((request, index) => {
      expect(new URL(request.url()).searchParams.get("since"), `request ${index + 2} carries a cursor`).toMatch(/^\d+$/);
      expect(headers[index + 1]["if-none-match"], `request ${index + 2} is conditional`).toBeTruthy();
    });

    // the same display in Amharic
    await page.getByRole("link", { name: "አማርኛ" }).click();
    await expect(page).toHaveURL(/\/app\/am\/kds\/kitchen\/$/);
    await expect(page.getByRole("heading", { level: 1, name: "ወጥ ቤት" })).toBeVisible();
    await expect(page.getByRole("button", { name: "ድምጹን ዝጋ" })).toBeVisible();
  });
});
