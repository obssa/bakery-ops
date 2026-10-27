import type {
  NewPaymentChannel,
  PaymentChannel,
  PaymentChannelChanges,
  PaymentListRow,
  PaymentStatus,
  PaymentView,
  ReceiptSettings,
  ReceiptSettingsInput,
  ReconciliationCell,
  ReconciliationChannelRow,
} from "./api/types";
import { PAYMENT_STATUSES } from "./api/types";
import { addDecimal } from "./decimal";
import { CASH } from "./payment";
import { patternOf, REFERENCE_MAX } from "./paySheet";

// The rules of the admin payments screens (step 4.5c, docs/phases/phase-4-plan.md section 6 item 5): plain functions over plain data, so the
// screens and e2e/p4/pay-admin-logic.spec.ts read the same rules. The list's filters live in the page's address (a deep link, a drill from the
// reconciliation and a reload all land on the same list) and become the query of GET /payments; the methods' order is a list of every live
// id, moved one place at a time; the channel and receipt forms say before sending what the API would refuse (the API checks it all again and
// has the last word: the reference pattern above all, which only its ReferencePattern judges); the reconciliation's day totals add decimal
// strings (decimal.ts), never a float.

// --- the list's filters -------------------------------------------------------------------------------------------------------------------

export interface PaymentFilters {
  /** local dates, YYYY-MM-DD, of when the payment was received; "" for no bound */
  from: string;
  to: string;
  /** a channel id as text, "" for every channel */
  channelId: string;
  status: "" | PaymentStatus;
  /** an order number (numbers restart daily: any business date), "" for none */
  orderNo: string;
  missingPhoto: boolean;
  flagged: boolean;
}

export const NO_FILTERS: PaymentFilters = { from: "", to: "", channelId: "", status: "", orderNo: "", missingPhoto: false, flagged: false };

const DATE = /^\d{4}-\d{2}-\d{2}$/;
const ID = /^[1-9]\d{0,9}$/;

function isDate(value: string): boolean {
  if (!DATE.test(value)) return false;
  const day = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(day.getTime()) && day.toISOString().slice(0, 10) === value;
}

function isOn(value: string | null): boolean {
  return value === "1" || value === "true";
}

/** The filters the page's address holds; anything malformed is left out (the address may have been typed or kept from an old link). */
export function filtersFromParams(params: { get(name: string): string | null }): PaymentFilters {
  const text = (name: string) => (params.get(name) ?? "").trim();
  const status = text("status");
  return {
    from: isDate(text("from")) ? text("from") : "",
    to: isDate(text("to")) ? text("to") : "",
    channelId: ID.test(text("channel_id")) ? text("channel_id") : "",
    status: (PAYMENT_STATUSES as readonly string[]).includes(status) ? (status as PaymentStatus) : "",
    orderNo: ID.test(text("order_no")) ? text("order_no") : "",
    missingPhoto: isOn(params.get("missing_photo")),
    flagged: isOn(params.get("flagged")),
  };
}

/** What would the API refuse? Dates the wrong way round, or an order number that is not a whole number above zero. */
export function filterIssue(filters: PaymentFilters): "dates" | "order_no" | null {
  if (filters.from && filters.to && filters.from > filters.to) return "dates";
  if (filters.orderNo.trim() !== "" && !ID.test(filters.orderNo.trim())) return "order_no";
  return null;
}

/** The query of GET /payments (and of the page's address): the filters that are set, the flags as 1. A malformed value is never sent. */
export function filterParams(filters: PaymentFilters): Record<string, string> {
  const query: Record<string, string> = {};
  if (isDate(filters.from)) query.from = filters.from;
  if (isDate(filters.to)) query.to = filters.to;
  if (ID.test(filters.channelId)) query.channel_id = filters.channelId;
  if (filters.status) query.status = filters.status;
  const orderNo = filters.orderNo.trim();
  if (ID.test(orderNo)) query.order_no = orderNo;
  if (filters.missingPhoto) query.missing_photo = "1";
  if (filters.flagged) query.flagged = "1";
  return query;
}

/** The list a "without photo" cell of the reconciliation opens: that day, that channel, the payments without a photo. */
export function missingPhotoDrill(day: string, channelId: number): Record<string, string> {
  return { from: day, to: day, channel_id: String(channelId), missing_photo: "1" };
}

