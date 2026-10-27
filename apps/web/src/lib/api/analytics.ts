"use client";

import { useQuery } from "@tanstack/react-query";
import type { AnalyticsRange, DayRange, ExportFormat, ItemsOptions, RebuildPiece, RoleKind } from "../analytics";
import { analyticsKeys } from "../analytics";
import { authed, authedBlob, can, handleUnauthorized, useSession } from "../session";
import { ApiError, apiUrl, conditionalGet } from "./client";

// The reads and the one write of the analytics screens (step 4.5e; phase-4 plan section 4.6). Every dashboard read answers
//   {from, to, timezone, as_of, stale_days, data, previous?}
// (4.4c) and is conditional: its ETag is built from the versions of the days it read, so it answers 304 until a rebuild moves one. The
// screens do not poll the dashboards: the freshness feed (lib/live/analytics.ts) polls GET /analytics/freshness and invalidates the queries
// of the days whose version moved; each read keeps its last ETag and answer here, so a read asked again for nothing (a pill visited again,
// a version moved outside its days) costs a 304. Query keys are one per answer shape (lib/analytics.ts analyticsKeys). Money and quantities
// are decimal strings; counts are integers; a figure of a point not built is null, never zero (R-U).

export type SeriesGroup = "day" | "week" | "month";
export type ReportName = "sales" | "channels" | "items" | "stations" | "staff" | "shifts";

export interface Freshness {
  from: string;
  to: string;
  timezone: string;
  /** the oldest rebuilt_at of the built days, ISO 8601 UTC; null when no day of the range is built */
  as_of: string | null;
  days: FreshnessDay[];
  /** days of the range with no summaries at all: "not built", never zero */
  stale_days: string[];
  /** a rebuild of a day of the range was asked for and has not run yet */
  running: boolean;
}

export interface FreshnessDay {
  day: string;
  version: number;
  rebuilt_at: string;
  trigger: string;
  changed: boolean;
}

export interface Period<T> {
  from: string;
  to: string;
  as_of: string | null;
  stale_days: string[];
  data: T;
}

export interface Answer<T> extends Period<T> {
  timezone: string;
  /** with ?compare=previous: the same number of days just before the range */
  previous?: Period<T>;
}

/** A name the API gives through the owning module's contract; a row it cannot name any more is still there by its id. */
export interface ChannelName {
  id: number;
  code?: string;
  name_en?: string;
  name_am?: string;
  is_removed?: boolean;
}

export interface ItemName {
  id: number;
  name_en?: string;
  name_am?: string;
  kind?: "product" | "ingredient";
  unit?: string;
  category?: { id: number; name_en: string; name_am: string } | null;
  is_new?: boolean;
  is_active?: boolean;
}

export interface PersonName {
  id: number;
  name?: string;
}

export interface StationName {
  id: number;
  code?: string;
  name_en?: string;
  name_am?: string;
  is_active?: boolean;
}

export interface Overview {
  revenue: string;
  net_revenue: string;
  orders_count: number;
  avg_ticket: string;
  items_qty: string;
  refund_count: number;
  refund_total: string;
  voided_count: number;
  voided_total: string;
  cancelled_count: number;
  by_type: { dine_in: string; takeaway: string; delivery: string; advance: string };
  channels: { channel: ChannelName; receipts_count: number; amount: string; net_amount: string; share: string | null }[];
  top_items: { item: ItemName; units_sold_qty: string; revenue: string }[];
}

export interface Bucket {
  key: string;
  from: string;
  to: string;
  days: number;
  built_days: number;
}

export interface SalesPoint extends Bucket {
  orders_count: number | null;
  items_qty: string | null;
  revenue: string | null;
  refund_total: string | null;
  net_revenue: string | null;
  avg_ticket: string | null;
}

export interface Sales {
  group: SeriesGroup;
  series: SalesPoint[];
}

export interface HourlySales {
  hours: { hour: number; orders_count: number; items_qty: string; revenue: string }[];
}

export interface ChannelFigures {
  channel: ChannelName;
  receipts_count: number;
  amount: string;
  refund_count: number;
  refund_amount: string;
  net_amount: string;
  share: string | null;
  without_reference_count: number;
  without_photo_count: number;
  verified_count: number;
  disputed_count: number;
  duplicate_reference_count: number;
}

export interface ChannelPoint extends Bucket {
  channels: { channel_id: number; receipts_count: number; amount: string; net_amount: string }[] | null;
}

export interface Channels {
  group: SeriesGroup;
  channels: ChannelFigures[];
  series: ChannelPoint[];
}

