"use client";

import { useTranslations } from "next-intl";
import { ROLE_KINDS, type RoleKind } from "@/lib/analytics";
import { useStaffFigures, useStationFigures, type PersonFigures, type StationFigures } from "@/lib/api/analytics";
import { useSession } from "@/lib/session";
import { PageHeader } from "../ui";
import { Choices, ExportBar, FreshnessBar, pageQuery, RangeControl, ReadError, Section, SkeletonRows, TableFrame, TD_NUM, TH, TH_NUM, useAnalyticsPage, useFigures, useFreshness, useNames } from "./shared";

// Admin > Analytics > Stations and Staff (step 4.5e), and the person's own page, /admin/analytics/me (analytics.view_own).
// - Stations (GET /analytics/stations): tickets, lines served and rejected, preparation times (the mean over the range, exact; a 90th
//   percentile only for one day, the worst day's otherwise), batches, produced and wasted, shift-days and locked ones, count variances and
//   the cash variance, stock-outs.
// - Staff (GET /analytics/staff?role_kind=): per person and kind of work (what they did, not the role they hold): orders, revenue,
//   payments, voids, refunds, lines prepared and the mean preparation time.
// - My figures: the same two reads, which the API limits for analytics.view_own to the caller's stations and the caller's own rows (the
//   person at the device). A person who reads everything (analytics.view) sees only their own here too: their stations and their row.

const ROLE_OPTIONS: readonly RoleKind[] = ["", ...ROLE_KINDS];

export function StationsScreen() {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  const { range, today } = page;
  const freshness = useFreshness(range);
  const stations = useStationFigures(range);
  return (
    <>
      <PageHeader title={t("stations.title")} intro={t("stations.intro")} />
      <div className="space-y-6">
        <RangeControl range={range} today={today} onChange={(next) => page.go(pageQuery(next, {}))} />
        <FreshnessBar state={freshness} asOf={stations.data?.as_of} />
        <ExportBar report="stations" range={range} />
        <Section title={t("stations.table")}>
          {stations.error ? (
            <ReadError error={stations.error} onRetry={() => void stations.refetch()} />
          ) : !stations.data ? (
            <SkeletonRows rows={5} label={t("loading")} />
          ) : stations.data.data.stations.length === 0 ? (
            <p className="text-base text-neutral-600">{stations.data.as_of === null ? t("overview.not_built") : t("stations.empty")}</p>
          ) : (
            <StationsTable rows={stations.data.data.stations} caption={t("stations.table")} oneDay={range.from === range.to} />
          )}
        </Section>
      </div>
    </>
  );
}

export function StationsTable({ rows, caption, oneDay }: { rows: StationFigures[]; caption: string; oneDay: boolean }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  const names = useNames();
  return (
    <TableFrame>
      <table className="w-full border-collapse text-left text-base">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr className="border-b border-neutral-200 text-sm text-neutral-600">
            <th scope="col" className={TH}>{t("col.station")}</th>
            <th scope="col" className={TH_NUM}>{t("col.tickets")}</th>
            <th scope="col" className={TH_NUM}>{t("col.lines_served")}</th>
            <th scope="col" className={TH_NUM}>{t("col.lines_rejected")}</th>
            <th scope="col" className={TH_NUM}>{t("col.avg_prep")}</th>
            <th scope="col" className={TH_NUM}>{oneDay ? t("col.p90_prep") : t("col.worst_p90_prep")}</th>
            <th scope="col" className={TH_NUM}>{t("col.batches")}</th>
            <th scope="col" className={TH_NUM}>{t("col.produced")}</th>
            <th scope="col" className={TH_NUM}>{t("col.waste")}</th>
            <th scope="col" className={TH_NUM}>{t("col.shifts")}</th>
            <th scope="col" className={TH_NUM}>{t("col.locked")}</th>
            <th scope="col" className={TH_NUM}>{t("col.variance_lines")}</th>
            <th scope="col" className={TH_NUM}>{t("col.cash_variance")}</th>
            <th scope="col" className={TH_NUM}>{t("col.stockouts")}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.station.id} className="border-b border-neutral-100">
              <th scope="row" className="py-3 pr-4 font-medium text-neutral-900">
                {names.station(row.station)}
              </th>
              <td className={TD_NUM}>{f.count(row.tickets_count)}</td>
              <td className={TD_NUM}>{f.count(row.lines_served)}</td>
              <td className={TD_NUM}>{f.count(row.lines_rejected)}</td>
              <td className={TD_NUM}>{f.prep(row.avg_prep_seconds)}</td>
              <td className={TD_NUM}>{f.prep(oneDay ? row.p90_prep_seconds : row.worst_day_p90_prep_seconds)}</td>
              <td className={TD_NUM}>{f.count(row.batches_finished)}</td>
              <td className={TD_NUM}>{f.qty(row.produced_qty)}</td>
              <td className={TD_NUM}>{f.qty(row.waste_qty)}</td>
              <td className={TD_NUM}>{f.count(row.shifts_count)}</td>
              <td className={TD_NUM}>{f.count(row.shifts_locked_count)}</td>
              <td className={TD_NUM}>{f.count(row.variance_line_count)}</td>
              <td className={TD_NUM}>{f.amount(row.cash_variance_total)}</td>
              <td className={TD_NUM}>{f.count(row.stockout_count)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </TableFrame>
  );
}

function roleKindOf(params: URLSearchParams): RoleKind {
  const kind = params.get("role_kind") ?? "";
  return (ROLE_KINDS as readonly string[]).includes(kind) ? (kind as RoleKind) : "";
}

