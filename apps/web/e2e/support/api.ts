import { randomUUID } from "node:crypto";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
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

// A failed test restarts its worker, and the new worker would log every account in again: ten logins a minute are soon spent. So the
// logins also live in a file for the whole run: global-setup.ts makes the file's path (E2E_TOKEN_FILE, in the system temp folder, never
// in a folder CI uploads) and removes the file when the run ends. Without the variable (a spec run on its own) only the map is used.
type Stored = { token: string; login: LoginResult };

function storedSession(key: string): Stored | undefined {
  const file = process.env.E2E_TOKEN_FILE;
  if (!file || !existsSync(file)) return undefined;
  try {
    return (JSON.parse(readFileSync(file, "utf8")) as Record<string, Stored>)[key];
  } catch {
    return undefined;
  }
}

function storeSession(key: string, value: Stored | null): void {
  const file = process.env.E2E_TOKEN_FILE;
  if (!file) return;
  try {
    const all = existsSync(file) ? (JSON.parse(readFileSync(file, "utf8")) as Record<string, Stored>) : {};
    if (value) all[key] = value;
    else delete all[key];
    writeFileSync(file, JSON.stringify(all));
  } catch {
    // the file is only a convenience: the map still holds the login
  }
}

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

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
      const stored = storedSession(key);
      pending = stored
        ? Promise.resolve(stored)
        : new Api(null, origin)
            .send<LoginResult>("POST", "/login", { phone, password, ...(branchId ? { branch_id: branchId } : {}) })
            .then((login) => {
              storeSession(key, { token: login.token, login });
              return { token: login.token, login };
            });
      sessions.set(key, pending);
      // a failed login is not kept: the next caller tries again
      pending.catch(() => sessions.delete(key));
    }
    return pending;
  }

  private async send<T>(method: string, path: string, body?: unknown, headers: Record<string, string> = {}, retried = false, throttled = false): Promise<T> {
    // a multipart body (POST /files) goes as it is: fetch writes its Content-Type with the boundary
    const form = body instanceof FormData;
    const response = await fetch(`${this.origin}/api/v1${path}`, {
      method,
      headers: {
        Accept: "application/json",
        ...(body === undefined || form ? {} : { "Content-Type": "application/json" }),
        ...(this.token ? { Authorization: `Bearer ${this.token}` } : {}),
        ...(method === "GET" ? {} : { "Idempotency-Key": randomUUID() }),
        ...headers,
      },
      body: body === undefined ? undefined : form ? body : JSON.stringify(body),
    });
    const text = await response.text();
    const json: unknown = text ? JSON.parse(text) : null;
    if (response.status === 429 && !throttled) {
      // throttled (the login allows ten a minute per address): wait as long as the server asks, 60 s when it does not say, and try once more
      const asked = Number(response.headers.get("Retry-After"));
      await sleep((Number.isFinite(asked) && asked > 0 ? Math.min(asked, 65) : 60) * 1000 + 500);
      return this.send<T>(method, path, body, headers, retried, true);
    }
    if (response.status === 401 && this.account && !retried) {
      // the token was revoked: forget the cached login, log in once more (shared with the other holders of this account) and retry
      const { phone, password, branchId } = this.account;
      const key = `${this.origin}|${phone}|${branchId ?? ""}`;
      if (this.token === null || (await sessions.get(key))?.token === this.token) {
        sessions.delete(key);
        storeSession(key, null);
      }
      this.token = (await Api.session(phone, password, branchId, this.origin)).token;
      return this.send<T>(method, path, body, headers, true, throttled);
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

  /** A multipart POST (the receipt photo upload of step 4.5c's fixture). */
  postForm<T>(path: string, form: FormData): Promise<T> {
    return this.send<T>("POST", path, form);
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
