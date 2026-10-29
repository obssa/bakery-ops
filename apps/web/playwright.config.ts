import { defineConfig, devices } from "@playwright/test";

// One Playwright project per critical path, named p<phase>-<flow>. A phase's gate runs exactly its
// projects; earlier phases' projects keep running in every CI run so later work cannot break them.
// Tests always run against the BUILT app (`next start`): the dev server hydrates late and makes taps
// flaky. Service workers are blocked except for the Phase 5 offline project.
//
// The shell lives under /app (basePath; on the live host it is the static export LiteSpeed serves from
// public/app/, ADR 0006), so baseURL ends in "/app/" and tests navigate with relative paths ("en/login/").
// Live mode: PLAYWRIGHT_BASE_URL=https://bakery-ops.orcamind.dev runs the suite against the deployed site
// (no local web server is started; the API is the same origin). Tests that can run there carry "@live"
// in their title; the deploy workflow runs `--grep @live` after every release.
const liveBase = process.env.PLAYWRIGHT_BASE_URL?.replace(/\/+$/, "");
const apiUrl = process.env.NEXT_PUBLIC_API_URL ?? liveBase ?? "http://localhost:8000";
const webPort = Number(process.env.PLAYWRIGHT_WEB_PORT ?? 3000);
const origin = liveBase ?? `http://localhost:${webPort}`;
const baseURL = `${origin}/app/`;

const tablet = { ...devices["Desktop Chrome"], viewport: { width: 1280, height: 800 } };

export default defineConfig({
  testDir: "./e2e",
  globalSetup: "./e2e/support/global-setup.ts",
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [["list"], ["html", { open: "never" }]] : "list",
  timeout: 60_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
    serviceWorkers: "block",
    extraHTTPHeaders: { Accept: "application/json" },
  },
  metadata: { apiUrl },
  webServer: liveBase
    ? undefined
    : {
        command: process.env.PLAYWRIGHT_WEB_SERVER === "next-dev" ? "pnpm dev" : `pnpm start -p ${webPort}`,
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        env: { NEXT_PUBLIC_API_URL: apiUrl },
      },
  projects: [
    { name: "p0-admin-smoke", testDir: "./e2e/p0", use: tablet },
    { name: "p1-order-flow", testDir: "./e2e/p1", testMatch: /order-flow/, use: tablet },
    { name: "p1-shortfall", testDir: "./e2e/p1", testMatch: /shortfall/, use: tablet },
    { name: "p2-stockout-production", testDir: "./e2e/p2", testMatch: /stockout/, use: tablet },
    { name: "p2-purchase-transfer", testDir: "./e2e/p2", testMatch: /purchase/, use: tablet },
    { name: "p3-handover", testDir: "./e2e/p3", testMatch: /handover/, use: tablet },
    { name: "p3-opening-prefill", testDir: "./e2e/p3", testMatch: /prefill/, use: tablet },
    { name: "p4-pay-photo-split", testDir: "./e2e/p4", testMatch: /pay/, use: tablet },
    { name: "p4-dashboards", testDir: "./e2e/p4", testMatch: /dashboards/, use: tablet },
    { name: "p5-device", testDir: "./e2e/p5", testMatch: /device/, use: tablet },
    { name: "p5-offline", testDir: "./e2e/p5", testMatch: /offline/, use: { ...tablet, serviceWorkers: "allow" } },
    { name: "p5-amharic", testDir: "./e2e/p5", testMatch: /amharic/, use: tablet },
  ],
});
