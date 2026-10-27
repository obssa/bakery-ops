import type { PaymentListRow, PaymentsPage, Reconciliation } from "../api/types";
import { LiveFeed, type FeedSpec } from "./feed";
import { feedSource } from "./tickets";
import { pollTransport, type PollOptions } from "./transport";

// The admin payments screens' two feeds (ADR 0009, phase-4 plan section 4.3, step 4.5c), each a conditional GET behind the API's ETag, so an
// idle screen is one 304 per look and keeps what it shows:
//   - the list: GET /payments with the screen's filters, the first page (the newest 50), every 10 s while the tab is visible and every 30 s
//     while it is hidden. Its ETag is Payments::fingerprint() (count, max id, max updated_at and a `settling` second while the newest row is
//     younger than the 5 s overlap window) with the query, the viewer's scope and a bucket of half the signed URLs' lifetime, so a list that
//     keeps answering 304 still gets fresh photo URLs before the old ones expire. The older pages are read once by cursor, not polled.
//   - the reconciliation: GET /payments/reconciliation for a range of local days, every 30 s visible and 60 s hidden (it changes when a
//     payment is taken, verified, disputed or voided, and with refunds from step 4.3d).
// There is no `since`: each changed answer replaces the state. A feed is made per query; new filters make a new feed.

export const PAYMENTS_POLL: PollOptions = { intervalMs: 10_000, hiddenMs: 30_000, maxBackoffMs: 30_000 };
export const RECONCILIATION_POLL: PollOptions = { intervalMs: 30_000, hiddenMs: 60_000, maxBackoffMs: 60_000 };

export interface PaymentsState {
  rows: PaymentListRow[];
  /** the cursor of the page after the first one; null when the first page is all there is */
  nextCursor: string | null;
  serverTime: string | null;
}

export const paymentsSpec: FeedSpec<PaymentsPage, PaymentsState> = {
  initial: { rows: [], nextCursor: null, serverTime: null },
  cursorOf: () => null,
  merge: (_state, page) => ({ rows: page.data, nextCursor: page.next_cursor, serverTime: page.server_time }),
};

export function createPaymentsFeed(query: Record<string, string>): LiveFeed<PaymentsPage, PaymentsState> {
  return new LiveFeed(feedSource<PaymentsPage>("/payments", { query, cursor: false }), paymentsSpec, pollTransport(PAYMENTS_POLL));
}

export interface ReconciliationState {
  report: Reconciliation | null;
}

export const reconciliationSpec: FeedSpec<Reconciliation, ReconciliationState> = {
  initial: { report: null },
  cursorOf: () => null,
  merge: (_state, page) => ({ report: page }),
};

export function createReconciliationFeed(query: Record<string, string>): LiveFeed<Reconciliation, ReconciliationState> {
  return new LiveFeed(feedSource<Reconciliation>("/payments/reconciliation", { query, cursor: false }), reconciliationSpec, pollTransport(RECONCILIATION_POLL));
}