/**
 * Does a "without photo" cell open the list? Only for a method that is not cash and has such payments: the list's missing_photo filter
 * leaves cash out (a cash payment never has a photo), so a drill from the cash row would open an empty list.
 */
export function canDrill(row: Pick<ReconciliationChannelRow, "channel" | "without_photo">): boolean {
  return row.without_photo > 0 && row.channel.code !== CASH;
}

export type ExportFormat = "csv" | "xlsx";

/** The export's file name, as GET /payments/export names it in its Content-Disposition. */
export function exportFileName(filters: PaymentFilters, format: ExportFormat, today: string): string {
  return `payments-${filters.from || "all"}-${filters.to || today}.${format}`;
}

// --- the payment methods and their order --------------------------------------------------------------------------------------------------

function bySort(a: PaymentChannel, b: PaymentChannel): number {
  return a.sort - b.sort || a.id - b.id;
}

/** The till's order: live channels (switched-off ones included) by sort, then id; removed ones after them, as GET ?all=1 lists them. */
export function listedChannels(all: PaymentChannel[]): PaymentChannel[] {
  const live = all.filter((channel) => channel.deleted_at === null).sort(bySort);
  const removed = all.filter((channel) => channel.deleted_at !== null);
  return [...live, ...removed];
}

/** Every live channel's id in the till's order: what PUT /payment-channels/order must name, each exactly once. */
export function liveIds(all: PaymentChannel[]): number[] {
  return all.filter((channel) => channel.deleted_at === null).sort(bySort).map((channel) => channel.id);
}

/** The order with the channel moved one place up (-1) or down (+1); null when it is at that end, or not in the list. */
export function moveIds(ids: number[], id: number, by: -1 | 1): number[] | null {
  const at = ids.indexOf(id);
  const to = at + by;
  if (at < 0 || to < 0 || to >= ids.length) return null;
  const next = [...ids];
  next[at] = ids[to];
  next[to] = id;
  return next;
}

export function isCash(channel: Pick<PaymentChannel, "code" | "is_cash">): boolean {
  return channel.is_cash || channel.code === CASH;
}

/**
 * What a row of the methods list offers. Cash is locked (the drawer count is built on it): it keeps its place in the order and nothing
 * else; the API answers 409 payments.channel.cash_locked to anything more. A removed channel only stays listed.
 */
export function channelControls(channel: PaymentChannel): { edit: boolean; toggle: boolean; remove: boolean; move: boolean } {
  if (channel.deleted_at !== null) return { edit: false, toggle: false, remove: false, move: false };
  if (isCash(channel)) return { edit: false, toggle: false, remove: false, move: true };
  return { edit: true, toggle: true, remove: true, move: true };
}

// --- the channel form ---------------------------------------------------------------------------------------------------------------------

export const CODE_MAX = 32;
export const NAME_MAX = 120;
export const HINT_MAX = 120;
export const PATTERN_MAX = 120;

const CODE = /^[a-z0-9][a-z0-9_-]*$/;

export interface ChannelDraft {
  code: string;
  name_en: string;
  name_am: string;
  requires_photo: boolean;
  requires_reference: boolean;
  reference_pattern: string;
  reference_hint_en: string;
  reference_hint_am: string;
  photo_hint_en: string;
  photo_hint_am: string;
}

/** The form's fields: empty for a new channel, the channel's own values for an edit. */
export function draftOf(channel: PaymentChannel | null): ChannelDraft {
  return {
    code: channel?.code ?? "",
    name_en: channel?.name_en ?? "",
    name_am: channel?.name_am ?? "",
    requires_photo: channel?.requires_photo ?? false,
    requires_reference: channel?.requires_reference ?? false,
    reference_pattern: channel?.reference_pattern ?? "",
    reference_hint_en: channel?.reference_hint_en ?? "",
    reference_hint_am: channel?.reference_hint_am ?? "",
    photo_hint_en: channel?.photo_hint_en ?? "",
    photo_hint_am: channel?.photo_hint_am ?? "",
  };
}

export type ChannelIssue = "code" | "name_en" | "name_am" | "pattern_long" | "reference_hint_pair" | "photo_hint_pair" | "hint_long";

function blank(text: string): boolean {
  return text.trim() === "";
}

