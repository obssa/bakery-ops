import { isDeviceRefusal } from "../device";

// The outbox's rules, as pure functions over the list of queued writes (step 5.2a; plan section 4.2 and 10.2 D10). The store
// (store.ts) keeps the list in memory and in IndexedDB (db.ts) and sends what these functions pick:
// - strict order per order: the writes of one order (its `group`) go one after another, and a write waiting on a failure holds up only
//   its own order;
// - `dependsOn`: a write goes only after the one it depends on was accepted, and then with that one's server id written in where it
//   `needs` it (a line action after its order's send, a payment after its photo);
// - a write that failed on the network or a 5xx waits 1, 2, 4 ... 60 s and goes again with the SAME Idempotency-Key; the browser's
//   online event sends everything at once;
// - a refusal (any other 4xx) moves the write, and every write that depends on it, to the conflict list (the tray is step 5.2c);
// - a device refusal keeps everything and sends nothing more until the page starts again (step 5.1b).

export const LEGACY_STORAGE_KEY = "bakery-ops.outbox.v1";

export type OutboxKind =
  // the kitchen and bar displays (step 1.6a)
  | "accept_line"
  | "ready_line"
  | "reject_line"
  // the waiter pad (steps 1.6a and 1.6b)
  | "send_order"
  | "replace_lines"
  | "send_existing"
  | "serve_line"
  | "cancel_order"
  // step 5.2a: the till's payments, the production batch steps, the shift bar's count and cash drafts
  | "pay_batch"
  | "start_batch"
  | "finish_batch"
  | "count_lines"
  | "count_cash";

/** The screens of the shell (lib/screens.ts), and "shifts" for the count pages; "other" is any other page. */
export type ScreenKey = "pad" | "till" | "kds_kitchen" | "kds_bar" | "production" | "shifts" | "admin" | "other";

export interface Conflict {
  /** the API's status (0 when the write was refused here, not by the API) */
  status: number;
  /** the API's error body, whole: `{message_key, message_en, message_am, ...}` */
  body: Record<string, unknown>;
  at: number;
  /** set on a write that was never sent because the write it depends on was refused */
  blockedBy: string | null;
}

export interface OutboxItem {
  id: string;
  /** the order of the queue (IndexedDB returns records by id) */
  seq: number;
  kind: OutboxKind;
  method: "POST" | "PUT";
  path: string;
  body: unknown;
  /** fixed when the write was queued, never changed: every retry is the same write for the API */
  idempotencyKey: string;
  /** headers fixed when the write was queued: the pad's X-Acting-Session (the waiter who tapped, not whoever acts at retry time) */
  headers?: Record<string, string>;
  /** the line or order the write is about, so a screen can show "sending" on it */
  subjectId: number | null;
  /** the order's client_uuid, so the pad finds the answer to its own send */
  subjectUuid: string | null;
  /** the writes of one group (an order) go strictly one after another; null: on its own */
  group: string | null;
  /** the write that must be accepted first */
  dependsOn: string | null;
  /** where the dependency's answer goes: `token` in the path and the body becomes the value at `from` in that answer */
  needs: { token: string; from: string } | null;
  /** true: the screen that queued it shows a refusal itself (the pad's shortfall on its line), so the shell strip stays quiet */
  inline: boolean;
  /** the account that queued it: only that account sends it */
  userId: number;
  /** the screen it was queued on, for the pending count per screen (null: not known, an item of the old queue) */
  screen: ScreenKey | null;
  createdAt: number;
  /** the failed attempts so far (network or 5xx) */
  attempts: number;
  /** not before this time (ms); 0: now */
  nextAttemptAt: number;
  state: "pending" | "conflict";
  conflict: Conflict | null;
}

export type Outcome = "retry" | "conflict" | "device" | "signed_out";

const MAX_BACKOFF_MS = 60_000;

/** How long a write waits after its `failures`-th failed attempt: 1, 2, 4 ... seconds, at most 60. */
export function backoffMs(failures: number): number {
  if (failures <= 0) return 0;
  return Math.min(MAX_BACKOFF_MS, 1_000 * 2 ** Math.min(failures - 1, 16));
}

/** What an answer of the API (0: no answer at all) does to the write. */
export function outcomeOf(status: number, key: string): Outcome {
  if (status === 0 || status >= 500 || status === 408 || status === 429) return "retry";
  if (isDeviceRefusal({ status, key })) return "device";
  if (status === 401 && key !== "identity.acting_session.expired") return "signed_out";
  return "conflict";
}

