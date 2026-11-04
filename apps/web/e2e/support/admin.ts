import { expect, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";

// The admin console's frame as the specs reach it (step 6.4a, the Admin board): an admin page has no station top bar. The bell stays in
// sight at the foot of the sidebar; the calendar ("Dates"), the language button and "Sign out" sit in the account menu that the person's
// card opens, with the names they had in the top bar.

/** Opens the account menu at the foot of the admin sidebar, if it is not open yet. */
export async function openAccountMenu(page: Page, locale: "en" | "am" = "en"): Promise<void> {
  const name = (locale === "am" ? am : en).admin.account_menu;
  const trigger = page.getByRole("button", { name: new RegExp(name) });
  if ((await trigger.getAttribute("aria-expanded")) !== "true") await trigger.click();
  await expect(page.getByRole("group", { name })).toBeVisible();
}