/** What the API would refuse, in the order the form shows its fields; the code only on a new channel (it never changes). */
export function channelIssues(draft: ChannelDraft, creating: boolean): ChannelIssue[] {
  const issues: ChannelIssue[] = [];
  const code = draft.code.trim();
  if (creating && (code.length > CODE_MAX || !CODE.test(code))) issues.push("code");
  if (blank(draft.name_en) || draft.name_en.trim().length > NAME_MAX) issues.push("name_en");
  if (blank(draft.name_am) || draft.name_am.trim().length > NAME_MAX) issues.push("name_am");
  if (draft.reference_pattern.trim().length > PATTERN_MAX) issues.push("pattern_long");
  if (blank(draft.reference_hint_en) !== blank(draft.reference_hint_am)) issues.push("reference_hint_pair");
  if (blank(draft.photo_hint_en) !== blank(draft.photo_hint_am)) issues.push("photo_hint_pair");
  const hints = [draft.reference_hint_en, draft.reference_hint_am, draft.photo_hint_en, draft.photo_hint_am];
  if (hints.some((hint) => hint.trim().length > HINT_MAX)) issues.push("hint_long");
  return issues;
}

/** A text as the API stores it: trimmed, and null when nothing is left. */
function stored(text: string): string | null {
  const value = text.trim();
  return value === "" ? null : value;
}

/** POST /payment-channels: the whole form, switched on; the client_uuid the form keeps until the channel is made. */
export function createBody(draft: ChannelDraft, clientUuid: string): NewPaymentChannel {
  return {
    client_uuid: clientUuid,
    code: draft.code.trim(),
    name_en: draft.name_en.trim(),
    name_am: draft.name_am.trim(),
    is_enabled: true,
    requires_photo: draft.requires_photo,
    requires_reference: draft.requires_reference,
    reference_pattern: stored(draft.reference_pattern),
    reference_hint_en: stored(draft.reference_hint_en),
    reference_hint_am: stored(draft.reference_hint_am),
    photo_hint_en: stored(draft.photo_hint_en),
    photo_hint_am: stored(draft.photo_hint_am),
  };
}

/** PATCH /payment-channels/{channel}: only what changed, a hint pair always together (the API asks for both of a pair), never the code. */
export function changesOf(draft: ChannelDraft, original: PaymentChannel): PaymentChannelChanges {
  const changes: PaymentChannelChanges = {};
  const name_en = draft.name_en.trim();
  const name_am = draft.name_am.trim();
  if (name_en !== original.name_en) changes.name_en = name_en;
  if (name_am !== original.name_am) changes.name_am = name_am;
  if (draft.requires_photo !== original.requires_photo) changes.requires_photo = draft.requires_photo;
  if (draft.requires_reference !== original.requires_reference) changes.requires_reference = draft.requires_reference;
  const pattern = stored(draft.reference_pattern);
  if (pattern !== original.reference_pattern) changes.reference_pattern = pattern;
  const referenceHint = [stored(draft.reference_hint_en), stored(draft.reference_hint_am)] as const;
  if (referenceHint[0] !== original.reference_hint_en || referenceHint[1] !== original.reference_hint_am) {
    changes.reference_hint_en = referenceHint[0];
    changes.reference_hint_am = referenceHint[1];
  }
  const photoHint = [stored(draft.photo_hint_en), stored(draft.photo_hint_am)] as const;
  if (photoHint[0] !== original.photo_hint_en || photoHint[1] !== original.photo_hint_am) {
    changes.photo_hint_en = photoHint[0];
    changes.photo_hint_am = photoHint[1];
  }
  return changes;
}

export type PatternCheck = "none" | "ok" | "too_long" | "unreadable";

/**
 * The pattern as it is typed: none (any reference is taken), too long for the API, one this browser cannot read, or one it reads. "ok" is
 * not the API's word: its ReferencePattern also refuses lookarounds, nested repeats and patterns that backtrack, and says so on save.
 */
export function patternCheck(body: string): PatternCheck {
  const value = body.trim();
  if (value === "") return "none";
  if (value.length > PATTERN_MAX) return "too_long";
  return patternOf(value) ? "ok" : "unreadable";
}

