import type { Fulfilment, ItemKind, Line, OrderTotals, PaymentView, RefundResult } from "./api/types";
import { addDecimal, compareDecimal, subtractDecimal } from "./decimal";

// The refund (step 4.5g), as plain logic tested without a browser (e2e/p4/pay-refund-logic.spec.ts).
//
// Ramin's rule (R-C, 2026-10-28): the admin decides per product whether it may be refunded (`refundable`) and whether a refunded unit
// goes back into stock (`refund_restock`: a display-case cake does, a burger made to order does not, its ingredients are used). A refund
// follows the settings, so the person only chooses the lines: the money back is each line's total, or the order's total when every
// charged line comes back. The API (POST /orders/{order}/refund, steps 4.1b and 4.3d) decides everything again under its locks; these
// rules only say what the dialog may offer and why it waits.
//
// The money goes back last in, first out over the order's payments that are not voided, each within what it still holds (its amount less
// what was paid back of it already); the person may share it out by hand instead (`allocation`). A share on a digital channel is a transfer
// made outside the app, so it needs the transfer's reference, and may carry a photo of it.

export const REFUND_REASON_MIN = 3;
export const REFUND_REASON_MAX = 255;
export const REFUND_PIN = /^\d{4,6}$/;
/** The API's MONEY rule of an allocation share: at most ten whole digits and two decimals. */
const SHARE_AMOUNT = /^\d{1,10}(\.\d{1,2})?$/;
const CASH = "cash";

// --- the product settings -----------------------------------------------------------------------------------------------------------------

export interface RefundSettings {
  refundable: boolean;
  restock: boolean;
}

/** The settings of the products the catalog lists (GET /items?kind=product); an item read without the two fields is left out (unknown). */
export function refundSettingsOf(items: readonly { id: number; refundable?: boolean; refund_restock?: boolean }[]): Map<number, RefundSettings> {
  const map = new Map<number, RefundSettings>();
  for (const item of items) {
    if (typeof item.refundable === "boolean" && typeof item.refund_restock === "boolean") map.set(item.id, { refundable: item.refundable, restock: item.refund_restock });
  }
  return map;
}

/** Only a product sold from stock or bought in can go back into stock (the API's 422 catalog.item.restock_not_stocked otherwise). */
export function restockAllowed(kind: ItemKind, fulfilment: Fulfilment): boolean {
  return kind === "product" && (fulfilment === "to_stock" || fulfilment === "bought_in");
}

/** What the admin's form sends: back into stock is off whenever it cannot apply (the toggle is greyed out then). */
export function refundSettingsBody(kind: ItemKind, fulfilment: Fulfilment, refundable: boolean, restock: boolean): { refundable: boolean; refund_restock: boolean } {
  return { refundable, refund_restock: restock && restockAllowed(kind, fulfilment) };
}

// --- the lines ----------------------------------------------------------------------------------------------------------------------------

/** open: may be chosen; refunded: came back already (once only); not_refundable: the admin's setting; not_charged: rejected or voided. */
export type LineState = "open" | "refunded" | "not_refundable" | "not_charged";

export interface RefundLineView {
  id: number;
  itemId: number;
  lineTotal: string;
  state: LineState;
  /** whether a refund puts it back into stock, from the product's setting; null while the product is not known (the API decides) */
  restock: boolean | null;
}

export function refundLineViews(lines: readonly Pick<Line, "id" | "item" | "line_total" | "status" | "refunded_at">[], settings: ReadonlyMap<number, RefundSettings> | null): RefundLineView[] {
  return lines.map((line) => {
    const known = settings?.get(line.item.id) ?? null;
    const state: LineState =
      line.status === "rejected" || line.status === "voided" ? "not_charged" : line.refunded_at ? "refunded" : known && !known.refundable ? "not_refundable" : "open";
    return { id: line.id, itemId: line.item.id, lineTotal: line.line_total, state, restock: known ? known.restock : null };
  });
}

/** A discount or a tax on the order: only every line at once (R-D, the API's 422 orders.refund.partial_not_allowed). */
export function wholeOnly(totals: Pick<OrderTotals, "discount" | "tax">): boolean {
  return compareDecimal(totals.discount, "0", 2) !== 0 || compareDecimal(totals.tax, "0", 2) !== 0;
}

/** The dialog opens with every line that may be refunded chosen. */
export function initialChoice(views: readonly RefundLineView[]): Set<number> {
  return new Set(views.filter((v) => v.state === "open").map((v) => v.id));
}

/** A tap on a line: it comes or goes; with a discount or a tax every open line comes or goes with it; a line that is not open never comes. */
export function toggleLine(views: readonly RefundLineView[], chosen: ReadonlySet<number>, lineId: number, whole: boolean): Set<number> {
  const open = views.filter((v) => v.state === "open").map((v) => v.id);
  if (!open.includes(lineId)) return new Set(chosen);
  if (whole) return chosen.has(lineId) ? new Set() : new Set(open);
  const next = new Set(chosen);
  if (next.has(lineId)) next.delete(lineId);
  else next.add(lineId);
  return next;
}

