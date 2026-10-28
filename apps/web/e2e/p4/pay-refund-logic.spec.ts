import { expect, test } from "@playwright/test";
import type { Line, PaymentView } from "../../src/lib/api/types";
import { ApiError } from "../../src/lib/api/client";
import { resolveDeepLink } from "../../src/lib/deeplink";
import { balanceView, ENTRY_KINDS, kindLabelKey } from "../../src/lib/orderRecord";
import { keepsKey } from "../../src/lib/paySheet";
import {
  allocationBody,
  allocationIssue,
  initialChoice,
  lifoSplit,
  needsReference,
  openAmount,
  refundAmount,
  refundBlock,
  refundField,
  refundLineViews,
  refundResultView,
  refundSettingsBody,
  refundSettingsOf,
  REFUND_REASON_MIN,
  requestLines,
  restockAllowed,
  sharesFromInputs,
  toggleLine,
  wholeOnly,
} from "../../src/lib/refund";
import amMessages from "../../messages/am.json";
import enMessages from "../../messages/en.json";

// Phase 4 project p4-pay-photo-split (the name matches /pay/), step 4.5g: the refund dialog's rules as plain logic (no browser, no API).
//
// Ramin's rule (R-C, 2026-10-28): the admin decides per product whether it may be refunded (`refundable`) and whether a refunded unit goes
// back into stock (`refund_restock`); a refund follows those settings, so the dialog only chooses lines. The money back is each line's
// total (the order's total when every charged line comes back); the payments give it back last in, first out unless the person shares it
// out by hand (`allocation`), each share within what that payment still holds and all of them adding up to the refund. A digital share
// needs the transfer's reference. Which field an API refusal points at, the result, the timeline's two refund kinds, the admin's two
// toggles and the inbox's link follow.

let nextLine = 1;

function line(fields: Partial<Line> = {}): Line {
  const id = fields.id ?? nextLine++;
  return {
    id,
    client_uuid: `l-${id}`,
    item: { id: 10, name_en: "Cake slice", name_am: "የኬክ ቁራጭ" },
    qty: "1.0000",
    unit_price: "60.00",
    modifier_price: "0.00",
    modifiers: [],
    line_total: "60.00",
    station: null,
    status: "served",
    recipe_version: null,
    accepted_by: null,
    accepted_at: null,
    prepared_by: null,
    ready_at: null,
    served_by: null,
    served_at: null,
    rejected: null,
    notes: null,
    refunded_at: null,
    version: 1,
    ...fields,
  };
}

function payment(id: number, fields: Partial<PaymentView> = {}): PaymentView {
  return {
    id,
    order_id: 50,
    client_uuid: `p-${id}`,
    channel: { id: 1, code: "cash", name_en: "Cash", name_am: "ጥሬ ገንዘብ" },
    amount: "50.00",
    tendered_amount: null,
    change_amount: null,
    currency: "ETB",
    reference_no: null,
    status: "recorded",
    received_at: "2026-10-28T08:00:00+00:00",
    received_by: { id: 4, name: "Cashier Hana" },
    shift_id: 1,
    batch_uuid: null,
    receipt: null,
    verified_at: null,
    disputed_at: null,
    dispute_reason: null,
    refunded_amount: "0.00",
    void_reason: null,
    voided_at: null,
    flags: { duplicate_reference: false, duplicate_receipt: false },
    ...fields,
  };
}

const telebirr = { id: 2, code: "telebirr", name_en: "Telebirr", name_am: "ቴሌብር" };

function apiError(status: number, key: string, extra: Record<string, unknown> = {}): ApiError {
  return new ApiError(status, { message_key: key, message_en: key, message_am: key, ...extra });
}

