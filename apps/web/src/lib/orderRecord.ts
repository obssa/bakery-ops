import type { Order, OrderEvent, OrderTotals, PaymentView } from "./api/types";
import { addDecimal, compareDecimal } from "./decimal";
import { isPayable, UNPAID_STATUSES } from "./live/orders";

// The order record page (step 4.5d, /admin/orders/record?id=): the rules, as plain logic tested without a browser
// (e2e/p4/pay-timeline-logic.spec.ts).
//
// The page reads two answers of the API that already exist, GET /orders/{order} (the order and its timeline, oldest first) and GET
// /orders/{order}/payments (every payment of the order, voided ones included, with its signed photo address), and shows them as one list
// by time. The timeline holds payment.recorded and payment.voided since step 4.3c; a payment taken before that (a Phase 1 cash payment)
// is known only by its own row, and a dispute or a verification is on the payment row only (no timeline event, no person: the payment's
// audit history names who did it). So the list is the timeline's events plus, for each payment, what the timeline does not already say.
//
// Who did it: an event carries ids only (user_id: the account; acting_user_id: the person acting by short code at a shared screen;
// approved_by_user_id: the owner of the PIN that approved a void). Names come from the people list (GET /users, people.manage: the admin)
// and from the names the page's own rows carry (the order's owner, whoever took a payment, the viewer), else the page writes "#id".

/** Where the record lives: a query, not a path segment, because the static export has no dynamic routes (the 4.5e product page's way). */
export const RECORD_PATH = "/admin/orders/record";

/**
 * Who may open it: the payment checkers (admin, manager). A cashier holds orders.create and payments.view too, but the record is the
 * checkers' page (the voids, the decisions, the audit links); the API still answers each read by its own permission.
 */
export const RECORD_PERMISSION = "payments.verify";

export function recordQuery(orderId: number): { id: string } {
  return { id: String(orderId) };
}

/** The order of the address (?id=), or null for a missing or malformed one (never sent to the API). */
export function recordIdOf(params: { get(name: string): string | null }): number | null {
  const raw = params.get("id") ?? "";
  return /^[1-9]\d{0,9}$/.test(raw) ? Number(raw) : null;
}

// --- the kinds of entry ---------------------------------------------------------------------------------------------------------------------

export type Tone = "neutral" | "blue" | "green" | "amber" | "red";

export interface KindSpec {
  tone: Tone;
  /** an entry about one payment: it names its channel and amount and links to it */
  payment?: true;
  /** written with a manager's PIN: the entry names who approved it */
  approved?: true;
}

/**
 * Every kind of entry the page has words for (adminOrders.ev_* in messages/*.json): the timeline's 20 events (order_events_event_check)
 * and the payment's own two decisions. A kind not listed is still shown, as the API wrote it.
 *
 * TODO(p4): 4.3d: the refund adds its kinds here, one line each (`"line.refunded"` and `"order.refunded"`, the events 4.1b adds to the
 * timeline, `approved: true`), with their two strings; nothing else in the merge changes.
 */
export const ENTRY_KINDS: Readonly<Record<string, KindSpec>> = {
  "order.created": { tone: "neutral" },
  "order.lines_replaced": { tone: "neutral" },
  "order.sent": { tone: "blue" },
  "order.send_refused": { tone: "amber" },
  "line.queued": { tone: "neutral" },
  "line.accepted": { tone: "neutral" },
  "line.ready": { tone: "green" },
  "line.rejected": { tone: "amber" },
  "line.served": { tone: "neutral" },
  "line.voided": { tone: "neutral", approved: true },
  "order.in_progress": { tone: "neutral" },
  "order.ready": { tone: "green" },
  "order.served": { tone: "neutral" },
  "order.settled": { tone: "green" },
  "order.paid": { tone: "green" },
  "order.closed": { tone: "neutral" },
  "order.cancelled": { tone: "amber" },
  "order.voided": { tone: "red", approved: true },
  "payment.recorded": { tone: "blue", payment: true },
  "payment.disputed": { tone: "red", payment: true },
  "payment.verified": { tone: "green", payment: true },
  "payment.voided": { tone: "red", payment: true, approved: true },
};

/** The message key of a kind ("order.created" -> "ev_order_created"), or null for one the page has no words for. */
export function kindLabelKey(kind: string): string | null {
  return Object.prototype.hasOwnProperty.call(ENTRY_KINDS, kind) ? `ev_${kind.replace(".", "_")}` : null;
}

export function kindSpec(kind: string): KindSpec | null {
  return Object.prototype.hasOwnProperty.call(ENTRY_KINDS, kind) ? ENTRY_KINDS[kind] : null;
}

// --- the merged list ------------------------------------------------------------------------------------------------------------------------

