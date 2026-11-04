"use client";

import { ChevronDown } from "lucide-react";
import { useTranslations } from "next-intl";
import { hourLabel, seriesLabel, spanLabel } from "@/lib/analytics";
import { useHourly, useSales, type SeriesGroup } from "@/lib/api/analytics";
import { compareDecimal } from "@/lib/decimal";
import { useDateFormat } from "@/lib/useDates";
import { InnerTable, TableCard } from "../admin/kit";
import { Bars } from "../charts/Bars";
import { Line } from "../charts/Line";
import { focusRing } from "../styles";
import {
  AnalyticsHeader,
  AnalyticsNav,
  Choices,
  FreshnessChip,
  FreshnessNotice,
  NOT_BUILT,
  NUM,
  pageQuery,
  RangeControl,
  ReadError,
  Section,
  SkeletonRows,
  useAnalyticsPage,
  useFigures,
  useFreshness,
} from "./shared";

// Admin > Analytics > Sales (step 4.5e; analytics.view): revenue and orders by day, by Monday-to-Sunday week (local) or by month
// (GET /analytics/sales?group=), each point the sums of its built days (a point with none is "not built", never zero), and the 24 local
// hours summed over the range (GET /analytics/sales/hourly). The grouping is kept in the address with the range; the export is the sales
// report (its days and its hours).
//
// Step 6.4d (fresh-counter.md 4.4): the title row with its twin, the freshness pill and the exports; the range and the grouping as the
// toolbar; the line in brand-600 on a white card, then the hours as Today's bars (the busiest hour brand-600, every hour labelled, their
// figures folded), then the figures of the line as a table card.

const GROUPS: readonly SeriesGroup[] = ["day", "week", "month"];

export function groupOf(params: URLSearchParams): SeriesGroup {
  const group = params.get("group");
  return group === "week" || group === "month" ? group : "day";
}

/** The fold under a chart: a brand link that opens its figures. */
export const FOLD_SUMMARY = `inline-flex min-h-11 cursor-pointer list-none items-center gap-1.5 rounded-lg px-1 text-sm font-semibold text-brand-600 hover:text-brand-700 [&::-webkit-details-marker]:hidden ${focusRing}`;

export function SalesScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  const { range, params, today } = page;
  const group = groupOf(params);
  const freshness = useFreshness(range);
  const query = (next: Partial<{ range: typeof range; group: SeriesGroup }>) => pageQuery(next.range ?? range, { group: (next.group ?? group) === "day" ? "" : (next.group ?? group) });

  return (
    <>
      <AnalyticsHeader title={t("sales.title")} intro={t("sales.intro")} titleAm={titleAm} report="sales" range={range} fresh={<FreshnessChip state={freshness} />} />
      <div className="flex flex-col gap-6">
        <AnalyticsNav />
        <RangeControl range={range} today={today} onChange={(next) => page.go(query({ range: next }))}>
          <Choices label={t("sales.group")} options={GROUPS} value={group} onChange={(next) => page.go(query({ group: next }))} text={(option) => t(`group.${option}`)} />
        </RangeControl>
        <FreshnessNotice state={freshness} />
        <Series group={group} part="chart" />
        <Hours />
        <Series group={group} part="table" />
      </div>
    </>
  );
}

