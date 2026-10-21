// Money and quantities are decimal strings end to end (ADR 0004): never parsed into floats. These helpers compare and
// add them as scaled integers (BigInt) and show them as the API sent them, only without the padding zeros a
// quantity carries ("2.0000" -> "2"). Money keeps 2 decimals, quantities up to 4.

const DECIMAL = /^(-?)(\d+)(?:\.(\d+))?$/;

function parts(value: string): { negative: boolean; whole: string; fraction: string } {
  const match = DECIMAL.exec(value.trim());
  if (!match) throw new Error(`not a decimal string: ${JSON.stringify(value)}`);
  return { negative: match[1] === "-", whole: match[2], fraction: match[3] ?? "" };
}

/** The value as an integer count of 10^-dp units; a longer fraction than dp is an error, never a rounding. */
function scaled(value: string, dp: number): bigint {
  const { negative, whole, fraction } = parts(value);
  if (fraction.length > dp && /[1-9]/.test(fraction.slice(dp))) throw new Error(`${value} has more than ${dp} decimals`);
  const digits = BigInt(whole + fraction.slice(0, dp).padEnd(dp, "0"));
  return negative ? -digits : digits;
}

function unscaled(units: bigint, dp: number): string {
  const negative = units < BigInt(0);
  const digits = (negative ? -units : units).toString().padStart(dp + 1, "0");
  const whole = digits.slice(0, digits.length - dp);
  const fraction = digits.slice(digits.length - dp);
  return `${negative ? "-" : ""}${whole}${dp > 0 ? `.${fraction}` : ""}`;
}

export function compareDecimal(a: string, b: string, dp = 4): -1 | 0 | 1 {
  const x = scaled(a, dp);
  const y = scaled(b, dp);
  return x < y ? -1 : x > y ? 1 : 0;
}

export function addDecimal(a: string, b: string, dp = 2): string {
  return unscaled(scaled(a, dp) + scaled(b, dp), dp);
}

export function subtractDecimal(a: string, b: string, dp = 2): string {
  return unscaled(scaled(a, dp) - scaled(b, dp), dp);
}

/**
 * A money amount times a quantity (a line total): exact on scaled integers, rounded half up to `dp` places when the
 * product has more (the API rounds the same way; its totals replace this figure after a send). Never a float.
 */
export function multiplyDecimal(amount: string, quantity: string, dp = 2): string {
  const a = scaled(amount, dp);
  const q = scaled(quantity, 4);
  const product = a * q; // dp + 4 decimals
  const unit = BigInt(10) ** BigInt(4);
  const negative = product < BigInt(0);
  const magnitude = negative ? -product : product;
  const rounded = (magnitude + unit / BigInt(2)) / unit;
  return unscaled(negative ? -rounded : rounded, dp);
}

/** Adds one to a whole-number quantity ("2" -> "3"); subtracts with a floor of zero when `by` is negative. */
export function stepQuantity(quantity: string, by: 1 | -1): string {
  const next = scaled(quantity, 0) + BigInt(by);
  return unscaled(next < BigInt(0) ? BigInt(0) : next, 0);
}

/** "2.0000" -> "2", "0.2500" -> "0.25": how a quantity reads on a ticket. Not a number conversion. */
export function trimDecimal(value: string): string {
  const { negative, whole, fraction } = parts(value);
  const trimmed = fraction.replace(/0+$/, "");
  const text = trimmed ? `${whole}.${trimmed}` : whole;
  return negative && /[1-9]/.test(text) ? `-${text}` : text;
}

/** Is it a plain non-negative decimal string with at most `dp` decimals ("6", "2.5", "0.0625")? Typed quantities are checked with this, never parsed. */
export function isQuantityText(value: string, dp = 4): boolean {
  return new RegExp(`^\d+(\.\d{1,${dp}})?$`).test(value.trim());
}