export function StaffScreen() {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  const { range, params, today } = page;
  const roleKind = roleKindOf(params);
  const freshness = useFreshness(range);
  const staff = useStaffFigures(range, roleKind);
  return (
    <>
      <PageHeader title={t("staff.title")} intro={t("staff.intro")} />
      <div className="space-y-6">
        <RangeControl range={range} today={today} onChange={(next) => page.go(pageQuery(next, { role_kind: roleKind }))} />
        <FreshnessBar state={freshness} asOf={staff.data?.as_of} />
        <ExportBar report="staff" range={range} />
        <Section
          title={t("staff.table")}
          actions={<Choices label={t("staff.role_kind")} options={ROLE_OPTIONS} value={roleKind} onChange={(next) => page.go(pageQuery(range, { role_kind: next }))} text={(option) => t(`role.${option || "all"}`)} />}
        >
          {staff.error ? (
            <ReadError error={staff.error} onRetry={() => void staff.refetch()} />
          ) : !staff.data ? (
            <SkeletonRows rows={6} label={t("loading")} />
          ) : staff.data.data.users.length === 0 ? (
            <p className="text-base text-neutral-600">{staff.data.as_of === null ? t("overview.not_built") : t("staff.empty")}</p>
          ) : (
            <StaffTable rows={staff.data.data.users} caption={t("staff.table")} />
          )}
        </Section>
      </div>
    </>
  );
}

export function StaffTable({ rows, caption }: { rows: PersonFigures[]; caption: string }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  const names = useNames();
  return (
    <TableFrame>
      <table className="w-full border-collapse text-left text-base">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr className="border-b border-neutral-200 text-sm text-neutral-600">
            <th scope="col" className={TH}>{t("col.person")}</th>
            <th scope="col" className={TH}>{t("col.role_kind")}</th>
            <th scope="col" className={TH_NUM}>{t("col.orders")}</th>
            <th scope="col" className={TH_NUM}>{t("col.items")}</th>
            <th scope="col" className={TH_NUM}>{t("col.revenue")}</th>
            <th scope="col" className={TH_NUM}>{t("col.payments")}</th>
            <th scope="col" className={TH_NUM}>{t("col.payments_amount")}</th>
            <th scope="col" className={TH_NUM}>{t("col.voids")}</th>
            <th scope="col" className={TH_NUM}>{t("col.refunds_count")}</th>
            <th scope="col" className={TH_NUM}>{t("col.lines_prepared")}</th>
            <th scope="col" className={TH_NUM}>{t("col.avg_prep")}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={`${row.user.id}-${row.role_kind}`} className="border-b border-neutral-100">
              <th scope="row" className="py-3 pr-4 font-medium text-neutral-900">
                {names.person(row.user)}
              </th>
              <td className="py-3 pr-4 align-top">{t(`role.${row.role_kind}`)}</td>
              <td className={TD_NUM}>{f.count(row.orders_count)}</td>
              <td className={TD_NUM}>{f.qty(row.items_qty)}</td>
              <td className={TD_NUM}>{f.amount(row.revenue)}</td>
              <td className={TD_NUM}>{f.count(row.payments_count)}</td>
              <td className={TD_NUM}>{f.amount(row.payments_amount)}</td>
              <td className={TD_NUM}>{f.count(row.voids_count)}</td>
              <td className={TD_NUM}>{f.count(row.refunds_count)}</td>
              <td className={TD_NUM}>{f.count(row.lines_prepared)}</td>
              <td className={TD_NUM}>{f.prep(row.avg_prep_seconds)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </TableFrame>
  );
}

/** /admin/analytics/me: the person's own stations and own work over the range. */
export function MeScreen() {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  const { range, today } = page;
  const me = useSession((s) => s.me);
  const freshness = useFreshness(range);
  const stations = useStationFigures(range);
  const staff = useStaffFigures(range, "");
  // the API already limits analytics.view_own; a person who reads everything sees their own here too
  const ownStations = new Set((me?.work_stations ?? []).map((station) => station.station_id));
  const myStations = (stations.data?.data.stations ?? []).filter((row) => ownStations.has(row.station.id));
  const myRows = (staff.data?.data.users ?? []).filter((row) => row.user.id === me?.user.id);

  return (
    <>
      <PageHeader title={t("me.title")} intro={t("me.intro")} />
      <div className="space-y-6">
        <RangeControl range={range} today={today} onChange={(next) => page.go(pageQuery(next, {}))} />
        <FreshnessBar state={freshness} asOf={staff.data?.as_of} />
        <Section title={t("me.work")}>
          {staff.error ? (
            <ReadError error={staff.error} onRetry={() => void staff.refetch()} />
          ) : !staff.data ? (
            <SkeletonRows rows={2} label={t("loading")} />
          ) : myRows.length === 0 ? (
            <p className="text-base text-neutral-600">{staff.data.as_of === null ? t("overview.not_built") : t("me.work_empty")}</p>
          ) : (
            <StaffTable rows={myRows} caption={t("me.work")} />
          )}
        </Section>
        <Section title={t("me.stations")}>
          {stations.error ? (
            <ReadError error={stations.error} onRetry={() => void stations.refetch()} />
          ) : !stations.data ? (
            <SkeletonRows rows={2} label={t("loading")} />
          ) : myStations.length === 0 ? (
            <p className="text-base text-neutral-600">{stations.data.as_of === null ? t("overview.not_built") : t("me.stations_empty")}</p>
          ) : (
            <StationsTable rows={myStations} caption={t("me.stations")} oneDay={range.from === range.to} />
          )}
        </Section>
      </div>
    </>
  );
}
