import { expect, test } from "@playwright/test";
import type { Count, CountLine, VarianceReason } from "../../src/lib/api/types";
import {
  approvalActions,
  currentCounts,
  isTouched,
  needsReason,
  phaseOf,
  prefillOf,
  reviewOf,
  shortHash,
  signedQuantity,
  stageOf,
  varianceOf,
} from "../../src/lib/counts";

// Step 3.7b, the parts of the count screen and the approval screens that are plain logic (no browser, no API): the variance of a
// line, which lines are touched, which need a reason or a note, what the review summary says before the count is submitted, which
// revision of a count is the one to show, and who may approve, co-sign, reject or unlock. Quantities stay decimal strings.
// They ride in the p3-handover project (its name matches), next to the flow in handover.spec.ts.

function line(patch: Partial<CountLine> = {}): CountLine {
  return {
    id: 1,
    item: { id: 10, name_en: "Flour", name_am: "ዱቄት", unit: "g" },
    expected_qty: "9500.0000",
    carried_qty: "9500.0000",
    counted_qty: null,
    variance_qty: null,
    confirmed: false,
    reason: null,
    note: null,
    reason_status: "none",
    decision_note: null,
    adjustment_movement_id: null,
    moved_since: null,
    ...patch,
  };
}

function count(patch: Partial<Count> = {}): Count {
  return {
    id: 100,
    shift_id: 7,
    location: { id: 5, name_en: "Kitchen stock", name_am: "የኩሽና ክምችት", type: "kitchen" },
    type: "closing",
    seq: 1,
    status: "draft",
    counted_by: { id: 11, name: "Abebe" },
    started_at: null,
    snapshot_at: null,
    submitted_at: null,
    cosigned_by: null,
    decided_by: null,
    decision: null,
    decision_note: null,
    supersede_cause: null,
    superseded_by_id: null,
    chain: null,
    blocked_by_pending: null,
    version: 1,
    lines: [],
    cash: null,
    ...patch,
  };
}

const reasons: VarianceReason[] = [
  { id: 1, code: "miscount", name_en: "Miscount", name_am: "የቆጠራ ስህተት", requires_note: false, is_active: true, sort: 1 },
  { id: 2, code: "other", name_en: "Other", name_am: "ሌላ", requires_note: true, is_active: true, sort: 2 },
];

