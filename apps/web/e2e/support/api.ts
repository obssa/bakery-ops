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

export class Api {
  constructor(
    readonly token: string | null = null,
    readonly origin: string = apiOrigin,
  ) {}

  static async login(phone: string, password: string, branchId?: number): Promise<{ api: Api; login: LoginResult }> {
    const anonymous = new Api();
    const login = await anonymous.send<LoginResult>("POST", "/login", { phone, password, ...(branchId ? { branch_id: branchId } : {}) });
    return { api: new Api(login.token), login };
  }

  private async send<T>(method: string, path: string, body?: unknown, headers: Record<string, string> = {}): Promise<T> {
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
