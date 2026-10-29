import { expect, test } from "@playwright/test";
import { apiOrigin } from "../support/env";

// Phase 5 step 5.5a (ADR 0021, plan 10.2 D21): a shell built with NEXT_PUBLIC_SENTRY_DSN loads the browser SDK lazily and sends a page's
// error to the ingest the DSN names, with nothing personal in it: no PIN, password, token, Bearer token, phone number, or the signature= and
// expires= of a signed link, wherever they sat (the page URL, a console line, a fetch, the error's message). The ingest is a page.route
// sink: nothing leaves the runner. Project p5-errors-on, run by CI's web-errors job on a build made with the made-up DSN in E2E_SENTRY_DSN.

const dsn = process.env.E2E_SENTRY_DSN ?? "";

// written in halves, so no secret is spelt out in this file (the same rule as the API's ErrorTrackingTest)
const SECRETS = {
  pin: "402" + "913",
  password: "Pa55-" + "SECRET-word",
  token: "TOKEN" + "secret0003",
  bearer: "BEARER" + "secretTOKENvalue0123456789abcdefghijkl",
  phone: "2519" + "11223344",
  "local phone": "09" + "11223344",
  signature: "SIG" + "secret",
  expires: "18934" + "56000",
};

test("an error reaches the fake ingest once error tracking has started, with nothing personal in it", async ({ page }) => {
  expect(dsn, "E2E_SENTRY_DSN must be the DSN the shell under test was built with (NEXT_PUBLIC_SENTRY_DSN)").not.toBe("");
  const ingest = new URL(dsn).origin;
  const envelopes: string[] = [];
  await page.route(`${ingest}/**`, async (route) => {
    envelopes.push(route.request().postData() ?? "");
    await route.fulfill({ status: 200, contentType: "application/json", body: "{}" });
  });
  await page.route(`${apiOrigin}/**`, (route) => route.fulfill({ status: 401, json: { message_key: "auth.unauthenticated" } }));

  const signed = `expires=${SECRETS.expires}&signature=${SECRETS.signature}`;
  await page.goto(`en/login/?${signed}0001&from=errors`);
  await expect(page.locator("html")).toHaveAttribute("data-error-tracking", "on");

  await page.evaluate(
    ({ s, signed }) => {
      console.log(`the customer on +${s.phone} asked twice, pin=${s.pin}`);
      void fetch(`/app/en/?${signed}0002`).catch(() => undefined);
      setTimeout(() => {
        throw new Error(
          `could not reach ${s["local phone"]} (password=${s.password}, token: ${s.token}) with Bearer 7|${s.bearer} at /r?${signed}0003`,
        );
      }, 200);
    },
    { s: SECRETS, signed },
  );

  await expect.poll(() => envelopes.filter((body) => body.includes('"type":"event"')).length, { timeout: 15_000 }).toBeGreaterThan(0);
  const raw = envelopes.join("\n");
  for (const [what, secret] of Object.entries(SECRETS)) expect(raw.includes(secret), `${what} reached the ingest`).toBe(false);
  expect(raw).toContain("could not reach [phone]");
  expect(raw).toContain("/app/en/login/");
  expect(raw).toContain("from=errors");
  expect(raw).not.toContain('"type":"session"');
  expect(raw).not.toContain('"type":"transaction"');
});
