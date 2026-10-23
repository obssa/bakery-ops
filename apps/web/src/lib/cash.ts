import type { CashBlock, CashChannelRow, VarianceReason } from "./api/types";
import { addDecimal, compareDecimal, isQuantityText, multiplyDecimal, subtractDecimal } from "./decimal";

// The plain logic of the till's cash screens (step 3.7d): the total of a count by denomination, the variance and what goes to the bank,
// what the review says before a drawer count is handed in, and the bodies of the declaration. No React and no browser, so e2e/p3 tests it
// directly. Money is decimal strings (2 decimals) end to end; a tally is a whole number per denomination, typed as digits.

const MAX_TALLY = 1_000_000;

/** The denominations of the count, largest first ("200" ... "0.05"); the server lists every configured one. */
export function tallyKeys(denominations: Record<string, unknown>): string[] {
  return Object.keys(denominations).sort((a, b) => compareDecimal(b, a, 2));
}

/** A count typed in a field: digits only, empty means none. Null when it is not a whole number up to 1,000,000. */
export function parseTally(text: string): number | null {
  const value = text.trim();
  if (value === "") return 0;
  if (!/^\d{1,7}$/.test(value)) return null;
  const n = Number(value);
  return n <= MAX_TALLY ? n : null;
}

/** The server's counts as the text of the fields: zero is an empty field. */
export function tallyOf(denominations: Record<string, number>): Record<string, string> {
  return Object.fromEntries(Object.entries(denominations).map(([key, n]) => [key, n === 0 ? "" : String(n)]));
}

/** Σ denomination x count as a 2 dp decimal string, exact; null while a field holds something that is not a whole number. */
export function tallyTotal(tally: Record<string, string>): string | null {
  let total = "0.00";
  for (const [key, text] of Object.entries(tally)) {
    const n = parseTally(text);
    if (n === null) return null;
    total = addDecimal(total, multiplyDecimal(key, String(n), 2), 2);
  }
  return total;
}

/** "-25.50", "+12.50", "0.00"; an empty text for none. */
export function signedMoney(value: string | null): string {
  if (value === null) return "";
  const normal = subtractDecimal(value, "0", 2);
  if (compareDecimal(normal, "0", 2) === 0) return "0.00";
  return normal.startsWith("-") ? normal : `+${normal}`;
}

/** What the cashier takes to the bank at a closing: counted minus the float left in the drawer. */
export function toBank(counted: string, floatLeft: string | null): string | null {
  return floatLeft === null ? null : subtractDecimal(counted, floatLeft, 2);
}

export interface CashReview {
  /** counted minus expected, as the server worked it out */
  variance: string;
  differs: boolean;
  /** channels where something was declared and it differs from what was taken */
  channelDiffs: CashChannelRow[];
  /** a cash variance or any channel difference: the declaration then needs a reason */
  needsReason: boolean;
  missingDeclaration: boolean;
  missingFloat: boolean;
  floatTooLarge: boolean;
  missingReason: boolean;
  missingNote: boolean;
  ready: boolean;
}

/** What blocks handing in a drawer count (the API refuses the same things: declaration_missing, float_required, reason_required, note_required). */
export function cashReview(type: "opening" | "closing", cash: CashBlock, reasons: readonly VarianceReason[]): CashReview {
  const differs = compareDecimal(cash.variance_total, "0", 2) !== 0;
  const channelDiffs = cash.channels.filter((row) => row.difference_sum !== null && compareDecimal(row.difference_sum, "0", 2) !== 0);
  const needsReason = differs || channelDiffs.length > 0;
  const closing = type === "closing";
  const missingFloat = closing && cash.float_left_total === null;
  const floatTooLarge = closing && cash.float_left_total !== null && compareDecimal(cash.float_left_total, cash.counted_total, 2) > 0;
  const missingReason = needsReason && cash.reason === null;
  const chosen = cash.reason === null ? null : reasons.find((reason) => reason.id === cash.reason?.id);
  const missingNote = needsReason && cash.reason !== null && chosen?.requires_note === true && (cash.note ?? "").trim() === "";
  const missingDeclaration = !cash.confirmed;
  return {
    variance: cash.variance_total,
    differs,
    channelDiffs,
    needsReason,
    missingDeclaration,
    missingFloat,
    floatTooLarge,
    missingReason,
    missingNote,
    ready: !missingDeclaration && !missingFloat && !floatTooLarge && !missingReason && !missingNote,
  };
}

export interface ChannelFields {
  receipts: string;
  sum: string;
}

/** The declared channels as the API takes them: a row with both fields valid is sent, both empty is left out, anything else is named. */
export function channelsBody(fields: Record<string, ChannelFields>): { rows: { code: string; receipts: number; sum: string }[]; incomplete: string[] } {
  const rows: { code: string; receipts: number; sum: string }[] = [];
  const incomplete: string[] = [];
  for (const [code, { receipts, sum }] of Object.entries(fields)) {
    const r = receipts.trim();
    const s = sum.trim();
    if (r === "" && s === "") continue;
    const count = r === "" ? null : parseTally(r);
    if (count === null || s === "" || !isQuantityText(s, 2)) {
      incomplete.push(code);
      continue;
    }
    rows.push({ code, receipts: count, sum: s });
  }
  return { rows, incomplete };
}

export interface CashParts {
  float_left_total?: string | null;
  channels?: { code: string; receipts: number; sum: string }[] | null;
  reason_id?: number | null;
  reason_note?: string | null;
}

/** The body of PUT /stock-counts/{count}/cash: always the whole count (the server takes it as such), plus only the parts that changed. Null for an invalid field. */
export function cashBody(tally: Record<string, string>, parts: CashParts): { denominations: Record<string, number> } & CashParts | null {
  const denominations: Record<string, number> = {};
  for (const [key, text] of Object.entries(tally)) {
    const n = parseTally(text);
    if (n === null) return null;
    denominations[key] = n;
  }
  return { denominations, ...parts };
}
