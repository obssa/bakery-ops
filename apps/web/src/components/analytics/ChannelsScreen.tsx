"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { paymentsDrill, seriesLabel, seriesTotals, spanLabel, type ChannelCount } from "@/lib/analytics";
import { useChannelFigures, type ChannelFigures, type SeriesGroup } from "@/lib/api/analytics";
import { can, useSession } from "@/lib/session";
import { useDateFormat } from "@/lib/useDates";
import { EmptyRow, TableCard } from "../admin/kit";
import { Line } from "../charts/Line";
import { focusRing } from "../styles";
import { groupOf } from "./SalesScreen";
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
  Section,
  SkeletonRows,
  useAnalyticsPage,
  useFigures,
  useFreshness,
  useNames,
} from "./shared";

// Admin > Analytics > Payment methods (step 4.5e; analytics.view): per payment method over the range (GET /analytics/channels), the money
// taken by the day it came in, its share, refunds and net, and the checker's counts: without a reference, without a photo, verified,
// disputed and duplicate references. A count opens the payments list (step 4.5c) with that day range, that method and the list's own
// filter where the list has one (verified, disputed, without photo except for cash: lib/analytics.ts paymentsDrill), for a person who may
// read the list. Above the table, the money of every method together by day, week or month (a point not built is a gap).
//
// Step 6.4d (fresh-counter.md 4.4): the title row with its twin and the exports; the range and the grouping as the toolbar; the methods a
// compact table card that fits the page (the money right-aligned in tabular numbers, a count that opens the payments a brand link), the
// money by day, week or month a brand-600 line on a white card under it.

const GROUPS: readonly SeriesGroup[] = ["day", "week", "month"];
const COUNTS: readonly ChannelCount[] = ["without_reference_count", "without_photo_count", "verified_count", "disputed_count", "duplicate_reference_count"];

export function ChannelsScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const f = useFigures();
  const page = useAnalyticsPage();
  const { range, params, today } = page;
  const group = groupOf(params);
  const freshness = useFreshness(range);
  const figures = useChannelFigures(range, group);
  const data = figures.data?.data;
  const query = (next: Partial<{ range: typeof range; group: SeriesGroup }>) => pageQuery(next.range ?? range, { group: (next.group ?? group) === "day" ? "" : (next.group ?? group) });
  const label = (key: string) => (group === "week" ? t("week_of", { date: seriesLabel(key, group, dates) }) : seriesLabel(key, group, dates));
  const totals = data ? seriesTotals(data.series) : [];

  return (
    <>
      <AnalyticsHeader title={t("channels.title")} intro={t("channels.intro")} titleAm={titleAm} report="channels" range={range} />
      <div className="flex flex-col gap-6">
        <AnalyticsNav />
        <RangeControl range={range} today={today} onChange={(next) => page.go(query({ range: next }))} aside={<FreshnessChip state={freshness} asOf={figures.data?.as_of} />}>
          <Choices label={t("sales.group")} options={GROUPS} value={group} onChange={(next) => page.go(query({ group: next }))} text={(option) => t(`group.${option}`)} />
        </RangeControl>
        <FreshnessNotice state={freshness} />
        {figures.error ? (
          <ReadError error={figures.error} onRetry={() => void figures.refetch()} />
        ) : (
          <>
            <ChannelTable rows={data?.channels} empty={figures.data?.as_of === null ? t("overview.not_built") : t("channels.empty")} />
            <Section title={t(`channels.by_${group}`)} actions={<span className="text-[13px] text-neutral-600">{t("col.amount")}</span>}>
              {!data ? (
                <SkeletonRows rows={4} label={t("loading")} />
              ) : (
                <Line
                  label={t("channels.chart_label", { range: spanLabel(range, dates) })}
                  points={data.series.map((point, i) => ({ key: point.key, label: label(point.key), value: totals[i] ?? null }))}
                  format={f.amount}
                  table={{ summary: t("chart.show_figures"), caption: t(`channels.by_${group}`), keyHeading: t(`col.${group}`), valueHeading: t("col.amount"), missingText: t("not_built") }}
                />
              )}
            </Section>
          </>
        )}
      </div>
    </>
  );
}

function ChannelTable({ rows, empty }: { rows: ChannelFigures[] | undefined; empty: string }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  const names = useNames();
  const { range } = useAnalyticsPage();
  const checker = useSession((s) => can(s.me, "payments.view"));
  const span = 8 + COUNTS.length;
  return (
    <TableCard caption={t("channels.table")} title={t("channels.table")} minWidth="min-w-[1040px]" compact>
      <thead>
        <tr>
          <th scope="col">{t("col.channel")}</th>
          <th scope="col" className={NUM}>
            {t("col.receipts")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.amount")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.share")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.refunds_count")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.refunded")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.net")}
          </th>
          {COUNTS.map((count) => (
            <th key={count} scope="col" className="text-right">
              {t(`col.${count}`)}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {rows === undefined ? (
          <tr>
            <td colSpan={span}>
              <SkeletonRows rows={4} label={t("loading")} />
            </td>
          </tr>
        ) : rows.length === 0 ? (
          <EmptyRow span={span}>{empty}</EmptyRow>
        ) : (
          rows.map((row) => {
            const name = names.channel(row.channel);
            return (
              <tr key={row.channel.id}>
                <th scope="row" className="font-semibold text-neutral-900">
                  {name}
                </th>
                <td className={NUM}>{f.count(row.receipts_count)}</td>
                <td className={`${NUM} font-semibold`}>{f.amount(row.amount)}</td>
                <td className={NUM}>{f.pct(row.share)}</td>
                <td className={NUM}>{f.count(row.refund_count)}</td>
                <td className={NUM}>{f.amount(row.refund_amount)}</td>
                <td className={`${NUM} font-semibold`}>{f.amount(row.net_amount)}</td>
                {COUNTS.map((count) => {
                  const value = row[count];
                  const drill = checker && value > 0 ? paymentsDrill(range, row.channel, count) : null;
                  return (
                    <td key={count} className={NUM}>
                      {drill ? (
                        <Link
                          href={{ pathname: "/admin/payments", query: drill }}
                          aria-label={t(`channels.drill.${count}`, { channel: name })}
                          className={`-my-1 inline-flex min-h-11 min-w-11 items-center justify-end rounded-lg px-2 font-semibold text-brand-600 underline underline-offset-2 hover:bg-brand-50 hover:text-brand-700 ${focusRing}`}
                        >
                          {f.count(value)}
                        </Link>
                      ) : (
                        f.count(value)
                      )}
                    </td>
                  );
                })}
              </tr>
            );
          })
        )}
      </tbody>
    </TableCard>
  );
}
