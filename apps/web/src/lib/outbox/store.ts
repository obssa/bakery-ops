"use client";

import { create } from "zustand";
import { ApiError, isActingExpired, request } from "../api/client";
import { uuid } from "../api/idempotency";
import type { NewOrder, NewOrderLine, RejectBody } from "../api/types";
import { actingHooks, useSession } from "../session";
import { actingHeaders } from "../acting";
import { isDeviceRefusal } from "../device";

// A minimal outbox (step 1.6a): the writes a screen must not lose to a bad connection, i.e. send an order, accept a
// line, mark a line ready. Each is queued with its Idempotency-Key (and the order its client UUID) fixed at enqueue,
// kept in localStorage (every access in try/catch), sent in order, retried with the SAME key on a network failure or a
// 5xx, and dropped with a visible error on a 4xx. Full offline (conflict screens, the photo queue) is Phase 5.

const STORAGE_KEY = "bakery-ops.outbox.v1";
const RETRY_MS = [2000, 4000, 8000, 10_000];

export type OutboxKind =
  | "send_order"
  | "accept_line"
  | "ready_line"
  | "reject_line"
  // the waiter pad (step 1.6b)
  | "replace_lines"
  | "send_existing"
  | "serve_line"
  | "cancel_order";

export interface OutboxItem {
  id: string;
  kind: OutboxKind;
  method: "POST" | "PUT";
  path: string;
  body: unknown;
  idempotencyKey: string;
  /** extra headers fixed at enqueue: the pad X-Acting-Session and X-Device-Id (the waiter who tapped, not whoever acts at retry time) */
  headers?: Record<string, string>;
  /** the order client_uuid, so the pad finds the answer to its own send */
  subjectUuid?: string | null;
  /** the item that must succeed first; when it is refused this one is dropped with it (replace lines, then send) */
  after?: string | null;
  /** true: the screen shows the refusal itself (the shortfall on its line), so the shell strip stays quiet */
  inline?: boolean;
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
  subjectUuid: string | null;
  /** shown by the screen that queued it, not by the shell strip */
  inline: boolean;
  /** the API own error, kept whole: a refused send carries its shortfall in `body` */
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

export type OutboxOutcome = "accepted" | "refused";
type Listener = (item: OutboxItem, outcome: OutboxOutcome) => void;
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
      headers: input.headers,
      subjectUuid: input.subjectUuid ?? null,
      after: input.after ?? null,
      inline: input.inline ?? false,
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
          await request(item.path, { method: item.method, body: item.body, idempotencyKey: item.idempotencyKey, token, headers: item.headers });
        } catch (error) {
          if (error instanceof ApiError && error.status === 401 && !isActingExpired(error)) {
            useSession.getState().expire();
            return;
          }
          if (isDeviceRefusal(error)) {
            // this tablet is not enrolled (or was revoked): the "not enrolled" screen says so; the write waits, with its key, until an
            // admin enrols the device and the page starts again (step 5.1b)
            return;
          }
          if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
            // refused for good (an ended acting session included: the waiter enters their code and taps again):
            // drop it, and whatever waited for it, and say so
            const dropped = new Set<string>([item.id]);
            for (let grown = true; grown; ) {
              grown = false;
              for (const other of get().items) {
                if (other.after && dropped.has(other.after) && !dropped.has(other.id)) {
                  dropped.add(other.id);
                  grown = true;
                }
              }
            }
            const items = get().items.filter((i) => !dropped.has(i.id));
            save(items);
            set({
              items,
              failures: [
                ...get().failures,
                { id: item.id, kind: item.kind, subjectId: item.subjectId, subjectUuid: item.subjectUuid ?? null, inline: item.inline ?? false, error, at: Date.now() },
              ],
            });
            if (isActingExpired(error)) actingHooks.onExpired();
            appliedListeners.forEach((l) => l(item, "refused"));
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
        appliedListeners.forEach((l) => l(item, "accepted"));
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

// The pad writes. Each carries the acting headers of the moment the waiter tapped; the sends are answered on the screen
// (inline), so a refusal such as the shortfall lands on its line and not in the shell strip.

/** POST /orders with send:true for a new cart. */
export function queuePadSend(order: NewOrder): OutboxItem | null {
  return useOutbox.getState().enqueue({
    kind: "send_order",
    method: "POST",
    path: "/orders",
    body: { ...order, send: true },
    subjectId: null,
    subjectUuid: order.client_uuid,
    headers: actingHeaders(),
    inline: true,
  });
}

/** PUT /orders/{id}/lines: the open order lines become the cart lines (after a shortfall the waiter fixed a line). */
export function queueReplaceLines(orderId: number, orderUuid: string, lines: NewOrderLine[]): OutboxItem | null {
  return useOutbox.getState().enqueue({
    kind: "replace_lines",
    method: "PUT",
    path: `/orders/${orderId}/lines`,
    body: { lines },
    subjectId: orderId,
    subjectUuid: orderUuid,
    headers: actingHeaders(),
    inline: true,
  });
}

/** POST /orders/{id}/send for an order that is already open on the server; `after` is the replace it waits for. */
export function queueSendExisting(orderId: number, orderUuid: string, after: string | null): OutboxItem | null {
  return useOutbox.getState().enqueue({
    kind: "send_existing",
    method: "POST",
    path: `/orders/${orderId}/send`,
    body: {},
    subjectId: orderId,
    subjectUuid: orderUuid,
    headers: actingHeaders(),
    after,
    inline: true,
  });
}

export function queueServeLine(lineId: number): OutboxItem | null {
  return useOutbox.getState().enqueue({ kind: "serve_line", method: "POST", path: `/order-lines/${lineId}/served`, body: {}, subjectId: lineId, headers: actingHeaders() });
}

export function queueCancelOrder(orderId: number): OutboxItem | null {
  return useOutbox.getState().enqueue({ kind: "cancel_order", method: "POST", path: `/orders/${orderId}/cancel`, body: {}, subjectId: orderId, headers: actingHeaders() });
}
