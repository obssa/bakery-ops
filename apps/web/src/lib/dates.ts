import { toEthiopian } from "./ethiopianDate";

// How dates read for people (step 4.5f): one formatter for every date the web shows, so a person's choice of calendar reaches all of them.
// The dashboards' labels (lib/analytics.ts dayLabel, seriesLabel, rangeLabel, spanLabel), the timestamps (addisStamp in
// components/payments/shared.tsx: the payments, the audit log, the freshness badge), the shift pages' dates and the order labels all come
// here. The calendar is a display choice only: every date stays a Gregorian local date (YYYY-MM-DD) or a UTC instant underneath, in the
// addresses, the date pickers and every request. The Ethiopian labels follow the API's EthiopianDate::format ("Tikimt 16, 2019",
// "ጥቅምት 16 ቀን 2019 ዓ.ም.") with the month names of messages/*.json (dates.ethiopian_months); the clock stays the 24-hour clock of
// Addis Ababa in every calendar. "both" writes the Gregorian label with the Ethiopian one in brackets.

export type DateDisplay = "gregorian" | "ethiopian" | "both";
export const DATE_DISPLAYS: readonly DateDisplay[] = ["gregorian", "ethiopian", "both"];
export const DEFAULT_DISPLAY: DateDisplay = "gregorian";

export interface DateFormat {
  /** the page's language ("en", "am") */
  locale: string;
  display: DateDisplay;
  /** the thirteen Ethiopian month names in the page's language, Meskerem first (from messages: dates.ethiopian_months) */
  months: readonly string[];
}

/** A format, or a bare locale: a bare locale reads in the Gregorian calendar (how every label read before step 4.5f). */
export type DateFormatLike = DateFormat | string;

export function formatOf(like: DateFormatLike): DateFormat {
  return typeof like === "string" ? { locale: like, display: DEFAULT_DISPLAY, months: [] } : like;
}

/** The thirteen month names of the dictionary dates.ethiopian_months ({"1": "Meskerem", ..., "13": "Pagume"}), in order. */
export function monthNames(dictionary: Record<string, string>): string[] {
  return Array.from({ length: 13 }, (_, i) => dictionary[String(i + 1)] ?? String(i + 1));
}

const DATE = /^\d{4}-\d{2}-\d{2}$/;
const ADDIS_OFFSET_MS = 3 * 60 * 60 * 1000; // Africa/Addis_Ababa is UTC+3 all year (no daylight saving)

function isDate(value: string): boolean {
  if (!DATE.test(value)) return false;
  const day = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(day.getTime()) && day.toISOString().slice(0, 10) === value;
}

function intlOf(locale: string): string {
  return locale === "am" ? "am-ET" : "en-GB";
}

function gregorianDay(date: string, locale: string, withYear: boolean, weekday: boolean): string {
  return new Date(`${date}T12:00:00Z`).toLocaleDateString(intlOf(locale), {
    ...(weekday ? { weekday: "short" } : {}),
    day: "numeric",
    month: "short",
    ...(withYear ? { year: "numeric" } : {}),
    timeZone: "UTC",
  });
}

function weekdayOf(date: string, locale: string): string {
  return new Date(`${date}T12:00:00Z`).toLocaleDateString(intlOf(locale), { weekday: "short", timeZone: "UTC" });
}

/** A local date in the Ethiopian calendar: "Tikimt 17" ("Tikimt 17, 2019" with the year); "ጥቅምት 17" ("ጥቅምት 17 ቀን 2019 ዓ.ም."). */
export function ethiopianLabel(date: string, fmt: DateFormat, withYear = false): string {
  const { year, month, day } = toEthiopian(date);
  const name = fmt.months[month - 1] ?? String(month);
  if (fmt.locale === "am") return withYear ? `${name} ${day} ቀን ${year} ዓ.ም.` : `${name} ${day}`;
  return withYear ? `${name} ${day}, ${year}` : `${name} ${day}`;
}

