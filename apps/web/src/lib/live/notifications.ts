import type { AppNotification, NotificationsPage } from "../api/types";
import { LiveFeed, type FeedSpec } from "./feed";
import { feedSource } from "./tickets";
import { pollTransport, type PollOptions } from "./transport";

// The bell's inbox as a feed: GET /notifications?since= (ADR 0009, Phase 2 plan section 4). The first load is the newest
// lines, every later look is the lines after the cursor, answered 304 behind the ETag when nothing changed. There is no
// socket for the inbox: a person's bell is polled, every 15 s while the tab is visible and every 60 s while it is hidden.

export const BELL_POLL: PollOptions = { intervalMs: 15_000, hiddenMs: 60_000, maxBackoffMs: 60_000 };

/** How many lines the first load asks for (the API allows 100). */
export const INBOX_LIMIT = 30;

export interface InboxState {
  lines: Record<number, AppNotification>;
  unread: number;
}

/**
 * Folds a page into the inbox: a line by id, and a line once read stays read (the API sends no version, and an answer
 * that was already on its way when the person read the line must not bring the dot back). The unread count is the API's.
 */
export function mergeInbox(state: InboxState, page: NotificationsPage): InboxState {
  const lines = { ...state.lines };
  for (const line of page.data) {
    const known = lines[line.id];
    lines[line.id] = known?.read_at && !line.read_at ? { ...line, read_at: known.read_at } : line;
  }
  return { lines, unread: page.unread_count };
}

/** The lines to list, newest first. */
export function inboxOf(state: InboxState): AppNotification[] {
  return Object.values(state.lines).sort((a, b) => b.id - a.id);
}

/** The state after the person read these lines here: the lines marked, the count lowered by the ones that were unread. */
export function markedRead(state: InboxState, ids: readonly number[], at: string): InboxState {
  const lines = { ...state.lines };
  let lowered = 0;
  for (const id of ids) {
    const line = lines[id];
    if (line && !line.read_at) {
      lines[id] = { ...line, read_at: at };
      lowered += 1;
    }
  }
  return { lines, unread: Math.max(0, state.unread - lowered) };
}

/** The state after "Mark all as read": every line this screen holds is read and the count is 0 (the API cleared the rest too). */
export function markedAllRead(state: InboxState, at: string): InboxState {
  const lines: Record<number, AppNotification> = {};
  for (const [id, line] of Object.entries(state.lines)) lines[Number(id)] = line.read_at ? line : { ...line, read_at: at };
  return { lines, unread: 0 };
}

export const inboxSpec: FeedSpec<NotificationsPage, InboxState> = {
  initial: { lines: {}, unread: 0 },
  cursorOf: (page) => page.cursor || null,
  merge: mergeInbox,
};

export function createInboxFeed(): LiveFeed<NotificationsPage, InboxState> {
  return new LiveFeed(feedSource<NotificationsPage>("/notifications", { query: { limit: INBOX_LIMIT } }), inboxSpec, pollTransport(BELL_POLL));
}
