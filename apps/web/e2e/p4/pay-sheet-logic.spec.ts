import { expect, test } from "@playwright/test";
import type { PaymentChannel } from "../../src/lib/api/types";
import { fitWithin, MAX_EDGE, QUALITY } from "../../src/lib/images/downscale";
import {
  allocated,
  batchRows,
  blockReason,
  canComplete,
  canUseChannel,
  changeDue,
  checkReference,
  keepsKey,
  MAX_ROWS,
  moneyOf,
  newRow,
  NO_PHOTO,
  remaining,
  restOf,
  rowIssue,
  rowsComplete,
  sheetChannels,
  type PayRow,
  type SheetState,
} from "../../src/lib/paySheet";

// Step 4.5b, the pay sheet's rules as plain logic (no browser, no API): which channels the sheet offers and in what order, the amounts on
// decimal strings (never a float), the change of a cash row, the live reference check (a convenience: the server's ReferencePattern is the
// judge), when a row is complete, why Complete is disabled and the body it posts. They ride in the p4-pay-photo-split project (the name
// matches /pay/).

function channel(patch: Partial<PaymentChannel> & { code: string }): PaymentChannel {
  return {
    id: patch.sort ?? 1,
    name_en: patch.code,
    name_am: patch.code,
    is_enabled: true,
    requires_photo: false,
    requires_reference: false,
    sort: 1,
    accepted_now: true,
    reference_pattern: null,
    reference_hint_en: null,
    reference_hint_am: null,
    photo_hint_en: null,
    photo_hint_am: null,
    is_cash: patch.code === "cash",
    deleted_at: null,
    ...patch,
  };
}

const CASH = channel({ code: "cash", name_en: "Cash", name_am: "ጥሬ ገንዘብ", sort: 3 });
const WALLET = channel({
  code: "wallet",
  name_en: "Wallet",
  sort: 1,
  requires_photo: true,
  requires_reference: true,
  reference_pattern: "[A-Z]{2}[0-9]{6}",
  reference_hint_en: "Two letters and six digits, as TX123456",
  reference_hint_am: "ሁለት ፊደላትና ስድስት አሃዞች፣ ለምሳሌ TX123456",
});
const BANK = channel({ code: "bank", name_en: "Bank", sort: 2, requires_reference: true });
const OFF = channel({ code: "off", sort: 4, is_enabled: false, accepted_now: false });
const CHANNELS = [WALLET, BANK, CASH, OFF];

function row(patch: Partial<PayRow> = {}): PayRow {
  return { id: "r1", channel: "cash", amount: "250.00", tendered: "", reference: "", photo: NO_PHOTO, ...patch };
}

const uploaded = { status: "uploaded" as const, fileId: 41, clientUuid: "f-1", key: "k-1" };

function state(patch: Partial<SheetState> = {}): SheetState {
  return { balance: "250.00", rows: [row({ tendered: "250" })], channels: CHANNELS, shift: null, online: true, ...patch };
}

