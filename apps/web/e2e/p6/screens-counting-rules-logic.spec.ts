import { expect, test } from "@playwright/test";
import type { CountingRulesView } from "../../src/lib/api/types";
import { applyChanges, countsAtOpening, RULES, ruleState, switchAll, switchOne } from "../../src/lib/countingRules";

// The counting rules page's arithmetic (phase-6/counting-rules, lib/countingRules.ts, ADR 0025): where a rule stands across the stations,
// the changes an "All stations" switch sends (only the stations not already so), the view shown at once before the server answers, and
// whether a station's shift starts by counting. Plain functions, no page: it runs in p6-a11y beside the screenshots of the page.

function view(stations: [number, boolean, boolean, boolean][]): CountingRulesView {
  return {
    stations: stations.map(([id, opening_count, cosign, approval]) => ({ id, code: "kitchen", name_en: `S${id}`, name_am: `ጣ${id}`, opening_count, cosign, approval })),
    waiting: { awaiting_cosign: 0, pending_approval: 0 },
  };
}

test.describe("counting rules: the page's logic", () => {
  test("shows the rules in the order a shift meets them", () => {
    expect(RULES).toEqual(["opening_count", "cosign", "approval"]);
  });

  test("says a rule is on, off or on at some stations", () => {
    const v = view([
      [1, true, false, true],
      [2, true, true, false],
    ]);
    expect(ruleState(v, "opening_count")).toBe("on");
    expect(ruleState(v, "cosign")).toBe("some");
    expect(ruleState(v, "approval")).toBe("some");
    expect(ruleState(view([[1, false, false, false]]), "approval")).toBe("off");
    expect(ruleState(view([]), "cosign")).toBe("off");
  });

  test("an All stations switch changes only the stations that are not already so", () => {
    const v = view([
      [1, true, false, true],
      [2, true, true, true],
      [3, true, false, true],
    ]);
    expect(switchAll(v, "cosign", true)).toEqual([
      { id: 1, cosign: true },
      { id: 3, cosign: true },
    ]);
    expect(switchAll(v, "opening_count", true)).toEqual([]);
    expect(switchOne(2, "approval", false)).toEqual([{ id: 2, approval: false }]);
  });

  test("shows the change at once and leaves the other stations and rules as they were", () => {
    const v = view([
      [1, true, false, true],
      [2, true, true, true],
    ]);
    const next = applyChanges(v, [{ id: 2, approval: false, opening_count: false }]);
    expect(next.stations[0]).toEqual(v.stations[0]);
    expect(next.stations[1]).toMatchObject({ opening_count: false, cosign: true, approval: false });
    expect(v.stations[1].approval).toBe(true);
  });

  test("a shift starts by counting unless the station's opening count is off (an older API counts, as before)", () => {
    expect(countsAtOpening({ counting_rules: { opening_count: false } })).toBe(false);
    expect(countsAtOpening({ counting_rules: { opening_count: true } })).toBe(true);
    expect(countsAtOpening({})).toBe(true);
    expect(countsAtOpening(null)).toBe(true);
  });
});
