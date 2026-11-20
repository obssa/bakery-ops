"use client";

import { create } from "zustand";
import { ApiError, isActingExpired, request, requestBlob, type RequestOptions } from "./api/client";
import { uuid } from "./api/idempotency";
import type { LoginResponse, Me } from "./api/types";
import { rememberBrand } from "./brandMemory";
import { isDeviceRefusal } from "./device";

// The signed-in state of this device (ADR 0007): a Sanctum bearer token kept in memory and in localStorage (so a
// reload or a later visit stays signed in), plus the GET /me answer (user, permissions, locale). Signing out revokes
// the token on the server and forgets it here; a 401 on any call forgets it too.

/**
 * GET /me. The API refuses every signed-in call of a revoked device (403 identity.device.revoked, whatever the enforcement flag): the
 * client has then made this device a new id (lib/device.ts), so /me is asked once more with it instead of signing the person out.
 */
async function readMe(token: string): Promise<Me> {
  let me: Me;
  try {
    me = await request<Me>("/me", { token });
  } catch (error) {
    if (!isDeviceRefusal(error)) throw error;
    me = await request<Me>("/me", { token });
  }
  // the brand this device last saw, for the sign-in screen, which nobody is signed in to (lib/brandMemory.ts)
  rememberBrand(me.brand ?? null);
  return me;
}

const TOKEN_KEY = "bakery-ops.token";

export type SessionStatus = "unknown" | "signed-out" | "signed-in";

interface SessionState {
  token: string | null;
  me: Me | null;
  status: SessionStatus;
  /** set when a call came back 401 (token revoked or user deactivated); the login page says so */
  expired: boolean;
  /** /me could not be reached (network): the token is kept, the guard offers a retry */
  unreachable: boolean;
  init: () => Promise<void>;
  /** keeps the session; resolves with the person once /me is read again (its work_stations), or with the sign-in answer if that fails */
  signIn: (login: LoginResponse) => Promise<Me>;
  signOut: () => Promise<void>;
  expire: () => void;
}

export function storedToken(): string | null {
  try {
    return window.localStorage.getItem(TOKEN_KEY);
  } catch {
    return null;
  }
}

function storeToken(token: string | null): void {
  try {
    if (token) window.localStorage.setItem(TOKEN_KEY, token);
    else window.localStorage.removeItem(TOKEN_KEY);
  } catch {
    // storage blocked (private mode): the token still lives in memory for this tab
  }
}

/**
 * The session's generation: a sign-in, a sign-out and an expiry each start a new one. A GET /me answer is applied only while the
 * generation it was asked in is still the current one, so a slow read of the token stored before a sign-in can never sign the new
 * person out (its 401) or put the old person back (its 200), and a read that ends after a sign-out cannot sign the device back in
 * with no token (implicit-state review, 2026-11-20).
 */
let generation = 0;
let initialising: Promise<void> | null = null;
/** The person is the sign-in answer's (no work_stations, device, acting, brand) until GET /me is read once for this sign-in. */
let provisional = false;

/** How long a session that could not read GET /me waits before asking again; the last delay repeats. Tests shorten it. */
export const sessionRetry: { delays: number[] } = { delays: [5_000, 15_000, 30_000, 60_000] };
let retryTimer: ReturnType<typeof setTimeout> | null = null;
let retryStep = 0;

function listenOnline(on: boolean): void {
  if (typeof window === "undefined" || typeof window.addEventListener !== "function") return;
  if (on) window.addEventListener("online", retryNow);
  else window.removeEventListener("online", retryNow);
}

function clearRetryTimer(): void {
  if (retryTimer !== null) clearTimeout(retryTimer);
  retryTimer = null;
  listenOnline(false);
}

function stopRetry(): void {
  clearRetryTimer();
  retryStep = 0;
}

/** Asks GET /me again after the next delay, or at once when the connection comes back. */
function scheduleRetry(): void {
  if (retryTimer !== null) return;
  const delays = sessionRetry.delays;
  const delay = delays[Math.min(retryStep, delays.length - 1)] ?? 60_000;
  retryStep += 1;
  retryTimer = setTimeout(retryNow, delay);
  listenOnline(true);
}

function retryNow(): void {
  clearRetryTimer();
  const session = useSession.getState();
  if (session.status === "unknown" && session.unreachable) void session.init();
  else if (session.status === "signed-in" && provisional && session.token) void confirm(session.token, generation, "keep");
}

/** A new sign-in, sign-out or expiry: answers asked for the one before are dropped, and so is their retry. */
function nextGeneration(): number {
  generation += 1;
  initialising = null;
  provisional = false;
  stopRetry();
  return generation;
}

