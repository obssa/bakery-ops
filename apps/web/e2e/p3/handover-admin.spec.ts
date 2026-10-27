import { expect, test } from "@playwright/test";
import { run, requireAdmin } from "../support/env";
import { findOrCreateLocation, findOrCreateStation, loginOnce } from "../support/fixture";
import { openAs } from "../support/pad";

// Step 3.7c: the admin shifts screens beyond the roster flow of p3-opening-prefill. The admin makes a shift template in the browser, changes
// its window and retires it; the setup check, the history with its "Verify" button and the replay at a point in time answer for the bakery.
// Built through the admin API in the isolated "E2E Test Branch", so it carries @live. The template is run-unique and retired at the end (a
// soft delete), so the branch does not grow a visible template per run.

interface TemplateRow {
  id: number;
  name_en: string;
  end_time: string;
  station_ids: number[];
}

test.describe("p3 admin shifts", () => {
  test("templates are made, edited and retired; history verifies the chain; replay shows a station at a moment @live", async ({ browser }) => {
    test.setTimeout(180_000);
    const credentials = requireAdmin(test.skip);
    const { api: admin } = await loginOnce(credentials.phone, credentials.password);
    const station = await findOrCreateStation(admin, "bakery");
    const location = await findOrCreateLocation(admin, station);
    const name = `E2E Template ${run}`;

    const context = await browser.newContext({ baseURL: test.info().project.use.baseURL, viewport: { width: 1280, height: 800 }, serviceWorkers: "block" });
    try {
      const page = await context.newPage();

      // --- the overview carries the setup check and the running shifts, and the approval queue below them --------------------------
      await openAs(page, admin, "en/admin/shifts/");
      await expect(page.getByRole("region", { name: "Setup check" })).toBeVisible({ timeout: 30_000 });
      await expect(page.getByRole("region", { name: "Shifts running now" })).toBeVisible();
      await expect(page.getByRole("region", { name: "Counts waiting for a decision" })).toBeVisible({ timeout: 30_000 });

      // --- a template is made in the browser ---------------------------------------------------------------------------------------
      await page.getByRole("link", { name: "Templates", exact: true }).click();
      await expect(page.getByRole("heading", { name: "Shift templates", level: 1 })).toBeVisible({ timeout: 30_000 });
      const form = page.getByRole("region", { name: "New template" });
      await form.getByLabel("Name in English").fill(name);
      await form.getByLabel("Name in Amharic").fill(`የሙከራ አብነት ${run}`);
      await form.getByLabel("Starts at").fill("06:00");
      await form.getByLabel("Ends at").fill("14:00");
      await form.getByRole("checkbox", { name: station.name_en, exact: true }).check();
      await form.getByRole("button", { name: "Add template", exact: true }).click();
      await expect(page.getByText(`Saved ${name}.`)).toBeVisible({ timeout: 30_000 });
      const made = (await admin.get<{ data: TemplateRow[] }>("/shift-templates")).data.find((row) => row.name_en === name);
      expect(made).toBeTruthy();
      expect(made!.station_ids).toContain(station.id);

      // --- edited: the window moves ------------------------------------------------------------------------------------------------
      await page.getByRole("button", { name: `Edit template ${name}`, exact: true }).click();
      const editing = page.getByRole("region", { name: "Edit template" });
      await editing.getByLabel("Ends at").fill("15:00");
      await editing.getByRole("button", { name: "Save changes", exact: true }).click();
      await expect(page.getByRole("row").filter({ hasText: name })).toContainText("06:00–15:00", { timeout: 30_000 });
      expect((await admin.get<{ data: TemplateRow[] }>("/shift-templates")).data.find((row) => row.id === made!.id)?.end_time).toBe("15:00");

      // --- retired: it leaves the list ---------------------------------------------------------------------------------------------
      await page.getByRole("button", { name: `Retire template ${name}`, exact: true }).click();
      await page.getByRole("dialog", { name: "Retire this template" }).getByRole("button", { name: "Retire", exact: true }).click();
      await expect(page.getByRole("button", { name: `Edit template ${name}`, exact: true })).toBeHidden({ timeout: 30_000 });
      expect((await admin.get<{ data: TemplateRow[] }>("/shift-templates")).data.some((row) => row.id === made!.id)).toBe(false);

      // --- history: the chain of the bakery's place verifies ----------------------------------------------------------------------
      await page.getByRole("link", { name: "History", exact: true }).click();
      await expect(page.getByRole("heading", { name: "Shift history", level: 1 })).toBeVisible({ timeout: 30_000 });
      await page.getByLabel("Place").selectOption(String(location.id));
      await page.getByRole("button", { name: /^Verify the chain of / }).click();
      await expect(page.getByRole("status").filter({ hasText: "The chain is intact" })).toBeVisible({ timeout: 60_000 });
      const verified = await admin.get<{ ok: boolean }>(`/locations/${location.id}/chain/verify`);
      expect(verified.ok).toBe(true);

      // --- replay: the bakery as it is now ------------------------------------------------------------------------------------------
      await page.getByRole("link", { name: "Replay", exact: true }).click();
      await expect(page.getByRole("heading", { name: "Replay", level: 1 })).toBeVisible({ timeout: 30_000 });
      await page.getByLabel("Station").selectOption(String(station.id));
      await page.getByRole("button", { name: "Show", exact: true }).click();
      await expect(page.getByRole("region", { name: new RegExp(`^${station.name_en} at `) })).toBeVisible({ timeout: 60_000 });
      await expect(page.getByRole("region", { name: /^Stock at / }).first()).toBeVisible();
      await expect(page.getByRole("region", { name: "Orders still open" })).toBeVisible();
    } finally {
      await context.close();
    }
  });

  // A crash seen on the live site: the admin overview's stations panel and the admin shifts screens both read GET /me under one query
  // key, one caching the whole answer and the other its work_stations list. Opening the overview first and then the Shifts tab (a client
  // navigation, so the query cache is kept) handed the setup check an object where it wanted a list, and the page fell over with
  // "e.stations.filter is not a function". A full reload of the Shifts page hid it.
  // The crash needs the templates to answer before the Shifts page's own GET /me does (until then the page shows what the overview cached):
  // a race, and the old code passed it on the CI stack, where /me answered first. So once the overview's answer is cached, the page's
  // GET /me is held back until the setup check has answered or the page has fallen over. Read-only, so it carries @live.
  test("the Shifts tab opens after the admin overview without a page error @live", async ({ page }) => {
    const credentials = requireAdmin(test.skip);
    const { api: admin } = await loginOnce(credentials.phone, credentials.password);
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    let meAnswers = 0;
    page.on("response", (response) => {
      if (response.request().method() === "GET" && new URL(response.url()).pathname === "/api/v1/me" && response.ok()) meAnswers += 1;
    });

    await openAs(page, admin, "en/admin/");
    await expect(page.getByRole("heading", { name: "Admin console", level: 1 })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole("region", { name: "Stations and shifts" })).toBeVisible({ timeout: 30_000 });
    // the session's own GET /me, then the stations panel's: the panel's answer is in the query cache before the tab is clicked
    await expect.poll(() => meAnswers, { timeout: 30_000 }).toBeGreaterThanOrEqual(2);

    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => (release = resolve));
    await page.route(
      (url) => url.pathname === "/api/v1/me",
      async (route) => {
        await held;
        await route.continue();
      },
    );
    const setup = page.getByRole("region", { name: "Setup check" });
    // the setup check answers once the stations and the templates are both in: the step that threw
    const answered = setup.getByText(/^(Everything is in place|Shifts work best when these are fixed)/);
    try {
      await page.getByRole("navigation", { name: "Admin sections" }).getByRole("link", { name: "Shifts", exact: true }).click();
      await expect(page).toHaveURL(/\/app\/en\/admin\/shifts\/?$/, { timeout: 30_000 });
      await expect(answered.or(page.getByText(/Application error/))).toBeVisible({ timeout: 30_000 });
    } finally {
      release();
    }
    expect(errors).toEqual([]);
    await expect(page.getByRole("heading", { name: "Shifts", level: 1 })).toBeVisible();
    await expect(answered).toBeVisible();
    await expect(page.getByRole("region", { name: "Shifts running now" })).toBeVisible();
  });
});
