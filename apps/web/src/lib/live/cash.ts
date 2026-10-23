import type { CashSummary } from "../api/types";
import { LiveFeed, type FeedSpec } from "./feed";
import { feedSource } from "./tickets";
import { pollTransport, type PollOptions } from "./transport";

// The till's cash so far as a feed: GET /shifts/{shift}/cash (ADR 0009, phase-3 plan section 4, step 3.7d). The answer is the whole summary
// behind an ETag made from its figures, so an idle till is one conditional GET and a 304. Polled every 5 s while the tab is visible and every
// 30 s while it is hidden; there is no cursor, the answer replaces the state.

export const CASH_POLL: PollOptions = { intervalMs: 5000, hiddenMs: 30_000, maxBackoffMs: 30_000 };

export interface CashState {
  summary: CashSummary | null;
}

export const cashSpec: FeedSpec<CashSummary, CashState> = {
  initial: { summary: null },
  cursorOf: () => null,
  merge: (_state, page) => ({ summary: page }),
};

export function createCashFeed(shiftId: number): LiveFeed<CashSummary, CashState> {
  return new LiveFeed(feedSource<CashSummary>(`/shifts/${shiftId}/cash`, { cursor: false }), cashSpec, pollTransport(CASH_POLL));
}
