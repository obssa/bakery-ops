import type { ChannelPoint, FreshnessDay, ItemFigures, ReportName, SeriesGroup } from "./api/analytics";
import { addDecimal, compareDecimal, divideDecimal, subtractDecimal } from "./decimal";

export type { FreshnessDay } from "./api/analytics";

// The rules of the analytics screens (step 4.5e, docs/phases/phase-4-plan.md sections 4.6 and 6 item 5): plain functions over plain data, so
// the screens and e2e/p4/dashboards-logic.spec.ts read the same rules. Every range is a range of the branch's local dates (YYYY-MM-DD, both
// ends counted) and its arithmetic is calendar arithmetic on UTC dates, so no time zone enters it: the dates already are Addis Ababa's.
// The API checks every range again (at most 400 days, never after today) and answers analytics.range.* in both languages. Figures stay
// decimal strings (decimal.ts): a change from the previous period is worked on scaled integers, never a float. The date labels of the
// dashboards are made here, in one place, so step 4.5f can switch them to the Ethiopian calendar.

export const RANGE_MAX_DAYS = 400;
export const REBUILD_SYNC_MAX_DAYS = 7;
export const REBUILD_QUEUE_MAX_DAYS = 31;

export type Preset = "today" | "7" | "30" | "90";
export const PRESETS: readonly Preset[] = ["today", "7", "30", "90"];
export const DEFAULT_PRESET: Preset = "30";

export interface DayRange {
  from: string;
  to: string;
}

export interface AnalyticsRange extends DayRange {
  /** which preset the range is, or "custom" for dates of its own (a typed range, the weekly summary's link) */
  preset: Preset | "custom";
}

// --- local dates ------------------------------------------------------------------------------------------------------------------------

const DATE = /^\d{4}-\d{2}-\d{2}$/;

function dayNumber(date: string): number {
  return Date.UTC(Number(date.slice(0, 4)), Number(date.slice(5, 7)) - 1, Number(date.slice(8, 10))) / 86_400_000;
}

function dateOf(number: number): string {
  return new Date(number * 86_400_000).toISOString().slice(0, 10);
}

/** A real calendar date written YYYY-MM-DD (not 2026-02-30). */
export function isLocalDate(value: string): boolean {
  if (!DATE.test(value)) return false;
  const day = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(day.getTime()) && day.toISOString().slice(0, 10) === value;
}

/** The local date `days` after (negative: before) a local date. */
export function addDays(date: string, days: number): string {
  return dateOf(dayNumber(date) + days);
}

/** How many local days a range holds, both ends counted. */
export function daysBetween(from: string, to: string): number {
  return dayNumber(to) - dayNumber(from) + 1;
}

/** Every local date of a range, oldest first. */
export function daysOf(range: DayRange): string[] {
  const days: string[] = [];
  for (let n = dayNumber(range.from); n <= dayNumber(range.to); n++) days.push(dateOf(n));
  return days;
}

const PRESET_DAYS: Record<Preset, number> = { today: 1, "7": 7, "30": 30, "90": 90 };

/** A preset as the range of local days it means: the last 1, 7, 30 or 90 days, today included. */
export function presetRange(preset: Preset, today: string): AnalyticsRange {
  return { preset, from: addDays(today, -(PRESET_DAYS[preset] - 1)), to: today };
}

/** The same number of days just before the range (what ?compare=previous reads). */
export function previousPeriod(range: DayRange): DayRange {
  const days = daysBetween(range.from, range.to);
  return { from: addDays(range.from, -days), to: addDays(range.from, -1) };
}

/** Every day an answer reads: the range, and with the comparison the period before it too. */
export function spanOf(range: DayRange, compare: boolean): DayRange {
  return compare ? { from: previousPeriod(range).from, to: range.to } : { from: range.from, to: range.to };
}

/** The days the freshness feed watches: the span read, or the range alone when the span is longer than any /analytics read may be. */
export function freshnessRange(range: DayRange, compare: boolean): DayRange {
  const span = spanOf(range, compare);
  return daysBetween(span.from, span.to) > RANGE_MAX_DAYS ? { from: range.from, to: range.to } : span;
}

export type RangeIssue = "dates" | "order" | "future" | "too_large";

/** What the API would refuse: a missing or impossible date, from after to, a day after today, more than 400 days. */
export function rangeIssue(from: string, to: string, today: string): RangeIssue | null {
  if (!isLocalDate(from) || !isLocalDate(to)) return "dates";
  if (from > to) return "order";
  if (from > today || to > today) return "future";
  return daysBetween(from, to) > RANGE_MAX_DAYS ? "too_large" : null;
}