export interface ItemFigures {
  units_sold_qty: string;
  refunded_qty: string;
  revenue: string;
  cogs_total: string;
  margin_total: string;
  margin_pct: string | null;
  cogs_complete: boolean;
  cogs_incomplete_days: number;
  produced_qty: string;
  consumed_qty: string;
  waste_qty: string;
  purchased_qty: string;
  stockout_count: number;
}

export interface Items {
  sort: string;
  items: ({ item: ItemName } & ItemFigures)[];
}

export interface ItemDay {
  day: string;
  built: boolean;
  units_sold_qty: string | null;
  refunded_qty: string | null;
  produced_qty: string | null;
  consumed_qty: string | null;
  waste_qty: string | null;
  purchased_qty: string | null;
  adjusted_qty: string | null;
  stockout_count: number | null;
  closing_on_hand_qty: string | null;
  revenue: string | null;
  cogs_total: string | null;
  margin_total: string | null;
  closing_value_total: string | null;
  cogs_complete: boolean | null;
}

export interface LaunchWeek {
  week: number;
  from: string;
  to: string;
  days: number;
  built_days: number;
  units_sold_qty: string | null;
  revenue: string | null;
}

/** The product page; the previous period (with ?compare=previous) has only totals and series. */
export interface ItemPage {
  item?: ItemName;
  totals: ItemFigures;
  series: ItemDay[];
  since_launch?: { first_sale_day: string | null; weekly_units: LaunchWeek[] };
}

export interface StationFigures {
  station: StationName;
  tickets_count: number;
  lines_served: number;
  lines_rejected: number;
  prep_samples: number;
  avg_prep_seconds: number | null;
  /** only for a one-day range (a 90th percentile of many days cannot be made from the days') */
  p90_prep_seconds: number | null;
  worst_day_p90_prep_seconds: number | null;
  batches_finished: number;
  produced_qty: string;
  waste_qty: string;
  shifts_count: number;
  shifts_locked_count: number;
  variance_line_count: number;
  cash_variance_total: string;
  stockout_count: number;
}

export interface Stations {
  stations: StationFigures[];
}

export interface PersonFigures {
  user: PersonName;
  role_kind: Exclude<RoleKind, "">;
  orders_count: number;
  items_qty: string;
  revenue: string;
  payments_count: number;
  payments_amount: string;
  voids_count: number;
  refunds_count: number;
  lines_prepared: number;
  prep_samples: number;
  avg_prep_seconds: number | null;
}

export interface Staff {
  users: PersonFigures[];
}

export interface ShiftDay {
  day: string;
  built: boolean;
  shifts_count: number | null;
  shifts_locked_count: number | null;
  variance_line_count: number | null;
  cash_variance_total: string | null;
}

export interface Shifts {
  days: ShiftDay[];
  totals: { variance_line_count: number; cash_variance_total: string };
}

export type RebuildAnswer =
  | { from: string; to: string; sync: true; days: { day: string; trigger: string; rows: number; version: number; changed: boolean; duration_ms: number }[] }
  | { from: string; to: string; sync: false; queued: number; days: string[] };

// --- the conditional reads ------------------------------------------------------------------------------------------------------------------

const KEPT = 60;
const answers = new Map<string, { etag: string; data: unknown }>();

/**
 * A GET of a dashboard with its last ETag: a 304 answers what this device already has. Kept per signed-in token and address, at most 60
 * answers (the oldest go first); a sign-out leaves them unreachable (a new token never matches).
 */
async function conditionalRead<T>(path: string, query: Record<string, string>): Promise<T> {
  const { token } = useSession.getState();
  const key = `${token ?? ""} ${apiUrl(path, query)}`;
  const known = answers.get(key);
  try {
    const result = await conditionalGet<T>(path, { query, token, etag: known?.etag ?? null });
    if (result.notModified) {
      if (known) return known.data as T;
      // a 304 for a tag this device no longer holds: ask once more without one
      const fresh = await conditionalGet<T>(path, { query, token });
      if (fresh.notModified) throw new ApiError(0, { message_key: "network" });
      return fresh.data;
    }
    answers.delete(key);
    if (result.etag) {
      answers.set(key, { etag: result.etag, data: result.data });
      while (answers.size > KEPT) answers.delete(answers.keys().next().value as string);
    }
    return result.data;
  } catch (error) {
    handleUnauthorized(error);
    throw error;
  }
}

function useAllowed(anyOf: readonly string[]): boolean {
  return useSession((s) => s.status === "signed-in" && anyOf.some((permission) => can(s.me, permission)));
}

const VIEW = ["analytics.view"] as const;
const VIEW_OR_OWN = ["analytics.view", "analytics.view_own"] as const;

function rangeQuery(range: DayRange): Record<string, string> {
  return { from: range.from, to: range.to };
}

