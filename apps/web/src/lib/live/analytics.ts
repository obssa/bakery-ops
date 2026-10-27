import type { DayRange } from "../analytics";
import type { Freshness } from "../api/analytics";
import { LiveFeed, type FeedSpec } from "./feed";
import { feedSource } from "./tickets";
import { pollTransport, type PollOptions } from "./transport";

// The analytics screens' one feed (ADR 0009, phase-4 plan section 4.6, step 4.5e): GET /analytics/freshness for the days the screen reads,
// a conditional GET every 30 s while the tab is visible and every 2 minutes while it is hidden; a 304 keeps what is shown. Its ETag is the
// range, every built day's version and rebuilt_at, and `running` (4.4b), so an idle dashboard is one 304 every 30 s. The dashboards
// themselves are not polled: when a day's version moves, the screen invalidates the queries that read that day (lib/analytics.ts
// changedDays and queryTouches), and each of those answers anew (or 304 for a day it did not read). The feed keeps the answer before the
// last one, so the screen can tell what moved. A feed is made per range; a new range makes a new feed, whose first answer is the baseline.

export const FRESHNESS_POLL: PollOptions = { intervalMs: 30_000, hiddenMs: 120_000, maxBackoffMs: 120_000 };

export interface FreshnessState {
  current: Freshness | null;
  /** the answer before `current`, to see which days moved; null until the second changed answer */
  before: Freshness | null;
}

export const freshnessSpec: FeedSpec<Freshness, FreshnessState> = {
  initial: { current: null, before: null },
  cursorOf: () => null,
  merge: (state, page) => ({ current: page, before: state.current }),
};

export function createFreshnessFeed(range: DayRange): LiveFeed<Freshness, FreshnessState> {
  return new LiveFeed(feedSource<Freshness>("/analytics/freshness", { query: { from: range.from, to: range.to }, cursor: false }), freshnessSpec, pollTransport(FRESHNESS_POLL));
}
