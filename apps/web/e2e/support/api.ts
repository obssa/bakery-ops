import { randomUUID } from "node:crypto";
import { apiOrigin } from "./env";

// A tiny typed client for the API, for fixtures and post-conditions: bearer token, JSON, and a fresh Idempotency-Key
// on every write (rule 7). It does not share code with the app's own client on purpose: the tests must not pass or
// fail because of a bug in what they check.

export class ApiFailure extends Error {
  constructor(
    readonly status: number,
    readonly path: string,
    readonly body: unknown,
  ) {
    super(`${status} ${path}: ${JSON.stringify(body)}`);
    this.name = "ApiFailure";
  }
  get key(): string | undefined {
    const body = this.body as { message_key?: string } | null;
    return body?.message_key;
  }
}

export interface LoginResult {
  token: string;
  user: { id: number; branch_id: number; name: string; phone: string; stations: { station_id: number }[] };
  permissions: string[];
}

// POST /login is throttled (10 a minute per address) and the live run logs in from one runner, so an account logs in once per worker
// whoever asks (a cache per origin, phone and branch) and again only when the API answers 401 on one of its requests (a revoked token).
const sessions = new Map<string, Promise<{ token: string; login: LoginResult }>>();

export class Api {
  constructor(
    public token: string | null = null,
    readonly origin: string = apiOrigin,
    private readonly account: { phone: string; password: string; branchId?: number } | null = null,
  ) {}

  static async login(phone: string, password: string, branchId?: number, origin: string = apiOrigin): Promise<{ api: Api; login: LoginResult }> {
    const session = await Api.session(phone, password, branchId, origin);
    return { api: new Api(session.token, origin, { phone, password, branchId }), login: session.login };
  }

  private static session(phone: string, password: string, branchId: number | undefined, origin: string) {
    const key = `${origin}|${phone}|${branchId ?? ""}`;
    let pending = sessions.get(key);
    if (!pending) {
      pending = new Api(null, origin)
        .send<LoginResult>("POST", "/login", { phone, password, ...(branchId ? { branch_id: branchId } : {}) })
        .then((login) => ({ token: login.token, login }));
      sessions.set(key, pending);
      // a failed login is not kept: the next caller tries again
      pending.catch(() => sessions.delete(key));
    }
    return pending;
  }

  private async send<T>(method: string, path: string, body?: unknown, headers: Record<string, string> = {}, retried = false): Promise<T> {
    const response = await fetch(`${this.origin}/api/v1${path}`, {
      method,
      headers: {
        Accept: "application/json",
        ...(body === undefined ? {} : { "Content-Type": "application/json" }),
        ...(this.token ? { Authorization: `Bearer ${this.token}` } : {}),
        ...(method === "GET" ? {} : { "Idempotency-Key": randomUUID() }),
        ...headers,
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    const text = await response.text();
    const json: unknown = text ? JSON.parse(text) : null;
    if (response.status === 401 && this.account && !retried) {
      // the token was revoked: forget the cached login, log in once more (shared with the other holders of this account) and retry
      const { phone, password, branchId } = this.account;
      const key = `${this.origin}|${phone}|${branchId ?? ""}`;
      if (this.token === null || (await sessions.get(key))?.token === this.token) sessions.delete(key);
      this.token = (await Api.session(phone, password, branchId, this.origin)).token;
      return this.send<T>(method, path, body, headers, true);
    }
    if (!response.ok) throw new ApiFailure(response.status, path, json);
    return json as T;
  }

  get<T>(path: string, query: Record<string, string | number> = {}): Promise<T> {
    const qs = new URLSearchParams(Object.entries(query).map(([k, v]) => [k, String(v)])).toString();
    return this.send<T>("GET", qs ? `${path}?${qs}` : path);
  }

  post<T>(path: string, body: unknown = {}): Promise<T> {
    return this.send<T>("POST", path, body);
  }

  put<T>(path: string, body: unknown): Promise<T> {
    return this.send<T>("PUT", path, body);
  }

  delete<T>(path: string): Promise<T> {
    return this.send<T>("DELETE", path);
  }

  patch<T>(path: string, body: unknown): Promise<T> {
    return this.send<T>("PATCH", path, body);
  }

  /** A write that is allowed to fail: returns the failure instead of throwing it. */
  async tryPost<T>(path: string, body: unknown = {}): Promise<{ ok: true; data: T } | { ok: false; failure: ApiFailure }> {
    try {
      return { ok: true, data: await this.post<T>(path, body) };
    } catch (error) {
      if (error instanceof ApiFailure) return { ok: false, failure: error };
      throw error;
    }
  }
}

export { randomUUID as uuid };