/**
 * The range the page's address holds: ?from=&to= (a custom range; a missing `to` is today and a missing `from` the 30 days up to `to`, as
 * the API reads them), else ?range=today|7|30|90, else the last 30 days. A malformed value is dropped. A range past today or too long is kept
 * as it was asked for: the API says why it cannot read it.
 */
export function rangeFromParams(params: { get(name: string): string | null }, today: string): AnalyticsRange {
  const from = (params.get("from") ?? "").trim();
  const to = (params.get("to") ?? "").trim();
  if (isLocalDate(from) && (to === "" || isLocalDate(to))) return { preset: "custom", from, to: to || today };
  if (from === "" && isLocalDate(to)) return { preset: "custom", from: addDays(to, -(PRESET_DAYS[DEFAULT_PRESET] - 1)), to };
  const preset = (params.get("range") ?? "").trim();
  return presetRange((PRESETS as readonly string[]).includes(preset) ? (preset as Preset) : DEFAULT_PRESET, today);
}

/** The range as the page's address writes it; the default leaves the address clean. */
export function rangeParams(range: AnalyticsRange): Record<string, string> {
  if (range.preset === "custom") return { from: range.from, to: range.to };
  return range.preset === DEFAULT_PRESET ? {} : { range: range.preset };
}

/** The Monday of a local date's week (weeks start on Monday, local, as the API's Buckets). */
export function weekStart(date: string): string {
  const weekday = (new Date(`${date}T00:00:00Z`).getUTCDay() + 6) % 7;
  return addDays(date, -weekday);
}

/** The key of the series point a day falls in: the day, its week's Monday, or YYYY-MM. */
export function seriesKeyOf(date: string, group: SeriesGroup): string {
  if (group === "week") return weekStart(date);
  if (group === "month") return date.slice(0, 7);
  return date;
}

// --- labels (one place: step 4.5f switches them to the Ethiopian calendar) -----------------------------------------------------------------

function intlOf(locale: string): string {
  return locale === "am" ? "am-ET" : "en-GB";
}

/** A local date for people: "27 Oct" ("27 Oct 2026" with the year). */
export function dayLabel(date: string, locale: string, withYear = false): string {
  return new Date(`${date}T12:00:00Z`).toLocaleDateString(intlOf(locale), { day: "numeric", month: "short", ...(withYear ? { year: "numeric" } : {}), timeZone: "UTC" });
}

/** A series key for people: a day with its weekday ("Tue 27 Oct"), a week by its Monday ("26 Oct"), a month ("Oct 2026"). */
export function seriesLabel(key: string, group: SeriesGroup, locale: string): string {
  if (group === "month") return new Date(`${key}-01T12:00:00Z`).toLocaleDateString(intlOf(locale), { month: "short", year: "numeric", timeZone: "UTC" });
  if (group === "week") return dayLabel(key, locale);
  return new Date(`${key}T12:00:00Z`).toLocaleDateString(intlOf(locale), { weekday: "short", day: "numeric", month: "short", timeZone: "UTC" });
}

/** A range for people: "27 Oct 2026", or "21 Oct 2026 – 27 Oct 2026". */
export function rangeLabel(range: DayRange, locale: string): string {
  const to = dayLabel(range.to, locale, true);
  return range.from === range.to ? to : `${dayLabel(range.from, locale, true)} – ${to}`;
}

/** The days of a week or a run for people: "27 Oct", or "21 Oct – 27 Oct". */
export function spanLabel(range: DayRange, locale: string): string {
  return range.from === range.to ? dayLabel(range.from, locale) : `${dayLabel(range.from, locale)} – ${dayLabel(range.to, locale)}`;
}

/** A local hour of the day: "07:00". */
export function hourLabel(hour: number): string {
  return `${String(hour).padStart(2, "0")}:00`;
}

// --- freshness ------------------------------------------------------------------------------------------------------------------------------

export type Ago = { unit: "never" | "now"; count: 0 } | { unit: "minutes" | "hours" | "days"; count: number };

/** How long ago an instant was: "updated 2 min ago". A server clock a little ahead of the device's is "just now". */
export function agoOf(asOf: string | null, now: number): Ago {
  if (!asOf) return { unit: "never", count: 0 };
  const at = Date.parse(asOf);
  if (Number.isNaN(at)) return { unit: "never", count: 0 };
  const seconds = Math.floor((now - at) / 1000);
  if (seconds < 60) return { unit: "now", count: 0 };
  if (seconds < 3600) return { unit: "minutes", count: Math.floor(seconds / 60) };
  if (seconds < 86_400) return { unit: "hours", count: Math.floor(seconds / 3600) };
  return { unit: "days", count: Math.floor(seconds / 86_400) };
}

