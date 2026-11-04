"use client";

import { useTranslations } from "next-intl";
import { ROLE_KINDS, type RoleKind } from "@/lib/analytics";
import { useStaffFigures, useStationFigures, type PersonFigures, type StationFigures } from "@/lib/api/analytics";
import { useSession } from "@/lib/session";
import { EmptyRow, TableCard } from "../admin/kit";
import {
  AnalyticsHeader,
  AnalyticsNav,
  Choices,
  FreshnessChip,
  FreshnessNotice,
  NUM,
  pageQuery,
  RangeControl,
  ReadError,
  SkeletonRows,
  useAnalyticsPage,
  useFigures,
  useFreshness,
  useNames,
} from "./shared";

// Admin > Analytics > Stations and Staff (step 4.5e), and the person's own page, /admin/analytics/me (analytics.view_own).
// - Stations (GET /analytics/stations): tickets, lines served and rejected, preparation times (the mean over the range, exact; a 90th
//   percentile only for one day, the worst day's otherwise), batches, produced and wasted, shift-days and locked ones, count variances and
//   the cash variance, stock-outs.
// - Staff (GET /analytics/staff?role_kind=): per person and kind of work (what they did, not the role they hold): orders, revenue,
//   payments, voids, refunds, lines prepared and the mean preparation time.
// - My figures: the same two reads, which the API limits for analytics.view_own to the caller's stations and the caller's own rows (the
//   person at the device). A person who reads everything (analytics.view) sees only their own here too: their stations and their row.
//
// Step 6.4d (fresh-counter.md 4.4, the CatalogList board's table): each a compact table card that fits the page, the figures right-aligned
// in tabular numbers; the range (and the kind of work) as the toolbar; the title row with its twin and the exports.

const ROLE_OPTIONS: readonly RoleKind[] = ["", ...ROLE_KINDS];

/** A table card's body while its read is on its way or empty. */
function Pending({ span, rows }: { span: number; rows: number }) {
  const t = useTranslations("analytics");
  return (
    <tr>
      <td colSpan={span}>
        <SkeletonRows rows={rows} label={t("loading")} />
      </td>
    </tr>
  );
}

export function StationsScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  const { range, today } = page;
  const freshness = useFreshness(range);
  const stations = useStationFigures(range);
  return (
    <>
      <AnalyticsHeader title={t("stations.title")} intro={t("stations.intro")} titleAm={titleAm} report="stations" range={range} />
      <div className="flex flex-col gap-6">
        <AnalyticsNav />
        <RangeControl range={range} today={today} onChange={(next) => page.go(pageQuery(next, {}))} aside={<FreshnessChip state={freshness} asOf={stations.data?.as_of} />} />
        <FreshnessNotice state={freshness} />
        {stations.error ? (
          <ReadError error={stations.error} onRetry={() => void stations.refetch()} />
        ) : (
          <StationsTable
            rows={stations.data?.data.stations}
            caption={t("stations.table")}
            oneDay={range.from === range.to}
            empty={stations.data?.as_of === null ? t("overview.not_built") : t("stations.empty")}
          />
        )}
      </div>
    </>
  );
}

