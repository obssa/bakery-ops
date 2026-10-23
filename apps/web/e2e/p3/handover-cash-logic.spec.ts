import { expect, test } from "@playwright/test";
import type { CashBlock, CashChannelRow, VarianceReason } from "../../src/lib/api/types";
import { cashBody, cashReview, channelsBody, parseTally, signedMoney, tallyKeys, tallyOf, tallyTotal, toBank } from "../../src/lib/cash";
import { CASH_POLL, cashSpec } from "../../src/lib/live/cash";

// Step 3.7d, the parts of the till's cash screens that are plain logic (no browser, no API): the total of a tally by denomination (string
// arithmetic, the worked table of the plan's section 10.5), the variance and what goes to the bank, what the review says before a
// drawer count is handed in, and the body of the declaration. They ride in the p3-handover project (the name matches).

const reasons: VarianceReason[] = [
  { id: 1, code: "miscount", name_en: "Miscount", name_am: "የቆጠራ ስህተት", requires_note: false, is_active: true, sort: 1 },
  { id: 2, code: "other", name_en: "Other", name_am: "ሌላ", requires_note: true, is_active: true, sort: 2 },
];

const ZERO = { "200": 0, "100": 0, "50": 0, "10": 0, "5": 0, "1": 0, "0.50": 0, "0.25": 0, "0.10": 0, "0.05": 0 };

function channel(patch: Partial<CashChannelRow> = {}): CashChannelRow {
  return {
    code: "cash",
    name_en: "Cash",
    name_am: "ጥሬ ገንዘብ",
    expected_receipts: 3,
    expected_sum: "225.50",
    declared_receipts: null,
    declared_sum: null,
    difference_sum: null,
    ...patch,
  };
}

function cash(patch: Partial<CashBlock> = {}): CashBlock {
  return {
    denominations: { ...ZERO, "100": 5 },
    counted_total: "500.00",
    expected_total: "500.00",
    variance_total: "0.00",
    carried_float_total: null,
    cash_sales_total: null,
    float_left_total: null,
    channels: [],
    confirmed: true,
    reason: null,
    note: null,
    currency: "ETB",
    ...patch,
  };
}

