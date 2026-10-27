"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { differenceOf, percentChange, seriesLabel, type AnalyticsRange } from "@/lib/analytics";
import { useOverview, useSales, type Answer, type Overview } from "@/lib/api/analytics";
import { useDateFormat } from "@/lib/useDates";
import { Bars } from "../charts/Bars";
import { Sparkline } from "../charts/Sparkline";
import { PageHeader } from "../ui";
import { focusRing } from "../styles";
import { FreshnessBar, Kpi, pageQuery, RangeControl, ReadError, Section, SkeletonRows, TableFrame, TD, TD_NUM, TH, TH_NUM, useAnalyticsPage, useFigures, useFreshness, useNames } from "./shared";

// Admin > Analytics > Overview (step 4.5e; analytics.view): the range's headline figures (revenue, net revenue, orders, the average ticket,
// items sold, refunds, voids, cancelled orders), revenue by order type, the payment methods' shares and the five best sellers, from
// GET /analytics/overview alone, so the first figure paints from that one answer. ?compare=previous adds the change from the same number of
// days before. The revenue tile's sparkline (GET /analytics/sales by day) and the freshness feed start once the overview has answered, so
// nothing competes with it. Refunds are the summaries' figures (zero until steps 4.1b and 4.3d); a range with no built day shows no
// figure at all (R-U: not built is never zero).

const TYPES = ["dine_in", "takeaway", "delivery", "advance"] as const;

export function OverviewScreen() {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  const { range, params, today } = page;
  const compare = params.get("compare") === "previous";
  const overview = useOverview(range, compare);
  const answered = overview.isFetched;
  const freshness = useFreshness(range, compare, answered);

  function choose(next: AnalyticsRange, nextCompare = compare) {
    page.go(pageQuery(next, { compare: nextCompare ? "previous" : "" }));
  }

  return (
    <>
      <PageHeader title={t("overview.title")} intro={t("overview.intro")} />
      <div className="space-y-6">
        <RangeControl range={range} today={today} onChange={(next) => choose(next)}>
          <label className="inline-flex min-h-12 items-center gap-3 text-base text-neutral-800">
            <input type="checkbox" className="h-5 w-5" checked={compare} onChange={(event) => choose(range, event.target.checked)} />
            {t("overview.compare")}
          </label>
        </RangeControl>
        <FreshnessBar state={freshness} asOf={overview.data?.as_of} />
        {overview.error ? <ReadError error={overview.error} onRetry={() => void overview.refetch()} /> : <Headline answer={overview.data} compare={compare} range={range} showSpark={answered} />}
        <div className="grid gap-6 lg:grid-cols-2">
          <ByType answer={overview.data} />
          <ChannelShares answer={overview.data} />
        </div>
        <TopItems answer={overview.data} />
      </div>
    </>
  );
}

function Headline({ answer, compare, range, showSpark }: { answer: Answer<Overview> | undefined; compare: boolean; range: AnalyticsRange; showSpark: boolean }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  const loading = answer === undefined;
  const data = answer?.data;
  const built = answer ? answer.as_of !== null : false;
  const before = answer?.previous;
  const beforeBuilt = before ? before.as_of !== null : false;

  /** The change line of a tile with the comparison: a signed percent and the difference, or why there is none. */
  function change(current: string, previous: string | undefined, kind: "money" | "count" | "qty") {
    if (!compare || !before || previous === undefined) return undefined;
    if (!beforeBuilt) return t("overview.change_none");
    const pct = percentChange(current, previous);
    const raw = differenceOf(current, previous, kind === "money" ? 2 : kind === "count" ? 0 : 4);
    const diff = kind === "qty" ? (raw.startsWith("+") ? `+${f.qty(raw.slice(1))}` : f.qty(raw)) : f.amount(raw);
    return pct === null ? t("overview.change_from_zero", { difference: diff }) : t("overview.change", { pct, difference: diff });
  }

  const value = (text: string) => (built ? text : "—");
  const detailNotBuilt = !loading && !built ? t("overview.not_built") : undefined;

  return (
    <section aria-label={t("overview.headline")} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <Kpi label={t("kpi.revenue")} loading={loading} value={data && value(f.money(data.revenue))} detail={detailNotBuilt} change={data && built ? change(data.revenue, before?.data.revenue, "money") : undefined}>
        {showSpark && built && range.from !== range.to ? <RevenueSpark range={range} /> : null}
      </Kpi>
      <Kpi label={t("kpi.net_revenue")} loading={loading} value={data && value(f.money(data.net_revenue))} detail={detailNotBuilt} change={data && built ? change(data.net_revenue, before?.data.net_revenue, "money") : undefined} />
      <Kpi label={t("kpi.orders")} loading={loading} value={data && value(f.count(data.orders_count))} detail={detailNotBuilt} change={data && built ? change(String(data.orders_count), before ? String(before.data.orders_count) : undefined, "count") : undefined} />
      <Kpi label={t("kpi.avg_ticket")} loading={loading} value={data && value(f.money(data.avg_ticket))} detail={detailNotBuilt} change={data && built ? change(data.avg_ticket, before?.data.avg_ticket, "money") : undefined} />
      <Kpi label={t("kpi.items")} loading={loading} value={data && value(f.qty(data.items_qty))} detail={detailNotBuilt} change={data && built ? change(data.items_qty, before?.data.items_qty, "qty") : undefined} />
      <Kpi
        label={t("kpi.refunds")}
        loading={loading}
        value={data && value(f.money(data.refund_total))}
        detail={data && built ? t("kpi.refunds_count", { count: data.refund_count }) : detailNotBuilt}
        change={data && built ? change(data.refund_total, before?.data.refund_total, "money") : undefined}
      />
      <Kpi
        label={t("kpi.voids")}
        loading={loading}
        value={data && value(f.money(data.voided_total))}
        detail={data && built ? t("kpi.voids_count", { count: data.voided_count }) : detailNotBuilt}
        change={data && built ? change(data.voided_total, before?.data.voided_total, "money") : undefined}
      />
      <Kpi label={t("kpi.cancelled")} loading={loading} value={data && value(f.count(data.cancelled_count))} detail={detailNotBuilt} change={data && built ? change(String(data.cancelled_count), before ? String(before.data.cancelled_count) : undefined, "count") : undefined} />
    </section>
  );
}

