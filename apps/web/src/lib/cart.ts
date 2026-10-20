"use client";

import { create } from "zustand";
import { uuid } from "./api/idempotency";
import type { MenuItem, NewOrder, NewOrderLine, OrderType, ShortfallLine } from "./api/types";
import { addDecimal, compareDecimal, multiplyDecimal, stepQuantity } from "./decimal";

// The waiter's cart on the pad. Pure functions over a plain object (so a test runner can pick them up) and a small
// store that keeps one cart PER WAITER in memory: locking the pad parks the cart, the same waiter's code brings it
// back, and nobody else ever sees it. Line and order client UUIDs are fixed when the line is added / the cart is made
// (rule 7), so a resend after a shortfall is the same order with the same lines.

export interface CartLine {
  uuid: string;
  /** the menu row as it was when the line was added: name, price and modifiers stay readable if the menu moves */
  item: MenuItem;
  /** a whole number as a decimal string ("2") */
  qty: string;
  modifierIds: number[];
  notes: string;
}

/** The API message of a refusal, in both languages (rule 9). */
export interface Refusal {
  key: string;
  message_en: string;
  message_am: string;
}

export interface Cart {
  orderUuid: string;
  type: OrderType;
  tableId: number | null;
  notes: string;
  lines: CartLine[];
  /** the order id once the API holds it open (after a refused send), so the next send is PUT lines + POST send */
  serverOrderId: number | null;
  /** a send is waiting in the outbox or on the wire */
  sending: boolean;
  /** what the last refused send said was short, per line (matched by line client UUID) */
  shortfall: ShortfallLine[];
  /** other refusals, per line client UUID */
  lineErrors: Record<string, Refusal>;
  /** a refusal that belongs to no line (table unknown, order no longer open ...) */
  error: Refusal | null;
}

export function newCart(): Cart {
  return { orderUuid: uuid(), type: "dine_in", tableId: null, notes: "", lines: [], serverOrderId: null, sending: false, shortfall: [], lineErrors: {}, error: null };
}

/** A line of this product with no modifiers and no note: tapping the tile again adds to it instead of a new row. */
function plainLineOf(cart: Cart, itemId: number): CartLine | undefined {
  return cart.lines.find((l) => l.item.item_id === itemId && l.modifierIds.length === 0 && l.notes === "");
}

function withoutIssues(cart: Cart, lineUuids: string[]): Pick<Cart, "shortfall" | "lineErrors"> {
  const lineErrors = { ...cart.lineErrors };
  for (const id of lineUuids) delete lineErrors[id];
  return { shortfall: cart.shortfall.filter((s) => !lineUuids.includes(s.line_client_uuid)), lineErrors };
}

export function addToCart(cart: Cart, item: MenuItem, extra: { qty?: string; modifierIds?: number[]; notes?: string } = {}): Cart {
  const modifierIds = extra.modifierIds ?? [];
  const notes = (extra.notes ?? "").trim();
  const qty = extra.qty ?? "1";
  const same = modifierIds.length === 0 && notes === "" ? plainLineOf(cart, item.item_id) : undefined;
  if (same) {
    const merged = addDecimal(same.qty, qty, 0);
    return { ...cart, ...withoutIssues(cart, [same.uuid]), lines: cart.lines.map((l) => (l === same ? { ...l, qty: merged } : l)) };
  }
  return { ...cart, lines: [...cart.lines, { uuid: uuid(), item, qty, modifierIds, notes }] };
}

/** One more or one fewer; going to zero removes the line. The line refusals no longer apply to a changed line. */
export function stepLine(cart: Cart, lineUuid: string, by: 1 | -1): Cart {
  return setQuantity(cart, lineUuid, stepQuantity(cart.lines.find((l) => l.uuid === lineUuid)?.qty ?? "0", by));
}

