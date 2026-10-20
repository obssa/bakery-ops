"use client";

import { create } from "zustand";
import { ApiError, request } from "../api/client";
import { uuid } from "../api/idempotency";
import type { NewOrder, RejectBody } from "../api/types";
import { useSession } from "../session";

// A minimal outbox (step 1.6a): the writes a screen must not lose to a bad connection, i.e. send an order, accept a
// line, mark a line ready. Each is queued with its Idempotency-Key (and the order its client UUID) fixed at enqueue,
// kept in localStorage (every access in try/catch), sent in order, retried with the SAME key on a network failure or a
// 5xx, and dropped with a visible error on a 4xx. Full offline (conflict screens, the photo queue) is Phase 5.

const STORAGE_KEY = "bakery-ops.outbox.v1";
const RETRY_MS = [2000, 4000, 8000, 10_000];

export type OutboxKind = "send_order" | "accept_line" | "ready_line" | "reject_line";

export interface OutboxItem {
  id: string;
  kind: OutboxKind;
  method: "POST";
  path: string;
  body: unknown;
  idempotencyKey: string;
  /** the account that queued it: only that account sends it */
  userId: number;
  /** the line or order the write is about, so a screen can show "sending" on it */
  subjectId: number | null;
  createdAt: number;
  attempts: number;
}

export interface OutboxFailure {
  id: string;
  kind: OutboxKind;
  subjectId: number | null;
  /** the API's own error, kept whole: a refused send carries its shortfall in `body` */
  error: ApiError;
  at: number;
}

interface OutboxState {
  items: OutboxItem[];
  failures: OutboxFailure[];
  /** true while a send is on the wire */
  sending: boolean;
  enqueue: (item: Omit<OutboxItem, "id" | "idempotencyKey" | "createdAt" | "attempts" | "userId"> & { idempotencyKey?: string }) => OutboxItem | null;
  dismissFailure: (id: string) => void;
  flush: () => Promise<void>;
}

function load(): OutboxItem[] {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    const parsed: unknown = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? (parsed as OutboxItem[]).filter((i) => i && typeof i.id === "string" && typeof i.path === "string") : [];
  } catch {
    return [];
  }
}

function save(items: OutboxItem[]): void {
  try {
    if (items.length) window.localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    else window.localStorage.removeItem(STORAGE_KEY);
  } catch {
    // storage blocked: the queue still works for this tab, it just does not survive a reload
  }
}

type Listener = (item: OutboxItem) => void;
const appliedListeners = new Set<Listener>();

/** Called after a queued write was accepted by the API (a screen then looks at its feed at once). */
export function onOutboxApplied(listener: Listener): () => void {
  appliedListeners.add(listener);
  return () => {
    appliedListeners.delete(listener);
  };
}

let retryTimer: ReturnType<typeof setTimeout> | null = null;
let retries = 0;

export const useOutbox = create<OutboxState>()((set, get) => ({
  items: [],
  failures: [],
  sending: false,

  enqueue: (input) => {
    const me = useSession.getState().me;
    if (!me) return null;
    const item: OutboxItem = {
      id: uuid(),
      kind: input.kind,
      method: input.method,
      path: input.path,
      body: input.body,
      subjectId: input.subjectId,
      idempotencyKey: input.idempotencyKey ?? uuid(),
      userId: me.user.id,
      createdAt: Date.now(),
      attempts: 0,
    };
    const items = [...get().items, item];
    save(items);
    set({ items });
    void get().flush();
    return item;
  },

  dismissFailure: (id) => set({ failures: get().failures.filter((f) => f.id !== id) }),

  flush: async () => {
    if (get().sending) return;
    const { token, me } = useSession.getState();
    if (!token || !me) return;
    set({ sending: true });
    try {
      for (;;) {
        const item = get().items.find((i) => i.userId === me.user.id);
        if (!item) {
          retries = 0;
          return;
        }
        try {
          await request(item.path, { method: item.method, body: item.body, idempotencyKey: item.idempotencyKey, token });
        } catch (error) {
          if (error instanceof ApiError && error.status === 401) {
            useSession.getState().expire();
            return;
          }
          if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
            // refused for good: drop it and say so
            const items = get().items.filter((i) => i.id !== item.id);
            save(items);
            set({ items, failures: [...get().failures, { id: item.id, kind: item.kind, subjectId: item.subjectId, error, at: Date.now() }] });
            appliedListeners.forEach((l) => l(item));
            continue;
          }
          // network or 5xx: keep it, same key, try again later
          const items = get().items.map((i) => (i.id === item.id ? { ...i, attempts: i.attempts + 1 } : i));
          save(items);
          set({ items });
          scheduleRetry();
          return;
        }
        const items = get().items.filter((i) => i.id !== item.id);
        save(items);
        set({ items });
        appliedListeners.forEach((l) => l(item));
      }
    } finally {
      set({ sending: false });
    }
  },
}));

function scheduleRetry(): void {
  if (retryTimer !== null) clearTimeout(retryTimer);
  const delay = RETRY_MS[Math.min(retries, RETRY_MS.length - 1)];
  retries += 1;
  retryTimer = setTimeout(() => {
    retryTimer = null;
    void useOutbox.getState().flush();
  }, delay);
}

/** Reads the saved queue and resumes sending; call once when the app starts and when the device comes back online. */
export function startOutbox(): () => void {
  useOutbox.setState({ items: load() });
  const resume = () => {
    retries = 0;
    void useOutbox.getState().flush();
  };
  window.addEventListener("online", resume);
  const stopWatching = useSession.subscribe((state, previous) => {
    if (state.status === "signed-in" && previous.status !== "signed-in") resume();
  });
  resume();
  return () => {
    window.removeEventListener("online", resume);
    stopWatching();
    if (retryTimer !== null) clearTimeout(retryTimer);
    retryTimer = null;
  };
}

// The writes of step 1.6 (and reject, which carries its reason).

/** POST /orders with send:true. The body carries the order and line client UUIDs the caller fixed. */
export function queueSendOrder(order: NewOrder): OutboxItem | null {
  return useOutbox.getState().enqueue({ kind: "send_order", method: "POST", path: "/orders", body: { ...order, send: true }, subjectId: null });
}

export function queueAcceptLine(lineId: number): OutboxItem | null {
  return useOutbox.getState().enqueue({ kind: "accept_line", method: "POST", path: `/order-lines/${lineId}/accept`, body: {}, subjectId: lineId });
}

export function queueReadyLine(lineId: number): OutboxItem | null {
  return useOutbox.getState().enqueue({ kind: "ready_line", method: "POST", path: `/order-lines/${lineId}/ready`, body: {}, subjectId: lineId });
}

export function queueRejectLine(lineId: number, body: RejectBody): OutboxItem | null {
  return useOutbox.getState().enqueue({ kind: "reject_line", method: "POST", path: `/order-lines/${lineId}/reject`, body, subjectId: lineId });
}