/**
 * The days whose summaries moved between two answers of the freshness feed: a new version, a new rebuilt_at (a day deleted and rebuilt starts
 * again at version 1), a day built for the first time or one no longer built. The first answer is the baseline: nothing moved.
 */
export function changedDays(before: FreshnessDay[] | null, after: FreshnessDay[]): string[] {
  if (before === null) return [];
  const known = new Map(before.map((day) => [day.day, `${day.version}|${day.rebuilt_at}`]));
  const moved = new Set<string>();
  for (const day of after) {
    if (known.get(day.day) !== `${day.version}|${day.rebuilt_at}`) moved.add(day.day);
    known.delete(day.day);
  }
  for (const gone of known.keys()) moved.add(gone);
  return [...moved].sort();
}

/** Days grouped into runs of consecutive days, oldest first: how the notice names the days not built ("1 Oct – 7 Oct, 12 Oct"). */
export function dayRuns(days: string[]): DayRange[] {
  const sorted = [...new Set(days.filter(isLocalDate))].sort();
  const runs: DayRange[] = [];
  for (const day of sorted) {
    const last = runs[runs.length - 1];
    if (last && addDays(last.to, 1) === day) last.to = day;
    else runs.push({ from: day, to: day });
  }
  return runs;
}

// --- query keys: one per answer shape (two shapes under one key crashed PR #71) ---------------------------------------------------------------

export interface ItemsOptions {
  kind: "" | "product" | "ingredient";
  /** a category id as text, "" for every category */
  categoryId: string;
  sort: ItemSort;
}

export type ItemSort = "revenue" | "units" | "margin" | "waste";
export const ITEM_SORTS: readonly ItemSort[] = ["revenue", "units", "margin", "waste"];

export type RoleKind = "" | "waiter" | "cashier" | "station";
export const ROLE_KINDS: readonly Exclude<RoleKind, "">[] = ["waiter", "cashier", "station"];

export const analyticsKeys = {
  overview: (range: DayRange, compare: boolean) => ["analytics", "overview", range.from, range.to, compare] as const,
  sales: (range: DayRange, group: SeriesGroup) => ["analytics", "sales", range.from, range.to, group] as const,
  hourly: (range: DayRange) => ["analytics", "sales-hourly", range.from, range.to] as const,
  channels: (range: DayRange, group: SeriesGroup) => ["analytics", "channels", range.from, range.to, group] as const,
  items: (range: DayRange, options: ItemsOptions) => ["analytics", "items", range.from, range.to, options.kind, options.categoryId, options.sort] as const,
  item: (range: DayRange, id: number) => ["analytics", "item", range.from, range.to, id] as const,
  stations: (range: DayRange) => ["analytics", "stations", range.from, range.to] as const,
  staff: (range: DayRange, roleKind: RoleKind) => ["analytics", "staff", range.from, range.to, roleKind] as const,
  shifts: (range: DayRange) => ["analytics", "shifts", range.from, range.to] as const,
};

/**
 * Does an analytics query read one of these days? Its range, the period before it for a compared overview, and always for the product
 * page (it also follows the weeks since the product's launch, outside its range). Any other query is never touched.
 */
export function queryTouches(key: readonly unknown[], days: string[]): boolean {
  if (key[0] !== "analytics" || days.length === 0) return false;
  if (key[1] === "item") return true;
  const from = key[2];
  const to = key[3];
  if (typeof from !== "string" || typeof to !== "string") return true;
  const span = spanOf({ from, to }, key[1] === "overview" && key[4] === true);
  return days.some((day) => day >= span.from && day <= span.to);
}

// --- the rebuild ------------------------------------------------------------------------------------------------------------------------------

export interface RebuildPiece extends DayRange {
  /** inline (200, the days built before the answer) for at most 7 days; queued (202, one job a day) otherwise */
  sync: boolean;
}

/**
 * The requests the Rebuild button sends for the days not built: newest first (the days people look at first), each at most 31 days (the
 * queue's limit) from its newest day back, inline only when it holds 7 days or fewer. A built day between two that are not is rebuilt with
 * them: a rebuild of unchanged facts writes the same figures (the fingerprint), so fewer requests cost nothing.
 */