/** "Gregorian (Ethiopian)", or one of the two. */
function shown(fmt: DateFormat, gregorian: () => string, ethiopian: () => string): string {
  if (fmt.display === "ethiopian") return ethiopian();
  if (fmt.display === "both") return `${gregorian()} (${ethiopian()})`;
  return gregorian();
}

/** A local date for people: "27 Oct" / "Tikimt 17" / "27 Oct (Tikimt 17)"; with the year, or with the weekday in front ("Tue 27 Oct"). */
export function dayText(date: string, like: DateFormatLike, options: { withYear?: boolean; weekday?: boolean } = {}): string {
  const fmt = formatOf(like);
  const { withYear = false, weekday = false } = options;
  if (!isDate(date)) return date;
  return shown(
    fmt,
    () => gregorianDay(date, fmt.locale, withYear, weekday),
    () => `${weekday && fmt.display === "ethiopian" ? `${weekdayOf(date, fmt.locale)} ` : ""}${ethiopianLabel(date, fmt, withYear)}`,
  );
}

function lastDayOfMonth(key: string): string {
  const [year, month] = key.split("-").map(Number);
  return new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);
}

/** The Ethiopian days a range covers: "Meskerem 21 – Tikimt 21, 2019", each end with its year when the two years differ. */
function ethiopianSpan(from: string, to: string, fmt: DateFormat, withYear: boolean): string {
  if (from === to) return ethiopianLabel(to, fmt, withYear);
  const sameYear = toEthiopian(from).year === toEthiopian(to).year;
  return `${ethiopianLabel(from, fmt, withYear && !sameYear)} – ${ethiopianLabel(to, fmt, withYear)}`;
}

/**
 * A month of a series (YYYY-MM, a Gregorian month: the API buckets by it): "Oct 2026"; in the Ethiopian calendar the days it covers
 * ("Meskerem 21 – Tikimt 21, 2019"), since a Gregorian month is not an Ethiopian one.
 */
export function monthText(key: string, like: DateFormatLike): string {
  const fmt = formatOf(like);
  const first = `${key}-01`;
  if (!isDate(first)) return key;
  return shown(
    fmt,
    () => new Date(`${first}T12:00:00Z`).toLocaleDateString(intlOf(fmt.locale), { month: "short", year: "numeric", timeZone: "UTC" }),
    () => ethiopianSpan(first, lastDayOfMonth(key), fmt, true),
  );
}

/** A range with its years: "21 Oct 2026 – 27 Oct 2026" / "Tikimt 11, 2019 – Tikimt 17, 2019"; one day once. */
export function rangeText(range: { from: string; to: string }, like: DateFormatLike): string {
  const fmt = formatOf(like);
  const gregorian = () => {
    const to = gregorianDay(range.to, fmt.locale, true, false);
    return range.from === range.to ? to : `${gregorianDay(range.from, fmt.locale, true, false)} – ${to}`;
  };
  const ethiopian = () => (range.from === range.to ? ethiopianLabel(range.to, fmt, true) : `${ethiopianLabel(range.from, fmt, true)} – ${ethiopianLabel(range.to, fmt, true)}`);
  return shown(fmt, gregorian, ethiopian);
}

/** The days of a week or a run, without years: "21 Oct – 27 Oct" / "Tikimt 11 – Tikimt 17"; one day once. */
export function spanText(range: { from: string; to: string }, like: DateFormatLike): string {
  const fmt = formatOf(like);
  const gregorian = () =>
    range.from === range.to ? gregorianDay(range.from, fmt.locale, false, false) : `${gregorianDay(range.from, fmt.locale, false, false)} – ${gregorianDay(range.to, fmt.locale, false, false)}`;
  const ethiopian = () => (range.from === range.to ? ethiopianLabel(range.from, fmt) : `${ethiopianLabel(range.from, fmt)} – ${ethiopianLabel(range.to, fmt)}`);
  return shown(fmt, gregorian, ethiopian);
}

