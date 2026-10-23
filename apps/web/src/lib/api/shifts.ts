import { authed } from "../session";
import type { Shift } from "./types";

// The shift writes of the shift bar (phase-3 plan section 4). Each carries the Idempotency-Key its caller chose and keeps it for a
// retry; the start also carries the client_uuid of the shift it makes (the same value, so a retry answers the shift already made).

export interface StartShiftAnswer {
  shift: Shift;
}

export function startShift(stationId: number, carry: boolean, key: string): Promise<StartShiftAnswer> {
  return authed<StartShiftAnswer>(`/stations/${stationId}/shifts/start`, { method: "POST", body: { client_uuid: key, carry }, idempotencyKey: key });
}

export function joinShift(shiftId: number, key: string): Promise<Shift> {
  return authed<Shift>(`/shifts/${shiftId}/join`, { method: "POST", body: {}, idempotencyKey: key });
}

export function leaveShift(shiftId: number, key: string): Promise<Shift> {
  return authed<Shift>(`/shifts/${shiftId}/leave`, { method: "POST", body: {}, idempotencyKey: key });
}

export function closeShift(shiftId: number, key: string): Promise<{ shift: Shift }> {
  return authed<{ shift: Shift }>(`/shifts/${shiftId}/close`, { method: "POST", body: {}, idempotencyKey: key });
}

export function cancelShift(shiftId: number, key: string): Promise<Shift> {
  return authed<Shift>(`/shifts/${shiftId}/cancel`, { method: "POST", body: {}, idempotencyKey: key });
}