function pending(items: OutboxItem[]): Set<string> {
  return new Set(items.filter((i) => i.state === "pending").map((i) => i.id));
}

/** Whether `item` may go now, time aside: the first pending write of its group, its dependency no longer waiting. */
function free(item: OutboxItem, items: OutboxItem[], waiting: Set<string>): boolean {
  if (item.state !== "pending") return false;
  if (item.dependsOn && waiting.has(item.dependsOn)) return false;
  // a write that needs its dependency's answer cannot go before it has it
  if (item.needs && item.dependsOn) return false;
  if (item.group === null) return true;
  const head = items.find((i) => i.state === "pending" && i.group === item.group);
  return head?.id === item.id;
}

/** The write to send next for this account at `now`, or null. */
export function nextSendable(items: OutboxItem[], userId: number, now: number): OutboxItem | null {
  const waiting = pending(items);
  return items.find((i) => i.userId === userId && i.nextAttemptAt <= now && free(i, items, waiting)) ?? null;
}

/** When the next write of this account could go (the earliest wait among those free to go), or null when none is. */
export function nextWakeAt(items: OutboxItem[], userId: number): number | null {
  const waiting = pending(items);
  let at: number | null = null;
  for (const i of items) if (i.userId === userId && free(i, items, waiting) && (at === null || i.nextAttemptAt < at)) at = i.nextAttemptAt;
  return at;
}

/** After a failed attempt (network or 5xx): the write stays, with its key, and waits. */
export function retryLater(items: OutboxItem[], id: string, now: number): OutboxItem[] {
  return items.map((i) => (i.id === id ? { ...i, attempts: i.attempts + 1, nextAttemptAt: now + backoffMs(i.attempts + 1) } : i));
}

/** The connection is back: every pending write may go now. */
export function resumeAll(items: OutboxItem[]): OutboxItem[] {
  return items.map((i) => (i.state === "pending" && i.nextAttemptAt !== 0 ? { ...i, nextAttemptAt: 0 } : i));
}

/** The value at `from` in an answer: `id`, `batch.id`, `lines[client_uuid=…].id`. */
export function pick(answer: unknown, from: string): unknown {
  let value: unknown = answer;
  for (const part of from.split(".")) {
    if (value === null || typeof value !== "object") return undefined;
    const select = /^(\w+)\[(\w+)=([^\]]*)\]$/.exec(part);
    if (select) {
      const list = (value as Record<string, unknown>)[select[1]];
      if (!Array.isArray(list)) return undefined;
      value = list.find((entry) => entry !== null && typeof entry === "object" && String((entry as Record<string, unknown>)[select[2]]) === select[3]);
    } else {
      value = (value as Record<string, unknown>)[part];
    }
  }
  return value;
}

function fill(value: unknown, token: string, answer: unknown): unknown {
  if (typeof value === "string") {
    if (value === token) return answer;
    return value.includes(token) ? value.split(token).join(String(answer)) : value;
  }
  if (Array.isArray(value)) return value.map((v) => fill(v, token, answer));
  if (value !== null && typeof value === "object") {
    return Object.fromEntries(Object.entries(value as Record<string, unknown>).map(([k, v]) => [k, fill(v, token, answer)]));
  }
  return value;
}

/** The API accepted write `id`: it leaves the queue, and every write that depended on it is freed and given what it needs. */
export function settle(items: OutboxItem[], id: string, answer: unknown): OutboxItem[] {
  return items
    .filter((i) => i.id !== id)
    .map((i) => {
      if (i.dependsOn !== id) return i;
      if (!i.needs) return { ...i, dependsOn: null };
      const value = pick(answer, i.needs.from);
      if (value === undefined || value === null) {
        // the answer does not carry what this write needs: it cannot go, it is a conflict to show
        return {
          ...i,
          dependsOn: null,
          state: "conflict" as const,
          conflict: { status: 0, body: { message_key: "outbox.dependency_answer_missing" }, at: Date.now(), blockedBy: id },
        };
      }
      const { token } = i.needs;
      return { ...i, dependsOn: null, needs: null, path: String(fill(i.path, token, value)), body: fill(i.body, token, value) };
    });
}

/** The API refused write `id` for good: it and every write that depends on it (however far down) become conflicts. */
export function refuse(items: OutboxItem[], id: string, refusal: { status: number; body: Record<string, unknown> }, now: number): OutboxItem[] {
  const blocked = new Set<string>();
  for (let grown = true; grown; ) {
    grown = false;
    for (const i of items) {
      if (i.state === "pending" && i.dependsOn && (i.dependsOn === id || blocked.has(i.dependsOn)) && !blocked.has(i.id)) {
        blocked.add(i.id);
        grown = true;
      }
    }
  }
  return items.map((i) => {
    if (i.id === id) return { ...i, state: "conflict" as const, conflict: { status: refusal.status, body: refusal.body, at: now, blockedBy: null } };
    if (blocked.has(i.id)) return { ...i, state: "conflict" as const, conflict: { status: refusal.status, body: refusal.body, at: now, blockedBy: id } };
    return i;
  });
}

