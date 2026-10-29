import { expect, test } from "@playwright/test";
import { ApiError } from "../../src/lib/api/client";
import { FILTERED, isRefusal, scrubEvent, scrubText } from "../../src/lib/errorTracking/scrub";

// Phase 5 step 5.5a (ADR 0021, plan 10.2 D21), the browser side's beforeSend as plain logic (no browser, no SDK): nothing personal leaves the
// tablet (PINs, passwords, tokens, the Authorization header, phone numbers, the signature= and expires= of a signed link), wherever it sits in
// the event, and an answer of the API that is a refusal (a 4xx with its message_key) or a lost connection is not an error at all. What helps
// to find a bug (the path, the order number, amounts, dates) stays. Rides in the p5-errors-off project.

const SECRETS = ["402913", "Pa55-SECRET-word", "TOKENsecret0003", "BEARERsecret", "251911223344", "0911223344", "SIGsecret", "1893456000"];

function sampleEvent() {
  return {
    message: "payment failed for 0911223344, pin=402913",
    exception: {
      values: [
        {
          type: "Error",
          value:
            "could not reach +251911223344 at /app/en/r/?expires=1893456000&signature=SIGsecret0001 (password=Pa55-SECRET-word, token: TOKENsecret0003) Bearer 7|BEARERsecretTOKENvalue0123456789abcdefghijkl",
          stacktrace: { frames: [{ filename: "/app/_next/static/chunks/app.js", function: "pay", vars: { pin: "402913" } }] },
        },
      ],
    },
    request: {
      url: "https://shop.example.test/app/en/till/?id=42&expires=1893456000&signature=SIGsecret0001",
      headers: { Authorization: "Bearer 7|BEARERsecretTOKENvalue0123456789abcdefghijkl", "User-Agent": "Mozilla/5.0", Referer: "https://shop.example.test/app/en/?signature=SIGsecret0002" },
      cookies: { bakery: "TOKENsecret0003" },
    },
    breadcrumbs: [
      { category: "console", message: "customer +251911223344 asked, pin=402913", data: { arguments: ["customer +251911223344", { password: "Pa55-SECRET-word" }] } },
      { category: "fetch", data: { method: "GET", url: "https://shop.example.test/api/v1/receipts/5?expires=1893456000&signature=SIGsecret0003", status_code: 200 } },
      { category: "ui.click", message: "button.pay-complete" },
    ],
    extra: { order_no: "A-0042", amount: "1250.00", day: "2026-10-29", caller: { phone_number: "+251911223344", short_code: "7788" } },
    tags: { station: "bar", token: "TOKENsecret0003" },
    user: { id: 12, email: "someone@example.test", ip_address: "203.0.113.9", username: "abebe" },
  };
}

test("scrubs PINs, passwords, tokens, phone numbers, the Authorization header and signed-link parameters from the whole event", () => {
  const out = scrubEvent(sampleEvent());
  const json = JSON.stringify(out);

  for (const secret of [...SECRETS, "7788", "someone@example.test", "203.0.113.9", "abebe"]) expect(json, secret).not.toContain(secret);
  expect(out.request.headers.Authorization).toBe(FILTERED);
  expect(out.request).not.toHaveProperty("cookies");
  expect(out.user).toEqual({ id: 12 });
  expect(out.exception.values[0].stacktrace.frames[0]).not.toHaveProperty("vars");
});

test("keeps what helps to find the bug", () => {
  const out = scrubEvent(sampleEvent());

  expect(out.request.url).toContain("/app/en/till/");
  expect(out.request.url).toContain("id=42");
  expect(out.request.headers["User-Agent"]).toBe("Mozilla/5.0");
  expect(out.extra).toMatchObject({ order_no: "A-0042", amount: "1250.00", day: "2026-10-29" });
  expect(out.tags.station).toBe("bar");
  expect(out.breadcrumbs[1].data?.url).toContain("/api/v1/receipts/5");
  expect(out.breadcrumbs[2].message).toBe("button.pay-complete");
  expect(out.exception.values[0].value).toContain("could not reach");
});

test("finds phone numbers in free text and leaves amounts, ids and dates alone", () => {
  const text = scrubText("call +251 91 122 3344 or 0911223344 or 251711223344 or +44 20 7946 0958");
  expect(text).not.toContain("3344");
  expect(text).not.toContain("7946");

  const plain = "order A-0042 paid 1250.00 ETB on 2026-10-29 at 14:05, payment 123456, item 98765";
  expect(scrubText(plain)).toBe(plain);
});

test("does not send the API's refusals or a lost connection: they are answers, not errors", () => {
  expect(isRefusal(new ApiError(409, { message_key: "payments.order.settled" }))).toBe(true);
  expect(isRefusal(new ApiError(422, { message_key: "validation.failed" }))).toBe(true);
  expect(isRefusal(new ApiError(0, {}))).toBe(true);
  expect(isRefusal(new ApiError(500, { message_key: "http.server_error" }))).toBe(false);
  expect(isRefusal(new TypeError("x is undefined"))).toBe(false);
  expect(isRefusal(undefined)).toBe(false);
});
