import { compareDecimal, subtractDecimal, trimDecimal } from "./decimal";
import type { Count, CountLine, ShiftStatus, VarianceReason } from "./api/types";

// The plain logic of the count screen and the approval screens (step 3.7b): the variance of a line, which lines are touched, what the
// review says before a count is handed in, which revision of a count to show, and who may approve, co-sign, reject or unlock. No React
// and no browser, so e2e/p3 tests it directly. Quantities are decimal strings in the item's base unit and never become numbers.

/** counted minus expected, exact (4 decimals); null until the line has a counted figure. A found item has no expected figure: it is 0. */
export function varianceOf(line: CountLine): string | null {
  if (line.counted_qty === null) return null;
  return subtractDecimal(line.counted_qty, line.expected_qty ?? "0", 4);
}

/** The counter has looked at the line (typed a figure, or pressed "as expected"); the server calls it `confirmed`. */
export function isTouched(line: CountLine): boolean {
  return line.confirmed;
}

function differs(line: CountLine): boolean {
  const variance = varianceOf(line);
  return variance !== null && compareDecimal(variance, "0", 4) !== 0;
}

/** Only a line the counter touched whose counted figure differs from what is expected has to be explained. */
export function needsReason(line: CountLine): boolean {
  return isTouched(line) && differs(line);
}

/** What "as expected" stands for on a line: the carried figure for an opening count, the figure the ledger holds for a closing one. */
export function prefillOf(count: Pick<Count, "type">, line: CountLine): string {
  if (count.type === "opening" && line.carried_qty !== null) return line.carried_qty;
  return line.expected_qty ?? "0.0000";
}

export interface Review {
  total: number;
  /** lines the counter has not touched: the count cannot be handed in while there are any */
  untouched: CountLine[];
  /** touched lines whose figure differs from what is expected */
  changed: CountLine[];
  missingReason: CountLine[];
  /** lines whose reason asks for a note and has none */
  missingNote: CountLine[];
  ready: boolean;
}

/** The summary shown before the count is submitted, and what still blocks the submit (the API refuses the same three things). */
export function reviewOf(lines: readonly CountLine[], reasons: readonly VarianceReason[]): Review {
  const byId = new Map(reasons.map((reason) => [reason.id, reason]));
  const untouched = lines.filter((line) => !isTouched(line));
  const changed = lines.filter(needsReason);
  const missingReason = changed.filter((line) => line.reason === null);
  const missingNote = changed.filter((line) => {
    if (line.reason === null) return false;
    const reason = byId.get(line.reason.id);
    return reason?.requires_note === true && (line.note ?? "").trim() === "";
  });
  return {
    total: lines.length,
    untouched,
    changed,
    missingReason,
    missingNote,
    ready: untouched.length === 0 && missingReason.length === 0 && missingNote.length === 0,
  };
}

/** The revision of each place and type that counts now: a rejected count and an unlocked one are replaced by their revision. */
export function currentCounts<T extends Pick<Count, "superseded_by_id">>(counts: readonly T[]): T[] {
  return counts.filter((count) => count.superseded_by_id === null);
}

export type CountPhase = "opening" | "closing";

/**
 * Which counts of the shift the screen shows: the opening ones while the shift is being opened (or has just opened), the closing ones from
 * the moment it is closed. When the shift has none of that kind (yet) it shows what exists.
 */
export function phaseOf(status: ShiftStatus, counts: readonly Pick<Count, "type">[]): CountPhase {
  const wanted: CountPhase = status === "scheduled" || status === "opening" || status === "open" ? "opening" : "closing";
  if (counts.some((count) => count.type === wanted)) return wanted;
  const other: CountPhase = wanted === "opening" ? "closing" : "opening";
  return counts.some((count) => count.type === other) ? other : wanted;
}

export type CountStage = "counting" | "waiting_cosign" | "waiting_approval" | "locked" | "sent_back";

/** What happens next to a count, for the line under its heading. */
export function stageOf(count: Pick<Count, "status">): CountStage {
  switch (count.status) {
    case "draft":
      return "counting";
    case "awaiting_cosign":
      return "waiting_cosign";
    case "pending_approval":
    case "approved":
      return "waiting_approval";
    case "locked":
      return "locked";
    default:
      return "sent_back";
  }
}

export interface ApprovalActions {
  approve: boolean;
  cosign: boolean;
  reject: boolean;
  unlock: boolean;
}

export interface Approver {
  userId: number;
  permissions: readonly string[];
  /** the person works at the count's station (assigned there, or holds stations.all) */
  worksAtStation: boolean;
  /** the station asks for an overseer's co-signature */
  requiresOverseer: boolean;
}

/**
 * What a person is offered on a count (the API decides in the end and answers 403 or 409 in both languages): approve and reject by
 * `shifts.approve` and never for the person who counted; co-sign (and reject at that step) by `shifts.cosign`, or by an overseer at a
 * station that asks for one; unlock by `shifts.unlock`, on a locked count that no revision has replaced.
 */
export function approvalActions(count: Pick<Count, "status" | "counted_by" | "superseded_by_id">, who: Approver): ApprovalActions {
  const has = (permission: string) => who.permissions.includes(permission);
  const counter = count.counted_by?.id === who.userId;
  const canCosign = !counter && (has("shifts.cosign") || (who.requiresOverseer && who.worksAtStation));
  const canApprove = !counter && has("shifts.approve");
  return {
    approve: count.status === "pending_approval" && canApprove,
    cosign: count.status === "awaiting_cosign" && canCosign,
    reject: (count.status === "pending_approval" && canApprove) || (count.status === "awaiting_cosign" && canCosign),
    unlock: count.status === "locked" && count.superseded_by_id === null && has("shifts.unlock"),
  };
}

/** "-500.0000" -> "-500", "12.5000" -> "+12.5", "0.0000" -> "0", null -> "": a difference as it reads on the screen. */
export function signedQuantity(value: string | null): string {
  if (value === null) return "";
  const text = trimDecimal(value);
  if (text === "0") return "0";
  return text.startsWith("-") ? text : `+${text}`;
}

/** A chain hash for a list: the first and last eight characters. The full hash is shown wherever it is the point (a locked count). */
export function shortHash(hash: string | null): string {
  if (hash === null) return "";
  return hash.length <= 20 ? hash : `${hash.slice(0, 8)}…${hash.slice(-8)}`;
}

/**
 * The count as this tablet sees it after draft edits the outbox holds (step 5.2a): offline, a typed figure stays on its line (counted and
 * confirmed, the note as typed) until the API's own answer replaces the count. Lines not on the count yet (an added item) show up then.
 */
export function withDraftEdits(count: Count, edits: { item_id: number; counted_qty?: string | null; confirm?: boolean; reason_note?: string | null }[]): Count {
  if (edits.length === 0) return count;
  const byItem = new Map(edits.map((edit) => [edit.item_id, edit]));
  return {
    ...count,
    lines: count.lines.map((line): CountLine => {
      const edit = byItem.get(line.item.id);
      if (!edit) return line;
      return {
        ...line,
        ...(edit.counted_qty !== undefined ? { counted_qty: edit.counted_qty } : {}),
        ...(edit.reason_note !== undefined ? { note: edit.reason_note } : {}),
        confirmed: edit.confirm ?? line.confirmed,
      };
    }),
  };
}
