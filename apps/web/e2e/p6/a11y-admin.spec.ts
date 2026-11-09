import { expect, test, type Page } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import { axeFindings, describeFindings } from "../support/a11y";
import type { Api } from "../support/api";
import { freshDevice } from "../support/devices";
import { live } from "../support/env";
import { loginOnce } from "../support/fixture";
import { openAs } from "../support/pad";

// Phase 6 gate project p6-a11y, step 6.5 (the coordinator's addition of 2026-11-04; ADR 0023): a light pass over the admin screens of 6.4c
// and 6.4d, which a11y.spec.ts did not cover: Orders with a card's timeline open, Payments with its Reconciliation, Stock, People, Shifts with
// its Handover notes, Analytics, the Audit log and System, each in its loaded state, once in English and once in Amharic, as the demo
// bakery's admin at the admin's 1440 x 900. The demo bakery is the one with figures to draw: SEED_PHASE=6 leaves orders, payments, shifts,
// notes and audit rows, and the product photos.
//
// phase-6/new-item-page adds the new item page: a product with its units loaded, then an ingredient sent empty, with its errors.
//
// A pass reads every screen and fails once, at its end, naming every serious or critical finding on every screen, so one run of the job says
// all there is to fix. A screen that does not reach its loaded state is a finding too.

const MESSAGES = { en, am } as const;
type Locale = keyof typeof MESSAGES;
type Messages = (typeof MESSAGES)[Locale];

interface AdminScreen {
  name: string;
  path: string;
  heading: (t: Messages) => string;
  /** what it says while it loads, gone once it has its data */
  loading?: (t: Messages) => string[];
  /** anything else that says it has drawn its data */
  loaded?: (page: Page, t: Messages) => Promise<void>;
  /** a second state of the screen to read as well; answers its name, or null when the seed leaves nothing to open */
  also?: (page: Page, t: Messages) => Promise<string | null>;
}

const TIMEOUT = { timeout: 30_000 };

/** A name built from a message around its one placeholder, whatever fills it. */
function around(message: string): RegExp {
  const [before, after = ""] = message.split(/\{\w+\}/).map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));
  return new RegExp(`^${before}.*${after}$`);
}

const SCREENS: AdminScreen[] = [
  {
    name: "Orders",
    path: "admin/orders/",
    heading: (t) => t.adminOrders.title,
    loading: (t) => [t.adminOrders.loading],
    also: async (page, t) => {
      const open = page.getByRole("button", { name: around(t.adminOrders.open_order) });
      if ((await open.count()) === 0) return null;
      await open.first().click();
      await expect(page.getByRole("region", { name: around(t.adminOrders.timeline) })).toBeVisible(TIMEOUT);
      await expect(page.getByText(t.adminOrders.timeline_loading, { exact: true })).toHaveCount(0, TIMEOUT);
      return "a card's timeline";
    },
  },
  { name: "Payments", path: "admin/payments/", heading: (t) => t.adminPayments.list.title, loading: (t) => [t.adminPayments.list.loading] },
  {
    name: "Reconciliation",
    path: "admin/payments/reconciliation/",
    heading: (t) => t.adminPayments.reconciliation.title,
    loading: (t) => [t.adminPayments.reconciliation.loading],
  },
  { name: "Stock", path: "admin/inventory/", heading: (t) => t.inventory.stock.title, loading: (t) => [t.common.loading] },
  {
    name: "People",
    path: "admin/people/",
    heading: (t) => t.people.title,
    loaded: async (page, t) => {
      await expect(page.getByRole("table", { name: t.people.list }).getByRole("row").nth(1).getByRole("checkbox")).toBeVisible(TIMEOUT);
    },
  },
  {
    name: "Shifts",
    path: "admin/shifts/",
    heading: (t) => t.counts.admin_title,
    loading: (t) => [t.adminShifts.setup.checking, t.adminShifts.running.loading],
  },
  { name: "Handover notes", path: "admin/shifts/notes/", heading: (t) => t.adminShifts.title_notes, loading: (t) => [t.common.loading] },
  {
    name: "Analytics",
    path: "admin/analytics/",
    heading: (t) => t.analytics.overview.title,
    loaded: async (page, t) => {
      await expect(page.getByRole("group", { name: t.analytics.kpi.revenue, exact: true })).not.toHaveAttribute("aria-busy", "true", TIMEOUT);
    },
  },
  {
    name: "Audit log",
    path: "admin/audit/",
    heading: (t) => t.audit.title,
    loaded: async (page, t) => {
      await expect(page.getByRole("table", { name: t.audit.list.table, exact: true }).getByRole("row").nth(1)).toBeVisible(TIMEOUT);
    },
  },
  {
    name: "System",
    path: "admin/system/",
    heading: (t) => t.system.title,
    loaded: async (page) => {
      await expect(page.getByTestId("system-scheduler")).toBeVisible(TIMEOUT);
    },
  },
  {
    name: "New product",
    path: "admin/catalog/new/",
    heading: (t) => t.catalog.new.title_product,
    loaded: async (page, t) => {
      await expect(page.getByRole("radiogroup", { name: t.catalog.field_fulfilment })).toBeVisible(TIMEOUT);
      await expect(page.getByLabel(t.catalog.field_unit).getByRole("option")).not.toHaveCount(1, TIMEOUT);
    },
    also: async (page, t) => {
      await page.getByRole("radio", { name: t.catalog.kind_ingredient, exact: true }).check();
      await page.getByRole("button", { name: t.catalog.new.create_ingredient }).click();
      await expect(page.getByRole("alert").filter({ hasText: t.catalog.new.check_fields })).toBeVisible(TIMEOUT);
      return "an ingredient sent empty, with its errors";
    },
  },
];