function useRead<T>(key: readonly unknown[], path: string, query: Record<string, string>, anyOf: readonly string[] = VIEW, enabled = true) {
  const allowed = useAllowed(anyOf);
  return useQuery({
    queryKey: key,
    queryFn: () => conditionalRead<Answer<T>>(path, query),
    enabled: allowed && enabled,
    staleTime: 5 * 60_000,
    // a refusal (a range the API will not read, a product of another branch) is not asked again; a lost answer once
    retry: (failures, error) => failures < 1 && !(error instanceof ApiError && error.status >= 400 && error.status < 500),
  });
}

/** GET /analytics/overview: the headline figures, by type, the channels' shares and the five best sellers; ?compare=previous adds the period before. */
export function useOverview(range: DayRange, compare: boolean) {
  return useRead<Overview>(analyticsKeys.overview(range, compare), "/analytics/overview", { ...rangeQuery(range), ...(compare ? { compare: "previous" } : {}) });
}

/** GET /analytics/sales?group=: a point a day, a Monday week or a month. */
export function useSales(range: DayRange, group: SeriesGroup, enabled = true) {
  return useRead<Sales>(analyticsKeys.sales(range, group), "/analytics/sales", { ...rangeQuery(range), group }, VIEW, enabled);
}

/** GET /analytics/sales/hourly: the 24 local hours summed over the range. */
export function useHourly(range: DayRange) {
  return useRead<HourlySales>(analyticsKeys.hourly(range), "/analytics/sales/hourly", rangeQuery(range));
}

/** GET /analytics/channels?group=: per payment method, and its series. */
export function useChannelFigures(range: DayRange, group: SeriesGroup) {
  return useRead<Channels>(analyticsKeys.channels(range, group), "/analytics/channels", { ...rangeQuery(range), group });
}

/** GET /analytics/items?kind=&category_id=&sort=&limit=50. */
export function useItemFigures(range: DayRange, options: ItemsOptions) {
  const query: Record<string, string> = { ...rangeQuery(range), sort: options.sort, limit: "50" };
  if (options.kind) query.kind = options.kind;
  if (options.categoryId) query.category_id = options.categoryId;
  return useRead<Items>(analyticsKeys.items(range, options), "/analytics/items", query);
}

/** GET /analytics/items/{item}: the product page. */
export function useItemPage(range: DayRange, id: number | null) {
  return useRead<ItemPage>(analyticsKeys.item(range, id ?? 0), `/analytics/items/${id ?? 0}`, rangeQuery(range), VIEW, id !== null);
}

/** GET /analytics/stations: every station for analytics.view, the caller's own for analytics.view_own (the API limits it). */
export function useStationFigures(range: DayRange) {
  return useRead<Stations>(analyticsKeys.stations(range), "/analytics/stations", rangeQuery(range), VIEW_OR_OWN);
}

/** GET /analytics/staff?role_kind=: everyone for analytics.view, the caller's own rows for analytics.view_own. */
export function useStaffFigures(range: DayRange, roleKind: RoleKind) {
  return useRead<Staff>(analyticsKeys.staff(range, roleKind), "/analytics/staff", { ...rangeQuery(range), ...(roleKind ? { role_kind: roleKind } : {}) }, VIEW_OR_OWN);
}

/** GET /analytics/shifts: a point a day over the stations, and the variances' totals. */
export function useShiftFigures(range: DayRange) {
  return useRead<Shifts>(analyticsKeys.shifts(range), "/analytics/shifts", rangeQuery(range));
}

// --- the rebuild and the exports ------------------------------------------------------------------------------------------------------------

/** POST /analytics/rebuild (analytics.manage): inline for at most 7 days (200, built before the answer), queued otherwise (202). */
export function rebuildRange(piece: RebuildPiece, idempotencyKey: string): Promise<RebuildAnswer> {
  return authed<RebuildAnswer>("/analytics/rebuild", {
    method: "POST",
    body: { from: piece.from, to: piece.to, sync: piece.sync },
    idempotencyKey,
    timeoutMs: piece.sync ? 120_000 : 30_000,
  });
}

const EXPORT_TYPES: Record<ExportFormat, string> = {
  xlsx: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  csv: "text/csv",
  pdf: "application/pdf",
};

/** GET /analytics/exports/{report} (analytics.view): the report over the range as a file, read with the bearer token. */
export function analyticsExport(report: ReportName, range: AnalyticsRange, format: ExportFormat, locale: string): Promise<Blob> {
  return authedBlob(`/analytics/exports/${report}`, { query: { ...rangeQuery(range), format, locale }, accept: EXPORT_TYPES[format], timeoutMs: 120_000 });
}
