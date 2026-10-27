"use client";

import { useLocale, useTranslations } from "next-intl";
import { hourLabel, seriesLabel, spanLabel } from "@/lib/analytics";
import { useHourly, useSales, type SeriesGroup } from "@/lib/api/analytics";
import { Bars } from "../charts/Bars";
import { Line } from "../charts/Line";
import { PageHeader } from "../ui";
import { focusRing } from "../styles";
import { Choices, ExportBar, FreshnessBar, pageQuery, RangeControl, ReadError, Section, SkeletonRows, TableFrame, TD, TD_NUM, TH, TH_NUM, useAnalyticsPage, useFigures, useFreshness } from "./shared";

// Admin > Analytics > Sales (step 4.5e; analytics.view): revenue and orders by day, by Monday-to-Sunday week (local) or by month
// (GET /analytics/sales?group=), each point the sums of its built days (a point with none is "not built", never zero), and the 24 local
// hours summed over the range (GET /analytics/sales/hourly). The grouping is kept in the address with the range; the export is the sales
// report (its days and its hours).

const GROUPS: readonly SeriesGroup[] = ["day", "week", "month"];

export function groupOf(params: URLSearchParams): SeriesGroup {
  const group = params.get("group");
  return group === "week" || group === "month" ? group : "day";
}

export function SalesScreen() {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  const { range, params, today } = page;
  const group = groupOf(params);
  const freshness = useFreshness(range);
  const query = (next: Partial<{ range: typeof range; group: SeriesGroup }>) => pageQuery(next.range ?? range, { group: (next.group ?? group) === "day" ? "" : (next.group ?? group) });

  return (
    <>
      <PageHeader title={t("sales.title")} intro={t("sales.intro")} />
      <div className="space-y-6">
        <RangeControl range={range} today={today} onChange={(next) => page.go(query({ range: next }))} />
        <FreshnessBar state={freshness} />
        <ExportBar report="sales" range={range} />
        <Series group={group} onGroup={(next) => page.go(query({ group: next }))} />
        <Hours />
      </div>
    </>
  );
}

function Series({ group, onGroup }: { group: SeriesGroup; onGroup: (group: SeriesGroup) => void }) {
  const t = useTranslations("analytics");
  const locale = useLocale();
  const f = useFigures();
  const { range } = useAnalyticsPage();
  const sales = useSales(range, group);
  const series = sales.data?.data.series ?? [];
  const label = (key: string) => (group === "week" ? t("week_of", { date: seriesLabel(key, group, locale) }) : seriesLabel(key, group, locale));

  return (
    <Section title={t(`sales.by_${group}`)} actions={<Choices label={t("sales.group")} options={GROUPS} value={group} onChange={onGroup} text={(option) => t(`group.${option}`)} />}>
      {sales.error ? (
        <ReadError error={sales.error} onRetry={() => void sales.refetch()} />
      ) : !sales.data ? (
        <SkeletonRows rows={6} label={t("loading")} />
      ) : (
        <div className="space-y-6">
          <Line label={t("sales.chart_label", { range: spanLabel(range, locale) })} points={series.map((point) => ({ key: point.key, label: label(point.key), value: point.revenue }))} format={f.amount} />
          <TableFrame>
            <table className="w-full border-collapse text-left text-base">
              <caption className="sr-only">{t(`sales.by_${group}`)}</caption>
              <thead>
                <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                  <th scope="col" className={TH}>{t(`col.${group}`)}</th>
                  <th scope="col" className={TH_NUM}>{t("col.built")}</th>
                  <th scope="col" className={TH_NUM}>{t("col.orders")}</th>
                  <th scope="col" className={TH_NUM}>{t("col.items")}</th>
                  <th scope="col" className={TH_NUM}>{t("col.revenue")}</th>
                  <th scope="col" className={TH_NUM}>{t("col.refunds")}</th>
                  <th scope="col" className={TH_NUM}>{t("col.net_revenue")}</th>
                  <th scope="col" className={TH_NUM}>{t("col.avg_ticket")}</th>
                </tr>
              </thead>
              <tbody>
                {[...series].reverse().map((point) => (
                  <tr key={point.key} className="border-b border-neutral-100">
                    <th scope="row" className={`${TD} font-medium`}>{label(point.key)}</th>
                    <td className={TD_NUM}>{t("built_of", { built: point.built_days, days: point.days })}</td>
                    {point.built_days === 0 ? (
                      <td colSpan={6} className={`${TD} text-right text-neutral-500`}>{t("not_built")}</td>
                    ) : (
                      <>
                        <td className={TD_NUM}>{f.count(point.orders_count)}</td>
                        <td className={TD_NUM}>{f.qty(point.items_qty)}</td>
                        <td className={TD_NUM}>{f.amount(point.revenue)}</td>
                        <td className={TD_NUM}>{f.amount(point.refund_total)}</td>
                        <td className={TD_NUM}>{f.amount(point.net_revenue)}</td>
                        <td className={TD_NUM}>{f.amount(point.avg_ticket)}</td>
                      </>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </TableFrame>
        </div>
      )}
    </Section>
  );
}

function Hours() {
  const t = useTranslations("analytics");
  const locale = useLocale();
  const f = useFigures();
  const { range } = useAnalyticsPage();
  const hourly = useHourly(range);
  const hours = hourly.data?.data.hours ?? [];
  const built = hourly.data ? hourly.data.as_of !== null : false;

  return (
    <Section title={t("sales.hourly")}>
      {hourly.error ? (
        <ReadError error={hourly.error} onRetry={() => void hourly.refetch()} />
      ) : !hourly.data ? (
        <SkeletonRows rows={6} label={t("loading")} />
      ) : !built ? (
        <p className="text-base text-neutral-600">{t("overview.not_built")}</p>
      ) : (
        <div className="space-y-6">
          <p className="text-sm text-neutral-600">{t("sales.hourly_note")}</p>
          <Bars label={t("sales.hourly_label", { range: spanLabel(range, locale) })} points={hours.map((hour) => ({ key: String(hour.hour), label: hourLabel(hour.hour), value: hour.revenue }))} format={f.amount} />
          <details>
            <summary className={`inline-flex min-h-12 cursor-pointer items-center rounded-md px-2 text-base font-medium text-blue-800 hover:bg-blue-50 ${focusRing}`}>{t("sales.hourly_table")}</summary>
            <TableFrame>
              <table className="w-full border-collapse text-left text-base">
                <caption className="sr-only">{t("sales.hourly")}</caption>
                <thead>
                  <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                    <th scope="col" className={TH}>{t("col.hour")}</th>
                    <th scope="col" className={TH_NUM}>{t("col.orders")}</th>
                    <th scope="col" className={TH_NUM}>{t("col.items")}</th>
                    <th scope="col" className={TH_NUM}>{t("col.revenue")}</th>
                  </tr>
                </thead>
                <tbody>
                  {hours.map((hour) => (
                    <tr key={hour.hour} className="border-b border-neutral-100">
                      <th scope="row" className={`${TD} font-medium tabular-nums`}>{hourLabel(hour.hour)}</th>
                      <td className={TD_NUM}>{f.count(hour.orders_count)}</td>
                      <td className={TD_NUM}>{f.qty(hour.items_qty)}</td>
                      <td className={TD_NUM}>{f.amount(hour.revenue)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </TableFrame>
          </details>
        </div>
      )}
    </Section>
  );
}
