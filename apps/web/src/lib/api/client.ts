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

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const method = options.method ?? "GET";
  const headers: Record<string, string> = { Accept: "application/json", ...options.headers };
  if (options.body !== undefined) headers["Content-Type"] = "application/json";
  if (options.token) headers.Authorization = `Bearer ${options.token}`;
  if (WRITE_METHODS.has(method)) {
    if (!options.idempotencyKey) throw new Error(`${method} ${path} needs an Idempotency-Key (rule 7)`);
    headers["Idempotency-Key"] = options.idempotencyKey;
  }

  let response: Response;
  try {
    response = await fetch(apiUrl(path, options.query), {
      method,
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: options.signal,
      cache: "no-store",
    });
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === "AbortError") throw cause;
    throw new ApiError(0, { message_key: "network" });
  }

  const text = await response.text();
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
    throw new ApiError(response.status, body);
  }
  return json as T;
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
  } = {},
): Promise<ConditionalResult<T>> {
  const headers: Record<string, string> = { Accept: "application/json", ...options.headers };
  if (options.token) headers.Authorization = `Bearer ${options.token}`;
  if (options.etag) headers["If-None-Match"] = options.etag;

  let response: Response;
  try {
    response = await fetch(apiUrl(path, options.query), { method: "GET", headers, signal: options.signal, cache: "no-store" });
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === "AbortError") throw cause;
    throw new ApiError(0, { message_key: "network" });
  }
  const etag = response.headers.get("ETag");
  if (response.status === 304) return { notModified: true, etag: etag ?? options.etag ?? null };

  const text = await response.text();
  let json: unknown = undefined;
  if (text) {
    try {
      json = JSON.parse(text);
    } catch {
      json = undefined;
    }
  }
  if (!response.ok) {
    throw new ApiError(response.status, json && typeof json === "object" ? (json as Partial<ApiErrorBody>) : {});
  }
  return { notModified: false, etag, data: json as T };
}
