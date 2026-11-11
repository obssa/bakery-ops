import type { ProductionRequest } from "./api/types";
import type { Column } from "./live/production";

// What the production board says about itself (phase-6/production-board, Ramin 2026-11-10: the board was too basic): the summary over its
// columns and the age of a batch in the oven. A batch started more than a day ago is stale: nothing bakes for a day, so it was left on the
// board unfinished, and the card asks for it to be finished and counted (a started batch is finished or sent back, never cancelled:
// production.request.in_progress). Plain functions, no React, so the logic spec runs them in Node.

/** A batch in the oven longer than this is stale (seconds): one day. */
export const STALE_AFTER = 86_400;

export interface BatchAge {
  /** whole seconds since it started, never negative */
  seconds: number;
  /** whole days since it started */
  days: number;
  stale: boolean;
}

/** How long a batch has been in the oven at `now` (ms), or null when it has no readable start. */
export function batchAge(startedAt: string | null | undefined, now: number): BatchAge | null {
  if (!startedAt) return null;
  const started = Date.parse(startedAt);
  if (Number.isNaN(started) || !Number.isFinite(now)) return null;
  const seconds = Math.max(0, Math.floor((now - started) / 1000));
  return { seconds, days: Math.floor(seconds / 86_400), stale: seconds >= STALE_AFTER };
}

/** The age of a request's batch while it is in the oven; null in every other state. */
export function ovenAge(request: ProductionRequest, now: number): BatchAge | null {
  if (request.status !== "in_progress" || !request.batch || request.batch.status !== "started") return null;
  return batchAge(request.batch.started_at, now);
}

export interface BoardSummary {
  counts: Record<Column, number>;
  /** urgent requests not started yet */
  urgent: number;
  /** batches in the oven for more than a day */
  stale: number;
}

/** The summary strip over the columns: each column's count, the urgent requests waiting and the stale batches. */
export function boardSummary(columns: Record<Column, ProductionRequest[]>, now: number): BoardSummary {
  return {
    counts: {
      requested: columns.requested.length,
      in_progress: columns.in_progress.length,
      pending_confirmation: columns.pending_confirmation.length,
      done_today: columns.done_today.length,
    },
    urgent: columns.requested.filter((request) => request.priority === "urgent").length,
    stale: columns.in_progress.filter((request) => ovenAge(request, now)?.stale === true).length,
  };
}
