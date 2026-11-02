"use client";

// Reconnect reconcile (step 5.2c; plan 4.2 and ADR 0018). A screen that may have missed changes while the tablet was offline, or while the
// page was hidden (a locked tablet, another app in front: the browser slows or stops its timers), looks again: on the browser's `online`
// event every time, and when the page becomes visible after at least RECONNECT_GAP_MS hidden. The pad and the till refetch their lists
// from the start, the outbox sends what waits; the kitchen and bar rails replay tickets?since=<cursor> through their poll transport.

export const RECONNECT_GAP_MS = 30_000;

/** Whether a page hidden since `since` (ms; null: it was not hidden) has been away long enough to look again from the start. */
export function afterGap(since: number | null, now: number, gapMs: number = RECONNECT_GAP_MS): boolean {
  return since !== null && now - since >= gapMs;
}

export type ReconnectCause = "online" | "visible";
type Listener = (cause: ReconnectCause) => void;

const listeners = new Set<Listener>();
let hiddenSince: number | null = null;
let watching = false;

function tell(cause: ReconnectCause): void {
  for (const listener of [...listeners]) listener(cause);
}

function watch(): void {
  if (watching || typeof window === "undefined") return;
  watching = true;
  if (document.visibilityState === "hidden") hiddenSince = Date.now();
  window.addEventListener("online", () => tell("online"));
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") {
      hiddenSince = Date.now();
      return;
    }
    const away = afterGap(hiddenSince, Date.now());
    hiddenSince = null;
    if (away) tell("visible");
  });
}

/** Calls `listener` on each reconnect (the online event, or visible again after a gap); returns the function that stops it. */
export function onReconnect(listener: Listener): () => void {
  watch();
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}
