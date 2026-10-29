import { expect, test } from "@playwright/test";
import { shortDeviceId } from "../../src/lib/device";
import { ApiFailure, type Api } from "../support/api";
import { deviceOf } from "../support/devices";
import { live, MULTI_CONTEXT_TEST_MS, requireAdmin, run } from "../support/env";
import { buildWaiter, findOrCreateStation, loginOnce } from "../support/fixture";
import { openAs, typeCode } from "../support/pad";

// Phase 5 gate project p5-device (docs/phases/phase-5-plan.md, step 5.1b and section 7; ADR 0017 and its 2026-10-29 note).
//
// CI runs the stack with IDENTITY_REQUIRE_DEVICE=true (compose.ci.yml). Every other context of every project sends a device enrolled
// for its role in global setup (e2e/support/devices.ts); these flows use a tablet nobody enrolled yet:
// - the pad, the kitchen display and the till answer it with the "not enrolled" screen; the purely admin pages (catalog, people,
//   devices) still open on it, because their routes are not station routes;
// - the admin enrols it in place from that screen, and after a reload the pad works (a waiter unlocks it with a short code);
// - the admin revokes it from another computer, and the tablet's next request brings the screen back with a new code (a revoked id is
//   never enrolled again);
// - a device enrolled in another branch (the demo bakery's) is refused;
// - a print bridge's token is shown once, with a copy button.
// The live host runs with enforcement off until Ramin's devices-enrolled sign-off (plan 10.5), so none of this carries @live.

const GATE = { level: 1, name: "This tablet is not enrolled" } as const;
const TABLET = { width: 1280, height: 800 };

/** The message key of a 403 the API answers `path` with on this device, or undefined when it answers. */
async function refusalOf(api: Api, path: string): Promise<string | undefined> {
  try {
    await api.get(path);
    return undefined;
  } catch (error) {
    if (error instanceof ApiFailure && error.status === 403) return error.key;
    throw error;
  }
}

interface ThisDevice {
  enrolled: boolean;
  revoked: boolean;
  device: { id: number; name: string; kind: string } | null;
  enforcement: boolean;
}

