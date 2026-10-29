import { test, expect, type Page } from "@playwright/test";
import { deviceOf, placeDevice } from "../support/devices";

// Phase 0 gate project p0-admin-smoke (manifest phases.0.gate.criteria): an admin signs in, creates an ingredient
// and a product with English and Amharic names, sees both listed, switches to Amharic and sees the Amharic names in
// Amharic chrome.
//
// Where it runs (ADR 0006):
// - CI e2e-p0: `next start` serves the shell under http://localhost:3000/app/ against the compose API; the job
//   creates the admin with `php artisan bakery:create-admin` and passes E2E_ADMIN_PHONE / E2E_ADMIN_PASSWORD.
// - Live (deploy.yml live-e2e, PLAYWRIGHT_BASE_URL=https://bakery-ops.orcamind.dev): the tests tagged @live run
//   against https://bakery-ops.orcamind.dev/app/ as the admin of the isolated "E2E Test Branch" (repository
//   secrets). Everything they create lives in that branch under names unique to the run; shop data is never touched.
//
// - CI e2e-p0 also seeds the demo bakery (DemoSeeder, SEED_PHASE=0) and passes DEMO_ADMIN_PHONE / DEMO_ADMIN_PASSWORD
//   (the documented local/CI demo password): the seeded catalog and people must show in both languages. Not @live: on
//   the live site the demo bakery is loaded by hand (docs/runbook/server.md) and its password is not a CI secret.
//
// Paths are relative to baseURL (".../app/"), so "en/login/" is /app/en/login/.

const live = !!process.env.PLAYWRIGHT_BASE_URL;
const apiUrl = (process.env.NEXT_PUBLIC_API_URL || process.env.PLAYWRIGHT_BASE_URL || "http://localhost:8000").replace(/\/$/, "");
const phone = process.env.E2E_ADMIN_PHONE ?? "";
const password = process.env.E2E_ADMIN_PASSWORD ?? "";
const demoPhone = process.env.DEMO_ADMIN_PHONE ?? "";
const demoPassword = process.env.DEMO_ADMIN_PASSWORD ?? "";
const run = `${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`;

function requireAdmin() {
  if (phone && password) return;
  // Live: the account comes from repository secrets that may not exist yet, so the flow is skipped, loudly.
  test.skip(live, "E2E_ADMIN_PHONE / E2E_ADMIN_PASSWORD are not set: the live admin flow needs the E2E Test Branch admin (repository secrets).");
  // CI and local runs must never pass the gate by skipping it.
  throw new Error(
    "E2E_ADMIN_PHONE and E2E_ADMIN_PASSWORD must be set: create the account with " +
      '`php artisan bakery:create-admin <phone> --branch="E2E Test Branch" --password=<password>`.',
  );
}

function requireDemoAdmin() {
  if (demoPhone && demoPassword) return;
  // CI seeds the demo bakery before Playwright runs and must not pass by skipping; a local run may not have seeded it.
  if (process.env.CI) throw new Error("DEMO_ADMIN_PHONE and DEMO_ADMIN_PASSWORD must be set in CI (e2e.yml): the e2e job seeds the demo bakery.");
  test.skip(true, "DEMO_ADMIN_PHONE / DEMO_ADMIN_PASSWORD are not set: seed the demo bakery (php artisan bakery:seed-demo) and set them.");
}

async function signIn(page: Page, account: { phone: string; password: string } = { phone, password }) {
  // the device enrolled for this account's role in global setup (step 5.1b: CI enforces enrolled devices on the station routes)
  await placeDevice(page, deviceOf(account.phone));
  await page.goto("en/login/");
  await page.getByLabel("Phone number").fill(account.phone);
  await page.getByLabel("Password").fill(account.password);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).toHaveURL(/\/app\/en\/admin\/$/);
  await expect(page.getByRole("heading", { level: 1, name: "Admin console" })).toBeVisible();
}

