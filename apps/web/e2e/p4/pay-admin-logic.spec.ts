import { expect, test } from "@playwright/test";
import type { PaymentChannel, PaymentListRow, ReconciliationChannelRow } from "../../src/lib/api/types";
import { resolveDeepLink } from "../../src/lib/deeplink";
import {
  canDispute,
  canVerify,
  changesOf,
  channelControls,
  channelIssues,
  chipsOf,
  createBody,
  dayTotals,
  draftOf,
  exportFileName,
  filterIssue,
  filterParams,
  filtersFromParams,
  foldHead,
  listedChannels,
  liveIds,
  missingPhotoDrill,
  moveIds,
  NO_FILTERS,
  olderCursor,
  patchRows,
  patternCheck,
  rangeIssue,
  reasonIssue,
  receiptBody,
  receiptFormOf,
  receiptIssues,
  sampleCheck,
  visibleRows,
  type PaymentFilters,
} from "../../src/lib/adminPayments";

// Step 4.5c, the admin payments screens' rules as plain logic (no browser, no API): the list's filters to the query string and back, the
// order of the payment methods and its up/down moves, the channel form (what the API would refuse, the body of a create, the changes of
// an edit, the live check of a reference pattern: a convenience, the server's ReferencePattern is the judge), the chips and actions of a
// payment, the polled first page folded with the older pages, the reconciliation's day totals on decimal strings (never a float), and the
// receipt settings form. They ride in the p4-pay-photo-split project (the name matches /pay/).

