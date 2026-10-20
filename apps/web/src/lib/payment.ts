import { addDecimal, compareDecimal, subtractDecimal } from "./decimal";

// The cash keypad of the till and the change it shows. The amounts are decimal strings and stay strings: the keypad
// edits text, the change is subtracted on scaled integers (decimal.ts), and the API's `change_amount` has the last word.

export const CASH = "cash";

const MAX_WHOLE_DIGITS = 8;

export type KeypadKey = "0" | "1" | "2" | "3" | "4" | "5" | "6" | "7" | "8" | "9" | "00" | "." | "back" | "clear";

/** The entry after a key: digits and one decimal point with at most two decimals; "back" deletes one, "clear" all. */
export function pressKey(entry: string, key: KeypadKey): string {
  if (key === "clear") return "";
  if (key === "back") return entry.slice(0, -1);
  if (key === ".") {
    if (entry.includes(".")) return entry;
    return entry === "" ? "0." : `${entry}.`;
  }
  const next = entry === "0" && key !== "00" ? key : entry === "0" || (entry === "" && key === "00") ? "0" : entry + key;
  const [whole, fraction] = next.split(".");
  if (whole.length > MAX_WHOLE_DIGITS || (fraction !== undefined && fraction.length > 2)) return entry;
  return next;
}

/** The entry as a money string with two decimals ("400" -> "400.00"), or null when nothing was typed. */
export function tenderedOf(entry: string): string | null {
  const text = entry.endsWith(".") ? entry.slice(0, -1) : entry;
  return text === "" ? null : addDecimal(text, "0.00");
}

/** Change to give back for cash handed over against an amount due; null while the cash does not cover it. */
export function changeDue(tendered: string | null, due: string): string | null {
  if (tendered === null || compareDecimal(tendered, due, 2) < 0) return null;
  return subtractDecimal(tendered, due);
}
