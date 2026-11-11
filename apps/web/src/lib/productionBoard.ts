import type { Me, ProductionRequest } from "./api/types";
import { canSignOff, columnOf, type Column } from "./live/production";

// What the production board says about itself (phase-6/production-board, Ramin 2026-11-10: the board was too basic; phase-6/board-drag,
// Ramin 2026-11-11: one set of headers, drag a card to its next step, say who moves it, and flag a request that waits too long). Plain
// functions, no React, so the logic spec runs them in Node.
//
// - A batch started more than a day ago is stale: nothing bakes for a day, so it was left on the board unfinished, and the card asks for it
//   to be finished and counted (a started batch is finished or sent back, never cancelled: production.request.in_progress).
// - A request nobody has started (requested or accepted) waits too long after WAIT_TOO_LONG_MINUTES (URGENT_TOO_LONG_MINUTES when urgent),
//   and is late once its due time has passed. The API's `production:overdue` uses the same numbers (config/production.php) to tell the
//   planners once.
// - A card dragged to another column makes the move that column means (dropMove), the same write as the card's buttons, or says why it
//   cannot; a card the viewer cannot move says who moves it next (nextMover).

/** A batch in the oven longer than this is stale (seconds): one day. */
export const STALE_AFTER = 86_400;
/** A request nobody has started waits too long after this many minutes (config/production.php waiting_too_long_minutes). */
export const WAIT_TOO_LONG_MINUTES = 120;
/** The same for an urgent request (config/production.php urgent_minutes). */
export const URGENT_TOO_LONG_MINUTES = 30;

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

export interface RequestWait {
  /** whole seconds since it was asked for, never negative */
  seconds: number;
  /** past its limit (two hours, half an hour when urgent) */
  tooLong: boolean;
  /** whole seconds past its due time, or null while it is not due yet (or has no due time) */
  lateSeconds: number | null;
}

/** How long a request nobody has started has waited at `now` (ms); null once it is started (or when it has no readable age). */
export function requestWait(request: ProductionRequest, now: number): RequestWait | null {
  if (request.status !== "requested" && request.status !== "accepted") return null;
  const age = batchAge(request.created_at, now);
  if (!age) return null;
  const limit = (request.priority === "urgent" ? URGENT_TOO_LONG_MINUTES : WAIT_TOO_LONG_MINUTES) * 60;
  const due = request.due_at ? Date.parse(request.due_at) : Number.NaN;
  const lateSeconds = Number.isNaN(due) || now <= due ? null : Math.floor((now - due) / 1000);
  return { seconds: age.seconds, tooLong: age.seconds >= limit, lateSeconds };
}

/** Does the request need someone: waiting too long or late? */
export function waitsTooLong(request: ProductionRequest, now: number): boolean {
  const wait = requestWait(request, now);
  return !!wait && (wait.tooLong || wait.lateSeconds !== null);
}

export interface BoardSummary {
  counts: Record<Column, number>;
  /** urgent requests not started yet */
  urgent: number;
  /** requests not started that wait too long or are late */
  waiting: number;
  /** batches in the oven for more than a day */
  stale: number;
}

/** Each column's count and what needs a look: the urgent requests waiting, the ones waiting too long and the stale batches. */
export function boardSummary(columns: Record<Column, ProductionRequest[]>, now: number): BoardSummary {
  return {
    counts: {
      requested: columns.requested.length,
      in_progress: columns.in_progress.length,
      pending_confirmation: columns.pending_confirmation.length,
      done_today: columns.done_today.length,
    },
    urgent: columns.requested.filter((request) => request.priority === "urgent").length,
    waiting: columns.requested.filter((request) => waitsTooLong(request, now)).length,
    stale: columns.in_progress.filter((request) => ovenAge(request, now)?.stale === true).length,
  };
}