/** The screen of a page path (with or without the /app base path and the locale). */
export function screenOf(pathname: string): ScreenKey {
  const parts = pathname.split("/").filter(Boolean);
  if (parts[0] === "app") parts.shift();
  if (parts[0] === "en" || parts[0] === "am") parts.shift();
  switch (parts[0]) {
    case "pad":
    case "till":
    case "production":
    case "shifts":
    case "admin":
      return parts[0];
    case "kds":
      return parts[1] === "bar" ? "kds_bar" : parts[1] === "kitchen" ? "kds_kitchen" : "other";
    default:
      return "other";
  }
}

/** The writes waiting to be sent from this screen and from the others, and the conflicts. */
export function pendingCounts(items: OutboxItem[], screen: ScreenKey): { here: number; elsewhere: number; conflicts: number } {
  let here = 0;
  let elsewhere = 0;
  let conflicts = 0;
  for (const i of items) {
    if (i.state === "conflict") conflicts += 1;
    else if (i.screen === screen) here += 1;
    else elsewhere += 1;
  }
  return { here, elsewhere, conflicts };
}

const ORDER_KINDS: readonly OutboxKind[] = ["send_order", "replace_lines", "send_existing", "cancel_order"];
const PAD_KINDS: readonly OutboxKind[] = ["send_order", "replace_lines", "send_existing", "serve_line", "cancel_order"];

/** The group of a write when its caller names none: an order's writes by the order (its client_uuid, else its id), a line's by the line. */
export function groupOf(kind: OutboxKind, subjectUuid: string | null, subjectId: number | null): string | null {
  if (ORDER_KINDS.includes(kind)) return subjectUuid ? `order:${subjectUuid}` : subjectId !== null ? `order#${subjectId}` : null;
  if (subjectId !== null && (kind === "accept_line" || kind === "ready_line" || kind === "reject_line" || kind === "serve_line")) return `line:${subjectId}`;
  return null;
}

interface LegacyItem {
  id: string;
  kind: OutboxKind;
  method: "POST" | "PUT";
  path: string;
  body?: unknown;
  idempotencyKey: string;
  headers?: Record<string, string>;
  subjectUuid?: string | null;
  after?: string | null;
  inline?: boolean;
  userId: number;
  subjectId?: number | null;
  createdAt?: number;
  attempts?: number;
}

function isLegacyItem(value: unknown): value is LegacyItem {
  if (value === null || typeof value !== "object") return false;
  const v = value as Record<string, unknown>;
  return typeof v.id === "string" && typeof v.path === "string" && typeof v.kind === "string" && typeof v.idempotencyKey === "string" && typeof v.userId === "number";
}

/** The queue of steps 1.6 to 5.1 (localStorage, `bakery-ops.outbox.v1`) as outbox items, in its order. Anything unreadable is left out. */
export function migrateLegacy(raw: string | null): OutboxItem[] {
  if (!raw) return [];
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return [];
  }
  if (!Array.isArray(parsed)) return [];
  return parsed.filter(isLegacyItem).map((old, index) => {
    const subjectUuid = old.subjectUuid ?? null;
    const subjectId = typeof old.subjectId === "number" ? old.subjectId : null;
    const pad = PAD_KINDS.includes(old.kind) && (old.kind !== "send_order" || old.inline === true);
    return {
      id: old.id,
      seq: index + 1,
      kind: old.kind,
      method: old.method === "PUT" ? "PUT" : "POST",
      path: old.path,
      body: old.body ?? {},
      idempotencyKey: old.idempotencyKey,
      ...(old.headers ? { headers: old.headers } : {}),
      subjectId,
      subjectUuid,
      group: groupOf(old.kind, subjectUuid, subjectId),
      dependsOn: old.after ?? null,
      needs: null,
      inline: old.inline === true,
      userId: old.userId,
      screen: pad ? "pad" : null,
      createdAt: typeof old.createdAt === "number" ? old.createdAt : 0,
      attempts: typeof old.attempts === "number" ? old.attempts : 0,
      nextAttemptAt: 0,
      state: "pending",
      conflict: null,
    } satisfies OutboxItem;
  });
}
