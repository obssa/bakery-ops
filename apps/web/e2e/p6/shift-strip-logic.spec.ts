import { expect, test } from "@playwright/test";
import am from "../../messages/am.json";
import en from "../../messages/en.json";
import type { Shift } from "../../src/lib/api/types";
import { barActions, barMode, stripAction, stripShown, type BarMode } from "../../src/lib/shifts";

// The shift's attention strip as plain logic (phase-6/shift-strip; no browser, no API): when it shows and which one action it offers, in each
// state of the shift, for a person who may count and for one who may not, on a station screen and on the count screen (`quiet`).

const ME = 1;
const CAN = { canCount: true, canManage: false };
const CANNOT = { canCount: false, canManage: false };

function shift(status: Shift["status"]): Shift {
  return { status, started_by: { id: ME, name: "Someone" }, roster: [] } as unknown as Shift;
}

const STATES: { mode: BarMode; shift: Shift | null }[] = [
  { mode: "idle", shift: null },
  { mode: "opening", shift: shift("opening") },
  { mode: "open", shift: shift("open") },
  { mode: "counting", shift: shift("counting") },
];

test.describe("the shift's attention strip", () => {
  for (const { mode, shift: running } of STATES) {
    const needsSomeone = mode !== "open";
    test(`${mode}: on a station screen ${needsSomeone ? "it shows" : "only a refusal shows it"}, on the count screen only a refusal`, () => {
      expect(barMode(running)).toBe(mode);
      expect(stripShown(mode, false, false)).toBe(needsSomeone);
      expect(stripShown(mode, false, true)).toBe(true);
      expect(stripShown(mode, true, false)).toBe(false);
      expect(stripShown(mode, true, true)).toBe(true);
    });
  }

  test("its one main action is the state's: start with none running, the count while one is in progress, none with the shift open", () => {
    expect(stripAction(barActions(null, ME, CAN), false)).toBe("start");
    expect(stripAction(barActions(shift("opening"), ME, CAN), false)).toBe("count");
    expect(stripAction(barActions(shift("counting"), ME, CAN), false)).toBe("count");
    expect(stripAction(barActions(shift("open"), ME, CAN), false)).toBeNull();
  });

  test("on the count screen the count is already open: a refusal there offers the start, never the count", () => {
    expect(stripAction(barActions(null, ME, CAN), true)).toBe("start");
    expect(stripAction(barActions(shift("opening"), ME, CAN), true)).toBeNull();
    expect(stripAction(barActions(shift("counting"), ME, CAN), true)).toBeNull();
  });

  test("a person who may not count gets the words and no action", () => {
    for (const { shift: running } of STATES) expect(stripAction(barActions(running, ME, CANNOT), false)).toBeNull();
  });

  test("its words are in both languages, and its action names are the panel's own messages", () => {
    for (const t of [en, am]) {
      for (const key of ["strip_more", "strip_dismiss", "start", "open_count", "idle", "opening", "counting"] as const) expect(t.shifts[key].length).toBeGreaterThan(0);
    }
    expect(en.shifts.strip_more).toBe("More");
    expect(am.shifts.strip_more).not.toBe(en.shifts.strip_more);
  });
});
