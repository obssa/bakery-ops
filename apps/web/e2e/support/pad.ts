import { expect, type Page } from "@playwright/test";
import { placeDevice } from "./devices";

// Page helpers for the waiter pad, shared by the step 1.6 specs (p1-shortfall, the pad lock test, and the full
// p1-order-flow of step 1.6c). They select by role and accessible name only.
//
// POST /login is throttled (10 a minute per address) and a gate run signs in many times, so the pad flows start from
// a device that is already signed in as the pad account: the token of the fixture's API session is put where the app
// keeps it (ADR 0007). The sign-in form itself is exercised by the kitchen display flow and the admin smoke tests.

const TOKEN_KEY = "bakery-ops.token";

/**
 * Opens a page (English by default) on a device that is already signed in as `account`: the token of its API session is
 * placed where the app keeps it (ADR 0007). Used by every role of the full order flow (kitchen, bar, till, admin). The page
 * runs on the device enrolled for the account's role (step 5.1b, devices.ts), or on `options.device` (a tablet a flow enrols).
 */
export async function openAs(page: Page, account: { token: string | null; device?: string }, path: string, options: { device?: string } = {}) {
  const token = account.token;
  if (!token) throw new Error("the account has no API session");
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
  const device = options.device ?? account.device;
  if (device) await placeDevice(page, device);
  await page.goto(path);
}

/** Opens the pad page (English) on a device signed in as the pad account. */
export async function openPad(page: Page, account: { api: { token: string | null } }, path = "en/pad/") {
  await openAs(page, account.api, path);
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

/**
 * Unlocks the pad again when it idle-locked while the test waited (the pad locks after 60 s without a touch, IDLE_LOCK_MS in
 * src/lib/acting.ts; on the live host a flow waits longer than that for the cron-drained queue). Types the short code only
 * when the lock screen shows, so it is safe to call whenever the pad may or may not have locked. Call it right before a pad
 * assertion that follows a long wait.
 */
export async function ensureUnlocked(page: Page, who: { name: string; shortCode: string }) {
  const lock = page.getByRole("heading", { name: "Enter your short code" });
  const acting = page.getByText(`Acting: ${who.name}`);
  await expect(lock.or(acting)).toBeVisible({ timeout: 30_000 });
  if (await lock.isVisible()) {
    await typeCode(page, who.shortCode);
    await expect(acting).toBeVisible({ timeout: 30_000 });
  }
}