function chosenOpen(views: readonly RefundLineView[], chosen: ReadonlySet<number>): RefundLineView[] {
  return views.filter((v) => v.state === "open" && chosen.has(v.id));
}

/** The money back: the chosen lines' totals; the order's total when every charged line comes back (the same, unless a discount or tax). */
export function refundAmount(views: readonly RefundLineView[], chosen: ReadonlySet<number>, orderTotal: string): string {
  const picked = chosenOpen(views, chosen);
  const charged = views.filter((v) => v.state !== "not_charged");
  if (picked.length > 0 && picked.length === charged.length) return orderTotal;
  return picked.reduce((sum, v) => addDecimal(sum, v.lineTotal, 2), "0.00");
}

/**
 * The request's `lines`: none (the whole order: every refundable line) when nothing came back before and every open line is chosen; else
 * the chosen lines by id (the API refuses a whole-order refund after a line refund).
 */
export function requestLines(views: readonly RefundLineView[], chosen: ReadonlySet<number>): number[] | undefined {
  const picked = chosenOpen(views, chosen).map((v) => v.id);
  const open = views.filter((v) => v.state === "open");
  if (picked.length > 0 && picked.length === open.length && !views.some((v) => v.state === "refunded")) return undefined;
  return picked.sort((a, b) => a - b);
}

// --- the money ----------------------------------------------------------------------------------------------------------------------------

type Holding = Pick<PaymentView, "id" | "amount" | "refunded_amount" | "status" | "received_at" | "channel">;

/** What a payment still holds to give back: its amount less what was paid back of it; nothing for a voided one. */
export function openAmount(payment: Pick<PaymentView, "amount" | "refunded_amount" | "status">): string {
  if (payment.status === "voided") return "0.00";
  const left = subtractDecimal(payment.amount, payment.refunded_amount, 2);
  return compareDecimal(left, "0", 2) > 0 ? left : "0.00";
}

function instant(at: string): number {
  const ms = Date.parse(at);
  return Number.isNaN(ms) ? 0 : ms;
}

/** The payments that can give money back, last taken first (by when it was received, then the higher id), as the API orders them. */
export function paymentsLastFirst<P extends Holding>(payments: readonly P[]): P[] {
  return payments.filter((p) => compareDecimal(openAmount(p), "0", 2) > 0).sort((a, b) => instant(b.received_at) - instant(a.received_at) || b.id - a.id);
}

export interface Share {
  paymentId: number;
  amount: string;
}

/** The API's default split: last in, first out, each payment within what it holds; `short` is what the payments cannot cover. */
export function lifoSplit(payments: readonly Holding[], amount: string): { shares: Share[]; short: string } {
  let left = amount;
  const shares: Share[] = [];
  for (const payment of paymentsLastFirst(payments)) {
    if (compareDecimal(left, "0", 2) <= 0) break;
    const open = openAmount(payment);
    const take = compareDecimal(open, left, 2) < 0 ? open : left;
    shares.push({ paymentId: payment.id, amount: take });
    left = subtractDecimal(left, take, 2);
  }
  return { shares, short: compareDecimal(left, "0", 2) > 0 ? left : "0.00" };
}

/** The amounts typed per payment, in the payments' order; a blank or zero one is no share. */
export function sharesFromInputs(inputs: Readonly<Record<number, string>>, order: readonly number[]): Share[] {
  const shares: Share[] = [];
  for (const id of order) {
    const typed = (inputs[id] ?? "").trim();
    if (typed === "") continue;
    if (SHARE_AMOUNT.test(typed) && compareDecimal(typed, "0", 2) === 0) continue;
    shares.push({ paymentId: id, amount: typed });
  }
  return shares;
}

export type AllocationReason = "sum" | "payment" | "exceeds" | "repeated" | "amount";

export interface AllocationIssue {
  reason: AllocationReason;
  paymentId: number | null;
}

/** The API's checks of a share-out (422 payments.refund.allocation_invalid {reason, payment_id}), in its order. */
export function allocationIssue(payments: readonly Holding[], shares: readonly Share[], amount: string): AllocationIssue | null {
  const seen = new Set<number>();
  let sum = "0.00";
  for (const share of shares) {
    const payment = payments.find((p) => p.id === share.paymentId && p.status !== "voided");
    if (!payment) return { reason: "payment", paymentId: share.paymentId };
    if (seen.has(share.paymentId)) return { reason: "repeated", paymentId: share.paymentId };
    seen.add(share.paymentId);
    if (!SHARE_AMOUNT.test(share.amount) || compareDecimal(share.amount, "0", 2) <= 0) return { reason: "amount", paymentId: share.paymentId };
    if (compareDecimal(share.amount, openAmount(payment), 2) > 0) return { reason: "exceeds", paymentId: share.paymentId };
    sum = addDecimal(sum, share.amount, 2);
  }
  if (compareDecimal(sum, amount, 2) !== 0) return { reason: "sum", paymentId: null };
  return null;
}