/** A sample reference tried against the pattern as the API matches it (the whole reference, trimmed, case-sensitive); null: nothing to try. */
export function sampleCheck(body: string, sample: string): "match" | "no_match" | null {
  const value = sample.trim();
  if (value === "" || patternCheck(body) !== "ok") return null;
  const pattern = patternOf(body.trim());
  if (!pattern) return null;
  return value.length <= REFERENCE_MAX && pattern.test(value) ? "match" : "no_match";
}

// --- payments: chips, decisions, the list's pages -----------------------------------------------------------------------------------------

export type PaymentChip = "disputed" | "duplicate_reference" | "duplicate_receipt";

/** The marks a checker looks for first: a dispute, a reference or a receipt photo seen on another payment. */
export function chipsOf(payment: PaymentView): PaymentChip[] {
  const chips: PaymentChip[] = [];
  if (payment.status === "disputed") chips.push("disputed");
  if (payment.flags.duplicate_reference) chips.push("duplicate_reference");
  if (payment.flags.duplicate_receipt) chips.push("duplicate_receipt");
  return chips;
}

/** recorded or disputed -> verified (step 4.3c). */
export function canVerify(payment: Pick<PaymentView, "status">): boolean {
  return payment.status === "recorded" || payment.status === "disputed";
}

/** recorded -> disputed only. */
export function canDispute(payment: Pick<PaymentView, "status">): boolean {
  return payment.status === "recorded";
}

export const REASON_MIN = 3;
export const REASON_MAX = 255;

/** A dispute's reason, as DisputePaymentRequest takes it: 3 to 255 characters once trimmed. */
export function reasonIssue(text: string): "short" | "long" | null {
  const value = text.trim();
  if (value.length < REASON_MIN) return "short";
  if (value.length > REASON_MAX) return "long";
  return null;
}

/**
 * The list on screen: the first page, polled (the newest payments under the filters, replaced whole by each changed answer), and the older
 * pages the checker asked for with "Older payments", read once by cursor and not polled. Any keyset-paged list newest first by id uses the
 * same three rules (step 4.5f: the audit log, whose first page is read again on Refresh).
 */
export interface ListPages<R extends { id: number } = PaymentListRow> {
  head: R[];
  older: R[];
}

function newestFirst<R extends { id: number }>(rows: R[]): R[] {
  const seen = new Set<number>();
  const out: R[] = [];
  for (const row of [...rows].sort((a, b) => b.id - a.id)) {
    if (seen.has(row.id)) continue;
    seen.add(row.id);
    out.push(row);
  }
  return out;
}

/**
 * A new first page. When older pages are on screen, the rows that new payments pushed out of the first page are kept above them (else they
 * would vanish between the two until the next "Older payments"); a first page that comes back empty means nothing matches any more.
 */
export function foldHead<R extends { id: number }>(pages: ListPages<R>, head: R[]): ListPages<R> {
  if (pages.older.length === 0 || head.length === 0) return { head, older: [] };
  const lowest = Math.min(...head.map((row) => row.id));
  const inHead = new Set(head.map((row) => row.id));
  const pushedOut = pages.head.filter((row) => row.id < lowest && !inHead.has(row.id));
  return { head, older: newestFirst([...pushedOut, ...pages.older.filter((row) => !inHead.has(row.id))]) };
}

/** Every listed payment once, newest first; the first page's copy wins over an older page's. */
export function visibleRows<R extends { id: number }>(pages: ListPages<R>): R[] {
  return newestFirst([...pages.head, ...pages.older]);
}

/** The cursor of the next older page: below the oldest row on screen (the first page's own cursor while no older page is loaded). */
export function olderCursor<R extends { id: number }>(pages: ListPages<R>, headCursor: string | null): string | null {
  if (pages.older.length === 0) return headCursor;
  return String(Math.min(...pages.older.map((row) => row.id)));
}

/** A decision's answer (verify, dispute) put where the payment is listed, so the row changes before the next poll. */
export function patchRows(pages: ListPages, payment: PaymentListRow): ListPages {
  const swap = (rows: PaymentListRow[]) => rows.map((row) => (row.id === payment.id ? { ...row, ...payment } : row));
  return { head: swap(pages.head), older: swap(pages.older) };
}

// --- the reconciliation -------------------------------------------------------------------------------------------------------------------

export const RECONCILIATION_MAX_DAYS = 62;

