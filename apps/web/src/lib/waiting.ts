import type { CountSummary, ShiftSummary } from "./api/types";

// What a shift or a count is waiting for, and who can act (the explain-itself follow-up, Ramin 2026-11-10: "why someone must wait").
// Plain functions over the running shifts (GET /shifts?running=1) and the approval queue (GET /stock-counts), so the Shifts overview can say
// it in a line and the logic spec can try every case (e2e/p6/screens-waiting-logic.spec.ts).
//
// The rules they mirror (apps/api Shifts: SubmitCount, CosignCount, ApproveCount; docs/handbook/counts-and-handover.md):
// - a shift that is `opening` waits for its opening count; one that is `counting` (shown as "Closing") for its closing counts;
// - at a station that needs a second person (requires_overseer), a handed-in count waits for a co-signature: an overseer or someone with
//   shifts.cosign, never the person who counted;
// - a count that differs from what was expected waits for approval: a manager or an admin (shifts.approve), never the person who counted;
// - while a count waits at a place, the next count there cannot be handed in (409 shifts.count.blocked_by_pending).

export type CountWait = { kind: "cosign" | "approval"; type: "opening" | "closing"; counter: string | null };

export type ShiftWait = { kind: "opening_count" } | { kind: "closing_count" } | CountWait;

type QueuedCount = Pick<CountSummary, "shift_id" | "type" | "status" | "counted_by">;

/** What a count in the queue waits for, or null when it waits for nothing (a draft, a locked or a sent-back count). */
export function countWait(count: QueuedCount): CountWait | null {
  if (count.status === "awaiting_cosign") return { kind: "cosign", type: count.type, counter: count.counted_by?.name ?? null };
  if (count.status === "pending_approval") return { kind: "approval", type: count.type, counter: count.counted_by?.name ?? null };
  return null;
}

/**
 * What a running shift waits for: a count of it that waits for a co-signature first (somebody has to come and sign), then one that waits
 * for approval, then what its status asks for. Null for a shift that waits for nothing: an open shift with nothing in the queue is the
 * normal course of a day (Ramin 2026-11-11: the same "Open: ..." line under every open shift only repeated itself), or one not running.
 */
export function shiftWait(shift: Pick<ShiftSummary, "id" | "status">, queue: readonly QueuedCount[]): ShiftWait | null {
  const mine = queue.filter((count) => count.shift_id === shift.id);
  const cosign = mine.find((count) => count.status === "awaiting_cosign");
  if (cosign) return countWait(cosign);
  const approval = mine.find((count) => count.status === "pending_approval");
  if (approval) return countWait(approval);
  if (shift.status === "opening") return { kind: "opening_count" };
  if (shift.status === "counting") return { kind: "closing_count" };
  return null;
}

/** Whether a wait needs someone other than the people on duty (amber on the overview), rather than the normal course of a shift. */
export function needsSomeone(wait: ShiftWait): boolean {
  return wait.kind === "cosign" || wait.kind === "approval";
}

const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * Whole days a shift has been closing (status `counting`) since its closing started; 0 under a day, for a shift that is not closing, or
 * when the start is not known. Over a day is worth a flag: nobody handed the closing count in, and the station stays held.
 */
export function closingDays(shift: Pick<ShiftSummary, "status" | "closing_started_at">, now: Date): number {
  if (shift.status !== "counting" || !shift.closing_started_at) return 0;
  const since = Date.parse(shift.closing_started_at);
  if (Number.isNaN(since)) return 0;
  return Math.max(0, Math.floor((now.getTime() - since) / DAY_MS));
}