export function StationsTable({ rows, caption, oneDay, empty }: { rows: StationFigures[] | undefined; caption: string; oneDay: boolean; empty: string }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  const names = useNames();
  return (
    <TableCard caption={caption} title={caption} minWidth="min-w-[1100px]" compact>
      <thead>
        <tr>
          <th scope="col">{t("col.station")}</th>
          <th scope="col" className="text-right">
            {t("col.tickets")}
          </th>
          <th scope="col" className="text-right">
            {t("col.lines_served")}
          </th>
          <th scope="col" className="text-right">
            {t("col.lines_rejected")}
          </th>
          <th scope="col" className="text-right">
            {t("col.avg_prep")}
          </th>
          <th scope="col" className="text-right">
            {oneDay ? t("col.p90_prep") : t("col.worst_p90_prep")}
          </th>
          <th scope="col" className="text-right">
            {t("col.batches")}
          </th>
          <th scope="col" className="text-right">
            {t("col.produced")}
          </th>
          <th scope="col" className="text-right">
            {t("col.waste")}
          </th>
          <th scope="col" className="text-right">
            {t("col.shifts")}
          </th>
          <th scope="col" className="text-right">
            {t("col.locked")}
          </th>
          <th scope="col" className="text-right">
            {t("col.variance_lines")}
          </th>
          <th scope="col" className="text-right">
            {t("col.cash_variance")}
          </th>
          <th scope="col" className="text-right">
            {t("col.stockouts")}
          </th>
        </tr>
      </thead>
      <tbody>
        {rows === undefined ? (
          <Pending span={14} rows={4} />
        ) : rows.length === 0 ? (
          <EmptyRow span={14}>{empty}</EmptyRow>
        ) : (
          rows.map((row) => (
            <tr key={row.station.id}>
              <th scope="row" className="font-semibold text-neutral-900">
                {names.station(row.station)}
              </th>
              <td className={NUM}>{f.count(row.tickets_count)}</td>
              <td className={NUM}>{f.count(row.lines_served)}</td>
              <td className={NUM}>{f.count(row.lines_rejected)}</td>
              <td className={NUM}>{f.prep(row.avg_prep_seconds)}</td>
              <td className={NUM}>{f.prep(oneDay ? row.p90_prep_seconds : row.worst_day_p90_prep_seconds)}</td>
              <td className={NUM}>{f.count(row.batches_finished)}</td>
              <td className={NUM}>{f.qty(row.produced_qty)}</td>
              <td className={NUM}>{f.qty(row.waste_qty)}</td>
              <td className={NUM}>{f.count(row.shifts_count)}</td>
              <td className={NUM}>{f.count(row.shifts_locked_count)}</td>
              <td className={NUM}>{f.count(row.variance_line_count)}</td>
              <td className={`${NUM} font-semibold`}>{f.amount(row.cash_variance_total)}</td>
              <td className={NUM}>{f.count(row.stockout_count)}</td>
            </tr>
          ))
        )}
      </tbody>
    </TableCard>
  );
}

function roleKindOf(params: URLSearchParams): RoleKind {
  const kind = params.get("role_kind") ?? "";
  return (ROLE_KINDS as readonly string[]).includes(kind) ? (kind as RoleKind) : "";
}

export function StaffScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  const { range, params, today } = page;
  const roleKind = roleKindOf(params);
  const freshness = useFreshness(range);
  const staff = useStaffFigures(range, roleKind);
  return (
    <>
      <AnalyticsHeader title={t("staff.title")} intro={t("staff.intro")} titleAm={titleAm} report="staff" range={range} />
      <div className="flex flex-col gap-6">
        <AnalyticsNav />
        <RangeControl range={range} today={today} onChange={(next) => page.go(pageQuery(next, { role_kind: roleKind }))} aside={<FreshnessChip state={freshness} asOf={staff.data?.as_of} />}>
          <Choices label={t("staff.role_kind")} options={ROLE_OPTIONS} value={roleKind} onChange={(next) => page.go(pageQuery(range, { role_kind: next }))} text={(option) => t(`role.${option || "all"}`)} />
        </RangeControl>
        <FreshnessNotice state={freshness} />
        {staff.error ? (
          <ReadError error={staff.error} onRetry={() => void staff.refetch()} />
        ) : (
          <StaffTable rows={staff.data?.data.users} caption={t("staff.table")} empty={staff.data?.as_of === null ? t("overview.not_built") : t("staff.empty")} />
        )}
      </div>
    </>
  );
}

