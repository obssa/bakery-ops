"use client";

import { useLocale, useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { paymentsDrill, seriesLabel, seriesTotals, spanLabel, type ChannelCount } from "@/lib/analytics";
import { useChannelFigures, type ChannelFigures, type SeriesGroup } from "@/lib/api/analytics";
import { can, useSession } from "@/lib/session";
import { Line } from "../charts/Line";
import { PageHeader } from "../ui";
import { focusRing } from "../styles";
import { groupOf } from "./SalesScreen";
import { Choices, ExportBar, FreshnessBar, pageQuery, RangeControl, ReadError, Section, SkeletonRows, TableFrame, TD_NUM, TH, TH_NUM, useAnalyticsPage, useFigures, useFreshness, useNames } from "./shared";

// Admin > Analytics > Payment methods (step 4.5e; analytics.view): per payment method over the range (GET /analytics/channels), the money
// taken by the day it came in, its share, refunds and net, and the checker's counts: without a reference, without a photo, verified,
// disputed and duplicate references. A count opens the payments list (step 4.5c) with that day range, that method and the list's own
// filter where the list has one (verified, disputed, without photo except for cash: lib/analytics.ts paymentsDrill), for a person who may
// read the list. Above the table, the money of every method together by day, week or month (a point not built is a gap).

const GROUPS: readonly SeriesGroup[] = ["day", "week", "month"];
const COUNTS: readonly ChannelCount[] = ["without_reference_count", "without_photo_count", "verified_count", "disputed_count", "duplicate_reference_count"];

export function ChannelsScreen() {
  const t = useTranslations("analytics");
  const locale = useLocale();
  const f = useFigures();
  const page = useAnalyticsPage();
  const { range, params, today } = page;
  const group = groupOf(params);
  const freshness = useFreshness(range);
  const figures = useChannelFigures(range, group);
  const data = figures.data?.data;
  const query = (next: Partial<{ range: typeof range; group: SeriesGroup }>) => pageQuery(next.range ?? range, { group: (next.group ?? group) === "day" ? "" : (next.group ?? group) });
  const label = (key: string) => (group === "week" ? t("week_of", { date: seriesLabel(key, group, locale) }) : seriesLabel(key, group, locale));
  const totals = data ? seriesTotals(data.series) : [];

  return (
    <>
      <PageHeader title={t("channels.title")} intro={t("channels.intro")} />
      <div className="space-y-6">
        <RangeControl range={range} today={today} onChange={(next) => page.go(query({ range: next }))} />
        <FreshnessBar state={freshness} asOf={figures.data?.as_of} />
        <ExportBar report="channels" range={range} />
        {figures.error ? (
          <ReadError error={figures.error} onRetry={() => void figures.refetch()} />
        ) : (
          <>
            <Section title={t("channels.table")}>
              {!data ? (
                <SkeletonRows rows={6} label={t("loading")} />
              ) : data.channels.length === 0 ? (
                <p className="text-base text-neutral-600">{figures.data?.as_of === null ? t("overview.not_built") : t("channels.empty")}</p>
              ) : (
                <ChannelTable rows={data.channels} />
              )}
            </Section>
            <Section title={t(`channels.by_${group}`)} actions={<Choices label={t("sales.group")} options={GROUPS} value={group} onChange={(next) => page.go(query({ group: next }))} text={(option) => t(`group.${option}`)} />}>
              {!data ? (
                <SkeletonRows rows={4} label={t("loading")} />
              ) : (
                <Line
                  label={t("channels.chart_label", { range: spanLabel(range, locale) })}
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

function ChannelTable({ rows }: { rows: ChannelFigures[] }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  const names = useNames();
  const { range } = useAnalyticsPage();
  const checker = useSession((s) => can(s.me, "payments.view"));
  return (
    <TableFrame>
      <table className="w-full border-collapse text-left text-base">
        <caption className="sr-only">{t("channels.table")}</caption>
        <thead>
          <tr className="border-b border-neutral-200 text-sm text-neutral-600">
            <th scope="col" className={TH}>{t("col.channel")}</th>
            <th scope="col" className={TH_NUM}>{t("col.receipts")}</th>
            <th scope="col" className={TH_NUM}>{t("col.amount")}</th>
            <th scope="col" className={TH_NUM}>{t("col.share")}</th>
            <th scope="col" className={TH_NUM}>{t("col.refunds_count")}</th>
            <th scope="col" className={TH_NUM}>{t("col.refunded")}</th>
            <th scope="col" className={TH_NUM}>{t("col.net")}</th>
            {COUNTS.map((count) => (
              <th key={count} scope="col" className={TH_NUM}>
                {t(`col.${count}`)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            const name = names.channel(row.channel);
            return (
              <tr key={row.channel.id} className="border-b border-neutral-100">
                <th scope="row" className="py-3 pr-4 font-medium text-neutral-900">
                  {name}
                </th>
                <td className={TD_NUM}>{f.count(row.receipts_count)}</td>
                <td className={TD_NUM}>{f.amount(row.amount)}</td>
                <td className={TD_NUM}>{f.pct(row.share)}</td>
                <td className={TD_NUM}>{f.count(row.refund_count)}</td>
                <td className={TD_NUM}>{f.amount(row.refund_amount)}</td>
                <td className={TD_NUM}>{f.amount(row.net_amount)}</td>
                {COUNTS.map((count) => {
                  const value = row[count];
                  const drill = checker && value > 0 ? paymentsDrill(range, row.channel, count) : null;
                  return (
                    <td key={count} className={TD_NUM}>
                      {drill ? (
                        <Link
                          href={{ pathname: "/admin/payments", query: drill }}
                          aria-label={t(`channels.drill.${count}`, { channel: name })}
                          className={`inline-flex min-h-12 min-w-12 items-center justify-end rounded-md px-2 font-semibold text-blue-800 underline hover:bg-blue-50 ${focusRing}`}
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
          })}
        </tbody>
      </table>
    </TableFrame>
  );
}
