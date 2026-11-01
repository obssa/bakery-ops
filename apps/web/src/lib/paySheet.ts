import type { PaymentChannel } from "./api/types";
import { addDecimal, compareDecimal, subtractDecimal } from "./decimal";
import { CASH, changeDue as cashChange, tenderedOf } from "./payment";
import type { PayBlock } from "./shifts";

// The rules of the till's pay sheet (step 4.5b, docs/phases/phase-4-plan.md section 6 item 5): plain functions over plain data, so the
// sheet and e2e/p4/pay-sheet-logic.spec.ts read the same rules. A bill is paid by 1 to 6 rows, each a payment on one channel; the rows must
// add up to exactly the balance, and the API records them all or none (POST /orders/{order}/payment-batches). Amounts are decimal strings
// end to end (ADR 0004): typed text is checked and padded to two decimals, added and compared on scaled integers (decimal.ts), never a float.
// What is checked here is a convenience for the cashier; the API checks everything again (the channel, the photo, the reference pattern
// with its ReferencePattern, the shift, the sum) and has the last word.

/** At most this many payments in one batch (RecordPaymentBatchRequest::MAX_ROWS). */
export const MAX_ROWS = 6;

/** The API refuses a longer reference without matching it (ReferencePattern, RecordPaymentRequest max:64). */
export const REFERENCE_MAX = 64;

const MONEY = /^\d{1,10}(\.\d{1,2})?$/;

export type PhotoStatus = "none" | "uploading" | "failed" | "uploaded";

/** A row's receipt photo: its upload's own client_uuid and Idempotency-Key are kept, so a retry of a failed upload is the same write. */
export interface RowPhoto {
  status: PhotoStatus;
  /** the stored file's id, once uploaded (the row sends it as receipt_file_id) */
  fileId: number | null;
  clientUuid: string | null;
  key: string | null;
}

export const NO_PHOTO: RowPhoto = { status: "none", fileId: null, clientUuid: null, key: null };

/** One payment of the sheet, as typed. `id` is the payment's client_uuid, fixed for the row's life (a retry sends the same). */
export interface PayRow {
  id: string;
  /** the channel's code, or null until one is picked */
  channel: string | null;
  /** the amount as typed */
  amount: string;
  /** cash only: the cash handed over, as the keypad typed it */
  tendered: string;
  reference: string;
  photo: RowPhoto;
}

/** The enabled channels the till may take, in the admin's order (sort), with cash first. */
export function sheetChannels(channels: PaymentChannel[]): PaymentChannel[] {
  const usable = channels.filter((c) => c.is_enabled && c.accepted_now);
  const sorted = [...usable].sort((a, b) => a.sort - b.sort || a.id - b.id);
  return [...sorted.filter((c) => c.code === CASH), ...sorted.filter((c) => c.code !== CASH)];
}

/** Typed text as a money string with two decimals ("12.5" -> "12.50"), or null when it is not a plain amount. */
export function moneyOf(text: string): string | null {
  const value = text.trim();
  if (!MONEY.test(value)) return null;
  return addDecimal(value, "0.00");
}

/** The row's amount when it is a valid amount above zero. */
export function rowAmount(row: PayRow): string | null {
  const amount = moneyOf(row.amount);
  return amount !== null && compareDecimal(amount, "0", 2) > 0 ? amount : null;
}

/** The sum of the rows' valid amounts. */
export function allocated(rows: PayRow[]): string {
  return rows.reduce((sum, row) => addDecimal(sum, rowAmount(row) ?? "0.00"), "0.00");
}

/** What is left to cover: the balance minus the rows' valid amounts (below zero when they cover more). */
export function remaining(balance: string, rows: PayRow[]): string {
  return subtractDecimal(balance, allocated(rows));
}

/** The amount that makes the sheet add up with row `index` (the others as they are), or null when the others cover the balance already. */
export function restOf(balance: string, rows: PayRow[], index: number): string | null {
  const others = rows.filter((_, i) => i !== index);
  const rest = remaining(balance, others);
  return compareDecimal(rest, "0", 2) > 0 ? rest : null;
}

