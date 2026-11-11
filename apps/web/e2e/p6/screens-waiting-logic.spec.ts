import { expect, test } from "@playwright/test";
import type { CountStatus, ShiftStatus } from "../../src/lib/api/types";
import { closingDays, countWait, needsSomeone, shiftWait } from "../../src/lib/waiting";

// What a shift or a count waits for (the explain-itself follow-up, Ramin 2026-11-10: "why someone must wait"; lib/waiting.ts): the line under
// each running shift on the Shifts overview and the "why waiting" line of the approval queue. Plain functions, no page: it runs in p6-a11y
// beside the screenshots, as the clock's logic does.

const counted = (name: string | null) => (name === null ? null : { id: 7, name });

function count(shiftId: number, type: "opening" | "closing", status: CountStatus, counter: string | null = "Dawit") {
  return { shift_id: shiftId, type, status, counted_by: counted(counter) };
}

test.describe("what a shift waits for", () => {
  test("with nothing in the queue, the status says it", () => {
    const cases: [ShiftStatus, string | null][] = [
      ["opening", "opening_count"],
      // an open shift is the normal course of a day: no line
      ["open", null],
      ["counting", "closing_count"],
      ["scheduled", null],
      ["pending_approval", null],
      ["locked", null],
      ["cancelled", null],
    ];
    for (const [status, kind] of cases) {
      expect(shiftWait({ id: 1, status }, [])?.kind ?? null, status).toBe(kind);
    }
  });

  test("a count of the shift that waits for a co-signature comes first, naming who counted", () => {
    const queue = [count(1, "closing", "pending_approval", "Hana"), count(1, "closing", "awaiting_cosign", "Dawit"), count(2, "closing", "awaiting_cosign", "Sara")];
    expect(shiftWait({ id: 1, status: "counting" }, queue)).toEqual({ kind: "cosign", type: "closing", counter: "Dawit" });
  });

  test("then one that waits for approval; the counts of other shifts are not this shift's", () => {
    const queue = [count(2, "closing", "awaiting_cosign"), count(1, "opening", "pending_approval", "Hana")];
    expect(shiftWait({ id: 1, status: "open" }, queue)).toEqual({ kind: "approval", type: "opening", counter: "Hana" });
    expect(shiftWait({ id: 3, status: "open" }, queue)).toBeNull();
  });

  test("a draft, an approved, a locked or a sent-back count waits for nobody", () => {
    for (const status of ["draft", "approved", "locked", "rejected"] as const) {
      expect(countWait(count(1, "closing", status)), status).toBeNull();
      expect(shiftWait({ id: 1, status: "counting" }, [count(1, "closing", status)])).toEqual({ kind: "closing_count" });
    }
  });

  test("a count whose counter is not known still says who may not act, without a name", () => {
    expect(countWait(count(1, "opening", "awaiting_cosign", null))).toEqual({ kind: "cosign", type: "opening", counter: null });
  });

  test("only a co-signature or an approval needs someone other than the people on duty", () => {
    expect(needsSomeone({ kind: "cosign", type: "closing", counter: null })).toBe(true);
    expect(needsSomeone({ kind: "approval", type: "closing", counter: null })).toBe(true);
    for (const kind of ["opening_count", "closing_count"] as const) expect(needsSomeone({ kind }), kind).toBe(false);
  });
});

test.describe("how long a shift has been closing", () => {
  const now = new Date("2026-11-28T09:00:00Z");

  test("whole days since the closing started, for a closing shift only", () => {
    expect(closingDays({ status: "counting", closing_started_at: "2026-11-11T08:30:00Z" }, now)).toBe(17);
    expect(closingDays({ status: "counting", closing_started_at: "2026-11-27T09:00:00Z" }, now)).toBe(1);
    // under a day is the normal course of a closing
    expect(closingDays({ status: "counting", closing_started_at: "2026-11-27T09:00:01Z" }, now)).toBe(0);
    expect(closingDays({ status: "open", closing_started_at: "2026-11-11T08:30:00Z" }, now)).toBe(0);
    expect(closingDays({ status: "counting", closing_started_at: null }, now)).toBe(0);
    expect(closingDays({ status: "counting", closing_started_at: "not a time" }, now)).toBe(0);
  });
});