/**
 * Reads GET /me for `token` and applies the answer only if `asked` is still the session's generation. A refusal (401, 403) of the
 * token found at start-up signs the device out ("sign-out"); after a fresh sign-in the sign-in answer is kept ("keep": the next call
 * that is refused signs out, as every call does). No answer (network, 5xx) keeps the token and asks again later (sessionRetry).
 */
async function confirm(token: string, asked: number, onRefused: "sign-out" | "keep"): Promise<Me | null> {
  const set = useSession.setState;
  try {
    const me = await readMe(token);
    if (asked !== generation) return null;
    provisional = false;
    stopRetry();
    set({ me, status: "signed-in", unreachable: false });
    return me;
  } catch (error) {
    if (asked !== generation) return null;
    if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
      stopRetry();
      if (onRefused === "sign-out") {
        storeToken(null);
        set({ token: null, me: null, status: "signed-out", unreachable: false });
      } else {
        provisional = false;
      }
    } else {
      set({ unreachable: true });
      scheduleRetry();
    }
    return null;
  }
}

/**
 * Where a deliberate sign-out goes: the sign-in page, which comes back to this screen when the next person's role opens it (the shared
 * till or pad stays the till or the pad; LoginForm's useFinishSignIn checks canOpen, so anyone else lands on their own screen).
 */
export function signOutTarget(pathname: string): { pathname: "/login"; query?: { next: string } } {
  if (!pathname.startsWith("/") || pathname.startsWith("//") || pathname === "/" || pathname.startsWith("/login")) return { pathname: "/login" };
  return { pathname: "/login", query: { next: pathname } };
}

export const useSession = create<SessionState>()((set, get) => ({
  token: null,
  me: null,
  status: "unknown",
  expired: false,
  unreachable: false,

  init: () => {
    if (initialising) return initialising;
    const token = get().token ?? storedToken();
    if (!token) {
      set({ status: "signed-out", token: null, me: null, unreachable: false });
      return Promise.resolve();
    }
    set({ token, unreachable: false });
    const run: Promise<void> = confirm(token, generation, "sign-out").then(() => undefined);
    initialising = run;
    void run.finally(() => {
      if (initialising === run) initialising = null;
    });
    return run;
  },

  signIn: (login) => {
    const asked = nextGeneration();
    storeToken(login.token);
    const first: Me = { user: login.user, permissions: login.permissions, locale: login.locale, acting: null, device: null, work_stations: [] };
    provisional = true;
    set({ token: login.token, me: first, status: "signed-in", expired: false, unreachable: false });
    // the sign-in answer has no work_stations: read /me once more so the station screens (and where the person lands) know where they
    // work; when it cannot be read now it is asked again (sessionRetry, or when the connection is back) rather than kept for the visit
    return confirm(login.token, asked, "keep").then((me) => me ?? first);
  },

  signOut: async () => {
    const token = get().token;
    nextGeneration();
    storeToken(null);
    set({ token: null, me: null, status: "signed-out", expired: false, unreachable: false });
    if (token) {
      try {
        await request("/logout", { method: "POST", token, idempotencyKey: uuid() });
      } catch {
        // already revoked or offline: forgetting it here is what matters on this device
      }
    }
  },

  expire: () => {
    nextGeneration();
    storeToken(null);
    set({ token: null, me: null, status: "signed-out", expired: true, unreachable: false });
  },
}));

/** Set by lib/acting.ts: what a 401 `identity.acting_session.expired` does (lock the pad). */
export const actingHooks = { onExpired: () => {} };

/**
 * A 401 on a call. The acting session of the shared pad ending is not the device signing out: the pad locks and the
 * next waiter enters their code. Any other 401 (token revoked, user switched off) signs this device out.
 */
export function handleUnauthorized(error: unknown): void {
  if (!(error instanceof ApiError) || error.status !== 401) return;
  if (isActingExpired(error)) actingHooks.onExpired();
  else useSession.getState().expire();
}

/** A call with the current token; a 401 signs this device out (the guard then sends the user to the login page). */
export async function authed<T>(path: string, options: Omit<RequestOptions, "token"> = {}): Promise<T> {
  const { token } = useSession.getState();
  try {
    return await request<T>(path, { ...options, token });
  } catch (error) {
    handleUnauthorized(error);
    throw error;
  }
}

/** A file (a receipt PDF, a payments export) with the current token; a 401 signs this device out, as `authed` does. */
export async function authedBlob(path: string, options: { query?: RequestOptions["query"]; timeoutMs?: number; accept?: string } = {}): Promise<Blob> {
  const { token } = useSession.getState();
  try {
    return await requestBlob(path, { ...options, token });
  } catch (error) {
    handleUnauthorized(error);
    throw error;
  }
}

export function can(me: Me | null, permission: string): boolean {
  return !!me?.permissions.includes(permission);
}
