import { Api, ApiFailure, uuid } from "./api";

// Shifts for the fixtures (step 3.2c, docs/phases/phase-3-plan.md section 10.1). With ledger.require_shift_for_station_locations
// on (CI turns it on for e2e-p0..p2; the live host from step 3.7e) a stock movement at a station that tracks shifts is refused
// (409 inventory.shift.required) unless that station has an open shift, so every fixture opens the shift of the station it is
// about to stock or move stock at. With the rule off the shift is simply there, as on a live bakery.
//
// The shifts a fixture opens are never closed (closing arrives with step 3.3): the next run finds the shift of the day running
// and does nothing, which is also what keeps the E2E branch from growing a shift per run.

export interface StationRef {
  id: number;
}

interface ShiftView {
  id: number;
  status: string;
}

/**
 * Makes sure the station has a running shift: asks for the current one and, when there is none, starts one carried (no opening
 * count exists yet). A station that tracks no shifts (the store) needs none and is left alone. A start that loses a race with
 * another one (409 shifts.shift.already_open) found the shift running, which is what was wanted.
 */
export async function ensureOpenShift(admin: Api, station: StationRef): Promise<void> {
  try {
    const current = (await admin.get<{ data: ShiftView | null }>(`/stations/${station.id}/shifts/current`)).data;
    if (current) return;
    await admin.post(`/stations/${station.id}/shifts/start`, { client_uuid: uuid(), carry: true });
  } catch (error) {
    if (error instanceof ApiFailure && (error.key === "shifts.station.not_tracked" || error.key === "shifts.shift.already_open")) return;
    throw error;
  }
}

/** Opens the shifts of several stations, one after the other (each is its own write). */
export async function ensureOpenShifts(admin: Api, ...stations: StationRef[]): Promise<void> {
  for (const station of stations) await ensureOpenShift(admin, station);
}

/** The shift of the station a location is tied to; a location with no station (the E2E branch's display case) needs none. */
export async function ensureOpenShiftAt(admin: Api, location: { station_id: number | null }): Promise<void> {
  if (location.station_id !== null) await ensureOpenShift(admin, { id: location.station_id });
}
