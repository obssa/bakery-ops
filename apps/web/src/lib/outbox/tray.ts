import type { NewOrderLine, OrderType } from "../api/types";
import type { CartSource } from "../cart";
import { trimDecimal } from "../decimal";
import { photosOf, type Conflict, type OutboxItem, type OutboxKind } from "./logic";

export type { CartSource } from "../cart";

// The conflict tray's rules (step 5.2c; plan 4.2 and ADR 0018), as pure functions over the queue. A write the API refused on sync (or the
// tablet refused, status 0) stays in the queue as a conflict until someone acts on it, so nothing refused is dropped silently:
// - each refused write is one entry, with the writes it held back (never sent) under it;
// - why it was refused comes from the API's key, and the reason decides what may be done: the pad's sends go back to the cart to be
//   changed ("edit and resend"); a write whose cause can be put right elsewhere (a shift to start, stock to arrive) goes again as a NEW
//   write, with a new Idempotency-Key and new client_uuids; anything else (a closed order, a photo no longer on the tablet) is discarded;
// - a new send of an order from its cart replaces that order's refused writes;
// - an order write that was tried before without an answer and is then refused because the order moved on is looked up: when the order
//   already shows what the write wanted, it had landed and is settled, not shown as a conflict.

export type RefusalReason = "shortfall" | "stock" | "shift" | "closed" | "photo" | "dependency" | "session" | "other";
export type TrayAction = "edit" | "resend" | "discard";

export interface Bilingual {
  en: string | null;
  am: string | null;
}

export interface TrayEntry {
  /** the write that was refused */
  item: OutboxItem;
  /** the writes that waited for it and were never sent (they go, or leave, with it) */
  held: OutboxItem[];
  reason: RefusalReason;
  status: number;
  key: string;
  /** the server's message in both languages (or the tablet's own, status 0) */
  message: Bilingual;
  /** the lines a shortfall names, each in both languages */
  details: Bilingual[];
  /** the receipt photo a refused upload names */
  photo: string | null;
  actions: TrayAction[];
  at: number;
}

/** The pad's (and the till's cart's) own writes: refused, the order goes back to the cart to be changed and sent again. */
export const CART_KINDS: readonly OutboxKind[] = ["send_order", "replace_lines", "send_existing"];

const REASONS: Record<string, RefusalReason> = {
  "orders.send.shortfall": "shortfall",
  "inventory.stock.insufficient": "stock",
  "inventory.shift.required": "shift",
  "payments.shift.required": "shift",
  "orders.order.not_open": "closed",
  "orders.order.paid": "closed",
  "payments.order.not_payable": "closed",
  "state_machine.illegal_transition": "closed",
  "outbox.photo_missing": "photo",
  "outbox.dependency_answer_missing": "dependency",
  "identity.acting_session.expired": "session",
};