test.describe("p5 device enrolment", () => {
  test.skip(live, "the live host runs with device enforcement off until the devices-enrolled sign-off (phase-5 plan 10.5)");

  test("an unknown tablet is refused on the pad, the KDS and the till, opens the admin pages, is enrolled in place, and is refused again once revoked", async ({
    browser,
  }, testInfo) => {
    test.setTimeout(3 * MULTI_CONTEXT_TEST_MS);
    const credentials = requireAdmin(test.skip);
    const { admin, waiter } = await buildWaiter(credentials);
    await findOrCreateStation(admin, "kitchen");
    const tablet = `e2e-${run}-tablet`;
    const name = `E2E tablet ${run}`;
    const onTablet = admin.onDevice(tablet);

    // the API: a station route refuses the unknown tablet, the probe tells it so
    expect(await refusalOf(onTablet, "/menu")).toBe("identity.device.unknown");
    expect(await onTablet.get<ThisDevice>("/devices/this")).toEqual({ enrolled: false, revoked: false, device: null, enforcement: true });

    const baseURL = testInfo.project.use.baseURL;
    const tabletContext = await browser.newContext({ baseURL, viewport: TABLET, serviceWorkers: "block" });
    const officeContext = await browser.newContext({ baseURL, viewport: TABLET, serviceWorkers: "block" });
    try {
      const page = await tabletContext.newPage();
      const gate = page.getByRole("heading", GATE);
      const code = page.getByText(/^Device code: /);

      // the station screens show the screen instead of their work, with the device's code and what to do
      await openAs(page, admin, "en/pad/", { device: tablet });
      await expect(gate).toBeVisible({ timeout: 30_000 });
      await expect(code).toHaveText(`Device code: ${shortDeviceId(tablet)}`);
      await expect(page.getByText(/Every computer and tablet that uses Bakery Ops is enrolled once/)).toBeVisible();
      await expect(page.getByText("This tablet is not enrolled; ask the admin to enrol it on this screen.")).toBeVisible();
      for (const path of ["en/kds/kitchen/", "en/till/"]) {
        await page.goto(path);
        await expect(gate).toBeVisible({ timeout: 30_000 });
      }

      // the purely admin pages open on the same tablet
      await page.goto("en/admin/catalog/");
      await expect(page.getByRole("heading", { level: 1, name: "Catalog" })).toBeVisible({ timeout: 30_000 });
      await page.goto("en/admin/people/");
      await expect(page.getByRole("heading", { level: 1, name: "People" })).toBeVisible({ timeout: 30_000 });
      await expect(page.getByRole("cell", { name: waiter.name, exact: true })).toBeVisible({ timeout: 30_000 });
      await page.getByRole("link", { name: "Devices", exact: true }).click();
      await expect(page.getByRole("heading", { level: 1, name: "Devices" })).toBeVisible({ timeout: 30_000 });
      await expect(page.getByText("This device is not enrolled.")).toBeVisible({ timeout: 30_000 });
      await expect(gate).toHaveCount(0);

      // the admin enrols it in place from the screen; the screen reloads the page, and the pad works
      await page.goto("en/pad/");
      await expect(gate).toBeVisible({ timeout: 30_000 });
      await page.getByLabel("Device name").fill(name);
      await page.getByRole("button", { name: "Enrol this device" }).click();
      const lock = page.getByRole("heading", { name: "Enter your short code" });
      await expect(lock).toBeVisible({ timeout: 30_000 });
      await page.reload();
      await expect(lock).toBeVisible({ timeout: 30_000 });
      await expect(gate).toHaveCount(0);
      expect(await onTablet.get<ThisDevice>("/devices/this")).toMatchObject({ enrolled: true, revoked: false, device: { name, kind: "tablet" } });
      expect(await refusalOf(onTablet, "/menu")).toBeUndefined();
      await typeCode(page, waiter.shortCode);
      await expect(page.getByText(`Acting: ${waiter.name}`)).toBeVisible({ timeout: 30_000 });

      // the admin revokes it from the office computer (enrolled for the admin in global setup), with a confirmation
      const office = await officeContext.newPage();
      await openAs(office, admin, "en/admin/people/devices/");
      const row = office.getByRole("row").filter({ hasText: name });
      await expect(row).toBeVisible({ timeout: 30_000 });
      await expect(row).toContainText(shortDeviceId(tablet));
      await expect(row).toContainText("Active");
      await row.getByRole("button", { name: "Revoke" }).click();
      const confirm = office.getByRole("dialog", { name: `Revoke ${name}?` });
      await expect(confirm).toContainText("never enrolled again");
      await confirm.getByRole("button", { name: "Revoke" }).click();
      await expect(confirm).toHaveCount(0);
      await expect(row).toContainText("Revoked", { timeout: 30_000 });
      expect(await refusalOf(onTablet, "/menu")).toBe("identity.device.revoked");

      // the tablet's next request (the pad's next look at the menu) brings the screen back, and the tablet now has a new id
      await expect(gate).toBeVisible({ timeout: 30_000 });
      await expect(code).toBeVisible();
      await expect(code).not.toHaveText(`Device code: ${shortDeviceId(tablet)}`);
    } finally {
      await tabletContext.close();
      await officeContext.close();
    }
  });

  test("a device enrolled in another branch is refused", async ({ page }) => {
    const credentials = requireAdmin(test.skip);
    const { api: admin } = await loginOnce(credentials.phone, credentials.password);
    const demoPhone = process.env.DEMO_ADMIN_PHONE;
    if (!demoPhone) throw new Error("DEMO_ADMIN_PHONE must be set (e2e.yml): global setup enrols a device in the demo bakery's branch.");
    const elsewhere = deviceOf(demoPhone);
    const onElsewhere = admin.onDevice(elsewhere);

    expect(await refusalOf(onElsewhere, "/menu")).toBe("identity.device.other_branch");
    // the probe reads another branch's device as unknown: it says nothing about other branches
    expect(await onElsewhere.get<ThisDevice>("/devices/this")).toMatchObject({ enrolled: false, revoked: false, device: null });

    await openAs(page, admin, "en/pad/", { device: elsewhere });
    await expect(page.getByRole("heading", GATE)).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText("This tablet is enrolled in another branch; ask the admin.")).toBeVisible();
  });

  test("the admin adds a print bridge and its token is shown once, with a copy button", async ({ browser }, testInfo) => {
    const credentials = requireAdmin(test.skip);
    const { api: admin } = await loginOnce(credentials.phone, credentials.password);
    const bridge = `E2E bridge ${run}`;
    const context = await browser.newContext({
      baseURL: testInfo.project.use.baseURL,
      viewport: TABLET,
      serviceWorkers: "block",
      permissions: ["clipboard-read", "clipboard-write"],
    });
    try {
      const page = await context.newPage();
      await openAs(page, admin, "en/admin/people/devices/");
      await expect(page.getByRole("heading", { level: 1, name: "Devices" })).toBeVisible({ timeout: 30_000 });
      await page.getByLabel("Bridge name").fill(bridge);
      await page.getByRole("button", { name: "Add bridge" }).click();
      const token = page.getByLabel(`Bridge token for ${bridge}`);
      await expect(token).toHaveValue(/^[0-9a-f]{64}$/, { timeout: 30_000 });
      const value = await token.inputValue();
      await page.getByRole("button", { name: "Copy" }).click();
      await expect(page.getByText("Copied.")).toBeVisible();
      expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(value);

      await page.getByRole("button", { name: "Done" }).click();
      await expect(token).toHaveCount(0);
      const row = page.getByRole("row").filter({ hasText: bridge });
      await expect(row).toContainText("Print bridge", { timeout: 30_000 });
      await page.reload();
      await expect(row).toBeVisible({ timeout: 30_000 });
      expect(await page.content()).not.toContain(value);

      // the list never carries a token
      const bridges = await admin.get<{ data: Record<string, unknown>[] }>("/devices", { kind: "bridge" });
      const listed = bridges.data.find((d) => d.name === bridge);
      expect(listed).toBeDefined();
      expect(listed).not.toHaveProperty("token");
    } finally {
      await context.close();
    }
  });
});
