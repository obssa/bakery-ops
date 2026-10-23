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

export function putLines(countId: number, body: { lines?: LineEdit[]; confirm_all?: boolean }, key: string): Promise<Count> {
  return authed<Count>(`/stock-counts/${countId}/lines`, { method: "PUT", body, idempotencyKey: key });
}

/** The cash declaration of a drawer count (step 3.7d): always the whole tally by denomination, plus the parts that changed. */
export function putCash(countId: number, body: { denominations: Record<string, number> } & CashParts, key: string): Promise<Count> {
  return authed<Count>(`/stock-counts/${countId}/cash`, { method: "PUT", body, idempotencyKey: key });
}

export function submitCount(countId: number, key: string): Promise<Count> {
  return authed<Count>(`/stock-counts/${countId}/submit`, { method: "POST", body: {}, idempotencyKey: key });
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