test.describe("p0 admin smoke", () => {
  test("API health answers with the ledger-ready stack @live", async ({ request }) => {
    const res = await request.get(`${apiUrl}/api/v1/health`);
    expect(res.ok()).toBeTruthy();
    const body = await res.json();
    expect(body.status).toBe("ok");
    expect(body.database).toBe("ok");
  });

  test("the shell is served in English and in Amharic @live", async ({ page }) => {
    await page.goto("en/login/");
    await expect(page.locator("html")).toHaveAttribute("lang", "en");
    await expect(page.getByRole("heading", { level: 1, name: "Sign in" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Admin console" })).toBeVisible();

    await page.goto("am/login/");
    await expect(page.locator("html")).toHaveAttribute("lang", "am");
    await expect(page.getByRole("heading", { level: 1, name: "ግባ" })).toBeVisible();
    await expect(page.getByRole("link", { name: "የአስተዳዳሪ ኮንሶል" })).toBeVisible();
  });

  test("the start page sends a signed-out visitor to the browser's language @live", async ({ browser, baseURL }) => {
    for (const [locale, expected] of [["en-US", "en"], ["am-ET", "am"]] as const) {
      const context = await browser.newContext({ locale, baseURL });
      const page = await context.newPage();
      await page.goto("./");
      await expect(page).toHaveURL(new RegExp(`/app/${expected}/$`));
      await expect(page.locator("html")).toHaveAttribute("lang", expected);
      await context.close();
    }
  });

  test("a screen that needs a sign-in sends a signed-out visitor to the login page @live", async ({ page }) => {
    // no screen is left that says "available in a later phase" (the production board was the last, step 2.8a), so this
    // stands for the guard of the screens the other flows only visit signed in
    await page.goto("en/production/");
    await expect(page).toHaveURL(/\/app\/en\/login\/\?next=/, { timeout: 30_000 });
    await expect(page.getByRole("heading", { level: 1, name: "Sign in" })).toBeVisible();
  });

  test("admin creates an ingredient and a product and sees both in English and Amharic @live", async ({ page }) => {
    requireAdmin();
    const flour = { en: `E2E flour ${run}`, am: `የሙከራ ዱቄት ${run}` };
    const bread = { en: `E2E bread ${run}`, am: `የሙከራ ዳቦ ${run}` };
    const category = { en: `E2E dry goods ${run}`, am: `የሙከራ ደረቅ ዕቃ ${run}` };

    await signIn(page);
    await page.getByRole("link", { name: "Catalog" }).first().click();
    await expect(page).toHaveURL(/\/app\/en\/admin\/catalog\/$/);
    await expect(page.getByRole("heading", { level: 1, name: "Catalog" })).toBeVisible();

    // a category of the ingredient kind, then the ingredient in it
    const categories = page.getByRole("region", { name: "Categories" });
    await categories.getByLabel("Kind").selectOption("ingredient");
    await categories.getByLabel("Name (English)").fill(category.en);
    await categories.getByLabel("Name (Amharic)").fill(category.am);
    await categories.getByRole("button", { name: "Add category" }).click();
    await expect(categories.getByRole("listitem").filter({ hasText: category.en })).toBeVisible();

    const form = page.getByRole("region", { name: "New item" });
    await form.getByLabel("Kind").selectOption("ingredient");
    await expect(form.getByLabel("Fulfilment")).toHaveCount(0);
    await form.getByLabel("Name (English)").fill(flour.en);
    await form.getByLabel("Name (Amharic)").fill(flour.am);
    await form.getByLabel("Category").selectOption({ label: category.en });
    await form.getByLabel("Unit").selectOption({ label: "Kilogram (kg)" });
    await form.getByLabel("SKU").fill(`E2E-F-${run}`);
    await form.getByRole("button", { name: "Create item" }).click();
    await expect(form.getByRole("status")).toContainText(flour.en);

    await form.getByLabel("Kind").selectOption("product");
    await form.getByLabel("Fulfilment").selectOption("to_stock");
    await form.getByLabel("Name (English)").fill(bread.en);
    await form.getByLabel("Name (Amharic)").fill(bread.am);
    await form.getByLabel("Unit").selectOption({ label: "Piece (pcs)" });
    await form.getByRole("button", { name: "Create item" }).click();
    await expect(form.getByRole("status")).toContainText(bread.en);

    const items = page.getByRole("table", { name: "Items" });
    await expect(items.getByRole("row").filter({ hasText: flour.en })).toContainText("Ingredient");
    await expect(items.getByRole("row").filter({ hasText: flour.en })).toContainText(category.en);
    await expect(items.getByRole("row").filter({ hasText: bread.en })).toContainText("Product");

    // the kind filter
    await page.getByRole("link", { name: "Ingredients", exact: true }).click();
    await expect(page).toHaveURL(/[?&]kind=ingredient/);
    await expect(items.getByRole("row").filter({ hasText: flour.en })).toBeVisible();
    await expect(items.getByRole("row").filter({ hasText: bread.en })).toHaveCount(0);
    await page.getByRole("link", { name: "Products", exact: true }).click();
    await expect(page).toHaveURL(/[?&]kind=product/);
    await expect(items.getByRole("row").filter({ hasText: bread.en })).toBeVisible();
    await expect(items.getByRole("row").filter({ hasText: flour.en })).toHaveCount(0);
    await page.getByRole("link", { name: "All", exact: true }).click();
    await expect(items.getByRole("row").filter({ hasText: flour.en })).toBeVisible();

    // Amharic: the same page in Amharic chrome, Amharic names first
    await page.getByRole("link", { name: "አማርኛ" }).click();
    await expect(page).toHaveURL(/\/app\/am\/admin\/catalog\//);
    await expect(page.locator("html")).toHaveAttribute("lang", "am");
    await expect(page.getByRole("heading", { level: 1, name: "ካታሎግ" })).toBeVisible();
    await expect(page.getByRole("link", { name: "የአስተዳዳሪ ኮንሶል" })).toBeVisible();
    const itemsAm = page.getByRole("table", { name: "ዕቃዎች" });
    await expect(itemsAm.getByRole("row").filter({ hasText: flour.am })).toContainText("ግብዓት");
    await expect(itemsAm.getByRole("row").filter({ hasText: flour.am })).toContainText(category.am);
    await expect(itemsAm.getByRole("row").filter({ hasText: bread.am })).toContainText("ምርት");

    // and back
    await page.getByRole("link", { name: "English" }).click();
    await expect(page.locator("html")).toHaveAttribute("lang", "en");
    await expect(page.getByRole("heading", { level: 1, name: "Catalog" })).toBeVisible();
  });

  // Not @live: it would leave one more sign-in-capable account on the live site every run.
  test("admin adds a person who then appears in People", async ({ page }) => {
    requireAdmin();
    const person = { name: `E2E cashier ${run}`, phone: `+2519${String(Date.now()).slice(-8)}` };

    await signIn(page);
    await page.getByRole("link", { name: "People" }).first().click();
    await expect(page.getByRole("heading", { level: 1, name: "People" })).toBeVisible();

    const form = page.getByRole("region", { name: "New person" });
    await form.getByLabel("Full name").fill(person.name);
    await form.getByLabel("Phone number").fill(person.phone);
    await form.getByLabel("Password").fill(`pw-${run}-long`);
    await form.getByLabel("Role").selectOption("cashier");
    await form.getByLabel("Language").selectOption("am");
    await form.getByRole("button", { name: "Add person" }).click();
    await expect(form.getByRole("status")).toContainText(person.name);

    const row = page.getByRole("table", { name: "People" }).getByRole("row").filter({ hasText: person.name });
    await expect(row).toContainText(person.phone);
    await expect(row).toContainText("Cashier");
    await expect(row).toContainText("አማርኛ");
  });

  // Not @live (see the header): the demo bakery Phase0Seeder loads, as its admin sees it.
  test("the demo bakery's admin sees its seeded catalog and people in English and Amharic", async ({ page }) => {
    requireDemoAdmin();
    await signIn(page, { phone: demoPhone, password: demoPassword });

    await page.getByRole("link", { name: "Catalog" }).first().click();
    const items = page.getByRole("table", { name: "Items" });
    await expect(items.getByRole("row").filter({ hasText: "Macchiato" })).toContainText("Made to order");
    await expect(items.getByRole("row").filter({ hasText: "Black forest cake (slice)" })).toContainText("Made in batches");
    await expect(items.getByRole("row").filter({ hasText: "Bottled water 0.5 L" })).toContainText("Bought in");
    await expect(items.getByRole("row").filter({ hasText: "Wheat flour" })).toContainText("Flour & grains");
    await expect(items.getByRole("row").filter({ hasText: "Wheat flour" })).toContainText("Ingredient");

    await page.getByRole("link", { name: "አማርኛ" }).click();
    await expect(page.locator("html")).toHaveAttribute("lang", "am");
    const itemsAm = page.getByRole("table", { name: "ዕቃዎች" });
    await expect(itemsAm.getByRole("row").filter({ hasText: "ማኪያቶ" })).toContainText("ምርት");
    await expect(itemsAm.getByRole("row").filter({ hasText: "የስንዴ ዱቄት" })).toContainText("ዱቄትና እህል");

    await page.getByRole("link", { name: "English" }).click();
    await expect(page.locator("html")).toHaveAttribute("lang", "en");
    await page.getByRole("link", { name: "People" }).first().click();
    const people = page.getByRole("table", { name: "People" });
    await expect(people.getByRole("row")).toHaveCount(10); // the header and the nine demo accounts
    await expect(people.getByRole("row").filter({ hasText: "Hirut Desta (ሂሩት ደስታ)" })).toContainText("Cake baker");
    await expect(people.getByRole("row").filter({ hasText: demoPhone })).toContainText("Admin");
  });

  test("a wrong password is refused with the API's message in the page's language @live", async ({ page }) => {
    await page.goto("am/login/");
    await page.getByLabel("ስልክ ቁጥር").fill("+251900000000");
    await page.getByLabel("የይለፍ ቃል").fill("not-the-password");
    await page.getByRole("button", { name: "ግባ" }).click();
    await expect(page.getByRole("main").getByRole("alert")).toBeVisible();
    await expect(page).toHaveURL(/\/app\/am\/login\/$/);
  });
});
