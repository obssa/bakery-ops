import { expect, test, type Page } from "@playwright/test";
import { deviceOf, placeDevice } from "../support/devices";
import { live, requireAdmin } from "../support/env";
import { buildStationFixture, clearStaleOrders, sendOrder } from "../support/fixture";

// phase-6/stuck-screens, item 22 (the kitchen display's double tap), on the CI stack in the E2E Test Branch. The chef taps Accept; the write
// goes and is accepted, but the display has not read the line's new status yet (its reads are held here, answered 304: nothing changed). Before,
// Accept was free again in that moment, and a second tap made a second write the API refused (the line had moved on) into the conflict tray.
// Now the tap holds the line's buttons until the display reads the line again: one write, no refusal, and the line moves on once read.

async function signInAs(page: Page, phone: string, password: string, next: string) {
  await placeDevice(page, deviceOf(phone));
  await page.goto(`en/login/?next=${encodeURIComponent(next)}`);
  await page.getByLabel("Phone number").fill(phone);
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Sign in" }).click();
}

test.describe("phase-6 stuck screens: the kitchen display", () => {
  test.skip(live, "CI stack only: it holds the display's reads");

  test("a second tap on Accept before the display reads the line again makes no second write", async ({ page }) => {
    test.setTimeout(120_000);
    const credentials = requireAdmin(test.skip);
    await clearStaleOrders(credentials);
    const fixture = await buildStationFixture(credentials, "kitchen");
    const name = fixture.product.name_en;

    await signInAs(page, fixture.worker.phone, fixture.worker.password, "/kds/kitchen");
    await expect(page.getByRole("heading", { level: 1, name: "Kitchen" })).toBeVisible({ timeout: 30_000 });
    const order = await sendOrder(fixture);
    const line = order.lines[0];
    const ticket = page.getByRole("article").filter({ hasText: name });
    await expect(ticket).toBeVisible({ timeout: 30_000 });

    // from here the display's reads of its tickets are answered "nothing changed": it keeps showing the line as new
    let holding = true;
    await page.route(
      (url) => url.pathname.endsWith(`/stations/${fixture.station.id}/tickets`),
      (route) => (holding && route.request().method() === "GET" ? route.fulfill({ status: 304 }) : route.fallback()),
    );
    const accepts: string[] = [];
    page.on("request", (request) => {
      if (request.method() === "POST" && new URL(request.url()).pathname.endsWith(`/order-lines/${line.id}/accept`)) accepts.push(request.url());
    });

    const accept = ticket.getByRole("button", { name: `Accept ${name}` });
    const answered = page.waitForResponse((response) => response.request().method() === "POST" && new URL(response.url()).pathname.endsWith(`/order-lines/${line.id}/accept`));
    await accept.click();
    expect((await answered).status()).toBe(200);
    // accepted on the server, still "new" on screen: the buttons hold
    await expect.poll(async () => (await fixture.waiter.api.get<{ lines: { id: number; status: string }[] }>(`/orders/${order.id}`)).lines[0].status).toBe("accepted");
    await expect(accept).toBeDisabled();
    await expect(ticket.getByRole("button", { name: `Reject ${name}` })).toBeDisabled();
    await accept.click({ force: true });
    // nothing more goes, and nothing lands in the tray
    await page.waitForTimeout(1_500);
    expect(accepts).toHaveLength(1);
    await expect(page.getByRole("region", { name: "Changes not saved" })).toHaveCount(0);

    // the display reads again: the line is accepted, and its next button is free
    holding = false;
    await expect(ticket).toContainText("Accepted", { timeout: 30_000 });
    await expect(ticket.getByRole("button", { name: `Ready ${name}` })).toBeEnabled();
  });
});
