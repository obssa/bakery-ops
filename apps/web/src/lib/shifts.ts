import type { RosterRow, Shift, WorkStation } from "./api/types";

// The plain logic of the shift bar (step 3.7a): who is on duty, which buttons a person gets in each state of the shift, when the
// till's Pay is blocked, how the two "shift required" refusals are recognised. No React and no browser, so e2e/p3 tests it directly.

/** The roster rows of people who are on the shift now (joined and not left). */
export function onDuty(shift: Shift | null): RosterRow[] {
  return (shift?.roster ?? []).filter((row) => row.user !== null && row.left_at === null);
}

export function isOnDuty(shift: Shift | null, userId: number): boolean {
  return onDuty(shift).some((row) => row.user?.id === userId);
}

export type BarMode = "idle" | "opening" | "open" | "counting";

export interface BarActions {
  mode: BarMode;
  /** start with an opening count (the shift is `opening` when the station has anything to count) */
  start: boolean;
  /** "Start without counting": open at once, the count of last night's stock waits for the approval */
  startCarried: boolean;
  join: boolean;
  leave: boolean;
  close: boolean;
  /** go to the count screen: the opening count of an `opening` shift, the closing count of a `counting` one */
  continueCount: boolean;
  /** cancel a start that is still `opening`: its starter, or a manager */
  cancel: boolean;
}

export interface Powers {
  /** shifts.count: start, join, leave, close, count */
  canCount: boolean;
  /** shifts.manage: acts for others */
  canManage: boolean;
}

/** What the person may do about the shift running at the station (null: none running), in the state it is in. */
export function barActions(shift: Shift | null, userId: number, powers: Powers): BarActions {
  const none = { start: false, startCarried: false, join: false, leave: false, close: false, continueCount: false, cancel: false };
  if (shift === null) return { mode: "idle", ...none, start: powers.canCount, startCarried: powers.canCount };
  switch (shift.status) {
    case "opening":
      return {
        mode: "opening",
        ...none,
        continueCount: powers.canCount,
        cancel: powers.canManage || (powers.canCount && shift.started_by?.id === userId),
      };
    case "open": {
      const on = isOnDuty(shift, userId);
      return { mode: "open", ...none, join: powers.canCount && !on, leave: powers.canCount && on, close: powers.canCount };
    }
    default:
      // counting (the closing count is in progress); the station is frozen until it is submitted
      return { mode: "counting", ...none, continueCount: powers.canCount };
  }
}

/**
 * Build-time switch for the till: NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH=true makes Pay wait for a running shift from the first screen,
 * without waiting to be told by a refusal. Off everywhere until step 3.7e flips payments.require_shift_for_cash.
 */
export const CASH_NEEDS_SHIFT_BUILD = process.env.NEXT_PUBLIC_REQUIRE_SHIFT_FOR_CASH === "true";

export type PayBlock = "no_shift" | "not_open";

/**
 * Why the till's Pay button is blocked, or null. It only matters once payments.require_shift_for_cash is on (step 3.7e flips it; the
 * web learns of it from the API's first payments.shift.required, or from the build flag), so `enforced` false never blocks, and a
 * station that tracks no shifts, or a feed that has not answered yet, never does either.
 */
export function payBlockReason(input: { shift: Shift | null; tracked: boolean; loaded: boolean; enforced: boolean }): PayBlock | null {
  if (!input.enforced || !input.tracked || !input.loaded) return null;
  if (input.shift === null) return "no_shift";
  return input.shift.status === "open" ? null : "not_open";
}

export type ShiftRequiredKind = "inventory" | "payments";

interface ErrorLike {
  status?: unknown;
  key?: unknown;
  body?: unknown;
}

/** The refusals that mean "start a shift first": 409 inventory.shift.required (names the station) and 409 payments.shift.required. */
export function shiftRequiredNotice(error: unknown): { kind: ShiftRequiredKind; stationId: number | null } | null {
  if (typeof error !== "object" || error === null) return null;
  const { status, key, body } = error as ErrorLike;
  if (status !== 409) return null;
  if (key === "payments.shift.required") return { kind: "payments", stationId: null };
  if (key === "inventory.shift.required") {
    const id = (body as { station_id?: unknown } | null)?.station_id;
    return { kind: "inventory", stationId: typeof id === "number" ? id : null };
  }
  return null;
}

/** The stations of /me that track shifts and have one of the codes, in the order /me lists them. */
export function stationsFor(workStations: readonly WorkStation[] | undefined, codes: readonly string[]): WorkStation[] {
  return (workStations ?? []).filter((station) => station.tracks_shifts && codes.includes(station.code));
}

/** The chosen station when it is among the choices, else the first one, else none. */
export function chooseStation(choices: readonly WorkStation[], wanted: number | null): WorkStation | null {
  return choices.find((station) => station.station_id === wanted) ?? choices[0] ?? null;
}

const CLOCK = new Intl.DateTimeFormat("en-GB", { timeZone: "Africa/Addis_Ababa", hour: "2-digit", minute: "2-digit", hourCycle: "h23" });

/** HH:MM in Africa/Addis_Ababa for a UTC ISO-8601 instant ("" for none). */
export function addisClock(iso: string | null | undefined): string {
  if (!iso) return "";
  const time = Date.parse(iso);
  return Number.isNaN(time) ? "" : CLOCK.format(new Date(time));
}
