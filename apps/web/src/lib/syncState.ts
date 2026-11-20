import { pendingCounts, type OutboxItem, type ScreenKey } from "./outbox/logic";
import { trayEntries } from "./outbox/tray";

// What the shell says about this tablet's writes (the sync chip and the band under the top bar, components/PendingIndicator), from the
// outbox's queue. Pure, so the rules are tested without a browser (e2e/p6/sync-state-logic.spec.ts).
//
// 2026-11-20 (the implicit state machines review, item 24): the chip counted every account's writes as "waiting", though only the
// account that queued a write sends it (lib/outbox: `userId`), so a cashier saw a waiter's queued orders as hers; and it counted
// every refused write, though the tray shows a write held back by another refused one inside that one's entry, so the chip said
// "3 to check" over a tray of 2. Now:
//   - here / elsewhere: the signed-in person's own writes waiting, on this screen and on the others of the tablet;
//   - others: the writes of other accounts, which wait until their account signs in here again (said apart, never as "waiting" to send);
//   - conflicts: the tray's entries, exactly the number of refused writes the tray lists.

export interface SyncCounts {
  here: number;
  elsewhere: number;
  others: number;
  conflicts: number;
}

/**
 * The counts for the shell. `sendingId`: a write on the wire for the first time is not waiting yet (its screen shows it busy); after a
 * failure it is. `userId` null: nobody is signed in, every write counts as waiting (nothing is sent until someone signs in). A write with
 * no account (userId 0, queued before the session was known) is the signed-in person's.
 */
export function syncCounts(queue: readonly OutboxItem[], sendingId: string | null, userId: number | null, screen: ScreenKey): SyncCounts {
  const waiting = queue.filter((item) => item.state !== "conflict" && (item.id !== sendingId || item.attempts > 0));
  const mine = (item: OutboxItem) => userId === null || item.userId === userId || item.userId === 0;
  const { here, elsewhere } = pendingCounts(waiting.filter(mine), screen);
  const others = waiting.filter((item) => !mine(item)).length;
  return { here, elsewhere, others, conflicts: trayEntries([...queue]).length };
}
