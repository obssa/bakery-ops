import { expect, test, type Browser, type Page } from "@playwright/test";
import type { Api } from "../support/api";
import { requireAdmin, run } from "../support/env";
import { buildWaiter, ensureStaff, loginOnce } from "../support/fixture";
import { ensureUnlocked, openAs, openPadAndUnlock } from "../support/pad";

// Phase 6 follow-up, Admin > Tables (Ramin, 2026-11-09: "when we wish to add a table, where can we do that?"), in project p6-a11y: the admin
// and the manager (tables.manage, both since this step) each add a table on the screen, rename it and switch it off, and a waiter's pad
// shows the new table in its "Tables" sheet, then drops it once it is off. @live: it runs against the deployed site in the E2E Test Branch
// as well, with a code unique to the run (the API keeps a code once used, an inactive table included), and the table is switched off at
// the end whatever happens, so the live pad's grid stays as it was.

type Role = "admin" | "manager";

interface TableRow {
  id: number;
  code: string;
  is_active: boolean;
}

const TABLET = { width: 1280, height: 800 };

/** The account that works the screen: the E2E branch's admin, or its fixed manager (created by the admin when missing). */
async function account(role: Role, credentials: { phone: string; password: string }): Promise<{ api: Api; admin: Api }> {
  const { api: admin, login } = await loginOnce(credentials.phone, credentials.password);
  if (role === "admin") return { api: admin, admin };
  const manager = await ensureStaff(admin, credentials.password, "manager");
  const { api } = await loginOnce(manager.phone, manager.password, login.user.branch_id);
  return { api, admin };
}

/** The pad's "Tables" sheet, opened (unlocking the pad again first if it idle-locked while the admin worked). */
async function padSheet(pad: Page, waiter: { name: string; shortCode: string }) {
  await ensureUnlocked(pad, waiter);
  const sheet = pad.getByRole("dialog", { name: "Tables" });
  if ((await sheet.count()) === 0) await pad.getByRole("button", { name: "Change the table or the order type" }).click();
  await expect(sheet).toBeVisible();
  return sheet;
}

async function newPad(browser: Browser, baseURL: string | undefined): Promise<Page> {
  const context = await browser.newContext({ baseURL, viewport: TABLET, serviceWorkers: "block" });
  return context.newPage();
}

test.describe("p6 admin tables", () => {
  for (const role of ["admin", "manager"] as const) {
    test(`the ${role} adds a table, renames it and switches it off, and the pad shows it then drops it @live`, async ({ page, browser, baseURL }) => {
      test.setTimeout(180_000);
      const credentials = requireAdmin(test.skip);
      const { api, admin } = await account(role, credentials);
      const code = `X${role === "admin" ? "A" : "M"}${run}`.toUpperCase().slice(0, 16);
      const name = `E2E ${role} table ${run}`;
      const pad = await newPad(browser, baseURL);

      try {
        // the screen: the sidebar's "Tables" in Sell, the title row and its "Add table"
        await openAs(page, api, "en/admin/tables/");
        await expect(page.getByRole("heading", { level: 1, name: "Tables" })).toBeVisible({ timeout: 30_000 });
        await expect(page.getByRole("navigation", { name: "Admin sections" }).getByRole("link", { name: "Tables" })).toHaveAttribute("aria-current", "page");
        await expect(page.getByText("Loading the tables…", { exact: true })).toHaveCount(0, { timeout: 30_000 });

        await page.getByRole("button", { name: "Add table", exact: true }).first().click();
        const form = page.getByRole("dialog", { name: "Add a table" });
        await expect(form).toBeVisible();
        await form.getByLabel("Code", { exact: true }).fill(code);
        await form.getByLabel("Name (English)").fill(name);
        await form.getByLabel("Name (Amharic)").fill(`የሙከራ ጠረጴዛ ${run}`);
        await form.getByLabel(/^Seats/).fill("4");
        await form.getByRole("button", { name: "Add table", exact: true }).click();
        await expect(form).toHaveCount(0, { timeout: 30_000 });
        await expect(page.getByRole("status").filter({ hasText: `Table ${code} added.` })).toBeVisible();
        const card = page.getByRole("article", { name: code, exact: true });
        await expect(card).toContainText(name);
        await expect(card).toContainText("4 seats");
        await expect(card.getByText("Free", { exact: true })).toBeVisible();

        // the same code again is refused under the Code field, in the page's language
        await page.getByRole("button", { name: "Add table", exact: true }).first().click();
        await form.getByLabel("Code", { exact: true }).fill(code.toLowerCase());
        await form.getByLabel("Name (English)").fill("Again");
        await form.getByLabel("Name (Amharic)").fill("እንደገና");
        await form.getByRole("button", { name: "Add table", exact: true }).click();
        await expect(form).toContainText("Another table of this branch already has this code.");
        await form.getByRole("button", { name: "Cancel" }).click();
        await expect(form).toHaveCount(0);

        // the waiter's pad lists it
        const { waiter } = await buildWaiter(credentials);
        await openPadAndUnlock(pad, waiter);
        await expect((await padSheet(pad, waiter)).getByRole("button", { name: `Table ${code}, free` })).toBeVisible({ timeout: 20_000 });

        // rename: the code is fixed once the table exists
        await card.getByRole("button", { name: `Edit table ${code}` }).click();
        const edit = page.getByRole("dialog", { name: `Edit table ${code}` });
        await expect(edit.getByLabel("Code", { exact: true })).toHaveValue(code);
        await expect(edit.getByLabel("Code", { exact: true })).toHaveAttribute("readonly", "");
        await edit.getByLabel("Name (English)").fill(`${name} renamed`);
        await edit.getByRole("button", { name: "Save changes" }).click();
        await expect(edit).toHaveCount(0, { timeout: 30_000 });
        await expect(card).toContainText(`${name} renamed`);

        // switch it off: "Off" on its card, and the pad drops it on its next poll
        await card.getByRole("button", { name: `Switch off table ${code}` }).click();
        await expect(card.getByText("Off", { exact: true })).toBeVisible({ timeout: 30_000 });
        await expect(card.getByRole("button", { name: `Switch on table ${code}` })).toBeVisible();
        await expect(page.getByRole("status").filter({ hasText: `Table ${code} is switched off.` })).toBeVisible();
        await expect((await padSheet(pad, waiter)).getByRole("button", { name: `Table ${code}, free` })).toHaveCount(0, { timeout: 20_000 });
      } finally {
        // whatever happened above, the run's table is off, so the live pad's grid stays clean
        const rows = (await admin.get<{ data: TableRow[] }>("/dining-tables/manage").catch(() => ({ data: [] as TableRow[] }))).data;
        const mine = rows.find((row) => row.code.toUpperCase() === code);
        if (mine?.is_active) await admin.patch(`/dining-tables/${mine.id}`, { is_active: false }).catch(() => undefined);
        await pad.context().close();
      }
    });
  }
});