function text(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

/** Why a write was refused, from the API's key; a refusal that names a receipt photo is about that photo. */
export function reasonOf(conflict: Pick<Conflict, "status" | "body">): RefusalReason {
  const key = text(conflict.body.message_key) ?? "";
  if (REASONS[key]) return REASONS[key];
  if (text(conflict.body.photo_client_uuid)) return "photo";
  return "other";
}

/** What a refusal allows: sending again only what would not be refused again for the same reason. Discard is always there. */
export function actionsFor(kind: OutboxKind, reason: RefusalReason): TrayAction[] {
  if (reason === "closed" || reason === "dependency" || reason === "photo") return ["discard"];
  if (CART_KINDS.includes(kind)) return ["edit", "discard"];
  if (reason === "shortfall" || reason === "stock" || reason === "shift") return ["resend", "discard"];
  return ["discard"];
}

function isConflict(item: OutboxItem): boolean {
  return item.state === "conflict" && item.conflict !== null;
}

/** The tray: one entry per refused write, oldest first, each with the writes it held back. */
export function trayEntries(queue: OutboxItem[]): TrayEntry[] {
  const conflicts = queue.filter(isConflict);
  const present = new Set(conflicts.map((i) => i.id));
  const heldBy = new Map<string, OutboxItem[]>();
  for (const i of conflicts) {
    const by = i.conflict?.blockedBy ?? null;
    if (by && present.has(by)) heldBy.set(by, [...(heldBy.get(by) ?? []), i]);
  }
  return conflicts
    .filter((i) => !(i.conflict?.blockedBy && present.has(i.conflict.blockedBy)))
    .map((i) => {
      const conflict = i.conflict!;
      const reason = reasonOf(conflict);
      const lines = Array.isArray(conflict.body.shortfall) ? (conflict.body.shortfall as Record<string, unknown>[]) : [];
      return {
        item: i,
        held: heldBy.get(i.id) ?? [],
        reason,
        status: conflict.status,
        key: text(conflict.body.message_key) ?? "",
        message: { en: text(conflict.body.message_en), am: text(conflict.body.message_am) },
        details: lines.map((line) => ({ en: text(line?.message_en), am: text(line?.message_am) })),
        photo: text(conflict.body.photo_client_uuid) ?? (i.kind === "upload_photos" ? (photosOf(i)[0] ?? null) : null),
        actions: actionsFor(i.kind, reason),
        at: conflict.at,
      };
    });
}

/** The ids of the writes `id` held back (however far down). */
function heldIds(queue: OutboxItem[], id: string): Set<string> {
  return new Set(queue.filter((i) => isConflict(i) && i.conflict?.blockedBy === id).map((i) => i.id));
}

/** Every `client_uuid` in a body made new, the same old value always to the same new one. */
function renew(value: unknown, fresh: () => string, made: Map<string, string>): unknown {
  if (Array.isArray(value)) return value.map((v) => renew(v, fresh, made));
  if (value === null || typeof value !== "object") return value;
  return Object.fromEntries(
    Object.entries(value as Record<string, unknown>).map(([k, v]) => {
      if (k === "client_uuid" && typeof v === "string") {
        if (!made.has(v)) made.set(v, fresh());
        return [k, made.get(v)];
      }
      return [k, renew(v, fresh, made)];
    }),
  );
}

/**
 * Send again: the refused write goes as a NEW write (a new id and Idempotency-Key, every client_uuid in its body new, so the API never
 * answers it with what it stored for the refused one), in the same place in the queue; the writes it held back wait for it again.
 */
export function resendEntry(queue: OutboxItem[], id: string, fresh: () => string): OutboxItem[] {
  const refused = queue.find((i) => i.id === id);
  if (!refused || !isConflict(refused)) return queue;
  const held = heldIds(queue, id);
  const newId = fresh();
  const made = new Map<string, string>();
  const body = renew(refused.body, fresh, made);
  return queue.map((i) => {
    if (i.id === id) {
      return { ...i, id: newId, idempotencyKey: fresh(), body, dependsOn: null, attempts: 0, nextAttemptAt: 0, state: "pending" as const, conflict: null };
    }
    if (held.has(i.id)) return { ...i, dependsOn: i.dependsOn === id ? newId : i.dependsOn, attempts: 0, nextAttemptAt: 0, state: "pending" as const, conflict: null };
    return i;
  });
}

/** Discard: the refused write and the writes it held back leave the queue; the photos they named are given back to be let go. */
export function discardEntry(queue: OutboxItem[], id: string): { queue: OutboxItem[]; photos: string[] } {
  const refused = queue.find((i) => i.id === id);
  if (!refused || !isConflict(refused)) return { queue, photos: [] };
  const gone = heldIds(queue, id).add(id);
  const leaving = queue.filter((i) => gone.has(i.id));
  return { queue: queue.filter((i) => !gone.has(i.id)), photos: leaving.flatMap(photosOf) };
}

/**
 * The order was sent again from its cart: its refused cart writes (by its client_uuid), the refused writes the cart was made from
 * (`ids`, a cart that became a new order), and what they held back, leave the tray. Other writes of the order (a kitchen's) stay.
 */
export function supersede(queue: OutboxItem[], orderUuid: string | null, ids: readonly string[] = []): OutboxItem[] {
  const gone = new Set<string>();
  for (const i of queue) {
    if (!isConflict(i)) continue;
    if (ids.includes(i.id) || (orderUuid !== null && i.subjectUuid === orderUuid && CART_KINDS.includes(i.kind))) gone.add(i.id);
  }
  for (const id of [...gone]) for (const held of heldIds(queue, id)) gone.add(held);
  return queue.filter((i) => !gone.has(i.id));
}

const LOOKED_UP: readonly OutboxKind[] = ["send_existing", "cancel_order"];
const SENT: readonly string[] = ["sent", "in_progress", "ready", "served", "closed"];

/** Whether a refusal may only mean the write had already landed: tried before without an answer, about an order that moved on. */
export function needsLookup(item: OutboxItem, reason: RefusalReason): boolean {
  return reason === "closed" && item.attempts > 0 && item.subjectId !== null && LOOKED_UP.includes(item.kind);
}

/** Whether the order, as the server now has it, already shows what the write wanted. */
export function landed(item: Pick<OutboxItem, "kind">, order: { status: string } | null): boolean {
  if (!order) return false;
  if (item.kind === "send_existing") return SENT.includes(order.status);
  if (item.kind === "cancel_order") return order.status === "cancelled";
  return false;
}

interface HeldOrder {
  id: number;
  client_uuid: string;
  type: OrderType;
  dining_table: { id: number } | null;
  notes: string | null;
  lines: { client_uuid: string; item: { id: number }; qty: string; modifiers: { id: number }[]; notes: string | null }[];
}

function isHeldOrder(value: unknown): value is HeldOrder {
  if (value === null || typeof value !== "object") return false;
  const order = value as Record<string, unknown>;
  return typeof order.id === "number" && typeof order.client_uuid === "string" && Array.isArray(order.lines);
}

/** The cart of an order the server holds open (after a shortfall, or read again): the same order, its lines with their client_uuids. */
export function sourceOfOrder(order: HeldOrder): CartSource {
  return {
    orderUuid: order.client_uuid,
    serverOrderId: order.id,
    type: order.type,
    tableId: order.dining_table?.id ?? null,
    notes: order.notes,
    lines: order.lines.map((line) => ({
      client_uuid: line.client_uuid,
      item_id: line.item.id,
      qty: trimDecimal(line.qty),
      ...(line.modifiers.length > 0 ? { modifier_ids: line.modifiers.map((m) => m.id) } : {}),
      ...(line.notes ? { notes: line.notes } : {}),
    })),
  };
}

/**
 * The cart a refused send of the pad is changed in: the open order the refusal carries (a shortfall: the same order, PUT lines and send
 * next), else for a refused new order a NEW order with new client_uuids (nothing of it is on the server); null when the order must be read
 * from the server first (a refused change of an order already there).
 */
export function cartSourceOf(item: OutboxItem, fresh: () => string): CartSource | null {
  const held = item.conflict?.body.order;
  if (isHeldOrder(held)) return sourceOfOrder(held);
  if (item.kind !== "send_order" || item.body === null || typeof item.body !== "object") return null;
  const body = item.body as { type?: OrderType; dining_table_id?: number | null; notes?: string | null; lines?: NewOrderLine[] };
  return {
    orderUuid: fresh(),
    serverOrderId: null,
    type: body.type ?? "dine_in",
    tableId: body.dining_table_id ?? null,
    notes: body.notes ?? null,
    lines: (body.lines ?? []).map((line) => ({ ...line, client_uuid: fresh() })),
  };
}