export function rebuildPlan(staleDays: string[]): RebuildPiece[] {
  const left = [...new Set(staleDays.filter(isLocalDate))].sort().reverse();
  const pieces: RebuildPiece[] = [];
  while (left.length > 0) {
    const to = left[0];
    const earliest = addDays(to, -(REBUILD_QUEUE_MAX_DAYS - 1));
    let from = to;
    while (left.length > 0 && left[0] >= earliest) from = left.shift() as string;
    pieces.push({ from, to, sync: daysBetween(from, to) <= REBUILD_SYNC_MAX_DAYS });
  }
  return pieces;
}

// --- figures --------------------------------------------------------------------------------------------------------------------------------

/** The change from the previous period in percent, one decimal, half up, signed ("+10.0", "-16.7", "0.0"); null when there was nothing before. */
export function percentChange(current: string, previous: string): string | null {
  if (compareDecimal(previous, "0") === 0) return null;
  const change = divideDecimal(subtractDecimal(current, previous, 4), previous, 1, 100);
  return compareDecimal(change, "0") > 0 ? `+${change}` : change;
}

/** The difference from the previous period, signed ("+100.00"), at `dp` places (2 for money, 0 for a count, 4 for a quantity). */
export function differenceOf(current: string, previous: string, dp = 2): string {
  const difference = subtractDecimal(current, previous, dp);
  return compareDecimal(difference, "0") > 0 ? `+${difference}` : difference;
}

/** Thousands separated by commas ("1,234,567.50"): only the characters move, the figure is the one given. */
export function groupDigits(text: string): string {
  const match = /^([+-]?)(\d+)(\.\d+)?$/.exec(text);
  if (!match) return text;
  return `${match[1]}${match[2].replace(/\B(?=(\d{3})+(?!\d))/g, ",")}${match[3] ?? ""}`;
}

/** The money of every payment method at each point of the channels series, added up (decimal strings); null for a point not built. */
export function seriesTotals(series: ChannelPoint[]): (string | null)[] {
  return series.map((point) => (point.channels === null ? null : point.channels.reduce((sum, channel) => addDecimal(sum, channel.amount), "0.00")));
}

export type Margin = { complete: true; margin: string; pct: string | null } | { complete: false; days: number };

/**
 * A margin, or the word that it is not known: over days whose cost of sales is incomplete (a display-case product made in the house has no
 * cost yet, 4.4a; a modifier's recipe is not costed) the API counts the missing cost as 0, so its margin would be the whole revenue.
 */
export function marginOf(figures: Pick<ItemFigures, "cogs_complete" | "cogs_incomplete_days" | "margin_total" | "margin_pct">): Margin {
  if (!figures.cogs_complete) return { complete: false, days: figures.cogs_incomplete_days };
  return { complete: true, margin: figures.margin_total, pct: figures.margin_pct };
}

/** A preparation time in minutes and seconds (the API counts whole seconds). */
export function prepTime(seconds: number | null): { minutes: number; seconds: number } | null {
  if (seconds === null) return null;
  return { minutes: Math.floor(seconds / 60), seconds: seconds % 60 };
}

// --- links and files --------------------------------------------------------------------------------------------------------------------------

export type ChannelCount = "without_reference_count" | "without_photo_count" | "verified_count" | "disputed_count" | "duplicate_reference_count";

/**
 * The payments list (step 4.5c) a checker's count opens: the range, the method, and the list's filter for that count. Only where the list has
 * that filter exactly: verified, disputed, and without a photo (but not for cash: the list's missing_photo leaves cash out). A missing
 * reference has no filter, and the list's "flagged" holds disputes and duplicate photos too, so those counts are not links.
 */
export function paymentsDrill(range: DayRange, channel: { id: number; code?: string | null }, count: ChannelCount): Record<string, string> | null {
  const base = { from: range.from, to: range.to, channel_id: String(channel.id) };
  if (count === "verified_count") return { ...base, status: "verified" };
  if (count === "disputed_count") return { ...base, status: "disputed" };
  if (count === "without_photo_count") return channel.code === "cash" ? null : { ...base, missing_photo: "1" };
  return null;
}

export type ExportFormat = "xlsx" | "csv" | "pdf";
export const EXPORT_FORMATS: readonly ExportFormat[] = ["xlsx", "csv", "pdf"];

/** A report's file, named as GET /analytics/exports/{report} names it (a cross-origin Content-Disposition cannot be read by the page). */
export function exportFileName(report: ReportName, range: DayRange, format: ExportFormat): string {
  return `analytics-${report}-${range.from}-${range.to}.${format}`;
}