/** Revenue day by day inside the revenue tile; asked for only after the overview answered. */
function RevenueSpark({ range }: { range: AnalyticsRange }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const f = useFigures();
  const sales = useSales(range, "day");
  if (!sales.data) return <span aria-hidden="true" className="mt-2 block h-9 animate-pulse rounded bg-neutral-100" />;
  const points = sales.data.data.series.map((point) => ({ key: point.key, label: seriesLabel(point.key, "day", dates), value: point.revenue }));
  return <Sparkline label={t("overview.spark_label")} points={points} format={f.amount} table={{ caption: t("overview.spark_label"), keyHeading: t("col.day"), valueHeading: t("col.revenue"), missingText: t("not_built") }} />;
}

function ByType({ answer }: { answer: Answer<Overview> | undefined }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  return (
    <Section title={t("overview.by_type")}>
      {answer === undefined ? (
        <SkeletonRows rows={4} label={t("loading")} />
      ) : answer.as_of === null ? (
        <p className="text-base text-neutral-600">{t("overview.not_built")}</p>
      ) : (
        <Bars
          orientation="horizontal"
          label={t("overview.by_type_label")}
          points={TYPES.map((type) => ({ key: type, label: t(`type.${type}`), value: answer.data.by_type[type] }))}
          format={f.amount}
          table={{ summary: t("chart.show_figures"), caption: t("overview.by_type"), keyHeading: t("col.type"), valueHeading: t("col.revenue"), missingText: t("not_built") }}
        />
      )}
    </Section>
  );
}

function ChannelShares({ answer }: { answer: Answer<Overview> | undefined }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  const names = useNames();
  const channels = answer?.data.channels ?? [];
  return (
    <Section title={t("overview.channels")}>
      {answer === undefined ? (
        <SkeletonRows rows={4} label={t("loading")} />
      ) : channels.length === 0 ? (
        <p className="text-base text-neutral-600">{answer.as_of === null ? t("overview.not_built") : t("overview.channels_empty")}</p>
      ) : (
        <Bars
          orientation="horizontal"
          label={t("overview.channels_label")}
          points={channels.map((row) => ({ key: String(row.channel.id), label: names.channel(row.channel), value: row.share }))}
          format={(share) => f.pct(share)}
          table={{ summary: t("chart.show_figures"), caption: t("overview.channels"), keyHeading: t("col.channel"), valueHeading: t("col.share"), missingText: "—" }}
        />
      )}
    </Section>
  );
}

function TopItems({ answer }: { answer: Answer<Overview> | undefined }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  const names = useNames();
  const page = useAnalyticsPage();
  const rows = answer?.data.top_items ?? [];
  return (
    <Section title={t("overview.top_items")}>
      {answer === undefined ? (
        <SkeletonRows rows={5} label={t("loading")} />
      ) : rows.length === 0 ? (
        <p className="text-base text-neutral-600">{answer.as_of === null ? t("overview.not_built") : t("overview.top_items_empty")}</p>
      ) : (
        <TableFrame>
          <table className="w-full border-collapse text-left text-base">
            <caption className="sr-only">{t("overview.top_items")}</caption>
            <thead>
              <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                <th scope="col" className={TH}>{t("col.product")}</th>
                <th scope="col" className={TH_NUM}>{t("col.units")}</th>
                <th scope="col" className={TH_NUM}>{t("col.revenue")}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.item.id} className="border-b border-neutral-100">
                  <th scope="row" className={`${TD} font-medium`}>
                    <Link href={{ pathname: "/admin/analytics/product", query: page.withRange({ id: String(row.item.id) }) }} className={`inline-flex min-h-12 items-center text-blue-800 underline ${focusRing}`}>
                      {names.item(row.item)}
                    </Link>
                  </th>
                  <td className={TD_NUM}>{f.qty(row.units_sold_qty)}</td>
                  <td className={TD_NUM}>{f.amount(row.revenue)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableFrame>
      )}
    </Section>
  );
}