let signedIn: Promise<{ admin: Api; device: string }> | null = null;

/** The demo bakery's admin and a device enrolled there for this pass. Once per worker. */
function demoAdmin(): Promise<{ admin: Api; device: string }> {
  signedIn ??= (async () => {
    const phone = process.env.DEMO_ADMIN_PHONE ?? "";
    const password = process.env.DEMO_ADMIN_PASSWORD ?? "";
    if (!phone || !password) throw new Error("DEMO_ADMIN_PHONE and DEMO_ADMIN_PASSWORD must be set (e2e.yml): the e2e job seeds the demo bakery.");
    const { api: admin } = await loginOnce(phone, password);
    return { admin, device: await freshDevice(admin, "a11y-admin") };
  })();
  signedIn.catch(() => (signedIn = null));
  return signedIn;
}

test.describe("a11y: the admin screens", () => {
  test.skip(live, "CI only: the pass reads the seeded demo bakery");
  test.use({ viewport: { width: 1440, height: 900 } });

  for (const locale of ["en", "am"] as const) {
    test(`Orders, Payments, Stock, People, Shifts, Analytics, the Audit log, System and the new item page (${locale}) have no serious or critical axe violation`, async ({ page }) => {
      test.setTimeout(360_000);
      const t = MESSAGES[locale];
      const { admin, device } = await demoAdmin();
      const problems: string[] = [];
      let opened = false;

      for (const screen of SCREENS) {
        const url = `${locale}/${screen.path}`;
        try {
          if (opened) {
            await page.goto(url);
          } else {
            await openAs(page, admin, url, { device });
            opened = true;
          }
          await expect(page.getByRole("heading", { level: 1, name: screen.heading(t) })).toBeVisible(TIMEOUT);
          for (const text of screen.loading?.(t) ?? []) await expect(page.getByText(text, { exact: true })).toHaveCount(0, TIMEOUT);
          await screen.loaded?.(page, t);
          for (const line of describeFindings(await axeFindings(page))) problems.push(`${url}: ${line}`);
          const state = await screen.also?.(page, t);
          if (state) for (const line of describeFindings(await axeFindings(page))) problems.push(`${url} (${state}): ${line}`);
        } catch (error) {
          problems.push(`${url}: did not reach its loaded state: ${error instanceof Error ? error.message.split("\n")[0] : String(error)}`);
        }
      }

      expect(problems, `the admin screens in ${locale}:\n${problems.join("\n")}`).toEqual([]);
    });
  }
});