export function StaffTable({ rows, caption, empty }: { rows: PersonFigures[] | undefined; caption: string; empty: string }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  const names = useNames();
  return (
    <TableCard caption={caption} title={caption} minWidth="min-w-[1040px]" compact>
      <thead>
        <tr>
          <th scope="col">{t("col.person")}</th>
          <th scope="col">{t("col.role_kind")}</th>
          <th scope="col" className="text-right">
            {t("col.orders")}
          </th>
          <th scope="col" className="text-right">
            {t("col.items")}
          </th>
          <th scope="col" className="text-right">
            {t("col.revenue")}
          </th>
          <th scope="col" className="text-right">
            {t("col.payments")}
          </th>
          <th scope="col" className="text-right">
            {t("col.payments_amount")}
          </th>
          <th scope="col" className="text-right">
            {t("col.voids")}
          </th>
          <th scope="col" className="text-right">
            {t("col.refunds_count")}
          </th>
          <th scope="col" className="text-right">
            {t("col.lines_prepared")}
          </th>
          <th scope="col" className="text-right">
            {t("col.avg_prep")}
          </th>
        </tr>
      </thead>
      <tbody>
        {rows === undefined ? (
          <Pending span={11} rows={5} />
        ) : rows.length === 0 ? (
          <EmptyRow span={11}>{empty}</EmptyRow>
        ) : (
          rows.map((row) => {
            const name = names.person(row.user);
            return (
              <tr key={`${row.user.id}-${row.role_kind}`}>
                {/* the name alone: a reader of the table (and p4-dashboards) takes the row header's text as the person */}
                <th scope="row" className="font-semibold text-neutral-900">
                  {name}
                </th>
                <td className="text-neutral-700">{t(`role.${row.role_kind}`)}</td>
                <td className={NUM}>{f.count(row.orders_count)}</td>
                <td className={NUM}>{f.qty(row.items_qty)}</td>
                <td className={`${NUM} font-semibold`}>{f.amount(row.revenue)}</td>
                <td className={NUM}>{f.count(row.payments_count)}</td>
                <td className={NUM}>{f.amount(row.payments_amount)}</td>
                <td className={NUM}>{f.count(row.voids_count)}</td>
                <td className={NUM}>{f.count(row.refunds_count)}</td>
                <td className={NUM}>{f.count(row.lines_prepared)}</td>
                <td className={NUM}>{f.prep(row.avg_prep_seconds)}</td>
              </tr>
            );
          })
        )}
      </tbody>
    </TableCard>
  );
}

/** /admin/analytics/me: the person's own stations and own work over the range. */
export function MeScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  const { range, today } = page;
  const me = useSession((s) => s.me);
  const freshness = useFreshness(range);
  const stations = useStationFigures(range);
  const staff = useStaffFigures(range, "");
  // the API already limits analytics.view_own; a person who reads everything sees their own here too
  const ownStations = new Set((me?.work_stations ?? []).map((station) => station.station_id));
  const myStations = stations.data ? stations.data.data.stations.filter((row) => ownStations.has(row.station.id)) : undefined;
  const myRows = staff.data ? staff.data.data.users.filter((row) => row.user.id === me?.user.id) : undefined;

  return (
    <>
      <AnalyticsHeader title={t("me.title")} intro={t("me.intro")} titleAm={titleAm} />
      <div className="flex flex-col gap-6">
        <AnalyticsNav />
        <RangeControl range={range} today={today} onChange={(next) => page.go(pageQuery(next, {}))} aside={<FreshnessChip state={freshness} asOf={staff.data?.as_of} />} />
        <FreshnessNotice state={freshness} />
        {staff.error ? (
          <ReadError error={staff.error} onRetry={() => void staff.refetch()} />
        ) : (
          <StaffTable rows={myRows} caption={t("me.work")} empty={staff.data?.as_of === null ? t("overview.not_built") : t("me.work_empty")} />
        )}
        {stations.error ? (
          <ReadError error={stations.error} onRetry={() => void stations.refetch()} />
        ) : (
          <StationsTable
            rows={myStations}
            caption={t("me.stations")}
            oneDay={range.from === range.to}
            empty={stations.data?.as_of === null ? t("overview.not_built") : t("me.stations_empty")}
          />
        )}
      </div>
    </>
  );
}
