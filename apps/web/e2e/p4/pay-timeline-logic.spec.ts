import { expect, test } from "@playwright/test";
import type { Order, OrderEvent, PaymentView } from "../../src/lib/api/types";
import { resolveDeepLink } from "../../src/lib/deeplink";
import {
  balanceView,
  canVoidOrder,
  canVoidPayment,
  ENTRY_KINDS,
  kindLabelKey,
  nameOf,
  peopleOf,
  photoOf,
  receiptAvailable,
  recordEntries,
  recordIdOf,
  recordQuery,
  RECORD_PATH,
  RESIGN_MARGIN_MS,
  SIGNED_URL_LIFE_MS,
  urlsStale,
} from "../../src/lib/orderRecord";
import { referenceTail } from "../../src/lib/paySheet";
import amMessages from "../../messages/am.json";
import enMessages from "../../messages/en.json";

// Phase 4 project p4-pay-photo-split (the name matches /pay/), step 4.5d: the order record page's rules as plain logic (no browser, no API).
// The record merges the order's timeline (GET /orders/{order} `events`, oldest first) with its payments (GET /orders/{order}/payments) into
// one list by time: the timeline already holds payment.recorded and payment.voided since step 4.3c, a payment older than that has only its
// own row, and a verification or a dispute is on the payment row only. Ties keep a fixed order. Who did it comes from the event (the person
// acting at a shared screen, else the account), the approver of a void from the event's approved_by_user_id; names from the people list
// when the viewer may read it, else from the names the page's own rows carry, else "#id". The header's money, the photo's state, when the
// list's signed photo addresses are too old to trust, the voids offered and the receipt follow.

const MANAGER = 7;
const CASHIER = 4;
const WAITER = 3;
const ACCOUNT = 9;

let nextEvent = 100;

