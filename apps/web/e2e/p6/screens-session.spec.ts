import { expect, test, type Page } from "@playwright/test";
import en from "../../messages/en.json";
import { deviceOf, placeDevice } from "../support/devices";
import { requireAdmin } from "../support/env";

// The sign-in's races in the browser (implicit-state review, 2026-11-20; lib/session.ts, e2e/p6/screens-session-logic.spec.ts has the
// orderings one by one). A device that still holds an old token checks it with GET /me when a page opens; when that check is slow and the
// person signs in meanwhile, its late refusal must not sign them out again. And a start page whose check gets no answer says so and offers
// Retry instead of "Checking…" for ever.

const TOKEN_KEY = "bakery-ops.token";
const STALE = "1|stale-token-of-the-person-before";

/** The old token sits in this device's storage when the first page opens (only then: a later page keeps what the app stored). */
async function placeStaleToken(page: Page): Promise<void> {
  await page.addInitScript(
    ([key, value]) => {
      try {
        if (!window.sessionStorage.getItem("e2e-stale-placed")) {
          window.sessionStorage.setItem("e2e-stale-placed", "1");
          window.localStorage.setItem(key, value);
        }
      } catch {
        // storage blocked: nothing stale to check
      }
    },
    [TOKEN_KEY, STALE],
  );
}

test.describe("p6 the sign-in's races", () => {
  test("a slow refusal of the old token, arriving after the person signed in, leaves them signed in", async ({ page }) => {
    const admin = requireAdmin(test.skip);
    await placeDevice(page, deviceOf(admin.phone));
    await placeStaleToken(page);

    let release: () => void = () => {};
    const released = new Promise<void>((resolve) => (release = resolve));
    let staleAsked = false;
    let staleAnswered = false;
    await page.route("**/api/v1/me", async (route) => {
      if (route.request().headers()["authorization"] !== `Bearer ${STALE}`) return route.fallback();
      staleAsked = true;
      await released;
      await route.fulfill({
        status: 401,
        contentType: "application/json",
        body: JSON.stringify({ message_key: "auth.unauthenticated", message_en: "Unauthenticated.", message_am: "አልገቡም።" }),
      });
      staleAnswered = true;
    });

    await page.goto("en/login/");
    await expect.poll(() => staleAsked, { message: "the page checks the old token" }).toBe(true);
    await page.getByLabel("Phone number").fill(admin.phone);
    await page.getByLabel("Password").fill(admin.password);
    await page.getByRole("button", { name: "Sign in" }).click();
    await expect(page).toHaveURL(/\/app\/en\/admin\/$/);
    await expect(page.getByRole("heading", { level: 1, name: "Today" })).toBeVisible();

    release();
    await expect.poll(() => staleAnswered, { message: "the old token's refusal arrived" }).toBe(true);
    // what the late refusal used to do: forget the new token and send the person to the sign-in page
    await page.waitForTimeout(1_000);
    await expect(page).toHaveURL(/\/app\/en\/admin\/$/);
    await expect(page.getByRole("heading", { level: 1, name: "Today" })).toBeVisible();
    const stored = await page.evaluate((key) => window.localStorage.getItem(key), TOKEN_KEY);
    expect(stored).not.toBeNull();
    expect(stored).not.toBe(STALE);
  });

  test("a start page whose sign-in check gets no answer says so and offers Retry", async ({ page }) => {
    await placeStaleToken(page);
    let reachable = false;
    await page.route("**/api/v1/me", async (route) => {
      if (!reachable) return route.abort("connectionfailed");
      return route.fallback();
    });

    await page.goto("en/");
    await expect(page.getByRole("alert").filter({ hasText: en.errors.network })).toBeVisible();
    const retry = page.getByRole("button", { name: en.common.retry });
    await expect(retry).toBeVisible();

    // the API answers again: Retry checks the token now (this one is refused, so the page offers the way to sign in)
    reachable = true;
    await retry.click();
    await expect(page.getByRole("main").getByRole("link", { name: new RegExp(en.nav.login) }).first()).toBeVisible();
    await expect(page.getByRole("alert").filter({ hasText: en.errors.network })).toHaveCount(0);
  });
});