const COUNTS = ["receipts", "with_photo", "without_photo", "with_reference", "verified", "disputed", "voided", "refunds"] as const;
const MONEY = ["amount", "refund_amount", "net_amount"] as const;

export const EMPTY_CELL: ReconciliationCell = {
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
};

/** A day's channels added up: counts as integers, money as decimal strings on scaled integers. */
export function dayTotals(rows: ReconciliationChannelRow[]): ReconciliationCell {
  const total: ReconciliationCell = { ...EMPTY_CELL };
  for (const row of rows) {
    for (const key of COUNTS) total[key] += row[key];
    for (const key of MONEY) total[key] = addDecimal(total[key], row[key]);
  }
  return total;
}

function dayNumber(date: string): number {
  return Date.UTC(Number(date.slice(0, 4)), Number(date.slice(5, 7)) - 1, Number(date.slice(8, 10))) / 86_400_000;
}

/** What GET /payments/reconciliation would refuse: a missing or malformed date, from after to, more than 62 days (both ends counted). */
export function rangeIssue(from: string, to: string): "dates" | "order" | "too_long" | null {
  if (!isDate(from) || !isDate(to)) return "dates";
  if (from > to) return "order";
  return dayNumber(to) - dayNumber(from) + 1 > RECONCILIATION_MAX_DAYS ? "too_long" : null;
}

/** The local date `days` before (negative) or after a local date. */
export function shiftDate(date: string, days: number): string {
  return new Date((dayNumber(date) + days) * 86_400_000).toISOString().slice(0, 10);
}

// --- the receipt settings -----------------------------------------------------------------------------------------------------------------

export interface ReceiptForm {
  business_name_en: string;
  business_name_am: string;
  address_en: string;
  address_am: string;
  phone: string;
  tin: string;
  footer_en: string;
  footer_am: string;
  show_amharic: boolean;
  ethiopian_dates: boolean;
}

export function receiptFormOf(settings: ReceiptSettings): ReceiptForm {
  return {
    business_name_en: settings.business_name_en,
    business_name_am: settings.business_name_am,
    address_en: settings.address_en ?? "",
    address_am: settings.address_am ?? "",
    phone: settings.phone ?? "",
    tin: settings.tin ?? "",
    footer_en: settings.footer_en ?? "",
    footer_am: settings.footer_am ?? "",
    show_amharic: settings.show_amharic,
    ethiopian_dates: settings.ethiopian_dates,
  };
}

export type ReceiptIssue = "business_name_en" | "business_name_am" | "address_pair" | "footer_pair" | "phone" | "tin" | "too_long";

const PHONE = /^\+?[0-9][0-9 ()-]{4,31}$/;
const TIN = /^[0-9A-Za-z-]{1,32}$/;

/** What UpdateReceiptSettingsRequest would refuse, in the form's order. */
export function receiptIssues(form: ReceiptForm): ReceiptIssue[] {
  const issues: ReceiptIssue[] = [];
  if (blank(form.business_name_en)) issues.push("business_name_en");
  if (blank(form.business_name_am)) issues.push("business_name_am");
  if (blank(form.address_en) !== blank(form.address_am)) issues.push("address_pair");
  if (blank(form.footer_en) !== blank(form.footer_am)) issues.push("footer_pair");
  if (!blank(form.phone) && !PHONE.test(form.phone.trim())) issues.push("phone");
  if (!blank(form.tin) && !TIN.test(form.tin.trim())) issues.push("tin");
  const long =
    form.business_name_en.trim().length > NAME_MAX ||
    form.business_name_am.trim().length > NAME_MAX ||
    [form.address_en, form.address_am, form.footer_en, form.footer_am].some((text) => text.trim().length > 255);
  if (long) issues.push("too_long");
  return issues;
}

/** PUT /receipt-settings: the whole form, trimmed, an empty text as null. */
export function receiptBody(form: ReceiptForm): ReceiptSettingsInput {
  return {
    business_name_en: form.business_name_en.trim(),
    business_name_am: form.business_name_am.trim(),
    address_en: stored(form.address_en),
    address_am: stored(form.address_am),
    phone: stored(form.phone),
    tin: stored(form.tin),
    footer_en: stored(form.footer_en),
    footer_am: stored(form.footer_am),
    show_amharic: form.show_amharic,
    ethiopian_dates: form.ethiopian_dates,
  };
}