function channel(patch: Partial<PaymentChannel> & { id: number; code: string }): PaymentChannel {
  return {
    name_en: patch.code,
    name_am: patch.code,
    is_enabled: true,
    requires_photo: false,
    requires_reference: false,
    sort: patch.id,
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

function payment(patch: Partial<PaymentListRow> & { id: number }): PaymentListRow {
  return {
    order_id: 7,
    client_uuid: `uuid-${patch.id}`,
    channel: { id: 3, code: "telebirr", name_en: "Telebirr", name_am: "ቴሌብር" },
    amount: "100.00",
    tendered_amount: null,
    change_amount: null,
    currency: "ETB",
    reference_no: "TX123456",
    status: "recorded",
    received_at: "2026-10-27T08:00:00+00:00",
    received_by: { id: 5, name: "Cashier" },
    shift_id: 9,
    batch_uuid: null,
    receipt: { file_id: 11, url: "https://example.test/files/11?signature=x", url_expires_at: "2026-10-27T08:05:00+00:00" },
    verified_at: null,
    disputed_at: null,
    dispute_reason: null,
    refunded_amount: "0.00",
    void_reason: null,
    voided_at: null,
    flags: { duplicate_reference: false, duplicate_receipt: false },
    order: { id: 7, order_no: 3, business_date: "2026-10-27", table_code: null, total: "100.00" },
    ...patch,
  };
}

function params(query: Record<string, string>) {
  const search = new URLSearchParams(query);
  return { get: (name: string) => search.get(name) };
}

test.describe("admin payments logic", () => {
  test("the filters become the API's query: empty ones are left out, the flags are 1, the dates and the order number are checked", () => {
    expect(filterParams(NO_FILTERS)).toEqual({});
    const filters: PaymentFilters = { from: "2026-10-20", to: "2026-10-27", channelId: "4", status: "disputed", orderNo: " 12 ", missingPhoto: true, flagged: true };
    expect(filterParams(filters)).toEqual({
      from: "2026-10-20",
      to: "2026-10-27",
      channel_id: "4",
      status: "disputed",
      order_no: "12",
      missing_photo: "1",
      flagged: "1",
    });
    expect(filterIssue(filters)).toBeNull();
    expect(filterIssue({ ...NO_FILTERS, from: "2026-10-27", to: "2026-10-20" })).toBe("dates");
    expect(filterIssue({ ...NO_FILTERS, orderNo: "12a" })).toBe("order_no");
    expect(filterIssue({ ...NO_FILTERS, orderNo: "0" })).toBe("order_no");
    // a bad order number is never sent: the API would answer 422
    expect(filterParams({ ...NO_FILTERS, orderNo: "12a" })).toEqual({});
  });

  test("the screen's address holds the filters: read back, anything malformed is dropped, the payment of a deep link is not a filter", () => {
    expect(filtersFromParams(params({}))).toEqual(NO_FILTERS);
    const back = filtersFromParams(params({ from: "2026-10-27", to: "2026-10-27", channel_id: "4", missing_photo: "1", payment: "55" }));
    expect(back).toEqual({ ...NO_FILTERS, from: "2026-10-27", to: "2026-10-27", channelId: "4", missingPhoto: true });
    expect(filtersFromParams(params({ from: "27/10/2026", channel_id: "four", status: "lost", order_no: "-3", flagged: "yes" }))).toEqual(NO_FILTERS);
    expect(filtersFromParams(params({ status: "verified", flagged: "true", order_no: "8" }))).toEqual({ ...NO_FILTERS, status: "verified", flagged: true, orderNo: "8" });
    // what the screen writes, it reads back unchanged
    const filters: PaymentFilters = { from: "2026-10-01", to: "2026-10-02", channelId: "9", status: "recorded", orderNo: "4", missingPhoto: false, flagged: true };
    expect(filtersFromParams(params(filterParams(filters)))).toEqual(filters);
  });

  test("a missing-photo cell of the reconciliation drills into that day, that channel and missing_photo=1", () => {
    expect(missingPhotoDrill("2026-10-27", 6)).toEqual({ from: "2026-10-27", to: "2026-10-27", channel_id: "6", missing_photo: "1" });
    expect(filtersFromParams(params(missingPhotoDrill("2026-10-27", 6)))).toEqual({ ...NO_FILTERS, from: "2026-10-27", to: "2026-10-27", channelId: "6", missingPhoto: true });
  });

  test("the payment_disputed notification's link (step 4.3c) is a page of the web: the inbox links to it", () => {
    expect(resolveDeepLink("/admin/payments?payment=55")).toEqual({ pathname: "/admin/payments", query: { payment: "55" } });
  });

  test("the export's file is named by its dates as the API names it", () => {
    expect(exportFileName(NO_FILTERS, "csv", "2026-10-27")).toBe("payments-all-2026-10-27.csv");
    expect(exportFileName({ ...NO_FILTERS, from: "2026-10-01", to: "2026-10-07" }, "xlsx", "2026-10-27")).toBe("payments-2026-10-01-2026-10-07.xlsx");
  });

  test("the payment methods: the live ones by sort, then the removed ones; the order sends every live id once", () => {
    const all = [
      channel({ id: 1, code: "cash", sort: 1 }),
      channel({ id: 4, code: "bank", sort: 3 }),
      channel({ id: 2, code: "telebirr", sort: 2, is_enabled: false }),
      channel({ id: 9, code: "old", sort: 2, deleted_at: "2026-10-01T00:00:00+00:00" }),
      channel({ id: 5, code: "card", sort: 3 }),
    ];
    expect(listedChannels(all).map((c) => c.code)).toEqual(["cash", "telebirr", "bank", "card", "old"]);
    expect(liveIds(all)).toEqual([1, 2, 4, 5]);
  });

  test("moving a payment method up or down swaps it with its neighbour; at an end there is no move", () => {
    const ids = [1, 2, 4, 5];
    expect(moveIds(ids, 4, -1)).toEqual([1, 4, 2, 5]);
    expect(moveIds(ids, 4, 1)).toEqual([1, 2, 5, 4]);
    expect(moveIds(ids, 1, -1)).toBeNull();
    expect(moveIds(ids, 5, 1)).toBeNull();
    expect(moveIds(ids, 77, 1)).toBeNull();
    // never a duplicate nor a lost id
    const moved = moveIds(ids, 2, 1) ?? [];
    expect([...moved].sort((a, b) => a - b)).toEqual(ids);
  });

  test("the cash row is locked: no edit, switch or remove; a removed row has no control at all", () => {
    expect(channelControls(channel({ id: 1, code: "cash" }))).toEqual({ edit: false, toggle: false, remove: false, move: true });
    expect(channelControls(channel({ id: 1, code: "till_cash", is_cash: true }))).toEqual({ edit: false, toggle: false, remove: false, move: true });
    expect(channelControls(channel({ id: 2, code: "telebirr" }))).toEqual({ edit: true, toggle: true, remove: true, move: true });
    expect(channelControls(channel({ id: 3, code: "old", deleted_at: "2026-10-01T00:00:00+00:00" }))).toEqual({ edit: false, toggle: false, remove: false, move: false });
  });

  test("the channel form: what the API would refuse is said before it is sent", () => {
    const fresh = draftOf(null);
    expect(channelIssues(fresh, true)).toEqual(["code", "name_en", "name_am"]);
    const good = { ...fresh, code: "e2e_wallet-1", name_en: "Wallet", name_am: "ዋሌት" };
    expect(channelIssues(good, true)).toEqual([]);
    expect(channelIssues({ ...good, code: "Wallet" }, true)).toEqual(["code"]);
    expect(channelIssues({ ...good, code: "_wallet" }, true)).toEqual(["code"]);
    expect(channelIssues({ ...good, code: "w".repeat(33) }, true)).toEqual(["code"]);
    // the code is not checked on an edit: it never changes
    expect(channelIssues({ ...good, code: "" }, false)).toEqual([]);
    // a hint is a pair: both languages or neither
    expect(channelIssues({ ...good, reference_hint_en: "Ten digits" }, true)).toEqual(["reference_hint_pair"]);
    expect(channelIssues({ ...good, photo_hint_am: "ፎቶ" }, true)).toEqual(["photo_hint_pair"]);
    expect(channelIssues({ ...good, reference_hint_en: "x".repeat(121), reference_hint_am: "y" }, true)).toEqual(["hint_long"]);
    expect(channelIssues({ ...good, reference_pattern: "a".repeat(121) }, true)).toEqual(["pattern_long"]);
    expect(channelIssues({ ...good, name_en: "n".repeat(121) }, true)).toEqual(["name_en"]);
  });

  test("a new channel's body: trimmed, an empty text is null, switched on, and the client_uuid the form keeps", () => {
    const draft = {
      ...draftOf(null),
      code: " e2e_bank_1 ",
      name_en: " Bank ",
      name_am: "ባንክ",
      requires_reference: true,
      reference_pattern: " ",
      reference_hint_en: "As on the slip",
      reference_hint_am: "በደረሰኙ ላይ እንዳለው",
    };
    expect(createBody(draft, "c0ffee00-0000-4000-8000-000000000001")).toEqual({
      client_uuid: "c0ffee00-0000-4000-8000-000000000001",
      code: "e2e_bank_1",
      name_en: "Bank",
      name_am: "ባንክ",
      is_enabled: true,
      requires_photo: false,
      requires_reference: true,
      reference_pattern: null,
      reference_hint_en: "As on the slip",
      reference_hint_am: "በደረሰኙ ላይ እንዳለው",
      photo_hint_en: null,
      photo_hint_am: null,
    });
  });

  test("an edit sends only what changed, a hint pair together, and never the code", () => {
    const original = channel({ id: 4, code: "bank", name_en: "Bank", name_am: "ባንክ", requires_reference: true, reference_hint_en: "Slip", reference_hint_am: "ደረሰኝ" });
    const draft = draftOf(original);
    expect(draft.code).toBe("bank");
    expect(changesOf(draft, original)).toEqual({});
    expect(changesOf({ ...draft, requires_photo: true, reference_pattern: "\\d{10}" }, original)).toEqual({ requires_photo: true, reference_pattern: "\\d{10}" });
    expect(changesOf({ ...draft, reference_hint_en: "Slip number" }, original)).toEqual({ reference_hint_en: "Slip number", reference_hint_am: "ደረሰኝ" });
    expect(changesOf({ ...draft, reference_hint_en: "", reference_hint_am: "" }, original)).toEqual({ reference_hint_en: null, reference_hint_am: null });
    expect(changesOf({ ...draft, code: "renamed", name_en: " Bank transfer " }, original)).toEqual({ name_en: "Bank transfer" });
  });

  test("the pattern is checked as it is typed, and a sample reference is tried against it; the server decides when it is saved", () => {
    expect(patternCheck("")).toBe("none");
    expect(patternCheck("  ")).toBe("none");
    expect(patternCheck("[A-Z]{2}[0-9]{6}")).toBe("ok");
    expect(patternCheck("FT\\d{10}")).toBe("ok");
    expect(patternCheck("a".repeat(121))).toBe("too_long");
    expect(patternCheck("[A-Z")).toBe("unreadable");
    expect(patternCheck("(a")).toBe("unreadable");
    expect(sampleCheck("[A-Z]{2}[0-9]{6}", "TX123456")).toBe("match");
    // the whole reference must match, trimmed, case-sensitive, as the API matches it
    expect(sampleCheck("[A-Z]{2}[0-9]{6}", " TX123456 ")).toBe("match");
    expect(sampleCheck("[A-Z]{2}[0-9]{6}", "TX1234567")).toBe("no_match");
    expect(sampleCheck("[A-Z]{2}[0-9]{6}", "tx123456")).toBe("no_match");
    expect(sampleCheck("a|b", "ab")).toBe("no_match");
    expect(sampleCheck("[A-Z]{2}[0-9]{6}", "")).toBeNull();
    expect(sampleCheck("", "anything")).toBeNull();
    expect(sampleCheck("[A-Z", "A")).toBeNull();
  });

  test("a payment's chips, and which decisions it can take", () => {
    expect(chipsOf(payment({ id: 1 }))).toEqual([]);
    expect(chipsOf(payment({ id: 1, status: "disputed", flags: { duplicate_reference: true, duplicate_receipt: true } }))).toEqual([
      "disputed",
      "duplicate_reference",
      "duplicate_receipt",
    ]);
    expect(chipsOf(payment({ id: 1, flags: { duplicate_reference: false, duplicate_receipt: true } }))).toEqual(["duplicate_receipt"]);
    expect([canVerify(payment({ id: 1 })), canDispute(payment({ id: 1 }))]).toEqual([true, true]);
    expect([canVerify(payment({ id: 1, status: "disputed" })), canDispute(payment({ id: 1, status: "disputed" }))]).toEqual([true, false]);
    expect([canVerify(payment({ id: 1, status: "verified" })), canDispute(payment({ id: 1, status: "verified" }))]).toEqual([false, false]);
    expect([canVerify(payment({ id: 1, status: "voided" })), canDispute(payment({ id: 1, status: "voided" }))]).toEqual([false, false]);
  });

  test("a dispute needs a reason of 3 to 255 characters after trimming", () => {
    expect(reasonIssue("")).toBe("short");
    expect(reasonIssue("  ab  ")).toBe("short");
    expect(reasonIssue("abc")).toBeNull();
    expect(reasonIssue("x".repeat(255))).toBeNull();
    expect(reasonIssue("x".repeat(256))).toBe("long");
  });

  test("the polled first page and the older pages: newest first, no row twice, no gap when new payments push rows out of the first page", () => {
    const rows = (...ids: number[]) => ids.map((id) => payment({ id }));
    // nothing older loaded: the first page is the list, and its cursor goes on
    let pages = foldHead({ head: [], older: [] }, rows(50, 49, 48));
    expect(visibleRows(pages).map((r) => r.id)).toEqual([50, 49, 48]);
    expect(olderCursor(pages, "48")).toBe("48");
    // an older page is loaded below it
    pages = { ...pages, older: rows(47, 46) };
    expect(olderCursor(pages, "48")).toBe("46");
    // two new payments arrive: the first page now ends at 50; 49 and 48 left it but stay listed above the older page
    pages = foldHead(pages, rows(52, 51, 50));
    expect(visibleRows(pages).map((r) => r.id)).toEqual([52, 51, 50, 49, 48, 47, 46]);
    expect(olderCursor(pages, "50")).toBe("46");
    // a row in both: the first page's copy wins
    pages = foldHead({ head: [], older: [payment({ id: 51, status: "recorded" })] }, [payment({ id: 52 }), payment({ id: 51, status: "verified" })]);
    expect(visibleRows(pages).map((r) => [r.id, r.status])).toEqual([
      [52, "recorded"],
      [51, "verified"],
    ]);
    // the first page alone, with no older page: rows that left it are gone (they are one "older" tap away)
    pages = foldHead({ head: rows(3, 2, 1), older: [] }, rows(4, 3, 2));
    expect(visibleRows(pages).map((r) => r.id)).toEqual([4, 3, 2]);
  });

  test("a decision's answer replaces the payment where it is listed", () => {
    const pages = { head: [payment({ id: 2 }), payment({ id: 1 })], older: [payment({ id: 0 })] };
    const after = patchRows(pages, payment({ id: 1, status: "verified", verified_at: "2026-10-27T09:00:00+00:00" }));
    expect(visibleRows(after).map((r) => [r.id, r.status])).toEqual([
      [2, "recorded"],
      [1, "verified"],
      [0, "recorded"],
    ]);
    expect(patchRows(pages, payment({ id: 0, status: "disputed" })).older[0].status).toBe("disputed");
    expect(visibleRows(patchRows(pages, payment({ id: 99 }))).map((r) => r.id)).toEqual([2, 1, 0]);
  });

  test("the reconciliation's day total adds the channels' figures as decimal strings, never as floats", () => {
    const row = (id: number, patch: Partial<ReconciliationChannelRow>): ReconciliationChannelRow => ({
      channel: { id, code: `c${id}`, name_en: `C${id}`, name_am: `C${id}` },
      receipts: 0,
      amount: "0.00",
      with_photo: 0,
      without_photo: 0,
      with_reference: 0,
      verified: 0,
      disputed: 0,
      voided: 0,
      refunds: 0,
      refund_amount: "0.00",
      net_amount: "0.00",
      ...patch,
    });
    const rows = [
      row(1, { receipts: 3, amount: "0.10", with_photo: 0, without_photo: 3, net_amount: "0.10" }),
      row(2, { receipts: 2, amount: "0.20", with_photo: 2, with_reference: 2, verified: 1, disputed: 1, voided: 1, net_amount: "0.20" }),
      row(3, { receipts: 1, amount: "9999999999.99", with_photo: 1, with_reference: 1, net_amount: "9999999999.99" }),
    ];
    expect(dayTotals(rows)).toEqual({
      receipts: 6,
      amount: "10000000000.29",
      with_photo: 3,
      without_photo: 3,
      with_reference: 3,
      verified: 1,
      disputed: 1,
      voided: 1,
      refunds: 0,
      refund_amount: "0.00",
      net_amount: "10000000000.29",
    });
    expect(dayTotals([]).amount).toBe("0.00");
  });

  test("the reconciliation reads at most 62 days, from not after to", () => {
    expect(rangeIssue("2026-10-21", "2026-10-27")).toBeNull();
    expect(rangeIssue("2026-10-27", "2026-10-27")).toBeNull();
    expect(rangeIssue("2026-10-28", "2026-10-27")).toBe("order");
    // 62 days inclusive pass, 63 are refused, across a month end and a leap day
    expect(rangeIssue("2026-08-27", "2026-10-27")).toBeNull();
    expect(rangeIssue("2026-08-26", "2026-10-27")).toBe("too_long");
    expect(rangeIssue("2028-01-01", "2028-03-02")).toBeNull();
    expect(rangeIssue("2027-12-31", "2028-03-02")).toBe("too_long");
    expect(rangeIssue("", "2026-10-27")).toBe("dates");
  });

  test("the receipt settings form: the business name in both languages, pairs, the phone and TIN shapes, empty text is null", () => {
    const form = receiptFormOf({
      business_name_en: "Demo Bakery",
      business_name_am: "ዴሞ ዳቦ ቤት",
      address_en: null,
      address_am: null,
      phone: null,
      tin: null,
      footer_en: null,
      footer_am: null,
      show_amharic: true,
      ethiopian_dates: false,
      is_default: true,
    });
    expect(form.address_en).toBe("");
    expect(receiptIssues(form)).toEqual([]);
    expect(receiptBody({ ...form, phone: "+251 00 000 0000 ", tin: "0012345678" })).toEqual({
      business_name_en: "Demo Bakery",
      business_name_am: "ዴሞ ዳቦ ቤት",
      address_en: null,
      address_am: null,
      phone: "+251 00 000 0000",
      tin: "0012345678",
      footer_en: null,
      footer_am: null,
      show_amharic: true,
      ethiopian_dates: false,
    });
    expect(receiptIssues({ ...form, business_name_am: " " })).toEqual(["business_name_am"]);
    expect(receiptIssues({ ...form, address_en: "Bole road" })).toEqual(["address_pair"]);
    expect(receiptIssues({ ...form, footer_am: "እናመሰግናለን" })).toEqual(["footer_pair"]);
    expect(receiptIssues({ ...form, phone: "call us" })).toEqual(["phone"]);
    expect(receiptIssues({ ...form, tin: "TIN 1" })).toEqual(["tin"]);
  });
});