/** A local date as the shift pages write it: "2026-10-27" (as before), "Tikimt 17, 2019", or both. A text that is not a date is kept. */
export function isoDayText(date: string, like: DateFormatLike): string {
  const fmt = formatOf(like);
  if (!isDate(date)) return date;
  return shown(fmt, () => date, () => ethiopianLabel(date, fmt, true));
}

/**
 * An instant (ISO 8601) in the bakery's time zone: "2026-10-27 14:05" (the same in both languages), "Tikimt 17, 2019 14:05", or
 * "2026-10-27 14:05 (Tikimt 17, 2019)". The day is Addis Ababa's local day, as the API's EthiopianDate reads a local time.
 */
export function stampText(iso: string | null | undefined, like: DateFormatLike): string {
  if (!iso) return "—";
  const fmt = formatOf(like);
  const at = new Date(iso);
  if (Number.isNaN(at.getTime())) return iso;
  const local = new Date(at.getTime() + ADDIS_OFFSET_MS).toISOString();
  const date = local.slice(0, 10);
  const clock = local.slice(11, 16);
  if (fmt.display === "ethiopian") return `${ethiopianLabel(date, fmt, true)} ${clock}`;
  if (fmt.display === "both") return `${date} ${clock} (${ethiopianLabel(date, fmt, true)})`;
  return `${date} ${clock}`;
}

/**
 * An instant with its day and clock, as the bell, the production board and the till's receipt preview write it: "27 Oct, 14:05" ("27 Oct
 * 2026, 14:05" with the year; in the device's time zone unless one is named, as before step 4.5f); "Tikimt 17 14:05" in Addis Ababa's day
 * and clock; or both.
 */
export function whenText(iso: string | null | undefined, like: DateFormatLike, options: { withYear?: boolean; timeZone?: string } = {}): string {
  if (!iso) return "—";
  const fmt = formatOf(like);
  const at = new Date(iso);
  if (Number.isNaN(at.getTime())) return iso;
  const { withYear = false, timeZone } = options;
  const gregorian = () =>
    at.toLocaleString(intlOf(fmt.locale), { ...(timeZone ? { timeZone } : {}), day: "numeric", month: "short", ...(withYear ? { year: "numeric" } : {}), hour: "2-digit", minute: "2-digit" });
  const ethiopian = () => {
    const local = new Date(at.getTime() + ADDIS_OFFSET_MS).toISOString();
    return `${ethiopianLabel(local.slice(0, 10), fmt, withYear)} ${local.slice(11, 16)}`;
  };
  return shown(fmt, gregorian, ethiopian);
}

// --- the person's choice, kept in the browser -----------------------------------------------------------------------------------------------

/**
 * Where a person's choice is kept: the browser's storage, one key per signed-in user (two people sharing a tablet keep their own). The
 * API has no preference field (GET /me), so nothing is sent; every read and write is guarded, so private mode or blocked site data
 * reads Gregorian and a choice that cannot be kept lasts for the page only.
 */
export function displayStorageKey(userId: number): string {
  return `bakery-ops.date-display.${userId}`;
}

export function parseDisplay(value: unknown): DateDisplay {
  return typeof value === "string" && (DATE_DISPLAYS as readonly string[]).includes(value) ? (value as DateDisplay) : DEFAULT_DISPLAY;
}

type Reader = Pick<Storage, "getItem">;
type Writer = Pick<Storage, "setItem">;

/** The kept choice of a person; Gregorian when nothing (or nothing readable) is kept. `storage` may throw or be null. */
export function readDisplay(storage: () => Reader | null, userId: number): DateDisplay {
  try {
    return parseDisplay(storage()?.getItem(displayStorageKey(userId)) ?? null);
  } catch {
    return DEFAULT_DISPLAY;
  }
}

/** Keeps a person's choice; false when the browser refused it (the choice then lasts until the page is left). */
export function writeDisplay(storage: () => Writer | null, userId: number, display: DateDisplay): boolean {
  try {
    const target = storage();
    if (!target) return false;
    target.setItem(displayStorageKey(userId), display);
    return true;
  } catch {
    return false;
  }
}
