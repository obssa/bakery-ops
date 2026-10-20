import type { Order, OrdersPage, OrderStatus } from "../api/types";
import { compareDecimal } from "../decimal";
import { LiveFeed } from "./feed";
import { ordersSpec, type OrdersState } from "./pad";
import { feedSource } from "./tickets";
import { createTransport } from "./transport";

// The two branch-wide order feeds of step 1.6c (ADR 0009), both over GET /orders for a person who sees every order of
// the branch (payments.take or stations.all): the till queue of orders awaiting payment (?unpaid=1 on the first load)
// and the admin board of every active order. A poll (?since=) answers every order with an event after the cursor,
// whatever its status, so an order that stopped matching (paid, closed, voided) arrives once and the client drops it;
// that is why both lists are derived from the merged state here and not trusted to the query. They reuse the pad's merge
// (rows by id, the higher version wins).

export type { OrdersState } from "./pad";

/** Orders that were sent to the stations and are not closed: the ones the till can still take money for or void. */
export const UNPAID_STATUSES: readonly OrderStatus[] = ["sent", "in_progress", "ready", "served"];

/** Every status the admin board lists: open orders are drafts a waiter has not sent yet. */
export const BOARD_STATUSES: readonly OrderStatus[] = ["open", "sent", "in_progress", "ready", "served", "paid"];

/** The till queue: sent, not closed, oldest first (by when it was sent, then id). */
export function queueOf(state: OrdersState): Order[] {
  return oldestFirst(Object.values(state).filter((order) => UNPAID_STATUSES.includes(order.status)));
}

/** Paid in full but not yet served: it waits in the queue, settled, and leaves when its last line is served and it closes. */
export function isSettled(order: Order): boolean {
  return order.settled_at !== null || compareDecimal(order.totals.balance, "0", 2) <= 0;
}

/** Can the till still take payment for it, or void it? Sent and not settled. */
export function isPayable(order: Order): boolean {
  return UNPAID_STATUSES.includes(order.status) && !isSettled(order);
}

function oldestFirst(orders: Order[]): Order[] {
  return orders.sort((a, b) => {
    const left = a.sent_at ?? a.created_at ?? "";
    const right = b.sent_at ?? b.created_at ?? "";
    return left < right ? -1 : left > right ? 1 : a.id - b.id;
  });
}

export interface BoardFilters {
  status: OrderStatus | "all";
  tableId: number | "all";
}

/** The board's columns: the active orders that pass the filters, oldest first inside each status. */
export function boardOf(state: OrdersState, filters: BoardFilters): Record<string, Order[]> {
  const columns: Record<string, Order[]> = {};
  for (const status of BOARD_STATUSES) columns[status] = [];
  for (const order of Object.values(state)) {
    if (!BOARD_STATUSES.includes(order.status)) continue;
    if (filters.status !== "all" && order.status !== filters.status) continue;
    if (filters.tableId !== "all" && order.dining_table?.id !== filters.tableId) continue;
    columns[order.status].push(order);
  }
  for (const status of BOARD_STATUSES) columns[status] = oldestFirst(columns[status]);
  return columns;
}

/** The till queue feed: the first load asks for ?unpaid=1, then the cursor; pushed on `branch.{id}.orders`. */
export function createQueueFeed(branchId: number): LiveFeed<OrdersPage, OrdersState> {
  return new LiveFeed(feedSource<OrdersPage>("/orders", { query: { unpaid: 1 } }), ordersSpec, createTransport([`branch.${branchId}.orders`]));
}

/** The admin board feed: every active order of the branch (the default of GET /orders), then the cursor. */
export function createBoardFeed(branchId: number): LiveFeed<OrdersPage, OrdersState> {
  return new LiveFeed(feedSource<OrdersPage>("/orders", { query: { limit: 200 } }), ordersSpec, createTransport([`branch.${branchId}.orders`]));
}
