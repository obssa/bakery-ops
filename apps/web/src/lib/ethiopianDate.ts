// The Ethiopian calendar (step 4.5f): twelve months of 30 days and Pagume of 5 days, 6 in a year whose number plus one is divisible by 4,
// the new year on Meskerem 1 (11 September, or 12 September in the Gregorian year before a leap year). The twin of the API's
// apps/api/app/Shared/Time/EthiopianDate.php (which prints the receipt's date when receipt_settings.ethiopian_dates is on): the same
// conversion through the Julian day number with integer arithmetic only, and e2e/p4/dashboards-logic.spec.ts runs the PHP unit table
// against it. A label only: nothing stored is ever in this calendar. Its input is a local date (YYYY-MM-DD, the branch's day), so no time
// zone enters it; a timestamp is turned into its Addis Ababa date first (lib/dates.ts).

export interface EthiopianDay {
  year: number;
  /** 1 (Meskerem) to 13 (Pagume) */
  month: number;
  day: number;
}

/** The Julian day number of Meskerem 1 of year 1 (Amete Mihret) minus 365: the epoch the standard conversion counts from (as PHP's EPOCH). */
const EPOCH = 1_723_856;

const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

function mod(a: number, b: number): number {
  return ((a % b) + b) % b;
}

function floorDiv(a: number, b: number): number {
  return (a - mod(a, b)) / b;
}

/** The Julian day number of a day of the proleptic Gregorian calendar (PHP's julianDay). */
function julianDay(year: number, month: number, day: number): number {
  const a = floorDiv(14 - month, 12);
  const y = year + 4800 - a;
  const m = month + 12 * a - 3;
  return day + floorDiv(153 * m + 2, 5) + 365 * y + floorDiv(y, 4) - floorDiv(y, 100) + floorDiv(y, 400) - 32045;
}

/** The Gregorian date (YYYY-MM-DD) of a Julian day number. */
function gregorianOf(jdn: number): string {
  const a = jdn + 32044;
  const b = floorDiv(4 * a + 3, 146_097);
  const c = a - floorDiv(146_097 * b, 4);
  const d = floorDiv(4 * c + 3, 1461);
  const e = c - floorDiv(1461 * d, 4);
  const m = floorDiv(5 * e + 2, 153);
  const day = e - floorDiv(153 * m + 2, 5) + 1;
  const month = m + 3 - 12 * floorDiv(m, 10);
  const year = 100 * b + d - 4800 + floorDiv(m, 10);
  return `${String(year).padStart(4, "0")}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

/** A leap year has a Pagume of 6 days: its number plus one is divisible by 4 (2011, 2015, 2019...). */
export function isEthiopianLeapYear(year: number): boolean {
  return mod(year, 4) === 3;
}

/** How many days a month has: 30, and Pagume (13) 5 or 6. */
export function ethiopianMonthDays(year: number, month: number): number {
  if (month === 13) return isEthiopianLeapYear(year) ? 6 : 5;
  return 30;
}

/** The Ethiopian date of a local Gregorian date (YYYY-MM-DD); throws on a text that is not one. */
export function toEthiopian(date: string): EthiopianDay {
  const match = DATE.exec(date);
  if (!match) throw new RangeError(`not a date: ${date}`);
  const days = julianDay(Number(match[1]), Number(match[2]), Number(match[3])) - EPOCH;
  const r = mod(days, 1461);
  const n = mod(r, 365) + 365 * floorDiv(r, 1460);
  const year = 4 * floorDiv(days, 1461) + floorDiv(r, 365) - floorDiv(r, 1460);
  return { year, month: floorDiv(n, 30) + 1, day: mod(n, 30) + 1 };
}

/** The Gregorian date (YYYY-MM-DD) of an Ethiopian date; null for a day the calendar does not have (Pagume 6 of a common year, month 14). */
export function fromEthiopian({ year, month, day }: EthiopianDay): string | null {
  if (![year, month, day].every(Number.isInteger)) return null;
  if (month < 1 || month > 13 || day < 1 || day > ethiopianMonthDays(year, month)) return null;
  return gregorianOf(EPOCH + 365 + 365 * (year - 1) + floorDiv(year, 4) + 30 * month + day - 31);
}
