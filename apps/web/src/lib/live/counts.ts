import type { QueuePage, QueueRow } from "../api/types";
import { LiveFeed, type FeedSpec } from "./feed";
import { feedSource } from "./tickets";
import { pollTransport, type PollOptions } from "./transport";

// The approval queue as a feed: GET /stock-counts (ADR 0009, phase-3 plan section 4). The answer is the whole queue behind an ETag that
// moves with the counts and with the ledger (a line's `moved_since` reads it), so an idle admin screen is one conditional GET and a 304.
// Polled every 5 s while the tab is visible and every 30 s while it is hidden; there is no cursor, the answer replaces the state.

export const QUEUE_POLL: PollOptions = { intervalMs: 5000, hiddenMs: 30_000, maxBackoffMs: 30_000 };

export interface QueueState {
  rows: QueueRow[];
  totals: { awaiting_cosign: number; pending_approval: number };
  serverTime: string | null;
}

export const queueSpec: FeedSpec<QueuePage, QueueState> = {
  initial: { rows: [], totals: { awaiting_cosign: 0, pending_approval: 0 }, serverTime: null },
  cursorOf: () => null,
  merge: (_state, page) => ({ rows: page.data, totals: page.counts, serverTime: page.server_time }),
};

export function createQueueFeed(): LiveFeed<QueuePage, QueueState> {
  return new LiveFeed(feedSource<QueuePage>("/stock-counts", { cursor: false }), queueSpec, pollTransport(QUEUE_POLL));
}
