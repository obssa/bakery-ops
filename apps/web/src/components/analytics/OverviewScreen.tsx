"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { differenceOf, percentChange, seriesLabel, type AnalyticsRange } from "@/lib/analytics";
import { useOverview, useSales, type Answer, type Overview } from "@/lib/api/analytics";
import { compareDecimal } from "@/lib/decimal";
import { useDateFormat } from "@/lib/useDates";
import { useProductImages } from "@/lib/productImages";
import { TableCard, ToolbarCheck } from "../admin/kit";
import { Bars } from "../charts/Bars";
import { Sparkline } from "../charts/Sparkline";
import { ProductImage } from "../ui";
import {
  AnalyticsHeader,
  AnalyticsNav,
  FreshnessChip,
  FreshnessNotice,
  Kpi,
  NUM,
  pageQuery,
  RangeControl,
  ReadError,
  ROW_LINK,
  Section,
  SkeletonRows,
  useAnalyticsPage,
  useFigures,
  useFreshness,
  useNames,
} from "./shared";

// Admin > Analytics > Overview (step 4.5e; analytics.view): the range's headline figures (revenue, net revenue, orders, the average ticket,
// items sold, refunds, voids, cancelled orders), revenue by order type, the payment methods' shares and the five best sellers, from
// GET /analytics/overview alone, so the first figure paints from that one answer. ?compare=previous adds the change from the same number of
// days before. The revenue tile's sparkline (GET /analytics/sales by day) and the freshness feed start once the overview has answered, so
// nothing competes with it. Refunds are the summaries' figures (zero until steps 4.1b and 4.3d); a range with no built day shows no
// figure at all (R-U: not built is never zero).
//
// Step 6.4d (fresh-counter.md 4.2 and 4.4, the Admin board): the title row with its Amharic twin and the exports, the Analytics sections,
// the range and "Compare" as the toolbar with the freshness chip; the headline figures as the board's KPI row (a change down in red);
// revenue by order type and the payment methods' shares as Today's share bars side by side; the best sellers a table card with each
// product's photo, as Today's "Top sellers".

const TYPES = ["dine_in", "takeaway", "delivery", "advance"] as const;

export function OverviewScreen({ titleAm }: { titleAm?: string }) {
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
      <AnalyticsHeader title={t("overview.title")} intro={t("overview.intro")} titleAm={titleAm} fresh={<FreshnessChip state={freshness} asOf={overview.data?.as_of} />} />
      <div className="flex flex-col gap-6">
        <AnalyticsNav />
        <RangeControl range={range} today={today} onChange={(next) => choose(next)}>
          <ToolbarCheck label={t("overview.compare")} checked={compare} onChange={(event) => choose(range, event.target.checked)} />
        </RangeControl>
        <FreshnessNotice state={freshness} />
        {overview.error ? <ReadError error={overview.error} onRetry={() => void overview.refetch()} /> : <Headline answer={overview.data} compare={compare} range={range} showSpark={answered} />}
        <div className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr))]">
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

  /** The change line of a tile with the comparison: a signed percent and the difference, or why there is none; `bad` when it fell. */
  function change(current: string, previous: string | undefined, kind: "money" | "count" | "qty", worseUp = false): { text: string; bad: boolean } | undefined {
    if (!compare || !before || previous === undefined) return undefined;
    if (!beforeBuilt) return { text: t("overview.change_none"), bad: false };
    const pct = percentChange(current, previous);
    const raw = differenceOf(current, previous, kind === "money" ? 2 : kind === "count" ? 0 : 4);
    const diff = kind === "qty" ? (raw.startsWith("+") ? `+${f.qty(raw.slice(1))}` : f.qty(raw)) : f.amount(raw);
    // a fall in takings is bad; for refunds, voids and cancelled orders a rise is
    const sign = compareDecimal(raw.replace(/^\+/, ""), "0");
    const bad = worseUp ? sign > 0 : sign < 0;
    return { text: pct === null ? t("overview.change_from_zero", { difference: diff }) : t("overview.change", { pct, difference: diff }), bad };
  }

  const value = (text: string) => (built ? text : "—");
  const detailNotBuilt = !loading && !built ? t("overview.not_built") : undefined;

  const tiles: { key: string; label: string; value?: string; detail?: string; change?: { text: string; bad: boolean }; spark?: boolean }[] = [
    { key: "revenue", label: t("kpi.revenue"), value: data && value(f.money(data.revenue)), change: data && built ? change(data.revenue, before?.data.revenue, "money") : undefined, spark: true },
    { key: "net", label: t("kpi.net_revenue"), value: data && value(f.money(data.net_revenue)), change: data && built ? change(data.net_revenue, before?.data.net_revenue, "money") : undefined },
    {
      key: "orders",
      label: t("kpi.orders"),
      value: data && value(f.count(data.orders_count)),
      change: data && built ? change(String(data.orders_count), before ? String(before.data.orders_count) : undefined, "count") : undefined,
    },
    { key: "avg", label: t("kpi.avg_ticket"), value: data && value(f.money(data.avg_ticket)), change: data && built ? change(data.avg_ticket, before?.data.avg_ticket, "money") : undefined },
    { key: "items", label: t("kpi.items"), value: data && value(f.qty(data.items_qty)), change: data && built ? change(data.items_qty, before?.data.items_qty, "qty") : undefined },
    {
      key: "refunds",
      label: t("kpi.refunds"),
      value: data && value(f.money(data.refund_total)),
      detail: data && built ? t("kpi.refunds_count", { count: data.refund_count }) : undefined,
      change: data && built ? change(data.refund_total, before?.data.refund_total, "money", true) : undefined,
    },
    {
      key: "voids",
      label: t("kpi.voids"),
      value: data && value(f.money(data.voided_total)),
      detail: data && built ? t("kpi.voids_count", { count: data.voided_count }) : undefined,
      change: data && built ? change(data.voided_total, before?.data.voided_total, "money", true) : undefined,
    },
    {
      key: "cancelled",
      label: t("kpi.cancelled"),
      value: data && value(f.count(data.cancelled_count)),
      change: data && built ? change(String(data.cancelled_count), before ? String(before.data.cancelled_count) : undefined, "count", true) : undefined,
    },
  ];

  return (
    <section aria-label={t("overview.headline")} className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr))]">
      {tiles.map((tile) => (
        <Kpi key={tile.key} label={tile.label} loading={loading} value={tile.value} detail={tile.detail ?? detailNotBuilt} change={tile.change?.text} bad={tile.change?.bad}>
          {tile.spark && showSpark && built && range.from !== range.to ? <RevenueSpark range={range} /> : null}
        </Kpi>
      ))}
    </section>
  );
}

