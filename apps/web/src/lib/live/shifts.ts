import type { Shift, ShiftCurrentPage } from "../api/types";
import { LiveFeed, type FeedSpec } from "./feed";
import { feedSource } from "./tickets";
import { pollTransport, type PollOptions } from "./transport";

// The running shift of a station as a feed: GET /stations/{station}/shifts/current (ADR 0009, phase-3 plan section 4). The answer is
// the whole shift (or null) behind an ETag that moves with its version, so a look is one conditional GET and an idle bar is a 304.
// Polled every 5 s while the tab is visible and every 30 s while it is hidden; there is no cursor, the answer replaces the state.

export const SHIFT_POLL: PollOptions = { intervalMs: 5000, hiddenMs: 30_000, maxBackoffMs: 30_000 };

export interface ShiftState {
  shift: Shift | null;
  /** the server clock at the answer, for "open for 3 h" without trusting the device clock */
  serverTime: string | null;
}

export const shiftSpec: FeedSpec<ShiftCurrentPage, ShiftState> = {
  initial: { shift: null, serverTime: null },
  cursorOf: () => null,
  merge: (_state, page) => ({ shift: page.data, serverTime: page.server_time }),
};

export function createShiftFeed(stationId: number): LiveFeed<ShiftCurrentPage, ShiftState> {
  return new LiveFeed(feedSource<ShiftCurrentPage>(`/stations/${stationId}/shifts/current`, { cursor: false }), shiftSpec, pollTransport(SHIFT_POLL));
}