/** May row `index` take this channel? Cash may be on one row only (one drawer, one count of what was handed over); others on several. */
export function canUseChannel(rows: PayRow[], index: number, channel: PaymentChannel): boolean {
  if (channel.code !== CASH) return true;
  return !rows.some((row, i) => i !== index && row.channel === CASH);
}

/** A new row: the rest of the balance (empty when nothing is left), on cash when no other row has it. */
export function newRow(id: string, balance: string, rows: PayRow[], channels: PaymentChannel[]): PayRow {
  const rest = remaining(balance, rows);
  const cash = sheetChannels(channels).find((c) => c.code === CASH);
  const channel = cash && !rows.some((row) => row.channel === CASH) ? CASH : null;
  return { id, channel, amount: compareDecimal(rest, "0", 2) > 0 ? rest : "", tendered: "", reference: "", photo: NO_PHOTO };
}

/** The change of a cash row: the cash handed over minus its amount; null for another channel, or while the cash does not cover it. */
export function changeDue(row: PayRow): string | null {
  if (row.channel !== CASH) return null;
  const amount = rowAmount(row);
  if (amount === null) return null;
  return cashChange(tenderedOf(row.tendered), amount);
}

export type ReferenceCheck = "ok" | "missing" | "invalid";

const compiled = new Map<string, RegExp | null>();

/**
 * The channel's pattern as the API reads it (ReferencePattern: the body matched against the whole reference). A body this browser cannot
 * compile, in the strict (u) mode or the plain one, is null: it is not checked here, and the API decides. The admin's channel form of
 * step 4.5c checks a pattern being typed with it.
 */
export function patternOf(body: string): RegExp | null {
  if (!compiled.has(body)) {
    let found: RegExp | null = null;
    for (const flags of ["u", ""]) {
      try {
        found = new RegExp(`^(?:${body})$`, flags);
        break;
      } catch {
        found = null;
      }
    }
    compiled.set(body, found);
  }
  return compiled.get(body) ?? null;
}

/** Is the reference fit to send on this channel? Trimmed as the API trims it, case-sensitive as the API matches it. */
export function checkReference(channel: PaymentChannel, reference: string): ReferenceCheck {
  if (!channel.requires_reference) return "ok";
  const value = reference.trim();
  if (value === "") return "missing";
  if (value.length > REFERENCE_MAX) return "invalid";
  const pattern = channel.reference_pattern ? patternOf(channel.reference_pattern) : null;
  if (pattern && !pattern.test(value)) return "invalid";
  return "ok";
}

export type RowIssue = "channel" | "amount" | "cash_short" | "reference_missing" | "reference_invalid" | "photo_missing" | "photo_uploading" | "photo_failed";

/** The first thing a row still needs, in the order the row shows it, or null when it is complete. */
export function rowIssue(row: PayRow, channel: PaymentChannel | undefined): RowIssue | null {
  if (!channel || row.channel !== channel.code) return "channel";
  const amount = rowAmount(row);
  if (amount === null) return "amount";
  if (channel.code === CASH && changeDue(row) === null) return "cash_short";
  const reference = checkReference(channel, row.reference);
  if (reference === "missing") return "reference_missing";
  if (reference === "invalid") return "reference_invalid";
  if (channel.requires_photo) {
    if (row.photo.status === "uploading") return "photo_uploading";
    if (row.photo.status === "failed") return "photo_failed";
    if (row.photo.status !== "uploaded" || row.photo.fileId === null) return "photo_missing";
  }
  return null;
}

function channelOf(channels: PaymentChannel[], code: string | null): PaymentChannel | undefined {
  return code === null ? undefined : sheetChannels(channels).find((c) => c.code === code);
}

/** Is every row complete? An empty sheet is not. */
export function rowsComplete(rows: PayRow[], channels: PaymentChannel[]): boolean {
  return rows.length > 0 && rows.every((row) => rowIssue(row, channelOf(channels, row.channel)) === null);
}

