import { actingHeaders } from "../acting";
import type { Line, Menu, Order, OrderStatus, OrdersPage } from "../api/types";
import { LiveFeed, type FeedSpec } from "./feed";
import { feedSource } from "./tickets";
import { configuredTransport, createTransport, pollTransport } from "./transport";

// The two feeds of the waiter pad (ADR 0009): the acting waiter's own orders (GET /orders?mine=1&since=, ETag, merged
// by id and version, pushed on `user.{id}` when a WebSocket transport is configured) and the menu with its sold-out
// products (GET /menu?include_unavailable=1, one ETag-gated body, refreshed on `branch.{id}.stock`).

export type OrdersState = Record<number, Order>;

/** Folds a page of orders into the state: an order at its highest version, and its lines at theirs. */
export function mergeOrders(state: OrdersState, page: OrdersPage): OrdersState {
  const next: OrdersState = { ...state };
  for (const order of page.data) {
    const known = next[order.id];
    if (!known || order.version >= known.version) next[order.id] = order;
  }
  return next;
}

export const ordersSpec: FeedSpec<OrdersPage, OrdersState> = {
  initial: {},
  cursorOf: (page) => page.cursor,
  merge: mergeOrders,
};

/** Finished orders leave the pad: they are paid and closed, cancelled or voided. */
const FINISHED: readonly OrderStatus[] = ["closed", "cancelled", "voided"];

/** The waiter's orders still to watch, newest first. */
export function openOrdersOf(state: OrdersState): Order[] {
  return Object.values(state)
    .filter((order) => !FINISHED.includes(order.status))
    .sort((a, b) => b.id - a.id);
}

/** Ids of lines that are ready and waiting to be served, for the badge and for noticing a line turning ready. */
export function readyLineIds(state: OrdersState): Set<number> {
  const ids = new Set<number>();
  for (const order of openOrdersOf(state)) for (const line of order.lines) if (line.status === "ready") ids.add(line.id);
  return ids;
}

export function isOpenForPad(order: Order): boolean {
  return !FINISHED.includes(order.status);
}

/** Lines the waiter still follows: everything but the lines voided by an edit. */
export function visibleLines(order: Order): Line[] {
  return order.lines.filter((line) => line.status !== "voided");
}

export function createOrdersFeed(userId: number): LiveFeed<OrdersPage, OrdersState> {
  return new LiveFeed(feedSource<OrdersPage>("/orders", { query: { mine: 1 }, headers: actingHeaders }), ordersSpec, createTransport([`user.${userId}`]));
}

export const menuSpec: FeedSpec<Menu, Menu | null> = {
  initial: null,
  cursorOf: () => null,
  merge: (_state, page) => page,
};

/** Menu looks are cheap (a 304 from aggregate queries) but stock moves slower than tickets: 5 s visible, 20 s hidden. */
const MENU_POLL = { intervalMs: 5000, hiddenMs: 20_000, maxBackoffMs: 20_000 };

export function createMenuFeed(branchId: number): LiveFeed<Menu, Menu | null> {
  const transport = configuredTransport() === "poll" ? pollTransport(MENU_POLL) : createTransport([`branch.${branchId}.stock`]);
  return new LiveFeed(feedSource<Menu>("/menu", { query: { include_unavailable: 1 }, cursor: false }), menuSpec, transport);
}