function event(kind: string, at: string, fields: Partial<OrderEvent> = {}): OrderEvent {
  return {
    id: ++nextEvent,
    event: kind,
    order_line_id: null,
    station_id: null,
    user_id: WAITER,
    acting_user_id: null,
    approved_by_user_id: null,
    payload: null,
    at,
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
    received_at: "2026-10-27T08:00:00+00:00",
    received_by: { id: CASHIER, name: "Cashier Hana" },
    shift_id: 12,
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

function order(fields: Partial<Order> = {}): Order {
  return {
    id: 50,
    client_uuid: "o-50",
    order_no: 3,
    business_date: "2026-10-27",
    type: "takeaway",
    table_code: null,
    dining_table: null,
    status: "sent",
    created_via: "till",
    owner: { id: WAITER, name: "Waiter Abel" },
    created_by_user_id: WAITER,
    acting_user_id: null,
    notes: null,
    totals: { subtotal: "120.00", discount: "0.00", tax: "0.00", total: "120.00", paid: "0.00", balance: "120.00" },
    currency: "ETB",
    sent_at: "2026-10-27T07:59:00+00:00",
    settled_at: null,
    paid_at: null,
    closed_at: null,
    cancelled: null,
    voided: null,
    version: 4,
    created_at: "2026-10-27T07:58:00+00:00",
    lines: [],
    ...fields,
  };
}

test.describe("p4 order record: the rules", () => {
  test("events and payments merge into one list by time; ties keep a fixed order; an instant is compared whatever its offset", () => {
    // a cash payment taken before the timeline wrote payment events (Phase 1): only its row knows it, in the same second as order.settled
    const created = event("order.created", "2026-10-27T07:58:00+00:00");
    const sent = event("order.sent", "2026-10-27T07:59:00+00:00");
    const settled = event("order.settled", "2026-10-27T08:00:00+00:00", { user_id: CASHIER, payload: { payment_id: 1, paid: "120.00" } });
    // two events written in one second keep the timeline's own order (by id)
    const paid = event("order.paid", "2026-10-27T08:05:00+00:00", { user_id: CASHIER });
    const closed = event("order.closed", "2026-10-27T08:05:00+00:00", { user_id: CASHIER });
    // the same instant written in Addis Ababa's offset: 11:05+03:00 is 08:05 UTC
    const verifiedSameSecond = payment(1, { amount: "120.00", status: "verified", verified_at: "2026-10-27T11:05:00+03:00" });

    const entries = recordEntries([created, sent, settled, paid, closed], [verifiedSameSecond]);
    expect(entries.map((e) => e.kind)).toEqual([
      "order.created",
      "order.sent",
      // the payment's own row goes before the events of its second (it settled the order)
      "payment.recorded",
      "order.settled",
      "order.paid",
      "order.closed",
      // a decision on the payment goes after the events of its second
      "payment.verified",
    ]);
    const recorded = entries[2];
    expect(recorded).toMatchObject({ source: "payment", paymentId: 1, actorId: CASHIER, amount: "120.00", channelCode: "cash", approverId: null });
    expect(entries[6]).toMatchObject({ source: "payment", paymentId: 1, actorId: null, approverId: null });
    // every entry has its own key
    expect(new Set(entries.map((e) => e.key)).size).toBe(entries.length);

    // the input order does not matter, and an empty order is an empty list
    expect(recordEntries([closed, paid, settled, sent, created], [verifiedSameSecond]).map((e) => e.key)).toEqual(entries.map((e) => e.key));
    expect(recordEntries([], [])).toEqual([]);

    // a time the page cannot read goes last, as written
    const odd = event("order.served", "not a time");
    expect(recordEntries([odd, created], []).map((e) => e.kind)).toEqual(["order.created", "order.served"]);
  });

  test("a voided payment shows once as recorded and once as voided, with who asked and who approved; the timeline's events are not repeated", () => {
    const cash = payment(1, { amount: "50.00", received_at: "2026-10-27T08:00:00+00:00" });
    const bank = payment(2, {
      channel: { id: 2, code: "e2e_bank", name_en: "Bank", name_am: "ባንክ" },
      amount: "70.00",
      reference_no: "TRF-123456",
      received_at: "2026-10-27T08:00:00+00:00",
      status: "voided",
      void_reason: "Wrong order",
      voided_at: "2026-10-27T08:10:00+00:00",
    });
    // the cashier acts by short code on the shared till's account; the manager's PIN approved the void
    const events = [
      event("payment.recorded", "2026-10-27T08:00:00+00:00", { user_id: ACCOUNT, acting_user_id: CASHIER, payload: { payment_id: 1, channel_code: "cash", amount: "50.00", receipt: false } }),
      event("payment.recorded", "2026-10-27T08:00:00+00:00", { user_id: ACCOUNT, acting_user_id: CASHIER, payload: { payment_id: 2, channel_code: "e2e_bank", amount: "70.00", receipt: false } }),
      event("order.settled", "2026-10-27T08:00:00+00:00", { user_id: ACCOUNT, acting_user_id: CASHIER, payload: { payment_id: 2, paid: "120.00" } }),
      event("payment.voided", "2026-10-27T08:10:00+00:00", {
        user_id: ACCOUNT,
        acting_user_id: CASHIER,
        approved_by_user_id: MANAGER,
        payload: { payment_id: 2, channel_code: "e2e_bank", amount: "70.00", reason: "Wrong order", by_user_id: CASHIER, approved_by_user_id: MANAGER },
      }),
    ];
    const entries = recordEntries(events, [cash, bank]);
    expect(entries.map((e) => [e.kind, e.paymentId, e.source])).toEqual([
      ["payment.recorded", 1, "event"],
      ["payment.recorded", 2, "event"],
      ["order.settled", 2, "event"],
      ["payment.voided", 2, "event"],
    ]);
    const voided = entries[3];
    expect(voided).toMatchObject({ actorId: CASHIER, accountId: ACCOUNT, approverId: MANAGER, reason: "Wrong order", amount: "70.00", channelCode: "e2e_bank" });
    // the account is named only when someone else acted on it
    expect(recordEntries([event("order.sent", "2026-10-27T08:00:00+00:00", { user_id: CASHIER, acting_user_id: CASHIER })], [])[0].accountId).toBeNull();

    // a void the timeline does not hold (none should exist since step 4.3c) still shows, from the payment's own row, with no person
    const fromRow = recordEntries([], [bank]);
    expect(fromRow.map((e) => [e.kind, e.source])).toEqual([
      ["payment.recorded", "payment"],
      ["payment.voided", "payment"],
    ]);
    expect(fromRow[1]).toMatchObject({ reason: "Wrong order", approverId: null, actorId: null, amount: "70.00" });

    // the order's own void names its approver too, and every removed line with it
    const orderVoid = recordEntries(
      [
        event("line.voided", "2026-10-27T09:00:00+00:00", { order_line_id: 31, user_id: CASHIER, approved_by_user_id: MANAGER, payload: { by_user_id: CASHIER, approved_by_user_id: MANAGER } }),
        event("order.voided", "2026-10-27T09:00:00+00:00", { user_id: CASHIER, approved_by_user_id: MANAGER, payload: { reason: "Customer left", by_user_id: CASHIER, approved_by_user_id: MANAGER, line_ids: [31] } }),
      ],
      [],
    );
    expect(orderVoid.map((e) => [e.kind, e.lineId, e.actorId, e.approverId, e.reason])).toEqual([
      ["line.voided", 31, CASHIER, MANAGER, null],
      ["order.voided", null, CASHIER, MANAGER, "Customer left"],
    ]);
  });

  test("a dispute and a verification come from the payment's own times, the dispute with its reason, and carry no person", () => {
    const checked = payment(5, {
      status: "verified",
      disputed_at: "2026-10-27T09:00:00+00:00",
      dispute_reason: "No such transfer on the statement",
      verified_at: "2026-10-27T10:00:00+00:00",
    });
    const entries = recordEntries([event("payment.recorded", "2026-10-27T08:00:00+00:00", { user_id: CASHIER, payload: { payment_id: 5, channel_code: "cash", amount: "50.00", receipt: false } })], [checked]);
    expect(entries.map((e) => e.kind)).toEqual(["payment.recorded", "payment.disputed", "payment.verified"]);
    expect(entries[1]).toMatchObject({ reason: "No such transfer on the statement", actorId: null, approverId: null, paymentId: 5 });
    expect(entries[2]).toMatchObject({ reason: null, actorId: null, approverId: null, paymentId: 5 });
    // disputed and verified in one second: the dispute first
    const quick = payment(6, { status: "verified", disputed_at: "2026-10-27T09:00:00+00:00", dispute_reason: "Check it", verified_at: "2026-10-27T09:00:00+00:00", received_at: "2026-10-27T09:00:00+00:00" });
    expect(recordEntries([], [quick]).map((e) => e.kind)).toEqual(["payment.recorded", "payment.disputed", "payment.verified"]);
  });

  test("names: the people list when the viewer may read it, then the names the page's rows carry, else nothing (the page writes #id)", () => {
    const rows = { order: order(), payments: [payment(1)], me: { user: { id: 1, name: "Admin Sara" }, acting: null } };
    // a manager has no people list: the owner, whoever took a payment and the viewer are named, the approver is not
    const fromRows = peopleOf({ users: null, ...rows });
    expect(nameOf(fromRows, WAITER)).toBe("Waiter Abel");
    expect(nameOf(fromRows, CASHIER)).toBe("Cashier Hana");
    expect(nameOf(fromRows, 1)).toBe("Admin Sara");
    expect(nameOf(fromRows, MANAGER)).toBeNull();
    expect(nameOf(fromRows, null)).toBeNull();
    // an admin's people list names everyone of the branch, and wins over a row's copy
    const listed = peopleOf({ users: [{ id: MANAGER, name: "Manager Tigist" }, { id: CASHIER, name: "Hana T." }], ...rows });
    expect(nameOf(listed, MANAGER)).toBe("Manager Tigist");
    expect(nameOf(listed, CASHIER)).toBe("Hana T.");
    expect(nameOf(listed, WAITER)).toBe("Waiter Abel");
    // a person acting at a shared screen is named from GET /me as well; an empty name counts as none
    const acting = peopleOf({ users: [{ id: 8, name: "" }], order: null, payments: null, me: { user: { id: 1, name: "Till" }, acting: { user: { id: 8, name: "Cashier Liya" } } } });
    expect(nameOf(acting, 8)).toBe("Cashier Liya");
    const nameless = peopleOf({ users: null, order: order({ owner: { id: WAITER, name: null } }), payments: [payment(1, { received_by: { id: CASHIER, name: null } })], me: null });
    expect(nameOf(nameless, WAITER)).toBeNull();
    expect(nameOf(nameless, CASHIER)).toBeNull();
  });

  test("the photo: a signed address, a file no longer stored, or none; the reference shows its last four characters", () => {
    expect(photoOf(payment(1))).toEqual({ kind: "none" });
    expect(photoOf(payment(1, { receipt: { file_id: 9, url: "https://api.test/files/9?signature=x", url_expires_at: "2026-10-27T08:05:00+00:00" } }))).toEqual({
      kind: "signed",
      fileId: 9,
      url: "https://api.test/files/9?signature=x",
    });
    expect(photoOf(payment(1, { receipt: { file_id: 9, url: null, url_expires_at: null } }))).toEqual({ kind: "gone", fileId: 9 });
    expect(referenceTail("TX123456")).toBe("…3456");
    expect(referenceTail(" AB12 ")).toBe("AB12");
    expect(referenceTail(null)).toBeNull();
    expect(referenceTail("")).toBeNull();
  });

  test("the header's money: due, settled, held beyond the total, or nothing; and whether the order and its payments were read in step", () => {
    const totals = (total: string, paid: string, balance: string) => ({ total, paid, balance });
    expect(balanceView(totals("120.00", "0.00", "120.00"), [], "120.00")).toMatchObject({ state: "due", inStep: true, paidByPayments: "0.00" });
    expect(balanceView(totals("120.00", "50.00", "70.00"), [payment(1)], "70.00")).toMatchObject({ state: "due", inStep: true, paidByPayments: "50.00" });
    // a voided payment no longer counts
    const voided = payment(2, { amount: "70.00", status: "voided" });
    expect(balanceView(totals("120.00", "50.00", "70.00"), [payment(1), voided], "70.00")).toMatchObject({ state: "due", inStep: true });
    expect(balanceView(totals("120.00", "120.00", "0.00"), [payment(1, { amount: "120.00" })], "0.00")).toMatchObject({ state: "settled", inStep: true });
    // a part-paid order voided as a whole: its lines cost nothing now and it still holds the payment until that is voided too
    expect(balanceView(totals("0.00", "20.00", "-20.00"), [payment(1, { amount: "20.00" })], "-20.00")).toMatchObject({ state: "over", inStep: true });
    expect(balanceView(totals("0.00", "0.00", "0.00"), [], "0.00")).toMatchObject({ state: "none" });
    // the two reads straddled a payment: the payments add up to more than the order knows of
    expect(balanceView(totals("120.00", "50.00", "70.00"), [payment(1), payment(3, { amount: "70.00" })], "0.00")).toMatchObject({ inStep: false, paidByPayments: "120.00" });
    expect(balanceView(totals("120.00", "50.00", "70.00"), [payment(1)], "0.00")).toMatchObject({ inStep: false });
    // before the payments are read there is nothing to compare
    expect(balanceView(totals("120.00", "50.00", "70.00"), null, null)).toMatchObject({ state: "due", inStep: true, paidByPayments: null });
    // the figures are shown as the API wrote them, never parsed
    expect(balanceView(totals("1234567.80", "0.00", "1234567.80"), [], "1234567.80")).toMatchObject({ total: "1234567.80", balance: "1234567.80" });
  });

  test("the list's signed photo addresses are trusted for their five minutes less a margin, then read again", () => {
    expect(SIGNED_URL_LIFE_MS).toBe(300_000);
    const readAt = Date.parse("2026-10-27T08:00:00Z");
    expect(urlsStale(readAt, readAt)).toBe(false);
    expect(urlsStale(readAt, readAt + SIGNED_URL_LIFE_MS - RESIGN_MARGIN_MS - 1)).toBe(false);
    expect(urlsStale(readAt, readAt + SIGNED_URL_LIFE_MS - RESIGN_MARGIN_MS)).toBe(true);
    expect(urlsStale(readAt, readAt + 6 * 60_000)).toBe(true);
    // never read (React Query's dataUpdatedAt is 0 before the first answer): nothing to trust
    expect(urlsStale(0, readAt)).toBe(true);
    // a clock that went back is not a reason to trust an address longer
    expect(urlsStale(readAt, readAt - 60_000)).toBe(false);
  });

  test("the voids offered and the receipt: the whole order while it is sent and unpaid, a payment while the order is not paid, a receipt once paid in full", () => {
    const unpaid = order({ status: "served" });
    expect(canVoidOrder(unpaid)).toBe(true);
    expect(canVoidOrder(order({ status: "open" }))).toBe(false);
    const settled = order({ status: "ready", settled_at: "2026-10-27T08:00:00+00:00", totals: { subtotal: "120.00", discount: "0.00", tax: "0.00", total: "120.00", paid: "120.00", balance: "0.00" } });
    expect(canVoidOrder(settled)).toBe(false);
    expect(canVoidPayment(settled, payment(1))).toBe(true);
    expect(canVoidPayment(settled, payment(1, { status: "voided" }))).toBe(false);
    expect(canVoidPayment(settled, payment(1, { refunded_amount: "10.00" }))).toBe(false);
    const closed = order({ status: "closed", settled_at: "2026-10-27T08:00:00+00:00" });
    expect(canVoidOrder(closed)).toBe(false);
    expect(canVoidPayment(closed, payment(1))).toBe(false);
    expect(canVoidPayment(order({ status: "paid" }), payment(1))).toBe(false);
    // a part-paid order voided as a whole still holds its payment, which can be voided (its money goes back); the order itself cannot again
    const voidedOrder = order({ status: "voided", totals: { subtotal: "0.00", discount: "0.00", tax: "0.00", total: "0.00", paid: "20.00", balance: "-20.00" } });
    expect(canVoidOrder(voidedOrder)).toBe(false);
    expect(canVoidPayment(voidedOrder, payment(1, { amount: "20.00" }))).toBe(true);
    expect(canVoidPayment(order({ status: "cancelled" }), payment(1))).toBe(false);

    expect(receiptAvailable(unpaid)).toBe(false);
    expect(receiptAvailable(settled)).toBe(true);
    expect(receiptAvailable(closed)).toBe(true);
    expect(receiptAvailable(order({ status: "paid" }))).toBe(true);
    expect(receiptAvailable(order({ status: "voided", totals: { subtotal: "0.00", discount: "0.00", tax: "0.00", total: "0.00", paid: "0.00", balance: "0.00" } }))).toBe(false);
    expect(receiptAvailable(order({ status: "cancelled" }))).toBe(false);
    expect(receiptAvailable(order({ status: "open", totals: { subtotal: "0.00", discount: "0.00", tax: "0.00", total: "0.00", paid: "0.00", balance: "0.00" } }))).toBe(false);
  });

  test("the address: ?id= of the record page, a malformed id dropped, and the inbox may link to it", () => {
    expect(RECORD_PATH).toBe("/admin/orders/record");
    expect(recordQuery(50)).toEqual({ id: "50" });
    const params = (query: string) => new URLSearchParams(query);
    expect(recordIdOf(params("id=50"))).toBe(50);
    expect(recordIdOf(params("id=0"))).toBeNull();
    expect(recordIdOf(params("id=-3"))).toBeNull();
    expect(recordIdOf(params("id=5x"))).toBeNull();
    expect(recordIdOf(params("id=12345678901"))).toBeNull();
    expect(recordIdOf(params(""))).toBeNull();
    expect(resolveDeepLink("/admin/orders/record?id=50")).toEqual({ pathname: "/admin/orders/record", query: { id: "50" } });
  });

  test("every kind of entry has its words in both languages, and an event the page does not know is kept as written", () => {
    const en = enMessages.adminOrders as Record<string, string>;
    const am = amMessages.adminOrders as Record<string, string>;
    for (const kind of Object.keys(ENTRY_KINDS)) {
      const key = kindLabelKey(kind);
      expect(key, kind).not.toBeNull();
      expect(en[key as string], kind).toBeTruthy();
      expect(am[key as string], kind).toBeTruthy();
      expect(am[key as string], kind).not.toBe(en[key as string]);
    }
    // the four payment kinds and the two voids that name an approver
    expect(Object.entries(ENTRY_KINDS).filter(([, spec]) => spec.approved).map(([kind]) => kind).sort()).toEqual(["line.voided", "order.voided", "payment.voided"]);
    expect(Object.entries(ENTRY_KINDS).filter(([, spec]) => spec.payment).map(([kind]) => kind).sort()).toEqual(["payment.disputed", "payment.recorded", "payment.verified", "payment.voided"]);
    expect(kindLabelKey("order.refunded_later")).toBeNull();
    const unknown = recordEntries([event("order.refunded_later", "2026-10-27T08:00:00+00:00")], []);
    expect(unknown).toHaveLength(1);
    expect(unknown[0].kind).toBe("order.refunded_later");
  });
});
