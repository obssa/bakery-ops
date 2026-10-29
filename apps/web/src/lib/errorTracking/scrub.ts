import { ApiError } from "../api/client";

// Phase 5 step 5.5a (ADR 0021, plan 10.2 D21): the browser side's beforeSend, as plain functions (no SDK here, so the logic spec runs them in
// Node and the SDK stays out of every bundle that does not ask for it). The same rules as the API's SentryScrubber: nothing personal leaves the
// tablet, wherever it sits in the event (the message, the exceptions, the page URL and headers, the breadcrumbs: console lines, fetch URLs,
// navigations, extra, tags, contexts), and a refusal of the API or a lost connection is not an error at all.

export const FILTERED = "[Filtered]";

/** A key whose value never leaves (searched inside the key, any case: Authorization, current_password, customer_phone, new_pin). */
const SECRET_KEY =
  /(?:^|[^a-z])pins?(?:[^a-z]|$)|password|passwd|passcode|secret|token|short_?code|authori[sz]ation|cookie|phone|msisdn|signature|session|credential|api_?key/i;

/** The parameters that make a signed link a bearer credential (Laravel's, and S3's presigned ones), as the API's LogScrubProcessor. */
const SIGNED_PARAMS =
  /([?&])(?:signature|expires|X-Amz-Signature|X-Amz-Expires|X-Amz-Credential|X-Amz-Date|X-Amz-Algorithm|X-Amz-SignedHeaders|X-Amz-Security-Token)=[^&\s"'#<>]*/gi;

/** Free text with the secrets, phone numbers and signed-link parameters taken out; everything else as it was. */
export function scrubText(text: string): string {
  let out = text;
  if (out.includes("=")) {
    out = out
      .replace(SIGNED_PARAMS, "$1")
      .replace(/\?&+/g, "?")
      .replace(/&{2,}/g, "&")
      .replace(/[?&](?=[\s"'#<>]|$)/g, "");
  }
  return (
    out
      // a bearer token, and a Sanctum token on its own (id|secret)
      .replace(/\bBearer\s+[^\s"',;)]+/gi, `Bearer ${FILTERED}`)
      .replace(/\b\d+\|[A-Za-z0-9]{20,}\b/g, FILTERED)
      // pin=1234, "password":"x", token: abc
      .replace(/\b(pins?|password|passwd|passcode|secret|token|short_?code|api_?key)("?\s*[=:]\s*"?)[^\s"',;&)}\]]+/gi, `$1$2${FILTERED}`)
      // phone numbers: any +international number, then Ethiopian ones without the plus (2519..., 09..., 07...)
      .replace(/\+\d[\d\s().-]{6,}\d/g, "[phone]")
      .replace(/(?<![\w.+])(?:251|0)[79]\d{8}(?![\w.])/g, "[phone]")
  );
}

function scrubValue(value: unknown, depth = 0): unknown {
  if (typeof value === "string") return scrubText(value);
  if (depth > 12 || value === null || typeof value !== "object") return value;
  if (Array.isArray(value)) return value.map((item) => scrubValue(item, depth + 1));
  const out: Record<string, unknown> = {};
  for (const [key, item] of Object.entries(value)) out[key] = SECRET_KEY.test(key) ? FILTERED : scrubValue(item, depth + 1);
  return out;
}

type Bag = Record<string, unknown>;

const isBag = (value: unknown): value is Bag => value !== null && typeof value === "object" && !Array.isArray(value);

/**
 * The event with nothing personal left in it. Only the parts that can carry what a person typed or a link held are rewritten; the SDK's own
 * bookkeeping (ids, timestamps, its processing metadata) is left as it is.
 */
export function scrubEvent<T extends object>(event: T): T {
  const out = { ...event } as Bag;

  for (const key of ["message", "transaction", "extra", "tags", "contexts", "breadcrumbs", "exception", "logentry"]) {
    if (key in out) out[key] = scrubValue(out[key]);
  }

  if (isBag(out.request)) {
    const request = scrubValue(out.request) as Bag;
    delete request.cookies;
    delete request.env;
    delete request.data;
    out.request = request;
  }

  // the arguments a frame captured are whatever the function was given: a PIN, a phone number
  const exception = out.exception;
  if (isBag(exception) && Array.isArray(exception.values)) {
    for (const value of exception.values) {
      const frames = isBag(value) && isBag(value.stacktrace) ? value.stacktrace.frames : undefined;
      if (Array.isArray(frames)) for (const frame of frames) if (isBag(frame)) delete frame.vars;
    }
  }

  if ("user" in out) {
    const id = isBag(out.user) ? out.user.id : undefined;
    out.user = id === undefined || id === null ? undefined : { id };
  }

  return out as T;
}

/**
 * An answer of the API that is a refusal (a 4xx with its message_key: the rule worked) or a lost connection (a shop tablet goes offline,
 * the outbox retries): neither is an error, so neither is sent.
 */
export function isRefusal(error: unknown): boolean {
  return error instanceof ApiError && (error.isNetwork || (error.status >= 400 && error.status < 500));
}
