"use client";

import { create } from "zustand";
import { ApiError, isActingExpired, request, type RequestOptions } from "./api/client";
import { uuid } from "./api/idempotency";
import type { LoginResponse, Me } from "./api/types";

// The signed-in state of this device (ADR 0007): a Sanctum bearer token kept in memory and in localStorage (so a
// reload or a later visit stays signed in), plus the GET /me answer (user, permissions, locale). Signing out revokes
// the token on the server and forgets it here; a 401 on any call forgets it too.

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
  signIn: (login: LoginResponse) => void;
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

let initialising: Promise<void> | null = null;

export const useSession = create<SessionState>()((set, get) => ({
  token: null,
  me: null,
  status: "unknown",
  expired: false,
  unreachable: false,

  init: () => {
    initialising ??= (async () => {
      const token = get().token ?? storedToken();
      if (!token) {
        set({ status: "signed-out", token: null, me: null });
        return;
      }
      set({ token, unreachable: false });
      try {
        const me = await request<Me>("/me", { token });
        set({ me, status: "signed-in" });
      } catch (error) {
        if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
          storeToken(null);
          set({ token: null, me: null, status: "signed-out" });
        } else {
          set({ unreachable: true });
        }
      } finally {
        initialising = null;
      }
    })();
    return initialising;
  },

  signIn: (login) => {
    storeToken(login.token);
    set({
      token: login.token,
      me: { user: login.user, permissions: login.permissions, locale: login.locale, acting: null, device: null },
      status: "signed-in",
      expired: false,
      unreachable: false,
    });
  },

  signOut: async () => {
    const token = get().token;
    storeToken(null);
    set({ token: null, me: null, status: "signed-out", expired: false });
    if (token) {
      try {
        await request("/logout", { method: "POST", token, idempotencyKey: uuid() });
      } catch {
        // already revoked or offline: forgetting it here is what matters on this device
      }
    }
  },

  expire: () => {
    storeToken(null);
    set({ token: null, me: null, status: "signed-out", expired: true });
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

export function can(me: Me | null, permission: string): boolean {
  return !!me?.permissions.includes(permission);
}