export interface SheetState {
  /** the order's balance, as the API reported it */
  balance: string;
  rows: PayRow[];
  channels: PaymentChannel[];
  /** why the till's shift blocks payments (payBlockReason), or null */
  shift: PayBlock | null;
  online: boolean;
}

export type Block =
  | { kind: "shift"; reason: PayBlock }
  | { kind: "offline" }
  | { kind: "nothing_due" }
  | { kind: "no_rows" }
  | { kind: "too_many_rows" }
  | { kind: "row"; row: number; issue: RowIssue }
  | { kind: "short"; amount: string }
  | { kind: "over"; amount: string };

/** Why Complete is disabled, the first reason from the top of the sheet; null when it may be pressed. */
export function blockReason(state: SheetState): Block | null {
  if (state.shift !== null) return { kind: "shift", reason: state.shift };
  // offline is no block since step 5.2a: a completed batch joins the outbox (plan 10.2 D10)
  if (compareDecimal(state.balance, "0", 2) <= 0) return { kind: "nothing_due" };
  if (state.rows.length === 0) return { kind: "no_rows" };
  if (state.rows.length > MAX_ROWS) return { kind: "too_many_rows" };
  for (let i = 0; i < state.rows.length; i++) {
    const issue = rowIssue(state.rows[i], channelOf(state.channels, state.rows[i].channel));
    if (issue !== null) return { kind: "row", row: i, issue };
  }
  const left = remaining(state.balance, state.rows);
  const sign = compareDecimal(left, "0", 2);
  if (sign > 0) return { kind: "short", amount: left };
  if (sign < 0) return { kind: "over", amount: subtractDecimal("0.00", left) };
  return null;
}

export function canComplete(state: SheetState): boolean {
  return blockReason(state) === null;
}

/** One row of the batch's body (POST /orders/{order}/payment-batches). */
export interface BatchRow {
  client_uuid: string;
  channel_code: string;
  amount: string;
  tendered_amount?: string;
  reference_no?: string;
  receipt_file_id?: number;
}

/**
 * The rows as the API takes them: each row's own client_uuid, the amount as a money string, the cash handed over for cash, the reference
 * and the photo only where the channel asks for them (a field the sheet does not show for the channel is not sent). Call it on complete rows.
 */
export function batchRows(rows: PayRow[], channels: PaymentChannel[]): BatchRow[] {
  return rows.map((row) => {
    const channel = channelOf(channels, row.channel);
    const body: BatchRow = { client_uuid: row.id, channel_code: row.channel ?? "", amount: rowAmount(row) ?? row.amount };
    if (row.channel === CASH) {
      const tendered = tenderedOf(row.tendered);
      if (tendered !== null) body.tendered_amount = tendered;
    }
    if (channel?.requires_reference) body.reference_no = row.reference.trim();
    if (channel?.requires_photo && row.photo.fileId !== null) body.receipt_file_id = row.photo.fileId;
    return body;
  });
}

/**
 * After a failed write, is its Idempotency-Key kept for the retry? Only when the outcome is unknown: no answer (network), a server error
 * (5xx, which the API never stores) or the same key still running (idempotency.in_flight); the write may have happened, so the retry must
 * replay it. A refusal (4xx) wrote nothing and the API would replay it under the same key, so the next try takes a new key; the batch's
 * and the rows' client_uuids stay, so even then nothing can be recorded twice.
 */
export function keepsKey(error: unknown): boolean {
  if (typeof error !== "object" || error === null || !("status" in error)) return true;
  const { status, key } = error as { status: unknown; key?: unknown };
  if (typeof status !== "number") return true;
  if (status === 0 || status >= 500) return true;
  return key === "idempotency.in_flight";
}

/** A reference's last 4 characters, as the printed receipt shows it (the till's receipt preview, the order record of step 4.5d). */
export function referenceTail(reference: string | null | undefined): string | null {
  if (!reference) return null;
  const trimmed = reference.trim();
  if (trimmed === "") return null;
  return trimmed.length <= 4 ? trimmed : `…${trimmed.slice(-4)}`;
}
