import type { Me, ProductionBoardPage, ProductionRequest } from "../api/types";
import { LiveFeed, type FeedSpec } from "./feed";
import { feedSource } from "./tickets";
import { pollTransport, type PollOptions } from "./transport";

// The production board as a feed: GET /production/requests?view=board (ADR 0009, Phase 2 plan section 4). The answer is
// the whole board (the open requests and the ones done today) behind an ETag, so a look is one conditional GET and an
// idle board is a 304; there is no cursor. Polled every 5 s while the tab is visible and every 30 s while it is hidden.

export const BOARD_POLL: PollOptions = { intervalMs: 5000, hiddenMs: 30_000, maxBackoffMs: 30_000 };

/** The four columns of the board (architecture section 6). Accepted cards sit in Requested with their baker shown. */
export const COLUMNS = ["requested", "in_progress", "pending_confirmation", "done_today"] as const;
export type Column = (typeof COLUMNS)[number];

export function columnOf(request: ProductionRequest): Column | null {
  switch (request.status) {
    case "requested":
    case "accepted":
      return "requested";
    case "in_progress":
      return "in_progress";
    case "pending_confirmation":
      return "pending_confirmation";
    case "done":
      return "done_today";
    default:
      return null;
  }
}

export interface BoardState {
  requests: ProductionRequest[];
}

export const boardSpec: FeedSpec<ProductionBoardPage, BoardState> = {
  initial: { requests: [] },
  cursorOf: () => null,
  // the answer is the whole board: whatever it does not list is no longer on it
  merge: (_state, page) => ({ requests: page.data }),
};

export function createProductionFeed(): LiveFeed<ProductionBoardPage, BoardState> {
  return new LiveFeed(feedSource<ProductionBoardPage>("/production/requests", { query: { view: "board", limit: 200 }, cursor: false }), boardSpec, pollTransport(BOARD_POLL));
}

/** The board's columns for a station filter, each in the order the API listed (the order a baker works them). */
export function boardColumns(state: BoardState, stationId: number | "all"): Record<Column, ProductionRequest[]> {
  const columns: Record<Column, ProductionRequest[]> = { requested: [], in_progress: [], pending_confirmation: [], done_today: [] };
  for (const request of state.requests) {
    if (stationId !== "all" && request.station.id !== stationId) continue;
    const column = columnOf(request);
    if (column) columns[column].push(request);
  }
  return columns;
}

/** The stations that make things which this person works at, or null when they work at none (then everything shows). */
export function ownStationId(me: Me | null): number | null {
  const own = (me?.user.stations ?? []).filter((station) => station.code === "cake_room" || station.code === "bakery");
  return own.length === 1 ? own[0].station_id : null;
}

/** May this person sign off (or send back) a batch made at the station? production.confirm, or the overseer flagged there. */
export function canSignOff(me: Me | null, stationId: number): boolean {
  if (!me) return false;
  if (me.permissions.includes("production.confirm")) return true;
  return me.user.stations.some((station) => station.station_id === stationId && station.is_overseer);
}

/** May this person cancel the request? A planner, or the person who asked. */
export function canCancel(me: Me | null, request: ProductionRequest): boolean {
  if (!me) return false;
  return me.permissions.includes("production.plan") || request.requested_by?.id === me.user.id;
}

/**
 * The one action a board card offers this person (fresh-counter.md 3.5, step 6.3c), the next move of the request card's own buttons:
 * accept an open request (a baker, when nobody or they have it), start the batch they accepted, finish the batch they started (the request
 * card opens on its Done form: produced and waste are counted there), or confirm a finished batch they may sign off and did not make
 * themselves (the API refuses the one who made it). None otherwise: the card is still opened by its name.
 */
export type CardMove = "accept" | "start" | "finish" | "confirm";

export function cardMove(me: Me | null, request: ProductionRequest): CardMove | null {
  if (!me) return null;
  const fulfils = me.permissions.includes("production.fulfil");
  const mine = request.assigned_to?.id === me.user.id;
  switch (request.status) {
    case "requested":
      return fulfils && (request.assigned_to === null || mine) ? "accept" : null;
    case "accepted":
      return fulfils && mine ? "start" : null;
    case "in_progress":
      return fulfils && mine && request.batch?.status === "started" ? "finish" : null;
    case "pending_confirmation":
      return request.batch !== null && request.batch.finished_by?.id !== me.user.id && canSignOff(me, request.station.id) ? "confirm" : null;
    default:
      return null;
  }
}

/** The quantity a card shows large: what was made once the batch is counted, otherwise what was asked for (in the product's unit). */
export function cardQty(request: ProductionRequest): string {
  const made = request.batch?.produced_qty;
  return (request.status === "pending_confirmation" || request.status === "done") && made != null ? made : request.requested_qty;
}
