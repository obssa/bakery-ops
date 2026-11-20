// A new version of the app, and a page left behind by one (2026-11-20, the implicit state machines review, item 30). The shell cache
// (scripts/build-sw.mjs) no longer takes over open tabs by itself (skipWaiting off): a new worker waits, the old one keeps serving the
// files of the version the tab started with, and the tab says a new version is ready (components/ServiceWorker.tsx). Its Reload hands
// over to the new worker and loads the page again, once nothing this tablet queued is still waiting (a reload must never lose a write, or
// the pay sheet a cashier is filling in).
//
// A tab can still miss a file of its own version (the worker was cleared, or none ran): an import() of a part of the page fails after a
// deploy removed it. Then the page reloads itself, once, if nothing is waiting and the connection is up; otherwise it asks for a reload.
// Pure, so the rules are tested without a browser (e2e/p6/sw-update-logic.spec.ts).

/** Whether an error is a part of the page (a JS chunk) that could not be fetched: a file of an older version that is gone. */
export function isChunkLoadError(reason: unknown): boolean {
  if (!reason) return false;
  const name = typeof reason === "object" && reason !== null && "name" in reason ? String((reason as { name: unknown }).name) : "";
  if (name === "ChunkLoadError") return true;
  const message = typeof reason === "string" ? reason : typeof reason === "object" && reason !== null && "message" in reason ? String((reason as { message: unknown }).message) : "";
  return /Loading (CSS )?chunk [\w-]+ failed|Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module/i.test(message);
}

/** How long after a reload of its own the page does not reload again (a chunk still missing must not loop). */
export const CHUNK_RELOAD_GAP_MS = 60_000;

/** What waits on this tablet: writes not sent yet, and whether the queue is saved on the tablet (else it lives in this page only). */
export interface Waiting {
  /** writes queued and not yet answered, refused ones included */
  queued: number;
  durable: boolean;
}

/**
 * A new version is ready: Reload is offered only when nothing waits (a write still to send, or a refused one to look at, stays with this
 * page's state until it is dealt with).
 */
export function mayLoadUpdate(waiting: Waiting): boolean {
  return waiting.queued === 0;
}

/**
 * A part of the page could not load: reload by itself when nothing waits, the connection is up, and it did not reload for this a moment
 * ago. Otherwise the page says so and offers Reload ({@link mayReloadStale}).
 */
export function mayAutoReload(args: { waiting: Waiting; online: boolean; lastReloadAt: number | null; now: number }): boolean {
  const { waiting, online, lastReloadAt, now } = args;
  if (!online || waiting.queued > 0) return false;
  return lastReloadAt === null || now - lastReloadAt >= CHUNK_RELOAD_GAP_MS;
}

/**
 * A part of the page could not load and the page asks for a reload: the person may reload while what waits is saved on the tablet (it is
 * sent after the reload); a queue kept in this page only would be lost, so then not before it is sent.
 */
export function mayReloadStale(waiting: Waiting): boolean {
  return waiting.queued === 0 || waiting.durable;
}
