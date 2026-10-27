import { stationHeaders, useShiftNotice } from "../station";
import type { ApiErrorBody, Locale } from "./types";

// The one way the shell talks to /api/v1 (ADR 0007): bearer token, JSON, and an Idempotency-Key on every write
// (rule 7, ADR 0003). The API origin is baked in at build time: http://localhost:8000 locally and in CI, empty on the
// live site where the shell and the API share https://bakery-ops.orcamind.dev.
const API_ORIGIN = (process.env.NEXT_PUBLIC_API_URL ?? "").replace(/\/+$/, "");
export const API_BASE = `${API_ORIGIN}/api/v1`;

const WRITE_METHODS = new Set(["POST", "PUT", "PATCH", "DELETE"]);

/** A failed call: the API's bilingual message_key body, or a network failure (status 0, key "network"). */
export class ApiError extends Error {
  readonly status: number;
  readonly key: string;
  readonly messages: Partial<Record<Locale, string>>;
  readonly fieldErrors: Record<string, string[]>;
  /** The whole error body, for answers that carry more than a message (409 orders.send.shortfall has the shortfall). */
  readonly body: Record<string, unknown>;

  constructor(status: number, body: Partial<ApiErrorBody>) {
    super(body.message_en || body.message_key || `HTTP ${status}`);
    this.name = "ApiError";
    this.status = status;
    this.key = body.message_key || (status === 0 ? "network" : `http.${status}`);
    this.messages = { en: body.message_en, am: body.message_am };
    this.fieldErrors = body.errors ?? {};
    this.body = body as Record<string, unknown>;
  }

  get isNetwork(): boolean {
    return this.status === 0;
  }
}

/** The 401 of a short-code session that ended or never was: the pad locks, the device stays signed in. */
export const ACTING_EXPIRED_KEY = "identity.acting_session.expired";

export function isActingExpired(error: unknown): boolean {
  return error instanceof ApiError && error.status === 401 && error.key === ACTING_EXPIRED_KEY;
}

export interface RequestOptions {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  query?: Record<string, string | number | boolean | null | undefined>;
  token?: string | null;
  /** Required for writes; reuse the same key when retrying the same request (see idempotency.ts). */
  idempotencyKey?: string;
  signal?: AbortSignal;
  /** Extra request headers: the pad sends X-Acting-Session and X-Device-Id (lib/acting.ts). */
  headers?: Record<string, string>;
  /** Give up after this long (default WRITE_TIMEOUT_MS for writes, READ_TIMEOUT_MS for reads): a network failure. */
  timeoutMs?: number;
}

/** How long a call may wait for an answer before it counts as a network failure (a hung connection must not freeze a feed). */
export const READ_TIMEOUT_MS = 20_000;
export const WRITE_TIMEOUT_MS = 30_000;

/**
 * A signal that fires when the caller aborts or when `ms` have passed, and a way to tell the two apart. Without a limit a fetch that
 * never answers (a dropped connection behind the proxy) leaves a feed waiting for ever: a look is awaited before the next is
 * scheduled, so the screen would stop updating and say nothing (step 3.7a, the pad menu that did not refresh).
 */
function withTimeout(caller: AbortSignal | undefined, ms: number): { signal: AbortSignal; timedOut: () => boolean; done: () => void } {
  const controller = new AbortController();
  let fired = false;
  const timer = setTimeout(() => {
    fired = true;
    controller.abort();
  }, ms);
  const onAbort = () => controller.abort();
  if (caller?.aborted) controller.abort();
  else caller?.addEventListener("abort", onAbort, { once: true });
  return {
    signal: controller.signal,
    timedOut: () => fired,
    done: () => {
      clearTimeout(timer);
      caller?.removeEventListener("abort", onAbort);
    },
  };
}

/** Records the refusals that mean "start a shift first" so the shift bar can say so (step 3.7a), and returns the error. */
function failed(error: ApiError): ApiError {
  if (error.status === 409) useShiftNotice.getState().report(error);
  return error;
}

export function apiUrl(path: string, query?: RequestOptions["query"]): string {
  const url = `${API_BASE}${path.startsWith("/") ? path : `/${path}`}`;
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(query ?? {})) {
    if (v !== undefined && v !== null && v !== "") params.set(k, String(v));
  }
  const qs = params.toString();
  return qs ? `${url}?${qs}` : url;
}