test.describe("refund logic", () => {
  test("which lines can be chosen: refundable products only, a refunded or never-made line never, and what happens to the stock", () => {
    const settings = refundSettingsOf([
      { id: 10, refundable: true, refund_restock: true },
      { id: 11, refundable: true, refund_restock: false },
      { id: 12, refundable: false, refund_restock: false },
    ]);
    const lines = [
      line({ id: 1, item: { id: 10, name_en: "Cake slice", name_am: "የኬክ ቁራጭ" } }),
      line({ id: 2, item: { id: 11, name_en: "Burger", name_am: "በርገር" }, line_total: "90.00" }),
      line({ id: 3, item: { id: 12, name_en: "Water", name_am: "ውሃ" }, line_total: "30.00" }),
      line({ id: 4, item: { id: 10, name_en: "Cake slice", name_am: "የኬክ ቁራጭ" }, refunded_at: "2026-10-28T09:00:00+00:00" }),
      line({ id: 5, item: { id: 11, name_en: "Burger", name_am: "በርገር" }, status: "rejected" }),
      line({ id: 6, item: { id: 11, name_en: "Burger", name_am: "በርገር" }, status: "voided" }),
      // a product the catalog no longer lists (deleted): the API decides, the dialog cannot say what happens to its stock
      line({ id: 7, item: { id: 99, name_en: "Old pastry", name_am: "የቆየ ኬክ" }, line_total: "20.00" }),
    ];
    const views = refundLineViews(lines, settings);
    expect(views.map((v) => [v.id, v.state, v.restock])).toEqual([
      [1, "open", true],
      [2, "open", false],
      [3, "not_refundable", false],
      [4, "refunded", true],
      [5, "not_charged", false],
      [6, "not_charged", false],
      [7, "open", null],
    ]);
    // before the catalog is read nothing is known of any product: every charged line that is not refunded may be chosen, stock unknown
    expect(refundLineViews(lines.slice(0, 3), null).map((v) => [v.state, v.restock])).toEqual([
      ["open", null],
      ["open", null],
      ["open", null],
    ]);
    // an item read without the two fields (an older answer) is not known either
    expect(refundSettingsOf([{ id: 10 }]).has(10)).toBe(false);
    expect([...initialChoice(views)].sort()).toEqual([1, 2, 7]);
  });

  test("chosen lines give the amount, the whole order its total; the request names the lines only when it is not the whole order", () => {
    const settings = refundSettingsOf([
      { id: 10, refundable: true, refund_restock: true },
      { id: 11, refundable: true, refund_restock: false },
      { id: 12, refundable: false, refund_restock: false },
    ]);
    const a = line({ id: 1, item: { id: 10, name_en: "a", name_am: "ሀ" }, line_total: "60.00" });
    const b = line({ id: 2, item: { id: 11, name_en: "b", name_am: "ለ" }, line_total: "90.50" });
    const c = line({ id: 3, item: { id: 12, name_en: "c", name_am: "ሐ" }, line_total: "30.00" });
    const rejected = line({ id: 4, item: { id: 11, name_en: "b", name_am: "ለ" }, line_total: "90.50", status: "rejected" });

    // every line refundable: all of them is the order's total, and no `lines` (the whole order)
    const all = refundLineViews([a, b, rejected], settings);
    expect(refundAmount(all, new Set([1, 2]), "150.50")).toBe("150.50");
    expect(requestLines(all, new Set([1, 2]))).toBeUndefined();
    expect(refundAmount(all, new Set([2]), "150.50")).toBe("90.50");
    expect(requestLines(all, new Set([2]))).toEqual([2]);
    expect(refundAmount(all, new Set(), "150.50")).toBe("0.00");
    expect(requestLines(all, new Set())).toEqual([]);

    // a non-refundable line stays: every refundable line is the sum of their totals, still sent as the whole order (no `lines`)
    const mixed = refundLineViews([a, b, c], settings);
    expect(refundAmount(mixed, new Set([1, 2]), "180.50")).toBe("150.50");
    expect(requestLines(mixed, new Set([1, 2]))).toBeUndefined();
    // a line that is not open is never counted, even if a stale choice still holds it
    expect(refundAmount(mixed, new Set([1, 3]), "180.50")).toBe("60.00");
    expect(requestLines(mixed, new Set([3, 1]))).toEqual([1]);

    // after a first refund the rest is named line by line (the API refuses a whole-order refund after a line refund)
    const later = refundLineViews([{ ...a, refunded_at: "2026-10-28T09:00:00+00:00" }, b], settings);
    expect(refundAmount(later, new Set([2]), "150.50")).toBe("90.50");
    expect(requestLines(later, new Set([2]))).toEqual([2]);

    // a discount or a tax: only every line at once (R-D), so a tap on one line chooses or clears them all
    expect(wholeOnly({ discount: "0.00", tax: "0.00" })).toBe(false);
    expect(wholeOnly({ discount: "10.00", tax: "0.00" })).toBe(true);
    expect(wholeOnly({ discount: "0.00", tax: "2.50" })).toBe(true);
    const chosen = initialChoice(all);
    expect([...toggleLine(all, chosen, 2, false)]).toEqual([1]);
    expect([...toggleLine(all, new Set([1]), 2, false)].sort()).toEqual([1, 2]);
    expect([...toggleLine(all, chosen, 2, true)]).toEqual([]);
    expect([...toggleLine(all, new Set(), 2, true)].sort()).toEqual([1, 2]);
    // a line that cannot be chosen is never added
    expect([...toggleLine(mixed, new Set([1]), 3, false)]).toEqual([1]);
  });

  test("the money goes back last in, first out, each payment within what it still holds", () => {
    const cash = payment(1, { amount: "50.00", received_at: "2026-10-28T08:00:00+00:00" });
    const wallet = payment(2, { amount: "70.00", channel: telebirr, received_at: "2026-10-28T08:05:00+00:00" });
    const voided = payment(3, { amount: "40.00", status: "voided", received_at: "2026-10-28T08:10:00+00:00" });
    const payments = [cash, wallet, voided];

    expect(openAmount(cash)).toBe("50.00");
    expect(openAmount(payment(4, { amount: "50.00", refunded_amount: "20.00" }))).toBe("30.00");
    expect(openAmount(voided)).toBe("0.00");

    // the plan's example: Telebirr 70 after cash 50, a refund of 40 comes off the Telebirr payment
    expect(lifoSplit(payments, "40.00")).toEqual({ shares: [{ paymentId: 2, amount: "40.00" }], short: "0.00" });
    // 100: the 70 of Telebirr first, then 30 of the cash
    expect(lifoSplit(payments, "100.00")).toEqual({
      shares: [
        { paymentId: 2, amount: "70.00" },
        { paymentId: 1, amount: "30.00" },
      ],
      short: "0.00",
    });
    // what was paid back already is not paid back again; more than the payments hold is short by the rest
    const partly = [cash, { ...wallet, refunded_amount: "60.00" }];
    expect(lifoSplit(partly, "30.00").shares).toEqual([
      { paymentId: 2, amount: "10.00" },
      { paymentId: 1, amount: "20.00" },
    ]);
    expect(lifoSplit(partly, "75.00").short).toBe("15.00");
    // the same second: the higher id was taken last; an offset does not change the order
    const sameSecond = [payment(7, { received_at: "2026-10-28T11:00:00+03:00" }), payment(6, { received_at: "2026-10-28T08:00:00+00:00" })];
    expect(lifoSplit(sameSecond, "60.00").shares.map((s) => s.paymentId)).toEqual([7, 6]);
  });

  test("a share-out by hand: each payment within what it holds, named once, more than zero, adding up to the refund", () => {
    const cash = payment(1, { amount: "50.00" });
    const wallet = payment(2, { amount: "70.00", channel: telebirr, received_at: "2026-10-28T08:05:00+00:00" });
    const voided = payment(3, { amount: "40.00", status: "voided" });
    const payments = [cash, wallet, voided];

    // typed amounts, blank and zero ones dropped, in the order the payments are listed
    const shares = sharesFromInputs({ 2: " 10.00 ", 1: "30", 3: "" }, [2, 1, 3]);
    expect(shares).toEqual([
      { paymentId: 2, amount: "10.00" },
      { paymentId: 1, amount: "30" },
    ]);
    expect(sharesFromInputs({ 1: "0", 2: "0.00" }, [1, 2])).toEqual([]);
    expect(allocationIssue(payments, shares, "40.00")).toBeNull();
    expect(allocationIssue(payments, shares, "45.00")).toEqual({ reason: "sum", paymentId: null });
    expect(allocationIssue(payments, [{ paymentId: 1, amount: "60.00" }], "60.00")).toEqual({ reason: "exceeds", paymentId: 1 });
    expect(allocationIssue(payments, [{ paymentId: 3, amount: "10.00" }], "10.00")).toEqual({ reason: "payment", paymentId: 3 });
    expect(allocationIssue(payments, [{ paymentId: 9, amount: "10.00" }], "10.00")).toEqual({ reason: "payment", paymentId: 9 });
    expect(
      allocationIssue(
        payments,
        [
          { paymentId: 1, amount: "5.00" },
          { paymentId: 1, amount: "5.00" },
        ],
        "10.00",
      ),
    ).toEqual({ reason: "repeated", paymentId: 1 });
    expect(allocationIssue(payments, [{ paymentId: 1, amount: "1.234" }], "1.23")).toEqual({ reason: "amount", paymentId: 1 });
    expect(allocationIssue(payments, [{ paymentId: 1, amount: "abc" }], "1.00")).toEqual({ reason: "amount", paymentId: 1 });
    expect(allocationIssue(payments, [{ paymentId: 1, amount: "-5" }], "1.00")).toEqual({ reason: "amount", paymentId: 1 });
    expect(allocationIssue(payments, [], "10.00")).toEqual({ reason: "sum", paymentId: null });
    // the default split is not sent (the API makes the same one); a different one is, as the API reads it
    const lifo = lifoSplit(payments, "40.00").shares;
    expect(allocationBody([{ paymentId: 2, amount: "40" }], lifo)).toBeUndefined();
    expect(allocationBody(shares, lifo)).toEqual([
      { payment_id: 2, amount: "10.00" },
      { payment_id: 1, amount: "30.00" },
    ]);
    // a share on a digital channel needs the transfer's reference; cash alone does not
    expect(needsReference(payments, shares)).toBe(true);
    expect(needsReference(payments, [{ paymentId: 1, amount: "40.00" }])).toBe(false);
  });

  test("the refund waits for lines, the money, the reference, the photo, a reason of 3 characters and a PIN, in that order", () => {
    const ready = {
      chosen: 1,
      amount: "60.00",
      short: "0.00",
      allocation: null,
      needsReference: false,
      reference: "",
      photoUploading: false,
      reason: "Customer returned it",
      pin: "1234",
    };
    expect(refundBlock(ready)).toBeNull();
    expect(refundBlock({ ...ready, chosen: 0, amount: "0.00" })).toBe("no_lines");
    expect(refundBlock({ ...ready, amount: "0.00" })).toBe("zero");
    expect(refundBlock({ ...ready, short: "5.00" })).toBe("short");
    expect(refundBlock({ ...ready, allocation: { reason: "sum", paymentId: null } })).toBe("allocation");
    expect(refundBlock({ ...ready, needsReference: true, reference: "  " })).toBe("reference");
    expect(refundBlock({ ...ready, needsReference: true, reference: "TX123456" })).toBeNull();
    expect(refundBlock({ ...ready, photoUploading: true })).toBe("photo");
    expect(REFUND_REASON_MIN).toBe(3);
    expect(refundBlock({ ...ready, reason: " ab " })).toBe("reason");
    expect(refundBlock({ ...ready, reason: "abc" })).toBeNull();
    expect(refundBlock({ ...ready, reason: "x".repeat(256) })).toBe("reason");
    expect(refundBlock({ ...ready, pin: "12" })).toBe("pin");
    expect(refundBlock({ ...ready, pin: "1234567" })).toBe("pin");
    expect(refundBlock({ ...ready, pin: "12a4" })).toBe("pin");
  });

  test("each refusal of the API points at its field, and the key is kept only when the outcome is unknown", () => {
    const cases: [ApiError, string | null][] = [
      [apiError(403, "identity.pin.rejected"), "pin"],
      [apiError(423, "identity.short_code.locked"), "pin"],
      [apiError(403, "orders.refund.approver_forbidden"), "pin"],
      [apiError(422, "orders.refund.reason_required"), "reason"],
      [apiError(422, "orders.refund.line_invalid", { line_id: 3 }), "lines"],
      [apiError(422, "orders.refund.line_not_refundable", { line_id: 3 }), "lines"],
      [apiError(409, "orders.refund.line_refunded", { line_ids: [3] }), "lines"],
      [apiError(422, "orders.refund.partial_not_allowed"), "lines"],
      [apiError(409, "orders.refund.exceeds_paid"), "lines"],
      [apiError(409, "orders.refund.not_refundable", { status: "served" }), "order"],
      [apiError(409, "orders.refund.window_closed", { hours: 72 }), "order"],
      [apiError(409, "orders.refund.client_uuid_reused"), "order"],
      [apiError(422, "payments.refund.allocation_invalid", { field: "allocation", reason: "exceeds", payment_id: 2 }), "allocation"],
      [apiError(422, "payments.refund.reference_required", { field: "refund_reference", channel_code: "telebirr" }), "reference"],
      [apiError(422, "payments.refund.proof_invalid", { field: "refund_proof_file_id", reason: "expired" }), "proof"],
      [apiError(409, "payments.shift.required"), "shift"],
      [apiError(409, "inventory.shift.required"), "shift"],
      [apiError(0, "network"), null],
      [apiError(500, "http.500"), null],
    ];
    for (const [error, field] of cases) expect(refundField(error), error.key).toBe(field);
    expect(refundField(new Error("boom"))).toBeNull();
    // a refusal wrote nothing: the next try is a new request; no answer or a 5xx may have refunded, so the same key replays it
    expect(keepsKey(apiError(403, "identity.pin.rejected"))).toBe(false);
    expect(keepsKey(apiError(409, "orders.refund.window_closed"))).toBe(false);
    expect(keepsKey(apiError(0, "network"))).toBe(true);
    expect(keepsKey(apiError(503, "http.503"))).toBe(true);

    // every allocation reason and every field has words in both languages, different from each other
    const en = enMessages.refund as Record<string, string>;
    const am = amMessages.refund as Record<string, string>;
    for (const reason of ["sum", "payment", "exceeds", "repeated", "amount"]) {
      expect(en[`allocation_${reason}`], reason).toBeTruthy();
      expect(am[`allocation_${reason}`], reason).toBeTruthy();
      expect(am[`allocation_${reason}`]).not.toBe(en[`allocation_${reason}`]);
    }
    for (const block of ["no_lines", "zero", "short", "allocation", "reference", "photo", "reason", "pin"]) {
      expect(en[`block_${block}`], block).toBeTruthy();
      expect(am[`block_${block}`], block).toBeTruthy();
    }
  });

  test("the result names the money per payment and which lines went back to stock", () => {
    const view = refundResultView({
      refund: {
        id: 9,
        order_id: 50,
        amount: "150.00",
        is_full: false,
        reason: "Wrong order",
        requested_by_user_id: 4,
        approved_by_user_id: 7,
        lines: [
          { line_id: 1, item_id: 10, qty: "1.0000", amount: "60.00", disposition: "restock", movement_ids: [31] },
          { line_id: 2, item_id: 11, qty: "1.0000", amount: "90.00", disposition: "waste", movement_ids: [] },
        ],
      },
      payments: [
        { id: 3, payment_id: 2, channel_code: "telebirr", channel_name_en: "Telebirr", channel_name_am: "ቴሌብር", amount: "70.00", reference_no: "TX1", proof_file_id: null, shift_instance_id: 1, refunded_at: "2026-10-28T09:00:00+00:00" },
        { id: 4, payment_id: 1, channel_code: "cash", channel_name_en: "Cash", channel_name_am: "ጥሬ ገንዘብ", amount: "80.00", reference_no: null, proof_file_id: null, shift_instance_id: 1, refunded_at: "2026-10-28T09:00:00+00:00" },
      ],
      movements: [{ id: 31, item_id: 10, location_id: 5, qty: "1.0000", reverses: 20 }],
      order: { id: 50, status: "closed", total: "180.00", paid: "180.00", balance: "0.00", refunded: "150.00", net_paid: "30.00" },
    });
    expect(view.amount).toBe("150.00");
    expect(view.restocked).toEqual([1]);
    expect(view.notRestocked).toEqual([2]);
    expect(view.payments.map((p) => [p.paymentId, p.channelCode, p.amount])).toEqual([
      [2, "telebirr", "70.00"],
      [1, "cash", "80.00"],
    ]);
    expect(view.netPaid).toBe("30.00");
  });

  test("the admin's two settings: back to stock only for a product sold from stock or bought in; made to order sends it off", () => {
    expect(restockAllowed("product", "to_stock")).toBe(true);
    expect(restockAllowed("product", "bought_in")).toBe(true);
    expect(restockAllowed("product", "to_order")).toBe(false);
    expect(restockAllowed("ingredient", "to_stock")).toBe(false);
    expect(refundSettingsBody("product", "to_stock", true, true)).toEqual({ refundable: true, refund_restock: true });
    expect(refundSettingsBody("product", "to_order", true, true)).toEqual({ refundable: true, refund_restock: false });
    expect(refundSettingsBody("ingredient", "to_stock", false, true)).toEqual({ refundable: false, refund_restock: false });
    expect(refundSettingsBody("product", "bought_in", false, true)).toEqual({ refundable: false, refund_restock: true });
  });

  test("the record: the refund's two kinds name the approver; the header shows what came back; the inbox's refund line opens the record", () => {
    expect(ENTRY_KINDS["line.refunded"]).toEqual({ tone: "amber", approved: true });
    expect(ENTRY_KINDS["order.refunded"]).toEqual({ tone: "amber", approved: true });
    expect(kindLabelKey("line.refunded")).toBe("ev_line_refunded");
    expect(kindLabelKey("order.refunded")).toBe("ev_order_refunded");

    // a refund leaves paid and the balance as they were: what came back and what the order keeps are beside them
    const totals = { total: "180.00", paid: "180.00", balance: "0.00", refunded: "150.00", net_paid: "30.00" };
    const payments = [
      { amount: "110.00", status: "recorded" as const, refunded_amount: "80.00" },
      { amount: "70.00", status: "recorded" as const, refunded_amount: "70.00" },
    ];
    const view = balanceView(totals, payments, "0.00");
    expect(view.state).toBe("settled");
    expect(view.refunded).toBe("150.00");
    expect(view.netPaid).toBe("30.00");
    expect(view.inStep).toBe(true);
    // the payments' own paid-back amounts must agree with the order's
    expect(balanceView({ ...totals, refunded: "60.00", net_paid: "120.00" }, payments, "0.00").inStep).toBe(false);
    // an order never refunded (or an answer without the fields) reads as nothing back
    const plain = balanceView({ total: "180.00", paid: "180.00", balance: "0.00" }, [{ amount: "180.00", status: "recorded" }], "0.00");
    expect(plain.refunded).toBe("0.00");
    expect(plain.netPaid).toBe("180.00");
    expect(plain.inStep).toBe(true);

    // NotifyRefund (step 4.3d) links to the record of the order
    expect(resolveDeepLink("/admin/orders/record?id=77")).toEqual({ pathname: "/admin/orders/record", query: { id: "77" } });
  });
});
