"use client";

import { useTranslations } from "next-intl";
import { seriesLabel, spanLabel } from "@/lib/analytics";
import { useShiftFigures } from "@/lib/api/analytics";
import { useDateFormat } from "@/lib/useDates";
import { TableCard } from "../admin/kit";
import { Bars } from "../charts/Bars";
import {
  AnalyticsHeader,
  AnalyticsNav,
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

// Admin > Analytics > Shifts (step 4.5e; analytics.view): GET /analytics/shifts, a point a day over the stations: the shifts that ran (a
// Night shift counts on both its days), those locked, and the count variance lines and cash variance of the shifts dated that day; the
// totals add up the variances only (each belongs to one day; shift-days are not summed). A day not built is said, never zero. The chart
// shows the cash variance a day (a shortage hangs below the zero line).
//
// Step 6.4d (fresh-counter.md 4.4): the title row with its twin and the exports; the range as the toolbar; the cash variance a day as
// Today's bars on a white card (the latest day brand-600, a shortage red under the zero line); the days a table card with the totals in
// its foot.

export function ShiftsScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const f = useFigures();
  const page = useAnalyticsPage();
  const { range, today } = page;
  const freshness = useFreshness(range);
  const shifts = useShiftFigures(range);
  const data = shifts.data?.data;

  return (
    <>
      <AnalyticsHeader title={t("shifts.title")} intro={t("shifts.intro")} titleAm={titleAm} report="shifts" range={range} fresh={<FreshnessChip state={freshness} asOf={shifts.data?.as_of} />} />
      <div className="flex flex-col gap-6">
        <AnalyticsNav />
        <RangeControl range={range} today={today} onChange={(next) => page.go(pageQuery(next, {}))} />
        <FreshnessNotice state={freshness} />
        {shifts.error ? (
          <ReadError error={shifts.error} onRetry={() => void shifts.refetch()} />
        ) : (
          <>
            <Section title={t("col.cash_variance")}>
              {!data ? (
                <SkeletonRows rows={5} label={t("loading")} />
              ) : (
                <Bars
                  label={t("shifts.chart_label", { range: spanLabel(range, dates) })}
                  points={data.days.map((day) => ({ key: day.day, label: seriesLabel(day.day, "day", dates), value: day.cash_variance_total }))}
                  format={f.amount}
                  height={160}
                />
              )}
            </Section>
            {data ? (
              <TableCard caption={t("shifts.table")} title={t("shifts.table")} minWidth="min-w-[640px]">
                <thead>
                  <tr>
                    <th scope="col">{t("col.day")}</th>
                    <th scope="col" className={NUM}>
                      {t("col.shifts")}
                    </th>
                    <th scope="col" className={NUM}>
                      {t("col.locked")}
                    </th>
                    <th scope="col" className={NUM}>
                      {t("col.variance_lines")}
                    </th>
                    <th scope="col" className={NUM}>
                      {t("col.cash_variance")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {[...data.days].reverse().map((day) => (
                    <tr key={day.day}>
                      <th scope="row" className="whitespace-nowrap font-semibold">
                        {seriesLabel(day.day, "day", dates)}
                      </th>
                      {!day.built ? (
                        <td colSpan={4} className={NOT_BUILT}>
                          {t("not_built")}
                        </td>
                      ) : (
                        <>
                          <td className={NUM}>{f.count(day.shifts_count)}</td>
                          <td className={NUM}>{f.count(day.shifts_locked_count)}</td>
                          <td className={NUM}>{f.count(day.variance_line_count)}</td>
                          <td className={`${NUM} font-semibold`}>{f.amount(day.cash_variance_total)}</td>
                        </>
                      )}
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr className="font-semibold">
                    <th scope="row">{t("shifts.totals")}</th>
                    <td className={NUM}>—</td>
                    <td className={NUM}>—</td>
                    <td className={NUM}>{f.count(data.totals.variance_line_count)}</td>
                    <td className={NUM}>{f.amount(data.totals.cash_variance_total)}</td>
                  </tr>
                </tfoot>
              </TableCard>
            ) : null}
          </>
        )}
      </div>
    </>
  );
}
