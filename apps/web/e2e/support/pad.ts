import { expect, type Page } from "@playwright/test";

// Page helpers for the waiter pad, shared by the step 1.6 specs (p1-shortfall, the pad lock test, and the full
// p1-order-flow of step 1.6c). They select by role and accessible name only.

/** Signs the device in with an account (the pad account is a waiter) and lands on `next`. */
export async function signInAs(page: Page, phone: string, password: string, next: string) {
  await page.goto(`en/login/?next=${encodeURIComponent(next)}`);
  await page.getByLabel("Phone number").fill(phone);
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Sign in" }).click();
}

/** Types a short code on the pad keypad and presses the unlock button (named in the page language). */
export async function typeCode(page: Page, code: string, unlock = "Unlock") {
  for (const digit of code) await page.getByRole("button", { name: digit, exact: true }).click();
  await page.getByRole("button", { name: unlock, exact: true }).click();
}

/** Opens /pad (English) signed in as the pad account and unlocks it with the waiter's short code. */
export async function openPadAndUnlock(page: Page, who: { phone: string; password: string; name: string; shortCode: string }) {
  await signInAs(page, who.phone, who.password, "/pad");
  await expect(page).toHaveURL(/\/app\/en\/pad\/$/, { timeout: 30_000 });
  await expect(page.getByRole("heading", { name: "Enter your short code" })).toBeVisible();
  await typeCode(page, who.shortCode);
  await expect(page.getByText(`Acting: ${who.name}`)).toBeVisible({ timeout: 30_000 });
}