function sameShares(a: readonly Share[], b: readonly Share[]): boolean {
  if (a.length !== b.length) return false;
  return a.every((share) => b.some((other) => other.paymentId === share.paymentId && compareDecimal(other.amount, share.amount, 2) === 0));
}

/** The request's `allocation`: none when it is the default split (the API makes the same); else every share as the API reads it. */
export function allocationBody(shares: readonly Share[], defaults: readonly Share[]): { payment_id: number; amount: string }[] | undefined {
  if (sameShares(shares, defaults)) return undefined;
  return shares.map((s) => ({ payment_id: s.paymentId, amount: addDecimal(s.amount, "0", 2) }));
}

/** A share on a channel other than cash is a transfer made outside the app: the refund needs its reference. */
export function needsReference(payments: readonly Holding[], shares: readonly Share[]): boolean {
  return shares.some((share) => {
    const payment = payments.find((p) => p.id === share.paymentId);
    return payment !== undefined && payment.channel.code !== CASH;
  });
}

// --- why the refund waits -----------------------------------------------------------------------------------------------------------------

export type RefundBlock = "no_lines" | "zero" | "short" | "allocation" | "reference" | "photo" | "reason" | "pin";

export function refundBlock(input: {
  chosen: number;
  amount: string;
  short: string;
  allocation: AllocationIssue | null;
  needsReference: boolean;
  reference: string;
  photoUploading: boolean;
  reason: string;
  pin: string;
}): RefundBlock | null {
  if (input.chosen === 0) return "no_lines";
  if (compareDecimal(input.amount, "0", 2) <= 0) return "zero";
  if (compareDecimal(input.short, "0", 2) > 0) return "short";
  if (input.allocation) return "allocation";
  if (input.needsReference && input.reference.trim() === "") return "reference";
  if (input.photoUploading) return "photo";
  const reason = input.reason.trim();
  if (reason.length < REFUND_REASON_MIN || reason.length > REFUND_REASON_MAX) return "reason";
  if (!REFUND_PIN.test(input.pin)) return "pin";
  return null;
}

// --- the API's refusals -------------------------------------------------------------------------------------------------------------------

/** Where a refusal belongs: lines, allocation, reference, proof, reason, pin, shift (the till's or the station's), or order (as a whole). */
export type RefundField = "lines" | "allocation" | "reference" | "proof" | "reason" | "pin" | "shift" | "order";

const FIELDS: Record<string, RefundField> = {
  "identity.pin.rejected": "pin",
  "identity.short_code.locked": "pin",
  "orders.refund.approver_forbidden": "pin",
  "payments.refund.pin_required": "pin",
  "orders.refund.reason_required": "reason",
  "orders.refund.line_invalid": "lines",
  "orders.refund.line_not_refundable": "lines",
  "orders.refund.line_refunded": "lines",
  "orders.refund.partial_not_allowed": "lines",
  "orders.refund.exceeds_paid": "lines",
  "orders.refund.not_refundable": "order",
  "orders.refund.window_closed": "order",
  "orders.refund.client_uuid_reused": "order",
  "payments.refund.allocation_invalid": "allocation",
  "payments.refund.reference_required": "reference",
  "payments.refund.proof_invalid": "proof",
  "payments.shift.required": "shift",
  "inventory.shift.required": "shift",
};

export function refundField(error: unknown): RefundField | null {
  if (typeof error !== "object" || error === null || !("key" in error)) return null;
  const key = (error as { key: unknown }).key;
  return typeof key === "string" && Object.prototype.hasOwnProperty.call(FIELDS, key) ? FIELDS[key] : null;
}

/** The reason of a refused share-out, when the API named one the dialog has words for. */
export function allocationReasonOf(error: unknown): AllocationReason | null {
  if (refundField(error) !== "allocation") return null;
  const reason = (error as { body?: Record<string, unknown> }).body?.reason;
  return reason === "sum" || reason === "payment" || reason === "exceeds" || reason === "repeated" || reason === "amount" ? reason : null;
}

// --- the result ---------------------------------------------------------------------------------------------------------------------------

export interface RefundResultView {
  amount: string;
  netPaid: string;
  payments: { paymentId: number; channelCode: string; nameEn: string; nameAm: string; amount: string }[];
  /** the order lines that went back into stock, and those that did not */
  restocked: number[];
  notRestocked: number[];
}

export function refundResultView(result: RefundResult): RefundResultView {
  return {
    amount: result.refund.amount,
    netPaid: result.order.net_paid,
    payments: result.payments.map((p) => ({ paymentId: p.payment_id, channelCode: p.channel_code, nameEn: p.channel_name_en, nameAm: p.channel_name_am, amount: p.amount })),
    restocked: result.refund.lines.filter((l) => l.disposition === "restock").map((l) => l.line_id),
    notRestocked: result.refund.lines.filter((l) => l.disposition !== "restock").map((l) => l.line_id),
  };
}