export interface RecordEntry {
  key: string;
  /** an ENTRY_KINDS kind, or an event this page has no words for */
  kind: string;
  at: string;
  /** "event": a row of the timeline; "payment": read off a payment row (what the timeline does not hold) */
  source: "event" | "payment";
  eventId: number | null;
  lineId: number | null;
  paymentId: number | null;
  /** who did it: the person acting by short code at a shared screen, else the account; null when the data names nobody */
  actorId: number | null;
  /** the account it was done on, when someone else acted on it */
  accountId: number | null;
  /** who approved it with their PIN (a void) */
  approverId: number | null;
  reason: string | null;
  amount: string | null;
  channelCode: string | null;
}

function numberOr(value: unknown): number | null {
  return typeof value === "number" && Number.isInteger(value) ? value : null;
}

function textOr(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

function entryOfEvent(event: OrderEvent): RecordEntry {
  const payload = event.payload ?? {};
  const actorId = event.acting_user_id ?? event.user_id;
  const payment = kindSpec(event.event)?.payment === true;
  return {
    key: `e${event.id}`,
    kind: event.event,
    at: event.at,
    source: "event",
    eventId: event.id,
    lineId: event.order_line_id,
    paymentId: numberOr(payload.payment_id),
    actorId,
    accountId: event.acting_user_id !== null && event.acting_user_id !== event.user_id ? event.user_id : null,
    approverId: event.approved_by_user_id ?? numberOr(payload.approved_by_user_id),
    reason: textOr(payload.reason),
    amount: payment ? textOr(payload.amount) : null,
    channelCode: payment ? textOr(payload.channel_code) : null,
  };
}

function entryOfPayment(payment: PaymentView, kind: string, at: string): RecordEntry {
  return {
    key: `p${payment.id}:${kind}`,
    kind,
    at,
    source: "payment",
    eventId: null,
    lineId: null,
    paymentId: payment.id,
    // the row names who took the money; nobody for the decisions (the audit history names them)
    actorId: kind === "payment.recorded" ? payment.received_by.id : null,
    accountId: null,
    approverId: null,
    reason: kind === "payment.disputed" ? payment.dispute_reason : kind === "payment.voided" ? payment.void_reason : null,
    amount: payment.amount,
    channelCode: payment.channel.code,
  };
}

/** The order the decisions on one payment go in when they share a second. */
const PAYMENT_KINDS = ["payment.recorded", "payment.disputed", "payment.verified", "payment.voided"];

function instant(iso: string): number {
  const at = Date.parse(iso);
  return Number.isNaN(at) ? Number.POSITIVE_INFINITY : at;
}

/**
 * Inside one second: a payment known only by its row comes first (it is what settled the order), then the timeline's events in the order
 * they were written (by id), then the decisions on payments (by payment, then recorded, disputed, verified, voided).
 */
function rank(entry: RecordEntry): number {
  if (entry.source === "event") return 1;
  return entry.kind === "payment.recorded" ? 0 : 2;
}

function compareEntries(a: RecordEntry, b: RecordEntry): number {
  const left = instant(a.at);
  const right = instant(b.at);
  if (left !== right) return left < right ? -1 : 1;
  const byRank = rank(a) - rank(b);
  if (byRank !== 0) return byRank;
  if (a.source === "event" && b.source === "event") return (a.eventId ?? 0) - (b.eventId ?? 0);
  const byPayment = (a.paymentId ?? 0) - (b.paymentId ?? 0);
  if (byPayment !== 0) return byPayment;
  return PAYMENT_KINDS.indexOf(a.kind) - PAYMENT_KINDS.indexOf(b.kind);
}

/** The order's timeline and its payments as one list, oldest first; nothing the timeline says is repeated from a payment row. */
export function recordEntries(events: readonly OrderEvent[], payments: readonly PaymentView[]): RecordEntry[] {
  const entries = events.map(entryOfEvent);
  const told = new Set(entries.filter((entry) => entry.paymentId !== null).map((entry) => `${entry.kind}:${entry.paymentId}`));
  for (const payment of payments) {
    if (!told.has(`payment.recorded:${payment.id}`)) entries.push(entryOfPayment(payment, "payment.recorded", payment.received_at));
    if (payment.disputed_at) entries.push(entryOfPayment(payment, "payment.disputed", payment.disputed_at));
    if (payment.verified_at) entries.push(entryOfPayment(payment, "payment.verified", payment.verified_at));
    if (payment.voided_at && !told.has(`payment.voided:${payment.id}`)) entries.push(entryOfPayment(payment, "payment.voided", payment.voided_at));
  }
  return entries.sort(compareEntries);
}

// --- names ------------------------------------------------------------------------------------------------------------------------------------

export interface Named {
  id: number;
  name: string | null;
}

export type People = ReadonlyMap<number, string>;

/**
 * The names the page knows: the people list first (the admin's GET /users, the People screen's ["users"]), then the names the page's own
 * rows carry: the order's owner, whoever took each payment, the viewer and the person acting at their screen. An empty name is no name.
 */
export function peopleOf(sources: {
  users: readonly Named[] | null | undefined;
  order: Pick<Order, "owner"> | null | undefined;
  payments: readonly Pick<PaymentView, "received_by">[] | null | undefined;
  me: { user: Named; acting: { user: Named } | null } | null | undefined;
}): Map<number, string> {
  const people = new Map<number, string>();
  const add = (person: Named | null | undefined) => {
    if (person && person.name && !people.has(person.id)) people.set(person.id, person.name);
  };
  for (const user of sources.users ?? []) add(user);
  add(sources.order?.owner);
  for (const payment of sources.payments ?? []) add(payment.received_by);
  add(sources.me?.user);
  add(sources.me?.acting?.user);
  return people;
}

/** A person's name, or null when the page knows none (it then writes "#id"). */
export function nameOf(people: People, id: number | null): string | null {
  if (id === null) return null;
  return people.get(id) ?? null;
}

// --- the header's money ---------------------------------------------------------------------------------------------------------------------

/**
 * due: money is owed; settled: paid in full; over: the order holds more than it costs (a part-paid order voided as a whole keeps its
 * payment until that is voided too); none: nothing to pay and nothing paid.
 */
export type BalanceState = "due" | "settled" | "over" | "none";

export interface BalanceView {
  total: string;
  paid: string;
  balance: string;
  state: BalanceState;
  /** the payments that are not voided, added up; null before they are read */
  paidByPayments: string | null;
  /** whether the two reads agree (they are two requests: a payment between them shows as a difference until the next read) */
  inStep: boolean;
}

export function balanceView(
  totals: Pick<OrderTotals, "total" | "paid" | "balance">,
  payments: readonly Pick<PaymentView, "amount" | "status">[] | null,
  paymentsBalance: string | null,
): BalanceView {
  const due = compareDecimal(totals.balance, "0", 2);
  const state: BalanceState = due > 0 ? "due" : due < 0 ? "over" : compareDecimal(totals.total, "0", 2) > 0 ? "settled" : "none";
  const paidByPayments = payments === null ? null : payments.filter((p) => p.status !== "voided").reduce((sum, p) => addDecimal(sum, p.amount, 2), "0.00");
  const inStep =
    paidByPayments === null ||
    (compareDecimal(paidByPayments, totals.paid, 2) === 0 && (paymentsBalance === null || compareDecimal(paymentsBalance, totals.balance, 2) === 0));
  return { total: totals.total, paid: totals.paid, balance: totals.balance, state, paidByPayments, inStep };
}

// --- the photo ------------------------------------------------------------------------------------------------------------------------------

export type PhotoState = { kind: "none" } | { kind: "gone"; fileId: number } | { kind: "signed"; fileId: number; url: string };

/** A payment's receipt photo: none taken, a file the API no longer has (its URL is null), or a signed address made on read. */
export function photoOf(payment: Pick<PaymentView, "receipt">): PhotoState {
  if (!payment.receipt) return { kind: "none" };
  if (!payment.receipt.url) return { kind: "gone", fileId: payment.receipt.file_id };
  return { kind: "signed", fileId: payment.receipt.file_id, url: payment.receipt.url };
}

/** A signed address lives files.url_ttl_seconds, 300 s by default (ADR 0015). */
export const SIGNED_URL_LIFE_MS = 300_000;

/** The addresses are read again this long before they die, so a picture never starts loading from one about to expire. */
export const RESIGN_MARGIN_MS = 15_000;

/**
 * Whether the photo addresses of a payments read made at `readAt` (ms; React Query's dataUpdatedAt, 0 before the first answer) are too
 * old to trust at `now`: the record page reads the payments again when a viewer opens after that (the viewer itself always asks for a
 * fresh address, and a picture that fails to load asks once more: 4.5c's useSignedUrl).
 */
export function urlsStale(readAt: number, now: number, lifeMs = SIGNED_URL_LIFE_MS, marginMs = RESIGN_MARGIN_MS): boolean {
  if (!(readAt > 0)) return true;
  return now - readAt >= lifeMs - marginMs;
}

// --- what the page offers -------------------------------------------------------------------------------------------------------------------

/** The whole order is voided while it is sent and not paid in full (the board's rule; 409 orders.order.paid otherwise). */
export function canVoidOrder(order: Order): boolean {
  return isPayable(order);
}

/**
 * One payment is voided while its order is sent and not paid or closed (a paid or closed order is refunded instead: 409
 * payments.void.order_closed), and while it is not voided and nothing of it was paid back (4.5b's VoidDialog offers the same ones).
 */
export function canVoidPayment(order: Pick<Order, "status">, payment: Pick<PaymentView, "status" | "refunded_amount">): boolean {
  return UNPAID_STATUSES.includes(order.status) && payment.status !== "voided" && compareDecimal(payment.refunded_amount, "0", 2) === 0;
}

/** The receipt exists once the order is paid in full (settled, paid or closed), never for a cancelled or voided one (as the API's ReceiptBuilder). */
export function receiptAvailable(order: Pick<Order, "status" | "settled_at">): boolean {
  if (order.status === "cancelled" || order.status === "voided" || order.status === "open") return false;
  return order.settled_at !== null || order.status === "paid" || order.status === "closed";
}
