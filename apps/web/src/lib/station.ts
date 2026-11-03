import { create } from "zustand";
import type { Shift } from "./api/types";
import { shiftRequiredNotice, type ShiftRequiredKind } from "./shifts";

// Two small pieces of state the station screens share with the API client, kept in a module of their own so the client can
// read them without importing a screen (step 3.7a).

/**
 * The station the person is working at on this screen (each station screen sets it through its shift bar). The API client sends it
 * as X-Station-Id, which the API uses to stamp the shift and station on every write (step 3.1a, F4): without it a manager or an
 * admin, who may work any station, has no station at all.
 */
interface ActiveStation {
  stationId: number | null;
  set: (stationId: number | null) => void;
}

export const useActiveStation = create<ActiveStation>()((set) => ({
  stationId: null,
  set: (stationId) => set({ stationId }),
}));

export const STATION_HEADER = "X-Station-Id";

/**
 * What the station top bar shows of the screen it frames (step 6.2b, fresh-counter.md 3): the station the screen works at and its shift,
 * as the screen's shift feed last read it. The station screens set it (useShiftStation) and clear it when they go; a page without a
 * station leaves it empty, and the bar shows the app's name alone.
 */
export interface StationChrome {
  station: { id: number; name_en: string; name_am: string };
  /** the running shift, or null when none runs; undefined while unknown, refused, or at a station that tracks no shifts (no chip) */
  shift: Shift | null | undefined;
}

interface StationChromeState {
  chrome: StationChrome | null;
  set: (chrome: StationChrome | null) => void;
}

export const useStationChrome = create<StationChromeState>()((set) => ({
  chrome: null,
  set: (chrome) => set({ chrome }),
}));

/** The header of a call made now, or none. */
export function stationHeaders(): Record<string, string> {
  const id = useActiveStation.getState().stationId;
  return id === null ? {} : { [STATION_HEADER]: String(id) };
}

export interface ShiftNotice {
  kind: ShiftRequiredKind;
  stationId: number | null;
  message_en: string;
  message_am: string;
}

interface ShiftNoticeState {
  /** the last 409 inventory.shift.required / payments.shift.required, until a shift is running or the person dismisses it */
  notice: ShiftNotice | null;
  /**
   * The till has been told, in this session, that a cash payment needs a shift (payments.shift.required): the rule is on, so the
   * Pay button is blocked while no shift is open. With the rule off (the live default until step 3.7e) nothing ever says so and the
   * button stays enabled.
   */
  cashNeedsShift: boolean;
  report: (error: unknown) => void;
  clear: () => void;
}

export const useShiftNotice = create<ShiftNoticeState>()((set) => ({
  notice: null,
  cashNeedsShift: false,
  report(error) {
    const found = shiftRequiredNotice(error);
    if (!found) return;
    const messages = error as { messages?: { en?: string; am?: string } };
    set((state) => ({
      notice: { ...found, message_en: messages.messages?.en ?? "", message_am: messages.messages?.am ?? "" },
      cashNeedsShift: state.cashNeedsShift || found.kind === "payments",
    }));
  },
  clear: () => set({ notice: null }),
}));