/** The series (one read, cached): `chart` its line on a white card, `table` its figures as a table card; the charts come first. */
function Series({ group, part }: { group: SeriesGroup; part: "chart" | "table" }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const f = useFigures();
  const { range } = useAnalyticsPage();
  const sales = useSales(range, group);
  const series = sales.data?.data.series ?? [];
  const label = (key: string) => (group === "week" ? t("week_of", { date: seriesLabel(key, group, dates) }) : seriesLabel(key, group, dates));

  if (sales.error) return part === "chart" ? <ReadError error={sales.error} onRetry={() => void sales.refetch()} /> : null;
  if (part === "chart") {
    return (
      <Section title={t(`sales.by_${group}`)} actions={<span className="text-[13px] text-neutral-600">{t("col.revenue")}</span>}>
        {!sales.data ? (
          <SkeletonRows rows={6} label={t("loading")} />
        ) : (
          <Line label={t("sales.chart_label", { range: spanLabel(range, dates) })} points={series.map((point) => ({ key: point.key, label: label(point.key), value: point.revenue }))} format={f.amount} />
        )}
      </Section>
    );
  }
  return (
    <>
      {sales.data ? (
        <TableCard caption={t(`sales.by_${group}`)} minWidth="min-w-[860px]">
          <thead>
            <tr>
              <th scope="col">{t(`col.${group}`)}</th>
              <th scope="col" className={NUM}>
                {t("col.built")}
              </th>
              <th scope="col" className={NUM}>
                {t("col.orders")}
              </th>
              <th scope="col" className={NUM}>
                {t("col.items")}
              </th>
              <th scope="col" className={NUM}>
                {t("col.revenue")}
              </th>
              <th scope="col" className={NUM}>
                {t("col.refunds")}
              </th>
              <th scope="col" className={NUM}>
                {t("col.net_revenue")}
              </th>
              <th scope="col" className={NUM}>
                {t("col.avg_ticket")}
              </th>
            </tr>
          </thead>
          <tbody>
            {[...series].reverse().map((point) => (
              <tr key={point.key}>
                <th scope="row" className="whitespace-nowrap font-semibold">
                  {label(point.key)}
                </th>
                <td className={`${NUM} text-neutral-600`}>{t("built_of", { built: point.built_days, days: point.days })}</td>
                {point.built_days === 0 ? (
                  <td colSpan={6} className={NOT_BUILT}>
                    {t("not_built")}
                  </td>
                ) : (
                  <>
                    <td className={NUM}>{f.count(point.orders_count)}</td>
                    <td className={NUM}>{f.qty(point.items_qty)}</td>
                    <td className={`${NUM} font-semibold`}>{f.amount(point.revenue)}</td>
                    <td className={NUM}>{f.amount(point.refund_total)}</td>
                    <td className={NUM}>{f.amount(point.net_revenue)}</td>
                    <td className={NUM}>{f.amount(point.avg_ticket)}</td>
                  </>
                )}
              </tr>
            ))}
          </tbody>
        </TableCard>
      ) : null}
    </>
  );
}

/** The hours the chart shows, as Today's: 06:00 to 17:00, widened to any hour that sold (the table under it keeps all 24). */
function hourWindow<T extends { hour: number; revenue: string | null }>(hours: readonly T[]): T[] {
  let first = 6;
  let last = 17;
  for (const { hour, revenue } of hours) {
    if (revenue === null || compareDecimal(revenue, "0") <= 0) continue;
    first = Math.min(first, hour);
    last = Math.max(last, hour);
  }
  return hours.filter((hour) => hour.hour >= first && hour.hour <= last);
}

function Hours() {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const f = useFigures();
  const { range } = useAnalyticsPage();
  const hourly = useHourly(range);
  const hours = hourly.data?.data.hours ?? [];
  const built = hourly.data ? hourly.data.as_of !== null : false;

  return (
    <Section title={t("sales.hourly")} actions={<span className="text-[13px] text-neutral-600">{t("sales.hourly_note")}</span>}>
      {hourly.error ? (
        <ReadError error={hourly.error} onRetry={() => void hourly.refetch()} />
      ) : !hourly.data ? (
        <SkeletonRows rows={6} label={t("loading")} />
      ) : !built ? (
        <p className="text-[15px] text-neutral-600">{t("overview.not_built")}</p>
      ) : (
        <div className="space-y-3">
          <Bars
            label={t("sales.hourly_label", { range: spanLabel(range, dates) })}
            points={hourWindow(hours).map((hour) => ({ key: String(hour.hour), label: hourLabel(hour.hour), value: hour.revenue }))}
            format={f.amount}
            height={180}
            highlight="peak"
            ticks="all"
            tick={(point) => point.key}
          />
          <details className="group">
            <summary className={FOLD_SUMMARY}>
              {t("sales.hourly_table")}
              <ChevronDown className="size-4 motion-safe:transition-transform group-open:rotate-180" strokeWidth={2} aria-hidden="true" />
            </summary>
            <div className="mt-2">
              <InnerTable caption={t("sales.hourly")} minWidth="min-w-[480px]">
                <thead>
                  <tr>
                    <th scope="col">{t("col.hour")}</th>
                    <th scope="col" className={NUM}>
                      {t("col.orders")}
                    </th>
                    <th scope="col" className={NUM}>
                      {t("col.items")}
                    </th>
                    <th scope="col" className={NUM}>
                      {t("col.revenue")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {hours.map((hour) => (
                    <tr key={hour.hour}>
                      <th scope="row" className="font-semibold tabular-nums">
                        {hourLabel(hour.hour)}
                      </th>
                      <td className={NUM}>{f.count(hour.orders_count)}</td>
                      <td className={NUM}>{f.qty(hour.items_qty)}</td>
                      <td className={NUM}>{f.amount(hour.revenue)}</td>
                    </tr>
                  ))}
                </tbody>
              </InnerTable>
            </div>
          </details>
        </div>
      )}
    </Section>
  );
}