/** Revenue day by day inside the revenue tile; asked for only after the overview answered. */
function RevenueSpark({ range }: { range: AnalyticsRange }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const f = useFigures();
  const sales = useSales(range, "day");
  if (!sales.data) return <span aria-hidden="true" className="mt-2 block h-9 rounded-lg bg-neutral-100 motion-safe:animate-skeleton" />;
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
        <p className="text-[15px] text-neutral-600">{t("overview.not_built")}</p>
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
        <p className="text-[15px] text-neutral-600">{answer.as_of === null ? t("overview.not_built") : t("overview.channels_empty")}</p>
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
  const images = useProductImages();
  const rows = answer?.data.top_items ?? [];
  return (
    <TableCard caption={t("overview.top_items")} title={t("overview.top_items")} minWidth="min-w-[560px]">
      <thead>
        <tr>
          <th scope="col">{t("col.product")}</th>
          <th scope="col" className={NUM}>
            {t("col.units")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.revenue")}
          </th>
        </tr>
      </thead>
      <tbody>
        {answer === undefined ? (
          <tr>
            <td colSpan={3}>
              <SkeletonRows rows={5} label={t("loading")} />
            </td>
          </tr>
        ) : rows.length === 0 ? (
          <tr>
            <td colSpan={3} className="py-6 text-neutral-600">
              {answer.as_of === null ? t("overview.not_built") : t("overview.top_items_empty")}
            </td>
          </tr>
        ) : (
          rows.map((row, index) => {
            const name = names.item(row.item);
            return (
              <tr key={row.item.id}>
                <th scope="row" className="font-semibold">
                  <span className="flex items-center gap-3">
                    <span aria-hidden="true" className="w-5 shrink-0 text-sm tabular-nums text-neutral-600">
                      {index + 1}
                    </span>
                    <span aria-hidden="true" className="shrink-0">
                      <ProductImage image={images.get(row.item.id) ?? null} name={name} category={names.category(row.item)} size="thumb" className="w-13 rounded-lg" />
                    </span>
                    <Link href={{ pathname: "/admin/analytics/product", query: page.withRange({ id: String(row.item.id) }) }} className={ROW_LINK}>
                      {name}
                    </Link>
                  </span>
                </th>
                <td className={NUM}>{f.qty(row.units_sold_qty)}</td>
                <td className={`${NUM} font-semibold`}>{f.amount(row.revenue)}</td>
              </tr>
            );
          })
        )}
      </tbody>
    </TableCard>
  );
}
