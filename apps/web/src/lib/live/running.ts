import type { ShiftPage, ShiftSummary } from "../api/types";
import { LiveFeed, type FeedSpec } from "./feed";
import { feedSource } from "./tickets";
import { pollTransport, type PollOptions } from "./transport";

// The shifts running now as a feed: GET /shifts?running=1 (opening, open, counting) behind an ETag over the count, the newest change and
// the versions (phase-3 plan section 4). Polled every 5 s while the tab is visible and every 30 s while it is hidden, like the approval
// queue; there is no cursor, the answer replaces the state.

export const RUNNING_POLL: PollOptions = { intervalMs: 5000, hiddenMs: 30_000, maxBackoffMs: 30_000 };

export interface RunningState {
  shifts: ShiftSummary[];
}

export const runningSpec: FeedSpec<ShiftPage, RunningState> = {
  initial: { shifts: [] },
  cursorOf: () => null,
  merge: (_state, page) => ({ shifts: page.data }),
};

export function createRunningFeed(): LiveFeed<ShiftPage, RunningState> {
  return new LiveFeed(feedSource<ShiftPage>("/shifts", { cursor: false, query: { running: 1, per_page: 100 } }), runningSpec, pollTransport(RUNNING_POLL));
}