test.describe("p4 pay sheet: logic", () => {
  test("the sheet offers the enabled channels in the admin's order, with cash first", () => {
    expect(sheetChannels(CHANNELS).map((c) => c.code)).toEqual(["cash", "wallet", "bank"]);
    // no cash channel at all (a branch that switched it off cannot, but the list must not break)
    expect(sheetChannels([WALLET, BANK]).map((c) => c.code)).toEqual(["wallet", "bank"]);
    // the admin's order is the sort, whatever order the list came in
    expect(sheetChannels([BANK, CASH, WALLET]).map((c) => c.code)).toEqual(["cash", "wallet", "bank"]);
  });

  test("a typed amount is a money string with two decimals or nothing; never a float", () => {
    expect(moneyOf("250")).toBe("250.00");
    expect(moneyOf("12.5")).toBe("12.50");
    expect(moneyOf(" 0.05 ")).toBe("0.05");
    expect(moneyOf("0.1")).toBe("0.10");
    expect(moneyOf("")).toBeNull();
    expect(moneyOf("12.")).toBeNull();
    expect(moneyOf("1.234")).toBeNull();
    expect(moneyOf("-5")).toBeNull();
    expect(moneyOf("1e3")).toBeNull();
    expect(moneyOf("12,50")).toBeNull();
    expect(moneyOf("12345678901")).toBeNull(); // more than the API's ten whole digits
    // the classic float trap stays exact: 0.1 + 0.2 is 0.30
    expect(allocated([row({ amount: "0.1" }), row({ id: "r2", amount: "0.2" })])).toBe("0.30");
  });

  test("the rest to pay is the balance minus every valid amount, and may go below zero", () => {
    expect(remaining("250.00", [row({ amount: "70" })])).toBe("180.00");
    expect(remaining("250.00", [row({ amount: "70" }), row({ id: "r2", channel: "wallet", amount: "180.00" })])).toBe("0.00");
    expect(remaining("250.00", [row({ amount: "300" })])).toBe("-50.00");
    // an invalid or zero amount counts for nothing
    expect(remaining("250.00", [row({ amount: "abc" }), row({ id: "r2", amount: "0" })])).toBe("250.00");
    expect(remaining("250.00", [])).toBe("250.00");
  });

  test("a new row takes the rest of the balance, and cash when no other row has it", () => {
    const first = newRow("r1", "250.00", [], CHANNELS);
    expect(first).toMatchObject({ id: "r1", channel: "cash", amount: "250.00", tendered: "", reference: "", photo: NO_PHOTO });
    const second = newRow("r2", "250.00", [row({ amount: "70.00" })], CHANNELS);
    expect(second).toMatchObject({ channel: null, amount: "180.00" });
    // nothing left: the amount is empty, to be typed
    expect(newRow("r2", "250.00", [row()], CHANNELS).amount).toBe("");
    // a branch whose till lists no cash channel starts with no channel picked
    expect(newRow("r1", "250.00", [], [WALLET]).channel).toBeNull();
  });

  test("the rest of the balance for one row makes the sheet add up with that row", () => {
    const rows = [row({ amount: "250.00" }), row({ id: "r2", channel: "wallet", amount: "180" })];
    expect(restOf("250.00", rows, 0)).toBe("70.00");
    expect(restOf("250.00", [row({ amount: "" }), row({ id: "r2", channel: "wallet", amount: "180" })], 0)).toBe("70.00");
    // nothing can be left for a row when the others already cover the balance
    expect(restOf("250.00", [row({ amount: "10" }), row({ id: "r2", amount: "250" })], 0)).toBeNull();
  });

  test("cash may be picked by one row only; any other channel by several", () => {
    const rows = [row(), row({ id: "r2", channel: "wallet", amount: "" })];
    expect(canUseChannel(rows, 1, CASH)).toBe(false);
    expect(canUseChannel(rows, 0, CASH)).toBe(true);
    expect(canUseChannel(rows, 0, WALLET)).toBe(true);
    expect(canUseChannel([...rows, row({ id: "r3", channel: null })], 2, WALLET)).toBe(true);
  });

  test("the change of a cash row is what was handed over minus its amount, and none while it does not cover it", () => {
    expect(changeDue(row({ amount: "70.00", tendered: "100" }))).toBe("30.00");
    expect(changeDue(row({ amount: "70.00", tendered: "70" }))).toBe("0.00");
    expect(changeDue(row({ amount: "70.00", tendered: "69.99" }))).toBeNull();
    expect(changeDue(row({ amount: "70.00", tendered: "" }))).toBeNull();
    expect(changeDue(row({ amount: "abc", tendered: "100" }))).toBeNull();
    expect(changeDue(row({ channel: "wallet", amount: "70.00", tendered: "100" }))).toBeNull();
    // the keypad's half-typed "100." still reads as 100
    expect(changeDue(row({ amount: "70.00", tendered: "100." }))).toBe("30.00");
  });

  test("the reference is checked live against the channel's pattern; the server stays the judge", () => {
    expect(checkReference(WALLET, "")).toBe("missing");
    expect(checkReference(WALLET, "   ")).toBe("missing");
    expect(checkReference(WALLET, "TX123456")).toBe("ok");
    expect(checkReference(WALLET, " TX123456 ")).toBe("ok"); // trimmed, as the API trims it
    expect(checkReference(WALLET, "tx123456")).toBe("invalid"); // case-sensitive, as the server matches it
    expect(checkReference(WALLET, "TX1234567")).toBe("invalid"); // the whole reference must match, not a part
    expect(checkReference(WALLET, "XTX123456")).toBe("invalid");
    // no pattern: any non-empty text, at most 64 characters (the API refuses a longer one without matching it)
    expect(checkReference(BANK, "anything 1")).toBe("ok");
    expect(checkReference(BANK, "x".repeat(64))).toBe("ok");
    expect(checkReference(BANK, "x".repeat(65))).toBe("invalid");
    // a channel that asks for no reference needs none
    expect(checkReference(CASH, "")).toBe("ok");
    // a pattern this browser cannot compile is left to the server: never a block here
    expect(checkReference(channel({ code: "odd", requires_reference: true, reference_pattern: "(?<=a)b(" }), "zz")).toBe("ok");
    // an escape the strict (u) mode refuses still checks in the plain mode
    expect(checkReference(channel({ code: "dash", requires_reference: true, reference_pattern: "\\d{4}\\-\\d{4}" }), "1234-5678")).toBe("ok");
    expect(checkReference(channel({ code: "dash", requires_reference: true, reference_pattern: "\\d{4}\\-\\d{4}" }), "1234_5678")).toBe("invalid");
  });

  test("a row is complete with a channel, an amount above zero, the cash that covers it, a valid reference and an uploaded photo", () => {
    expect(rowIssue(row({ channel: null }), undefined)).toBe("channel");
    expect(rowIssue(row({ channel: "gone" }), undefined)).toBe("channel");
    expect(rowIssue(row({ amount: "0", tendered: "1" }), CASH)).toBe("amount");
    expect(rowIssue(row({ amount: "", tendered: "1" }), CASH)).toBe("amount");
    expect(rowIssue(row({ tendered: "" }), CASH)).toBe("cash_short");
    expect(rowIssue(row({ tendered: "249.99" }), CASH)).toBe("cash_short");
    expect(rowIssue(row({ tendered: "300" }), CASH)).toBeNull();

    const wallet = row({ channel: "wallet", amount: "180.00" });
    expect(rowIssue(wallet, WALLET)).toBe("reference_missing");
    expect(rowIssue({ ...wallet, reference: "TX12" }, WALLET)).toBe("reference_invalid");
    expect(rowIssue({ ...wallet, reference: "TX123456" }, WALLET)).toBe("photo_missing");
    expect(rowIssue({ ...wallet, reference: "TX123456", photo: { ...uploaded, status: "uploading", fileId: null } }, WALLET)).toBe("photo_uploading");
    expect(rowIssue({ ...wallet, reference: "TX123456", photo: { ...uploaded, status: "failed", fileId: null } }, WALLET)).toBe("photo_failed");
    expect(rowIssue({ ...wallet, reference: "TX123456", photo: uploaded }, WALLET)).toBeNull();
    // a digital channel needs no cash handed over
    expect(rowIssue(row({ channel: "bank", amount: "10", reference: "B-1" }), BANK)).toBeNull();

    expect(rowsComplete([row({ tendered: "300" })], CHANNELS)).toBe(true);
    expect(rowsComplete([row({ tendered: "300" }), wallet], CHANNELS)).toBe(false);
    expect(rowsComplete([], CHANNELS)).toBe(false);
  });

  test("Complete waits for the shift, every row and amounts that add up to exactly the balance (not for the connection: the outbox)", () => {
    expect(blockReason(state())).toBeNull();
    expect(canComplete(state())).toBe(true);

    // the till's shift comes first: nothing can be taken without it (every channel since step 4.3b)
    expect(blockReason(state({ shift: "no_shift" }))).toEqual({ kind: "shift", reason: "no_shift" });
    expect(blockReason(state({ shift: "not_open" }))).toEqual({ kind: "shift", reason: "not_open" });
    // offline is no block since step 5.2a: the completed batch joins the outbox (Phase 5 plan 10.2 D10)
    expect(blockReason(state({ online: false }))).toBeNull();
    expect(blockReason(state({ balance: "0.00" }))).toEqual({ kind: "nothing_due" });
    expect(blockReason(state({ rows: [] }))).toEqual({ kind: "no_rows" });
    const seven = Array.from({ length: MAX_ROWS + 1 }, (_, i) => row({ id: `r${i}`, channel: "bank", amount: "1", reference: "x" }));
    expect(blockReason(state({ rows: seven }))).toEqual({ kind: "too_many_rows" });

    // the first row with something missing, by its index
    const split = [row({ amount: "70.00", tendered: "100" }), row({ id: "r2", channel: "wallet", amount: "180.00", reference: "TX123456" })];
    expect(blockReason(state({ rows: split }))).toEqual({ kind: "row", row: 1, issue: "photo_missing" });
    split[1] = { ...split[1], photo: uploaded };
    expect(blockReason(state({ rows: split }))).toBeNull();

    // short of the balance, or over it, by how much
    expect(blockReason(state({ rows: [row({ amount: "200", tendered: "200" })] }))).toEqual({ kind: "short", amount: "50.00" });
    expect(blockReason(state({ rows: [row({ amount: "260", tendered: "300" })] }))).toEqual({ kind: "over", amount: "10.00" });
    expect(canComplete(state({ rows: [row({ amount: "260", tendered: "300" })] }))).toBe(false);
  });

  test("the body carries each row's own id, the amount as a money string, cash's tender, a required reference and photo only", () => {
    const rows: PayRow[] = [
      row({ id: "a", amount: "70", tendered: "100.", reference: "ignored", photo: uploaded }),
      row({ id: "b", channel: "wallet", amount: "180", reference: " TX123456 ", photo: uploaded }),
      row({ id: "c", channel: "bank", amount: "0.5", reference: "B-9" }),
    ];
    expect(batchRows(rows, CHANNELS)).toEqual([
      { client_uuid: "a", channel_code: "cash", amount: "70.00", tendered_amount: "100.00" },
      { client_uuid: "b", channel_code: "wallet", amount: "180.00", reference_no: "TX123456", receipt_file_id: 41 },
      { client_uuid: "c", channel_code: "bank", amount: "0.50", reference_no: "B-9" },
    ]);
  });

  test("after a failure the Idempotency-Key is kept only when the outcome is unknown", () => {
    // no answer, a server error or the same key still running: the write may have happened, so the retry must replay it
    expect(keepsKey({ status: 0, key: "network" })).toBe(true);
    expect(keepsKey({ status: 500, key: "http.500" })).toBe(true);
    expect(keepsKey({ status: 503, key: "files.unavailable" })).toBe(true);
    expect(keepsKey({ status: 409, key: "idempotency.in_flight" })).toBe(true);
    // a refusal wrote nothing (and would be replayed under the same key): the next try is a new request
    expect(keepsKey({ status: 409, key: "payments.shift.required" })).toBe(false);
    expect(keepsKey({ status: 422, key: "payments.batch.sum_mismatch" })).toBe(false);
    expect(keepsKey({ status: 422, key: "idempotency.key_reused" })).toBe(false);
    expect(keepsKey(new Error("not an API error"))).toBe(true);
  });

  test("the photo is shrunk in the browser to a longest side of 1600 px, never enlarged, as JPEG 0.82", () => {
    expect(MAX_EDGE).toBe(1600);
    expect(QUALITY).toBe(0.82);
    expect(fitWithin(2400, 1800)).toEqual({ width: 1600, height: 1200 });
    expect(fitWithin(1800, 2400)).toEqual({ width: 1200, height: 1600 });
    expect(fitWithin(4000, 3000)).toEqual({ width: 1600, height: 1200 });
    expect(fitWithin(1000, 700)).toEqual({ width: 1000, height: 700 });
    expect(fitWithin(1600, 1600)).toEqual({ width: 1600, height: 1600 });
    expect(fitWithin(3001, 1000)).toEqual({ width: 1600, height: 533 });
    expect(fitWithin(5000, 1)).toEqual({ width: 1600, height: 1 });
  });
});
