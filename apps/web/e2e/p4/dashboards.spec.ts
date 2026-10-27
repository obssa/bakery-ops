import { expect, test } from "@playwright/test";
import { live } from "../support/env";
import { loginOnce } from "../support/fixture";
import { openAs } from "../support/pad";

// Phase 4 gate project p4-dashboards (docs/phases/phase-4-plan.md section 7 and 10.2 R-G): the dashboards' first data in the browser
// under 1000 ms, on the demo bakery's seeded history. CI only: the e2e-p4 job seeds the demo branch with 60 days of history (the
// CI fallback of section 8) before the projects run, and the overview reads the last 30 days by default, which those 60 days hold in
// full (so the 90-day seed of perf-dashboards would time the same page; that job keeps k6 and no browser). The live twin of this
// measure is dashboards-live.spec.ts, which logs its figure against a soft budget of 2500 ms and never fails on it.
//
// How it is timed (step 4.5e's note): the demo admin's token is put in the page's storage, the API is warmed once (GET /me and the
// overview's own read: the first request after the seed is cold), and the clock starts when the page's own GET /me has answered
// (every signed-in page waits for it before it renders) and stops when the revenue tile shows a figure. Both instants are read in the
// browser (the resource timing of /me and performance.now() in a requestAnimationFrame poll), so Playwright's own round trips are not
// counted.

const BUDGET_MS = 1000;
const demoPhone = process.env.DEMO_ADMIN_PHONE ?? "";
const demoPassword = process.env.DEMO_ADMIN_PASSWORD ?? "";

test.describe("dashboards", () => {
  test("the overview shows its first figure within 1000 ms of /me on the seeded history (CI only)", async ({ page }, testInfo) => {
    test.skip(live, "the live demo branch is small and the host is shared: dashboards-live.spec.ts logs the live figure against its soft budget");
    if (!demoPhone || !demoPassword) throw new Error("DEMO_ADMIN_PHONE and DEMO_ADMIN_PASSWORD must be set (e2e.yml): the e2e job seeds the demo bakery.");
    const { api } = await loginOnce(demoPhone, demoPassword);

    // warm the API: the first requests after the seed load the code and the permission cache
    await api.get("/me");
    const warm = await api.get<{ as_of: string | null; data: { revenue: string; orders_count: number } }>("/analytics/overview");
    expect(warm.as_of, "the seeded days are built").not.toBeNull();
    expect(warm.data.orders_count, "the seeded history sold something in the last 30 days").toBeGreaterThan(0);

    await openAs(page, api, "en/admin/analytics/");
    const marks = await page.waitForFunction(
      () => {
        const tile = document.querySelector('[role="group"][aria-label="Revenue"]');
        if (!tile || tile.getAttribute("aria-busy") === "true") return null;
        if (!/\d\.\d\d ETB/.test(tile.textContent ?? "")) return null;
        const me = performance
          .getEntriesByType("resource")
          .find((entry) => /\/api\/v1\/me(\?|$)/.test(entry.name)) as PerformanceResourceTiming | undefined;
        if (!me || me.responseEnd === 0) return null;
        return { meAnswered: me.responseEnd, figure: performance.now() };
      },
      null,
      { polling: "raf", timeout: 30_000 },
    );
    const { meAnswered, figure } = (await marks.jsonValue()) as { meAnswered: number; figure: number };
    const firstData = Math.round(figure - meAnswered);
    testInfo.annotations.push({ type: "first-data-ms", description: String(firstData) });
    console.log(`[p4-dashboards] first data ${firstData} ms after /me answered (budget ${BUDGET_MS} ms, the demo history)`);

    await expect(page.getByRole("heading", { level: 1, name: "Analytics" })).toBeVisible();
    await expect(page.getByRole("group", { name: "Revenue", exact: true })).toContainText(/\d\.\d\d ETB/);
    expect(firstData, "the overview's first figure, from /me answering to the revenue tile showing it").toBeLessThan(BUDGET_MS);
  });
});
