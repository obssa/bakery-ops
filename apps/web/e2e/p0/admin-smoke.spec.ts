import { test, expect } from "@playwright/test";

// Phase 0 gate project p0-admin-smoke. Skeleton version: the shell renders in both languages and the
// API answers. Step 0.7 extends this to: login, create an ingredient and a product, see both listed in
// en and am (manifest phases.0.gate.criteria).
const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

test.describe("p0 admin smoke", () => {
  test("API health answers with the ledger-ready stack @live", async ({ request }) => {
    const res = await request.get(`${apiUrl}/api/v1/health`);
    expect(res.ok()).toBeTruthy();
    const body = await res.json();
    expect(body.status).toBe("ok");
    expect(body.database).toBe("ok");
  });

  test("shell renders in English by default", async ({ page }) => {
    await page.goto("/");
    await expect(page.getByRole("heading", { name: "Bakery Ops" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Admin console" })).toBeVisible();
  });

  test("shell renders in Amharic when the device locale is am", async ({ page, context, baseURL }) => {
    await context.addCookies([{ name: "locale", value: "am", url: baseURL ?? "http://localhost:3000" }]);
    await page.goto("/admin");
    await expect(page.getByRole("heading", { name: "የአስተዳዳሪ ኮንሶል" })).toBeVisible();
  });
});