/** The write a drop makes: accept and start, start, finish (the Done form opens), confirm, or send back (the Send back form opens). */
export type DropMove = "accept_start" | "start" | "finish" | "confirm" | "send_back";

/** Why a card cannot go to that column, with the person it names (if any). */
export type DropRefusal =
  | { reason: "drop_no_skip" }
  | { reason: "drop_no_back" }
  | { reason: "drop_done" }
  | { reason: "drop_not_baker" }
  | { reason: "drop_given_to"; name: string }
  | { reason: "drop_not_yours_start"; name: string }
  | { reason: "drop_not_yours_finish"; name: string }
  | { reason: "drop_not_overseer" }
  | { reason: "drop_own_batch"; name: string };

export type DropVerdict = { move: DropMove } | { refused: DropRefusal } | null;

/**
 * What dropping the card on `target` does for this person: null when it is its own column (nothing happens), a move when the column is its
 * next step and the person may make it, otherwise why not. The server decides in the end; this mirrors its rules (AcceptRequest,
 * StartBatch, FinishBatch, ConfirmBatch, SendBackBatch) so a drop that would be refused is dimmed before it is made.
 */
export function dropMove(me: Me | null, request: ProductionRequest, target: Column): DropVerdict {
  const from = columnOf(request);
  if (!from || from === target) return null;
  if (from === "done_today") return { refused: { reason: "drop_done" } };
  const fulfils = !!me && me.permissions.includes("production.fulfil");
  const mine = !!me && request.assigned_to?.id === me.user.id;
  const baker = request.assigned_to?.name ?? "";

  if (from === "requested") {
    if (target !== "in_progress") return { refused: { reason: "drop_no_skip" } };
    if (!fulfils) return { refused: { reason: "drop_not_baker" } };
    if (request.status === "requested") return request.assigned_to === null || mine ? { move: "accept_start" } : { refused: { reason: "drop_given_to", name: baker } };
    return mine ? { move: "start" } : { refused: { reason: "drop_not_yours_start", name: baker } };
  }

  if (from === "in_progress") {
    if (target === "requested") return { refused: { reason: "drop_no_back" } };
    // a finished batch goes to Pending confirmation or, where no overseer is needed, straight to Done today: either column finishes it
    if (fulfils && mine && request.batch?.status === "started") return { move: "finish" };
    return { refused: { reason: "drop_not_yours_finish", name: baker } };
  }

  // pending confirmation: confirmed into Done today, or sent back to the oven
  if (target === "requested") return { refused: { reason: "drop_no_back" } };
  const maker = request.batch?.finished_by ?? null;
  if (!request.batch || !canSignOff(me, request.station.id)) return { refused: { reason: "drop_not_overseer" } };
  if (me && maker?.id === me.user.id) return { refused: { reason: "drop_own_batch", name: maker.name } };
  return { move: target === "done_today" ? "confirm" : "send_back" };
}

/** Who moves a card next, for a viewer who has no button on it: the words' key and the name they carry. */
export type NextMover =
  | { key: "next_accept_any" }
  | { key: "next_accept_named"; name: string }
  | { key: "next_start"; name: string }
  | { key: "next_finish"; name: string }
  | { key: "next_confirm_not"; name: string }
  | { key: "next_confirm_mine" };

export function nextMover(me: Me | null, request: ProductionRequest): NextMover | null {
  const baker = request.assigned_to?.name ?? null;
  switch (request.status) {
    case "requested":
      return baker ? { key: "next_accept_named", name: baker } : { key: "next_accept_any" };
    case "accepted":
      return baker ? { key: "next_start", name: baker } : null;
    case "in_progress":
      return baker ? { key: "next_finish", name: baker } : null;
    case "pending_confirmation": {
      const maker = request.batch?.finished_by ?? null;
      if (me && maker?.id === me.user.id) return { key: "next_confirm_mine" };
      return maker ? { key: "next_confirm_not", name: maker.name } : null;
    }
    default:
      return null;
  }
}
