import { submit, type Submitted } from "../outbox/store";
import { authed } from "../session";
import type { CashParts } from "../cash";
import type { Collection, Count, HandoverNote, HandoverPanelData, VarianceReason } from "./types";

// The count, handover and approval calls of the shift screens (step 3.7b; phase-3 plan section 4). Every write carries the
// Idempotency-Key its caller chose and keeps for a retry.

export interface LineEdit {
  item_id: number;
  counted_qty?: string | null;
  confirm?: boolean;
  reason_id?: number | null;
  reason_note?: string | null;
}

export function getShiftCounts(shiftId: number): Promise<Count[]> {
  return authed<Collection<Count>>(`/shifts/${shiftId}/counts`).then((page) => page.data);
}

export function getCount(countId: number): Promise<Count> {
  return authed<Count>(`/stock-counts/${countId}`);
}

export function getVarianceReasons(): Promise<VarianceReason[]> {
  return authed<Collection<VarianceReason>>("/variance-reasons", { query: { active: 1 } }).then((page) => page.data);
}

/** The submit's answer: the count, and the items whose lines it dropped because they were deleted from the catalog while the draft listed them. */
export type SubmittedCount = Count & { dropped_item_ids?: number[] };

export function submitCount(countId: number, key: string): Promise<SubmittedCount> {
  return authed<SubmittedCount>(`/stock-counts/${countId}/submit`, { method: "POST", body: {}, idempotencyKey: key });
}

export function cosignCount(countId: number, note: string, key: string): Promise<Count> {
  return authed<Count>(`/stock-counts/${countId}/cosign`, { method: "POST", body: note.trim() ? { note } : {}, idempotencyKey: key });
}

export function approveCount(countId: number, note: string, key: string): Promise<Count> {
  return authed<Count>(`/stock-counts/${countId}/approve`, { method: "POST", body: note.trim() ? { note } : {}, idempotencyKey: key });
}

export function rejectCount(countId: number, note: string, lineIds: number[] | null, key: string): Promise<{ recount: Count }> {
  return authed<{ recount: Count }>(`/stock-counts/${countId}/reject`, {
    method: "POST",
    body: lineIds === null ? { note } : { note, line_ids: lineIds },
    idempotencyKey: key,
  });
}

export function unlockCount(countId: number, reason: string, key: string): Promise<{ revision: Count }> {
  return authed<{ revision: Count }>(`/stock-counts/${countId}/unlock`, { method: "POST", body: { reason }, idempotencyKey: key });
}

export function getHandover(stationId: number): Promise<HandoverPanelData> {
  return authed<HandoverPanelData>(`/stations/${stationId}/handover`);
}

export function getNotes(shiftId: number): Promise<HandoverNote[]> {
  return authed<Collection<HandoverNote>>(`/shifts/${shiftId}/notes`).then((page) => page.data);
}

/** The note's `client_uuid` is the Idempotency-Key, so a retry answers the note already written. */
export function addNote(shiftId: number, body: string, toUserId: number | null, key: string): Promise<HandoverNote> {
  return authed<HandoverNote>(`/shifts/${shiftId}/notes`, {
    method: "POST",
    body: { client_uuid: key, body, ...(toUserId === null ? {} : { to_user_id: toUserId }) },
    idempotencyKey: key,
  });
}

/** The outbox group of a count's drafts: its lines and its cash declaration, strictly in the order they were saved. */
export function countGroup(countId: number): string {
  return `count:${countId}`;
}

/** PUT /stock-counts/{id}/lines, a draft, through the outbox (step 5.2a): the count when online, queued when offline. */
export function submitLines(countId: number, body: { lines?: LineEdit[]; confirm_all?: boolean }, key: string): Promise<Submitted<Count>> {
  return submit<Count>({ kind: "count_lines", method: "PUT", path: `/stock-counts/${countId}/lines`, body, subjectId: countId, group: countGroup(countId), idempotencyKey: key });
}

/**
 * The cash declaration of a drawer count (step 3.7d): always the whole tally by denomination, plus the parts that changed. A draft, so
 * through the outbox (step 5.2a): the count when online, queued when offline.
 */
export function submitCash(countId: number, body: { denominations: Record<string, number> } & CashParts, key: string): Promise<Submitted<Count>> {
  return submit<Count>({ kind: "count_cash", method: "PUT", path: `/stock-counts/${countId}/cash`, body, subjectId: countId, group: countGroup(countId), idempotencyKey: key });
}