/** A typed quantity: digits only, zero removes the line, anything else is ignored. */
export function setQuantity(cart: Cart, lineUuid: string, qty: string): Cart {
  if (!/^\d{1,3}$/.test(qty)) return cart;
  const issues = withoutIssues(cart, [lineUuid]);
  if (compareDecimal(qty, "0", 0) <= 0) return { ...cart, ...issues, lines: cart.lines.filter((l) => l.uuid !== lineUuid) };
  return { ...cart, ...issues, lines: cart.lines.map((l) => (l.uuid === lineUuid ? { ...l, qty: qty.replace(/^0+(?=\d)/, "") } : l)) };
}

export function setLineNotes(cart: Cart, lineUuid: string, notes: string): Cart {
  return { ...cart, lines: cart.lines.map((l) => (l.uuid === lineUuid ? { ...l, notes } : l)) };
}

export function removeLine(cart: Cart, lineUuid: string): Cart {
  return { ...cart, ...withoutIssues(cart, [lineUuid]), lines: cart.lines.filter((l) => l.uuid !== lineUuid) };
}

/** Price of one unit of a line: the product price plus the chosen modifiers' deltas (decimal strings). */
export function unitPriceOf(line: CartLine): string {
  return line.item.modifiers.filter((m) => line.modifierIds.includes(m.id)).reduce((sum, m) => addDecimal(sum, m.price_delta), line.item.price);
}

export function lineTotalOf(line: CartLine): string {
  return multiplyDecimal(unitPriceOf(line), line.qty);
}

export function cartTotal(cart: Cart): string {
  return cart.lines.reduce((sum, line) => addDecimal(sum, lineTotalOf(line)), "0.00");
}

export function orderLinesOf(cart: Cart): NewOrderLine[] {
  return cart.lines.map((l) => ({
    client_uuid: l.uuid,
    item_id: l.item.item_id,
    qty: l.qty,
    ...(l.modifierIds.length > 0 ? { modifier_ids: l.modifierIds } : {}),
    ...(l.notes.trim() !== "" ? { notes: l.notes.trim() } : {}),
  }));
}

export function newOrderOf(cart: Cart): NewOrder {
  return {
    client_uuid: cart.orderUuid,
    type: cart.type,
    dining_table_id: cart.type === "dine_in" ? cart.tableId : null,
    notes: cart.notes.trim() === "" ? null : cart.notes.trim(),
    lines: orderLinesOf(cart),
  };
}

/** Why the cart cannot be sent yet, or null: it needs lines, and a dine-in order needs its table. */
export function blocker(cart: Cart): "empty" | "table" | null {
  if (cart.lines.length === 0) return "empty";
  if (cart.type === "dine_in" && cart.tableId === null) return "table";
  return null;
}

/** A line the menu no longer offers (sold out since it was added): shown, and the API decides on send. */
export function isSoldOut(line: CartLine, menu: MenuItem[] | undefined): boolean {
  const now = menu?.find((m) => m.item_id === line.item.item_id);
  return !!now && compareDecimal(now.available, "0") <= 0;
}

// --- the store: one cart per waiter, in memory only -----------------------------------------------------------

interface CartsState {
  carts: Record<number, Cart>;
  update: (userId: number, change: (cart: Cart) => Cart) => void;
  reset: (userId: number) => void;
  /** a cart whose order was sent is finished: the waiter starts a new one (the table choice is kept) */
  finish: (userId: number) => void;
}

export const useCarts = create<CartsState>()((set, get) => ({
  carts: {},
  update(userId, change) {
    const current = get().carts[userId] ?? newCart();
    set({ carts: { ...get().carts, [userId]: change(current) } });
  },
  reset(userId) {
    const carts = { ...get().carts };
    delete carts[userId];
    set({ carts });
  },
  finish(userId) {
    const previous = get().carts[userId];
    set({ carts: { ...get().carts, [userId]: { ...newCart(), type: previous?.type ?? "dine_in" } } });
  },
}));