test.describe("p3 count screen: logic", () => {
  test("the variance of a line is counted minus expected, exact on decimal strings, null until counted", () => {
    expect(varianceOf(line())).toBeNull();
    expect(varianceOf(line({ counted_qty: "9000.0000" }))).toBe("-500.0000");
    expect(varianceOf(line({ counted_qty: "9500.0000" }))).toBe("0.0000");
    expect(varianceOf(line({ counted_qty: "9500.0001", expected_qty: "9500.0000" }))).toBe("0.0001");
    expect(varianceOf(line({ counted_qty: "0.1000", expected_qty: "0.3000" }))).toBe("-0.2000"); // no float drift
    expect(varianceOf(line({ counted_qty: "5.0000", expected_qty: null }))).toBe("5.0000"); // an item found that was not on the list
  });

  test("a line is touched once confirmed; only a touched line with a variance needs a reason", () => {
    expect(isTouched(line())).toBe(false);
    expect(isTouched(line({ confirmed: true, counted_qty: "9500.0000" }))).toBe(true);
    expect(needsReason(line({ counted_qty: "9000.0000" }))).toBe(false); // not touched yet: nothing to explain
    expect(needsReason(line({ confirmed: true, counted_qty: "9000.0000" }))).toBe(true);
    expect(needsReason(line({ confirmed: true, counted_qty: "9500.0000" }))).toBe(false);
  });

  test("an untouched line is prefilled with what 'nothing changed' stands for: carried for an opening, expected for a closing", () => {
    expect(prefillOf(count({ type: "opening" }), line({ carried_qty: "9000.0000", expected_qty: "9500.0000" }))).toBe("9000.0000");
    expect(prefillOf(count({ type: "opening" }), line({ carried_qty: null, expected_qty: "9500.0000" }))).toBe("9500.0000");
    expect(prefillOf(count({ type: "closing" }), line({ carried_qty: "9000.0000", expected_qty: "9500.0000" }))).toBe("9500.0000");
    expect(prefillOf(count({ type: "closing" }), line({ carried_qty: null, expected_qty: null }))).toBe("0.0000");
  });

  test("the review lists what blocks the submit: untouched lines, missing reasons, missing notes", () => {
    const lines = [
      line({ id: 1, confirmed: true, counted_qty: "9500.0000" }), // as expected
      line({ id: 2, confirmed: true, counted_qty: "9000.0000" }), // varies, no reason
      line({ id: 3, confirmed: true, counted_qty: "100.0000", expected_qty: "200.0000", reason: reasons[1] }), // "Other" needs a note
      line({ id: 4, confirmed: true, counted_qty: "100.0000", expected_qty: "200.0000", reason: reasons[0] }), // explained
      line({ id: 5 }), // untouched
    ];
    const review = reviewOf(lines, reasons);
    expect(review.total).toBe(5);
    expect(review.untouched.map((l) => l.id)).toEqual([5]);
    expect(review.changed.map((l) => l.id)).toEqual([2, 3, 4]);
    expect(review.missingReason.map((l) => l.id)).toEqual([2]);
    expect(review.missingNote.map((l) => l.id)).toEqual([3]);
    expect(review.ready).toBe(false);

    const fixed = lines.slice(0, 4).map((l) => (l.id === 2 ? { ...l, reason: reasons[0] } : l.id === 3 ? { ...l, note: "Fell off the shelf" } : l));
    expect(reviewOf(fixed, reasons)).toMatchObject({ total: 4, untouched: [], missingReason: [], missingNote: [], ready: true });
    expect(reviewOf([], reasons).ready).toBe(true); // a place with nothing on it can be handed in
  });

  test("the revision to show is the newest of each place and type, a recount replacing the rejected one", () => {
    const rejected = count({ id: 1, seq: 1, status: "rejected", superseded_by_id: 2 });
    const recount = count({ id: 2, seq: 2, status: "draft" });
    const other = count({ id: 3, location: { id: 6, name_en: "Cold room", name_am: "ቀዝቃዛ ክፍል", type: "kitchen" } });
    const opening = count({ id: 4, type: "opening" });
    expect(currentCounts([rejected, recount, other, opening]).map((c) => c.id)).toEqual([2, 3, 4]);
  });

  test("an opening shift shows its opening counts, a closing one its closing counts, and a shift falls back to what exists", () => {
    const opening = count({ id: 1, type: "opening" });
    const closing = count({ id: 2, type: "closing" });
    expect(phaseOf("opening", [opening, closing])).toBe("opening");
    expect(phaseOf("counting", [opening, closing])).toBe("closing");
    expect(phaseOf("pending_approval", [opening, closing])).toBe("closing");
    expect(phaseOf("locked", [opening, closing])).toBe("closing");
    expect(phaseOf("open", [opening])).toBe("opening");
    expect(phaseOf("counting", [opening])).toBe("opening"); // no closing counts (yet): show what there is
  });

  test("the stage of a count says what happens next", () => {
    expect(stageOf(count({ status: "draft" }))).toBe("counting");
    expect(stageOf(count({ status: "awaiting_cosign" }))).toBe("waiting_cosign");
    expect(stageOf(count({ status: "pending_approval" }))).toBe("waiting_approval");
    expect(stageOf(count({ status: "approved" }))).toBe("waiting_approval"); // approved and locked are one step; this is a moment in it
    expect(stageOf(count({ status: "locked" }))).toBe("locked");
    expect(stageOf(count({ status: "rejected" }))).toBe("sent_back");
  });

  test("approve and reject belong to shifts.approve, never to the person who counted; co-sign to the overseers", () => {
    const pending = count({ status: "pending_approval", counted_by: { id: 11, name: "Abebe" } });
    const manager = { userId: 20, permissions: ["shifts.view", "shifts.approve", "shifts.cosign"], worksAtStation: true, requiresOverseer: false };
    expect(approvalActions(pending, manager)).toEqual({ approve: true, cosign: false, reject: true, unlock: false });
    expect(approvalActions(pending, { ...manager, userId: 11 })).toEqual({ approve: false, cosign: false, reject: false, unlock: false }); // the counter
    expect(approvalActions(pending, { ...manager, permissions: ["shifts.view"] })).toEqual({ approve: false, cosign: false, reject: false, unlock: false });

    const waiting = count({ status: "awaiting_cosign" });
    expect(approvalActions(waiting, manager)).toEqual({ approve: false, cosign: true, reject: true, unlock: false });
    // an overseer flagged at a station that requires one co-signs without holding shifts.cosign
    expect(approvalActions(waiting, { userId: 30, permissions: ["shifts.view"], worksAtStation: true, requiresOverseer: true })).toMatchObject({ cosign: true, reject: true });
    expect(approvalActions(waiting, { userId: 30, permissions: ["shifts.view"], worksAtStation: false, requiresOverseer: true })).toMatchObject({ cosign: false });

    const locked = count({ status: "locked" });
    const admin = { ...manager, permissions: ["shifts.view", "shifts.approve", "shifts.unlock"] };
    expect(approvalActions(locked, admin)).toEqual({ approve: false, cosign: false, reject: false, unlock: true });
    expect(approvalActions(count({ status: "locked", superseded_by_id: 9 }), admin).unlock).toBe(false); // already replaced by a revision
    expect(approvalActions(locked, manager).unlock).toBe(false);
  });

  test("quantities read with their sign, and a hash is shortened for the list but never altered", () => {
    expect(signedQuantity("-500.0000")).toBe("-500");
    expect(signedQuantity("12.5000")).toBe("+12.5");
    expect(signedQuantity("0.0000")).toBe("0");
    expect(signedQuantity(null)).toBe("");
    const hash = "9f2c1ab4d3e5f60718293a4b5c6d7e8f9a0b1c2d3e4f5061728394a5b6c7d8e9";
    expect(shortHash(hash)).toBe(`${hash.slice(0, 8)}…${hash.slice(-8)}`);
    expect(shortHash("abc")).toBe("abc");
    expect(shortHash(null)).toBe("");
  });
});
