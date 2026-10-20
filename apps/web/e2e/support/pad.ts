import { expect, type Page } from "@playwright/test";

// Page helpers for the waiter pad, shared by the step 1.6 specs (p1-shortfall, the pad lock test, and the full
// p1-order-flow of step 1.6c). They select by role and accessible name only.
//
// POST /login is throttled (10 a minute per address) and a gate run signs in many times, so the pad flows start from
// a device that is already signed in as the pad account: the token of the fixture's API session is put where the app
// keeps it (ADR 0007). The sign-in form itself is exercised by the kitchen display flow and the admin smoke tests.

const TOKEN_KEY = "bakery-ops.token";

/** Opens the pad page (English) on a device signed in as the pad account. */
export async function openPad(page: Page, account: { api: { token: string | null } }, path = "en/pad/") {
  const token = account.api.token;
  if (!token) throw new Error("the pad account has no API session");
  await page.addInitScript(
    ([key, value]) => {
      try {
        window.localStorage.setItem(key, value);
      } catch {
        // storage blocked: the test fails visibly on the sign-in page
      }
    },
    [TOKEN_KEY, token],
  );
  await page.goto(path);
}

/** Types a short code on the pad keypad and presses the unlock button (named in the page language). */
export async function typeCode(page: Page, code: string, unlock = "Unlock") {
  for (const digit of code) await page.getByRole("button", { name: digit, exact: true }).click();
  await page.getByRole("button", { name: unlock, exact: true }).click();
}

/** Opens /pad (English) as the pad account and unlocks it with the waiter's short code. */
export async function openPadAndUnlock(page: Page, who: { api: { token: string | null }; name: string; shortCode: string }) {
  await openPad(page, who);
  await expect(page).toHaveURL(/\/app\/en\/pad\/$/, { timeout: 30_000 });
  await expect(page.getByRole("heading", { name: "Enter your short code" })).toBeVisible({ timeout: 30_000 });
  await typeCode(page, who.shortCode);
  await expect(page.getByText(`Acting: ${who.name}`)).toBeVisible({ timeout: 30_000 });
}
