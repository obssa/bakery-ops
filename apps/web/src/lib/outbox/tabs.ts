import type { OutboxItem } from "./logic";

// The tabs of one tablet share one outbox (ADR 0029): the pad and the till open side by side, a second window after a crash. Each tab keeps
// the queue in memory and writes only what it changed to IndexedDB (db.ts apply, diffQueue); the others hear of it on a BroadcastChannel
// and fold it in (db.ts mergeQueue). Only one tab sends at a time: the send pass runs under a Web Lock, so two tabs never put the same
// write on the wire at once, and the tab that sent another tab's write tells that tab the answer (a screen there may be waiting for it).
// A browser without these (both are in every browser the shop uses) keeps each tab on its own, as before; the per-record writes still mean
// no tab erases another's queue.

export const FLUSH_LOCK = "bakery-ops.outbox.flush";
export const OUTBOX_CHANNEL = "bakery-ops.outbox";

export type TabMessage =
  /** this tab wrote to the store: read it again */
  | { type: "changed" }
  /** this tab sent a write the API accepted (it may be another tab's: a screen there may wait for the answer) */
  | { type: "applied"; item: OutboxItem; outcome: "accepted"; answer: unknown }
  /** this tab sent a write the API refused */
  | { type: "applied"; item: OutboxItem; outcome: "refused"; status: number; body: Record<string, unknown> };

/** Runs one send pass under the tablet-wide lock (the next tab waits its turn); without Web Locks, at once. */
export function withFlushLock<T>(run: () => Promise<T>): Promise<T> {
  const locks = typeof navigator === "undefined" ? undefined : (navigator as Navigator & { locks?: LockManager }).locks;
  if (!locks || typeof locks.request !== "function") return run();
  return locks.request(FLUSH_LOCK, () => run()) as Promise<T>;
}

export interface TabChannel {
  post(message: TabMessage): void;
  close(): void;
}

/** The channel to the other tabs, or null in a browser without BroadcastChannel. A message that cannot be posted is let go. */
export function openTabChannel(onMessage: (message: TabMessage) => void): TabChannel | null {
  if (typeof BroadcastChannel === "undefined") return null;
  try {
    const channel = new BroadcastChannel(OUTBOX_CHANNEL);
    channel.onmessage = (event: MessageEvent<TabMessage>) => onMessage(event.data);
    return {
      post: (message) => {
        try {
          channel.postMessage(message);
        } catch {
          // a body that cannot be cloned: the other tabs read the store on the next change
        }
      },
      close: () => channel.close(),
    };
  } catch {
    return null;
  }
}
