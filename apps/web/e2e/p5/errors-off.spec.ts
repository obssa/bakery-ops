import { expect, test } from "@playwright/test";
import { apiOrigin } from "../support/env";

// Phase 5 step 5.5a (ADR 0021): a shell built without NEXT_PUBLIC_SENTRY_DSN (every build but a deliberate one, the live one included until
// Ramin sets the GitHub variable) has no Sentry SDK in it and sends nothing anywhere when a page fails: every request the page makes goes to
// its own origin or the API's. Project p5-errors-off, run by CI's web-errors job on web-build's artifact (no API needed: its answers are stubbed).

test("without a DSN at build time the page loads no Sentry SDK and an error sends nothing", async ({ page, baseURL }) => {
  const own = new URL(baseURL ?? "http://localhost:3000/app/").origin;
  const elsewhere: string[] = [];
  const scripts: Promise<string>[] = [];
  page.on("request", (request) => {
    const origin = new URL(request.url()).origin;
    if (origin !== own && origin !== apiOrigin) elsewhere.push(request.url());
  });
  page.on("response", (response) => {
    if (response.request().resourceType() === "script") scripts.push(response.text().catch(() => ""));
  });
  await page.route(`${apiOrigin}/**`, (route) => route.fulfill({ status: 401, json: { message_key: "auth.unauthenticated" } }));

  await page.goto("en/login/");
  await page.waitForLoadState("networkidle");
  await page.evaluate(() => {
    setTimeout(() => {
      throw new Error("a page failed for the customer on +251911223344");
    }, 0);
  });
  // what an SDK would have sent goes out within a moment; give it two
  await page.waitForTimeout(2_000);

  expect(elsewhere).toEqual([]);
  expect(await page.evaluate(() => "__SENTRY__" in window)).toBe(false);
  expect(await page.locator("html").getAttribute("data-error-tracking")).toBeNull();
  const bodies = await Promise.all(scripts);
  expect(bodies.length).toBeGreaterThan(0);
  expect(bodies.filter((body) => body.includes("__SENTRY__") || body.includes("sentry.javascript"))).toEqual([]);
});
