import { conditionalGet } from "../api/client";
import type { Line, LineStatus, Ticket, TicketOrder, TicketsPage } from "../api/types";
import { handleUnauthorized, useSession } from "../session";
import { LiveFeed, type FeedSource, type FeedSpec } from "./feed";
import { createTransport } from "./transport";

// The kitchen and bar rail as a feed: GET /stations/{station}/tickets (ADR 0009). A page holds the tickets whose lines
// changed (or, on the first load, every active ticket) with ALL their lines at the station; the state keeps every
// order it has seen, merged by line id and version, and the rail is derived from it. Served, rejected and voided
// lines are kept in the state on purpose (a late, older copy of a line must never bring one back) but are not shown.

export interface RailEntry {
  order: TicketOrder;
  lines: Record<number, Line>;
}

export type RailState = Record<number, RailEntry>;

/** The lines a station still has to work on or hand over: new, accepted, or ready and waiting to be served. */
export const ACTIVE_LINE_STATUSES: readonly LineStatus[] = ["queued", "accepted", "ready"];

export function isActiveLine(line: Line): boolean {
  return ACTIVE_LINE_STATUSES.includes(line.status);
}

/** Folds a page of tickets into the rail state: the header of the newest answer, each line at its highest version. */
export function mergeTickets(state: RailState, page: TicketsPage): RailState {
  const next: RailState = { ...state };
  for (const ticket of page.data) {
    const known = next[ticket.order.id];
    const lines: Record<number, Line> = { ...(known?.lines ?? {}) };
    for (const line of ticket.lines) {
      const have = lines[line.id];
      if (!have || line.version >= have.version) lines[line.id] = line;
    }
    next[ticket.order.id] = { order: ticket.order, lines };
  }
  return next;
}

/** The tickets to show, oldest first (by when they were sent), each with only its active lines; empty ones are gone. */
export function railOf(state: RailState): Ticket[] {
  const tickets: Ticket[] = [];
  for (const entry of Object.values(state)) {
    const lines = Object.values(entry.lines)
      .filter(isActiveLine)
      .sort((a, b) => a.id - b.id);
    if (lines.length > 0) tickets.push({ order: entry.order, lines });
  }
  return tickets.sort((a, b) => {
    const left = a.order.sent_at ?? "";
    const right = b.order.sent_at ?? "";
    return left < right ? -1 : left > right ? 1 : a.order.id - b.order.id;
  });
}

/** The ids of lines on the rail, for noticing a new ticket (the chime). */
export function lineIdsOf(state: RailState): Set<number> {
  const ids = new Set<number>();
  for (const entry of Object.values(state)) for (const line of Object.values(entry.lines)) if (isActiveLine(line)) ids.add(line.id);
  return ids;
}

export const ticketsSpec: FeedSpec<TicketsPage, RailState> = {
  initial: {},
  cursorOf: (page) => page.cursor,
  merge: mergeTickets,
};

export interface FeedSourceOptions {
  /** query parameters every look carries (?mine=1, ?include_unavailable=1) */
  query?: Record<string, string | number>;
  /** extra headers per look, read at call time (the pad sends its acting session) */
  headers?: () => Record<string, string>;
  /** false for a feed that has no cursor (the menu answers whole, behind its ETag) */
  cursor?: boolean;
}

/** The source of a cursor feed over the signed-in session: bearer token, `since`, If-None-Match; a 401 ends the session
 * (or, for an ended acting session, locks the pad). */
export function feedSource<Page>(path: string, options: FeedSourceOptions = {}): FeedSource<Page> {
  return {
    async fetchPage({ cursor, etag, signal }) {
      const { token } = useSession.getState();
      try {
        return await conditionalGet<Page>(path, {
          query: { ...options.query, ...(cursor && options.cursor !== false ? { since: cursor } : {}) },
          token,
          etag,
          signal,
          headers: options.headers?.(),
        });
      } catch (error) {
        handleUnauthorized(error);
        throw error;
      }
    },
  };
}

export function createTicketsFeed(stationId: number): LiveFeed<TicketsPage, RailState> {
  return new LiveFeed(feedSource<TicketsPage>(`/stations/${stationId}/tickets`), ticketsSpec, createTransport([`station.${stationId}`]));
}