test.describe("p3 cash screens: logic", () => {
  test("the denominations are listed largest first, with a count typed as digits only (empty means zero)", () => {
    expect(tallyKeys(ZERO)).toEqual(["200", "100", "50", "10", "5", "1", "0.50", "0.25", "0.10", "0.05"]);
    expect(parseTally("")).toBe(0);
    expect(parseTally(" 3 ")).toBe(3);
    expect(parseTally("0")).toBe(0);
    expect(parseTally("1000000")).toBe(1_000_000);
    expect(parseTally("1000001")).toBeNull();
    expect(parseTally("2.5")).toBeNull();
    expect(parseTally("-1")).toBeNull();
    expect(parseTally("abc")).toBeNull();
  });

  test("the total of a tally is exact, to the plan's worked table (case 1 and 4)", () => {
    expect(tallyTotal({ ...tallyOf(ZERO), "100": "5" })).toBe("500.00");
    expect(tallyTotal({ ...tallyOf(ZERO), "200": "3", "100": "1", "10": "2", "5": "1", "0.50": "1" })).toBe("725.50");
    expect(tallyTotal(tallyOf(ZERO))).toBe("0.00");
    // coins add without float drift
    expect(tallyTotal({ ...tallyOf(ZERO), "0.10": "3", "0.05": "1" })).toBe("0.35");
    expect(tallyTotal({ ...tallyOf(ZERO), "100": "x" })).toBeNull();
  });

  test("tallyOf turns the server's counts into the fields' text, zero as an empty field", () => {
    expect(tallyOf({ ...ZERO, "200": 2, "0.50": 3 })).toMatchObject({ "200": "2", "0.50": "3", "100": "" });
  });

  test("money differences read with a sign and two decimals; what goes to the bank is counted minus the float", () => {
    expect(signedMoney("-25.50")).toBe("-25.50");
    expect(signedMoney("12.5")).toBe("+12.50");
    expect(signedMoney("0.00")).toBe("0.00");
    expect(signedMoney(null)).toBe("");
    expect(toBank("725.50", "200.00")).toBe("525.50");
    expect(toBank("725.50", null)).toBeNull();
  });

  test("an opening count with no variance is ready as soon as it is declared; an undeclared drawer is not", () => {
    expect(cashReview("opening", cash(), reasons).ready).toBe(true);
    const undeclared = cashReview("opening", cash({ confirmed: false }), reasons);
    expect(undeclared.missingDeclaration).toBe(true);
    expect(undeclared.ready).toBe(false);
    // an opening count never asks for the float left
    expect(cashReview("opening", cash(), reasons).missingFloat).toBe(false);
  });

  test("a cash variance needs a reason, and the reason's note when it asks for one (case 2: 700.00 against 725.50)", () => {
    const short = cash({ counted_total: "700.00", expected_total: "725.50", variance_total: "-25.50", float_left_total: "100.00" });
    const none = cashReview("closing", short, reasons);
    expect(none.differs).toBe(true);
    expect(none.missingReason).toBe(true);
    expect(none.ready).toBe(false);
    const noNote = cashReview("closing", { ...short, reason: reasons[1] }, reasons);
    expect(noNote.missingReason).toBe(false);
    expect(noNote.missingNote).toBe(true);
    expect(noNote.ready).toBe(false);
    expect(cashReview("closing", { ...short, reason: reasons[1], note: "Change given twice" }, reasons).ready).toBe(true);
    expect(cashReview("closing", { ...short, reason: reasons[0] }, reasons).ready).toBe(true);
  });

  test("a closing needs the float left, at most what was counted; an empty drawer is 0.00", () => {
    const base = cash({ counted_total: "725.50", expected_total: "725.50" });
    expect(cashReview("closing", base, reasons).missingFloat).toBe(true);
    expect(cashReview("closing", base, reasons).ready).toBe(false);
    expect(cashReview("closing", { ...base, float_left_total: "800.00" }, reasons).floatTooLarge).toBe(true);
    expect(cashReview("closing", { ...base, float_left_total: "800.00" }, reasons).ready).toBe(false);
    expect(cashReview("closing", { ...base, float_left_total: "200.00" }, reasons).ready).toBe(true);
    const empty = cash({ denominations: { ...ZERO }, counted_total: "0.00", expected_total: "0.00", float_left_total: "0.00" });
    expect(cashReview("closing", empty, reasons).ready).toBe(true);
  });

  test("a channel differs only where something was declared; a difference needs the same reason", () => {
    const base = cash({ counted_total: "225.50", expected_total: "225.50", float_left_total: "0.00" });
    const undeclared = cashReview("closing", { ...base, channels: [channel()] }, reasons);
    expect(undeclared.channelDiffs).toEqual([]);
    expect(undeclared.ready).toBe(true);
    const declared = channel({ declared_receipts: 3, declared_sum: "200.00", difference_sum: "-25.50" });
    const review = cashReview("closing", { ...base, channels: [declared] }, reasons);
    expect(review.channelDiffs.map((row) => row.code)).toEqual(["cash"]);
    expect(review.needsReason).toBe(true);
    expect(review.missingReason).toBe(true);
    expect(cashReview("closing", { ...base, channels: [declared], reason: reasons[0] }, reasons).ready).toBe(true);
    const matching = channel({ declared_receipts: 3, declared_sum: "225.50", difference_sum: "0.00" });
    expect(cashReview("closing", { ...base, channels: [matching] }, reasons).needsReason).toBe(false);
  });

  test("the channel fields become the body: both filled and valid are sent, both empty are left out, half filled are named", () => {
    const sent = channelsBody({ cash: { receipts: "3", sum: "225.50" }, telebirr: { receipts: "", sum: "" }, cbe: { receipts: "2", sum: "" } });
    expect(sent.rows).toEqual([{ code: "cash", receipts: 3, sum: "225.50" }]);
    expect(sent.incomplete).toEqual(["cbe"]);
    expect(channelsBody({ cash: { receipts: "1.5", sum: "10" } }).incomplete).toEqual(["cash"]);
    expect(channelsBody({ cash: { receipts: "1", sum: "10.123" } }).incomplete).toEqual(["cash"]);
    expect(channelsBody({ cash: { receipts: "1", sum: "10.5" } }).rows).toEqual([{ code: "cash", receipts: 1, sum: "10.5" }]);
  });

  test("a declaration body always carries the whole count; only the parts that changed ride along", () => {
    const tally = { ...tallyOf(ZERO), "200": "3", "100": "1" };
    expect(cashBody(tally, {})).toEqual({ denominations: { ...ZERO, "200": 3, "100": 1 } });
    expect(cashBody(tally, { float_left_total: "100.00" })).toEqual({ denominations: { ...ZERO, "200": 3, "100": 1 }, float_left_total: "100.00" });
    expect(cashBody(tally, { reason_id: null, reason_note: "x" })).toMatchObject({ reason_id: null, reason_note: "x" });
    expect(cashBody({ ...tally, "50": "x" }, {})).toBeNull();
  });

  test("the till widget feed replaces its state with each answer and polls like the other shift feeds", () => {
    const answer = {
      opening_total: "500.00",
      carried_float_total: "500.00",
      cash_sales_total: "100.00",
      expected_total: "600.00",
      by_channel: [{ code: "cash", name_en: "Cash", name_am: "ጥሬ ገንዘብ", receipts: 2, sum: "100.00" }],
      currency: "ETB",
    };
    const next = cashSpec.merge(cashSpec.initial, answer);
    expect(next.summary).toEqual(answer);
    expect(cashSpec.cursorOf(next)).toBeNull();
    expect(CASH_POLL.intervalMs).toBe(5000);
  });
});
