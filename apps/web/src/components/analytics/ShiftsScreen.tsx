"use client";

import { useLocale, useTranslations } from "next-intl";
import { seriesLabel, spanLabel } from "@/lib/analytics";
import { useShiftFigures } from "@/lib/api/analytics";
import { Bars } from "../charts/Bars";
import { PageHeader } from "../ui";
import { ExportBar, FreshnessBar, pageQuery, RangeControl, ReadError, Section, SkeletonRows, TableFrame, TD, TD_NUM, TH, TH_NUM, useAnalyticsPage, useFigures, useFreshness } from "./shared";

// Admin > Analytics > Shifts (step 4.5e; analytics.view): GET /analytics/shifts, a point a day over the stations: the shifts that ran (a
// Night shift counts on both its days), those locked, and the count variance lines and cash variance of the shifts dated that day; the
// totals add up the variances only (each belongs to one day; shift-days are not summed). A day not built is said, never zero. The chart
// shows the cash variance a day (a shortage hangs below the zero line).

export function ShiftsScreen() {
  const t = useTranslations("analytics");
  const locale = useLocale();
  const f = useFigures();
  const page = useAnalyticsPage();
  const { range, today } = page;
  const freshness = useFreshness(range);
  const shifts = useShiftFigures(range);
  const data = shifts.data?.data;

  return (
    <>
      <PageHeader title={t("shifts.title")} intro={t("shifts.intro")} />
      <div className="space-y-6">
        <RangeControl range={range} today={today} onChange={(next) => page.go(pageQuery(next, {}))} />
        <FreshnessBar state={freshness} asOf={shifts.data?.as_of} />
        <ExportBar report="shifts" range={range} />
        <Section title={t("shifts.table")}>
          {shifts.error ? (
            <ReadError error={shifts.error} onRetry={() => void shifts.refetch()} />
          ) : !data ? (
            <SkeletonRows rows={7} label={t("loading")} />
          ) : (
            <div className="space-y-6">
              <Bars label={t("shifts.chart_label", { range: spanLabel(range, locale) })} points={data.days.map((day) => ({ key: day.day, label: seriesLabel(day.day, "day", locale), value: day.cash_variance_total }))} format={f.amount} height={140} />
              <TableFrame>
                <table className="w-full border-collapse text-left text-base">
                  <caption className="sr-only">{t("shifts.table")}</caption>
                  <thead>
                    <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                      <th scope="col" className={TH}>{t("col.day")}</th>
                      <th scope="col" className={TH_NUM}>{t("col.shifts")}</th>
                      <th scope="col" className={TH_NUM}>{t("col.locked")}</th>
                      <th scope="col" className={TH_NUM}>{t("col.variance_lines")}</th>
                      <th scope="col" className={TH_NUM}>{t("col.cash_variance")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {[...data.days].reverse().map((day) => (
                      <tr key={day.day} className="border-b border-neutral-100">
                        <th scope="row" className={`${TD} font-medium`}>{seriesLabel(day.day, "day", locale)}</th>
                        {!day.built ? (
                          <td colSpan={4} className={`${TD} text-right text-neutral-500`}>{t("not_built")}</td>
                        ) : (
                          <>
                            <td className={TD_NUM}>{f.count(day.shifts_count)}</td>
                            <td className={TD_NUM}>{f.count(day.shifts_locked_count)}</td>
                            <td className={TD_NUM}>{f.count(day.variance_line_count)}</td>
                            <td className={TD_NUM}>{f.amount(day.cash_variance_total)}</td>
                          </>
                        )}
                      </tr>
                    ))}
                  </tbody>
                  <tfoot>
                    <tr className="border-t-2 border-neutral-300 font-semibold">
                      <th scope="row" className={TD}>{t("shifts.totals")}</th>
                      <td className={TD_NUM}>—</td>
                      <td className={TD_NUM}>—</td>
                      <td className={TD_NUM}>{f.count(data.totals.variance_line_count)}</td>
                      <td className={TD_NUM}>{f.amount(data.totals.cash_variance_total)}</td>
                    </tr>
                  </tfoot>
                </table>
              </TableFrame>
            </div>
          )}
        </Section>
      </div>
    </>
  );
}
