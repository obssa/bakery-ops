"use client";

import { create } from "zustand";
import { request } from "./api/client";
import { uuid } from "./api/idempotency";
import type { Locale, ShortCodeVerified } from "./api/types";
import { actingHooks, useSession } from "./session";

// The shared pad (docs/architecture.md section 6): the device is signed in as the pad account, and a waiter wakes it
// with their short code. POST /short-code/verify starts a 10-minute acting session; every pad call then carries its id
// in X-Acting-Session so the API stamps the waiter, not the pad account (and, like every call of the shell since step 5.1b, the
// device's id in X-Device-Id: lib/device.ts). The session lives in memory only: a reload, a lock or the end of the session
// leaves the pad locked, never open.

/** Locks the pad after this long without a touch. */
export const IDLE_LOCK_MS = 60_000;

export const ACTING_HEADER = "X-Acting-Session";

export type LockReason = "idle" | "expired" | "manual";

export interface ActingUser {
  id: number;
  name: string;
  locale: Locale;
}

interface ActingState {
  user: ActingUser | null;
  sessionId: number | null;
  /** device clock instant the session ends (the server's length added to now, so a skewed clock does not matter) */
  expiresAt: number | null;
  /** the last touch on the pad, device clock */
  touchedAt: number;
  /** why the pad is locked right now (null when open or never opened) */
  lockedBy: LockReason | null;
  unlock: (code: string) => Promise<void>;
  lock: (reason: LockReason) => void;
  touch: () => void;
}

export const useActing = create<ActingState>()((set, get) => ({
  user: null,
  sessionId: null,
  expiresAt: null,
  touchedAt: 0,
  lockedBy: null,

  async unlock(code) {
    const { token, me } = useSession.getState();
    if (!token || !me) throw new Error("the pad account is not signed in");
    const verified = await request<ShortCodeVerified>("/short-code/verify", {
      method: "POST",
      body: { code, branch_id: me.user.branch_id },
      token,
      idempotencyKey: uuid(),
    });
    const length = Date.parse(verified.acting_session.expires_at) - Date.parse(verified.acting_session.started_at);
    const now = Date.now();
    set({
      user: { id: verified.user.id, name: verified.user.name, locale: verified.user.locale },
      sessionId: verified.acting_session.id,
      expiresAt: now + (Number.isFinite(length) && length > 0 ? length : 10 * 60_000),
      touchedAt: now,
      lockedBy: null,
    });
  },

  lock(reason) {
    if (get().user === null) return;
    set({ user: null, sessionId: null, expiresAt: null, lockedBy: reason });
  },

  touch() {
    if (get().user !== null) set({ touchedAt: Date.now() });
  },
}));

// a 401 identity.acting_session.expired from any call locks the pad (the device stays signed in)
actingHooks.onExpired = () => useActing.getState().lock("expired");

/** Why the pad should lock now, or null: 60 s without a touch, or the 10-minute session over. */
export function lockDue(state: Pick<ActingState, "user" | "expiresAt" | "touchedAt">, now: number): LockReason | null {
  if (state.user === null) return null;
  if (state.expiresAt !== null && now >= state.expiresAt) return "expired";
  if (now - state.touchedAt >= IDLE_LOCK_MS) return "idle";
  return null;
}

/** The headers of a pad call: the acting session when a waiter has the pad (the client adds the device, lib/device.ts). */
export function actingHeaders(): Record<string, string> {
  const sessionId = useActing.getState().sessionId;
  return sessionId === null ? {} : { [ACTING_HEADER]: String(sessionId) };
}

// signing the pad account out leaves the pad locked for whoever signs in next
useSession.subscribe((state, previous) => {
  if (previous.status === "signed-in" && state.status !== "signed-in") {
    useActing.setState({ user: null, sessionId: null, expiresAt: null, lockedBy: null });
  }
});
