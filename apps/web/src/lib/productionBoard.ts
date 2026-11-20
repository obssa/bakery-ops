import { may } from "./api/actions";
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
  | { reason: "drop_finish_first" }
  | { reason: "drop_no_overseer" }
  | { reason: "drop_not_overseer" }
  | { reason: "drop_own_batch"; name: string };

export type DropVerdict = { move: DropMove } | { refused: DropRefusal } | null;

/**
 * What dropping the card on `target` does for this person: null when it is its own column (nothing happens), a move when the column is its
 * next step and the request's `actions` list the move, otherwise why not. The API says which moves the person may make (ADR 0032, from
 * AcceptRequest, StartBatch, FinishBatch, ConfirmBatch and SendBackBatch themselves), so a drop that would be refused is dimmed before it is
 * made and no rule of the server is copied here.
 */
export function dropMove(me: Me | null, request: ProductionRequest, target: Column): DropVerdict {
  const from = columnOf(request);
  if (!from || from === target) return null;
  if (from === "done_today") return { refused: { reason: "drop_done" } };
  // whether the move is made is the request's `actions` (ADR 0032: the API's AcceptRequest, StartBatch, FinishBatch, ConfirmBatch and
  // SendBackBatch decide it for this person); the person and the batch only choose the words of a refusal
  const fulfils = !!me && me.permissions.includes("production.fulfil");
  const baker = request.assigned_to?.name ?? "";

  if (from === "requested") {
    if (target !== "in_progress") return { refused: { reason: "drop_no_skip" } };
    // a request nobody started is accepted and started by the one drop; an accepted one is started
    if (request.status === "requested" && may(request, "accept")) return { move: "accept_start" };
    if (request.status === "accepted" && may(request, "start")) return { move: "start" };
    // the words: someone else has it, or the person is no baker of this station (or nothing is listed yet)
    const someoneElse = request.assigned_to !== null && request.assigned_to.id !== me?.user.id;
    if (!fulfils || !someoneElse) return { refused: { reason: "drop_not_baker" } };
    return request.status === "requested" ? { refused: { reason: "drop_given_to", name: baker } } : { refused: { reason: "drop_not_yours_start", name: baker } };
  }

  if (from === "in_progress") {
    if (target === "requested") return { refused: { reason: "drop_no_back" } };
    // finishing lands where FinishBatch puts the batch: Pending confirmation at a station that needs an overseer (Done today is then a
    // second step, someone else's), straight to Done today at a station that does not (Pending confirmation is then never used)
    const lands: Column = request.station.requires_overseer ? "pending_confirmation" : "done_today";
    if (target !== lands) return { refused: { reason: request.station.requires_overseer ? "drop_finish_first" : "drop_no_overseer" } };
    if (may(request, "finish")) return { move: "finish" };
    return { refused: { reason: "drop_not_yours_finish", name: baker } };
  }

  // pending confirmation: confirmed into Done today, or sent back to the oven
  if (target === "requested") return { refused: { reason: "drop_no_back" } };
  const move: DropMove = target === "done_today" ? "confirm" : "send_back";
  if (may(request, move)) return { move };
  const maker = request.batch?.finished_by ?? null;
  if (me && maker?.id === me.user.id && canSignOff(me, request.station.id)) return { refused: { reason: "drop_own_batch", name: maker.name } };
  return { refused: { reason: "drop_not_overseer" } };
}

/** A card shown in its new column before the server said so: the move, and the queued write it waits for when it went offline. */
export interface ShownMove {
  column: Column;
  /** the request's version when it moved: the move ends when the board reads a newer one */
  version: number;
  /** the outbox write of a start queued offline: the card goes back if the outbox has it refused later */
  item?: string;
}

/**
 * The cards whose queued move was refused since (the outbox holds the write as a conflict): they go back to the column the server has them
 * in. Before, such a card stayed in its new column until the page was reloaded, while the server never moved it.
 */
export function refusedMoves(moved: ReadonlyMap<number, ShownMove>, queue: readonly { id: string; state: string }[]): number[] {
  const refused: number[] = [];
  for (const [id, move] of moved) {
    if (move.item !== undefined && queue.some((i) => i.id === move.item && i.state === "conflict")) refused.push(id);
  }
  return refused;
}

/**
 * The column a request moves to next: Requested goes to In progress, In progress to where FinishBatch puts the batch (Pending confirmation
 * at a station that needs an overseer, Done today at one that does not), Pending confirmation to Done today. null for a request that is
 * done, cancelled or on no column.
 */
export function nextColumn(request: ProductionRequest): Column | null {
  switch (columnOf(request)) {
    case "requested":
      return "in_progress";
    case "in_progress":
      return request.station.requires_overseer ? "pending_confirmation" : "done_today";
    case "pending_confirmation":
      return "done_today";
    default:
      return null;
  }
}

/**
 * The one action a board card offers this person (fresh-counter.md 3.5, step 6.3c), the next move of the request card's own buttons:
 * accept an open request (a baker, when nobody or they have it), start the batch they accepted, finish the batch they started (the request
 * card opens on its Done form: produced and waste are counted there), or confirm a finished batch they may sign off and did not make
 * themselves (the API refuses the one who made it). None otherwise: the card is still opened by its name.
 */
export type CardMove = "accept" | "start" | "finish" | "confirm";

const CARD_MOVES: Partial<Record<DropMove, CardMove>> = { accept_start: "accept", start: "start", finish: "finish", confirm: "confirm" };

/**
 * The card's button is the drop on its next column, read from the one table of rules (dropMove), so the button and the drag can never
 * disagree about who may move a card: a drop that accepts and starts is the button "Accept" (the button takes one step at a time).
 */
export function cardMove(me: Me | null, request: ProductionRequest): CardMove | null {
  const target = nextColumn(request);
  if (!me || !target) return null;
  const verdict = dropMove(me, request, target);
  return verdict && "move" in verdict ? (CARD_MOVES[verdict.move] ?? null) : null;
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
