import type { SystemStatus } from "../system";
import { LiveFeed, type FeedSpec } from "./feed";
import { feedSource } from "./tickets";
import { pollTransport, type PollOptions } from "./transport";

// The System page's feed (ADR 0009, phase-5 plan 4.4, step 5.5c): GET /system/status as one conditional GET every 30 s while the page is
// visible and every 60 s while it is hidden. The API takes its ETag over the body, so an unchanged status is a 304 and the page keeps what
// it shows; the heartbeat moves it about once a minute. There is no cursor: each changed answer replaces the state.

export const SYSTEM_POLL: PollOptions = { intervalMs: 30_000, hiddenMs: 60_000, maxBackoffMs: 60_000 };

export interface SystemState {
  status: SystemStatus | null;
}

export const systemSpec: FeedSpec<SystemStatus, SystemState> = {
  initial: { status: null },
  cursorOf: () => null,
  merge: (_state, page) => ({ status: page }),
};

export function createSystemFeed(): LiveFeed<SystemStatus, SystemState> {
  return new LiveFeed(feedSource<SystemStatus>("/system/status", { cursor: false }), systemSpec, pollTransport(SYSTEM_POLL));
}