/** A multipart body (POST /files, step 4.5b): sent as it is, the browser writes its Content-Type with the boundary. */
function isForm(body: unknown): body is FormData {
  return typeof FormData !== "undefined" && body instanceof FormData;
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? "GET";
  const headers: Record<string, string> = { Accept: "application/json", ...stationHeaders(), ...options.headers };
  if (options.body !== undefined && !isForm(options.body)) headers["Content-Type"] = "application/json";
  if (options.token) headers.Authorization = `Bearer ${options.token}`;
  if (WRITE_METHODS.has(method)) {
    if (!options.idempotencyKey) throw new Error(`${method} ${path} needs an Idempotency-Key (rule 7)`);
    headers["Idempotency-Key"] = options.idempotencyKey;
  }

  const limit = withTimeout(options.signal, options.timeoutMs ?? (WRITE_METHODS.has(method) ? WRITE_TIMEOUT_MS : READ_TIMEOUT_MS));
  let response: Response;
  let text: string;
  try {
    response = await fetch(apiUrl(path, options.query), {
      method,
      headers,
      body: options.body === undefined ? undefined : isForm(options.body) ? options.body : JSON.stringify(options.body),
      signal: limit.signal,
      cache: "no-store",
    });
    text = await response.text();
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === "AbortError" && !limit.timedOut()) throw cause;
    throw new ApiError(0, { message_key: "network" });
  } finally {
    limit.done();
  }

  let json: unknown = undefined;
  if (text) {
    try {
      json = JSON.parse(text);
    } catch {
      json = undefined;
    }
  }
  if (!response.ok) {
    const body = json && typeof json === "object" ? (json as Partial<ApiErrorBody>) : {};
    throw failed(new ApiError(response.status, body));
  }
  return json as T;
}

/**
 * A GET whose answer is a file (the receipt PDF of step 4.5b), with the same headers, time limit and errors as `request`: a refusal is
 * still the API's bilingual JSON body.
 */
export async function requestBlob(
  path: string,
  options: { query?: RequestOptions["query"]; token?: string | null; signal?: AbortSignal; timeoutMs?: number } = {},
): Promise<Blob> {
  const headers: Record<string, string> = { Accept: "application/pdf, application/json", ...stationHeaders() };
  if (options.token) headers.Authorization = `Bearer ${options.token}`;
  const limit = withTimeout(options.signal, options.timeoutMs ?? READ_TIMEOUT_MS);
  let response: Response;
  let blob: Blob;
  try {
    response = await fetch(apiUrl(path, options.query), { method: "GET", headers, signal: limit.signal, cache: "no-store" });
    blob = await response.blob();
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === "AbortError" && !limit.timedOut()) throw cause;
    throw new ApiError(0, { message_key: "network" });
  } finally {
    limit.done();
  }
  if (!response.ok) {
    let body: Partial<ApiErrorBody> = {};
    try {
      const json: unknown = JSON.parse(await blob.text());
      if (json && typeof json === "object") body = json as Partial<ApiErrorBody>;
    } catch {
      body = {};
    }
    throw failed(new ApiError(response.status, body));
  }
  return blob;
}

/** What a conditional GET came back with: the page, or "nothing changed" (304). */
export type ConditionalResult<T> = { notModified: true; etag: string | null } | { notModified: false; etag: string | null; data: T };

/**
 * A GET that sends If-None-Match and treats 304 as an answer (ADR 0009: the cursor feeds are polled this way). The
 * ETag comes back so the caller can send it with the next poll. Errors are ApiErrors like everywhere else.
 */
export async function conditionalGet<T>(
  path: string,
  options: {
    query?: RequestOptions["query"];
    token?: string | null;
    etag?: string | null;
    signal?: AbortSignal;
    headers?: Record<string, string>;
    timeoutMs?: number;
  } = {},
): Promise<ConditionalResult<T>> {
  const headers: Record<string, string> = { Accept: "application/json", ...stationHeaders(), ...options.headers };
  if (options.token) headers.Authorization = `Bearer ${options.token}`;
  if (options.etag) headers["If-None-Match"] = options.etag;

  const limit = withTimeout(options.signal, options.timeoutMs ?? READ_TIMEOUT_MS);
  let response: Response;
  let text = "";
  try {
    response = await fetch(apiUrl(path, options.query), { method: "GET", headers, signal: limit.signal, cache: "no-store" });
    if (response.status !== 304) text = await response.text();
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === "AbortError" && !limit.timedOut()) throw cause;
    throw new ApiError(0, { message_key: "network" });
  } finally {
    limit.done();
  }
  const etag = response.headers.get("ETag");
  if (response.status === 304) return { notModified: true, etag: etag ?? options.etag ?? null };

  let json: unknown = undefined;
  if (text) {
    try {
      json = JSON.parse(text);
    } catch {
      json = undefined;
    }
  }
  if (!response.ok) {
    throw failed(new ApiError(response.status, json && typeof json === "object" ? (json as Partial<ApiErrorBody>) : {}));
  }
  return { notModified: false, etag, data: json as T };
}
