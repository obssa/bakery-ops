"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { marginOf, seriesLabel, spanLabel } from "@/lib/analytics";
import { ApiError } from "@/lib/api/client";
import { useItemPage, type ItemPage, type Answer } from "@/lib/api/analytics";
import { useDateFormat } from "@/lib/useDates";
import { Bars } from "../charts/Bars";
import { Line } from "../charts/Line";
import { Alert, Badge, PageHeader } from "../ui";
import { focusRing } from "../styles";
import { CostNote } from "./ProductsScreen";
import { FreshnessBar, Kpi, pageQuery, RangeControl, ReadError, Section, SkeletonRows, TableFrame, TD, TD_NUM, TH, TH_NUM, useAnalyticsPage, useDayLabel, useFigures, useFreshness, useNames } from "./shared";

// Admin > Analytics > a product (step 4.5e; analytics.view): the record page of one item, /admin/analytics/product?id= (a query, not a
// path segment: the static export has no dynamic routes). GET /analytics/items/{item}: its totals over the range, a point a day (a day not
// built is a gap), and since launch: the local day of its first sale in all the built history and its units week by week from that day
// (seven-day weeks, at most 12, cut at today), whatever the range. A new product is flagged. A cost of sales that was incomplete on a day
// of the range (a display-case product made in the house, 4.4a) is said in words: "cost incomplete", never a margin of 100 % or 0 %.

export function ProductScreen() {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  const { range, params, today } = page;
  const raw = params.get("id") ?? "";
  const id = /^[1-9]\d{0,9}$/.test(raw) ? Number(raw) : null;
  const freshness = useFreshness(range);
  const answer = useItemPage(range, id);

  if (id === null) {
    return (
      <>
        <PageHeader title={t("product.title")} />
        <Alert>{t("product.no_id")}</Alert>
        <BackToProducts />
      </>
    );
  }

  const notFound = answer.error instanceof ApiError && answer.error.status === 404;
  return (
    <>
      <Header answer={answer.data} loading={!answer.data && !answer.error} />
      <div className="space-y-6">
        <RangeControl range={range} today={today} onChange={(next) => page.go(pageQuery(next, { id: String(id) }))} />
        <FreshnessBar state={freshness} asOf={answer.data?.as_of} />
        {notFound ? (
          <>
            <Alert>{t("product.not_found")}</Alert>
            <BackToProducts />
          </>
        ) : answer.error ? (
          <ReadError error={answer.error} onRetry={() => void answer.refetch()} />
        ) : (
          <Body answer={answer.data} />
        )}
      </div>
    </>
  );
}

function BackToProducts() {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  return (
    <p className="mt-4">
      <Link href={{ pathname: "/admin/analytics/products", query: page.withRange() }} className={`inline-flex min-h-12 items-center text-blue-800 underline ${focusRing}`}>
        {t("product.back")}
      </Link>
    </p>
  );
}

function Header({ answer, loading }: { answer: Answer<ItemPage> | undefined; loading: boolean }) {
  const t = useTranslations("analytics");
  const names = useNames();
  const item = answer?.data.item;
  if (loading || !item) return <PageHeader title={loading ? t("product.loading") : t("product.title")} />;
  const category = names.category(item);
  return (
    <header className="mb-6 space-y-2">
      <h1 className="text-2xl font-semibold tracking-tight text-neutral-900">{names.item(item)}</h1>
      <div className="flex flex-wrap items-center gap-2 text-base text-neutral-600">
        {item.is_new ? <Badge tone="blue">{t("products.new")}</Badge> : null}
        {item.kind ? <Badge>{t(`product.kind_${item.kind}`)}</Badge> : null}
        {item.is_active === false ? <Badge tone="amber">{t("product.inactive")}</Badge> : null}
        {category ? <span>{t("product.category", { category })}</span> : null}
        {item.unit ? <span>{t("product.unit", { unit: item.unit })}</span> : null}
      </div>
    </header>
  );
}

function Body({ answer }: { answer: Answer<ItemPage> | undefined }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const f = useFigures();
  const { range } = useAnalyticsPage();
  const loading = answer === undefined;
  const totals = answer?.data.totals;
  const built = answer ? answer.as_of !== null : false;
  const margin = totals ? marginOf(totals) : null;
  const value = (text: string) => (built ? text : "—");

  return (
    <>
      <section aria-label={t("product.totals")} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi label={t("kpi.units_sold")} loading={loading} value={totals && value(f.qty(totals.units_sold_qty))} />
        <Kpi label={t("kpi.revenue")} loading={loading} value={totals && value(f.money(totals.revenue))} />
        <Kpi label={t("kpi.cost")} loading={loading} value={totals && (margin?.complete ? value(f.money(totals.cogs_total)) : t("cost.incomplete"))} />
        <Kpi
          label={t("kpi.margin")}
          loading={loading}
          value={totals && (margin?.complete ? value(f.money(margin.margin)) : t("cost.incomplete"))}
          detail={margin?.complete && built ? t("product.margin_pct", { pct: f.pct(margin.pct) }) : undefined}
        />
        <Kpi label={t("kpi.produced")} loading={loading} value={totals && value(f.qty(totals.produced_qty))} />
        <Kpi label={t("kpi.waste")} loading={loading} value={totals && value(f.qty(totals.waste_qty))} />
        <Kpi label={t("kpi.consumed")} loading={loading} value={totals && value(f.qty(totals.consumed_qty))} />
        <Kpi label={t("kpi.stockouts")} loading={loading} value={totals && value(f.count(totals.stockout_count))} />
      </section>
      {totals && !totals.cogs_complete ? <CostNote days={totals.cogs_incomplete_days} /> : null}
      <Launch answer={answer} />
      <Section title={t("product.by_day")}>
        {!answer ? (
          <SkeletonRows rows={6} label={t("loading")} />
        ) : (
          <div className="space-y-6">
            <Line label={t("product.chart_label", { range: spanLabel(range, dates) })} points={answer.data.series.map((day) => ({ key: day.day, label: seriesLabel(day.day, "day", dates), value: day.units_sold_qty }))} format={f.qty} />
            <Days answer={answer} />
          </div>
        )}
      </Section>
    </>
  );
}

function Launch({ answer }: { answer: Answer<ItemPage> | undefined }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const f = useFigures();
  const day = useDayLabel();
  const launch = answer?.data.since_launch;
  return (
    <Section title={t("product.launch")}>
      {!answer ? (
        <SkeletonRows rows={4} label={t("loading")} />
      ) : !launch || launch.first_sale_day === null ? (
        <p className="text-base text-neutral-600">{t("product.never_sold")}</p>
      ) : (
        <div className="space-y-4">
          <p className="text-base text-neutral-700">{t("product.first_sale", { date: day(launch.first_sale_day, true) })}</p>
          <Bars label={t("product.launch_label")} points={launch.weekly_units.map((week) => ({ key: String(week.week), label: t("product.week", { n: week.week }), value: week.units_sold_qty }))} format={f.qty} height={120} />
          <TableFrame>
            <table className="w-full border-collapse text-left text-base">
              <caption className="sr-only">{t("product.launch_table")}</caption>
              <thead>
                <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                  <th scope="col" className={TH}>{t("col.week")}</th>
                  <th scope="col" className={TH}>{t("col.days")}</th>
                  <th scope="col" className={TH_NUM}>{t("col.units")}</th>
                  <th scope="col" className={TH_NUM}>{t("col.revenue")}</th>
                </tr>
              </thead>
              <tbody>
                {launch.weekly_units.map((week) => (
                  <tr key={week.week} className="border-b border-neutral-100">
                    <th scope="row" className={`${TD} font-medium`}>{t("product.week", { n: week.week })}</th>
                    <td className={TD}>{spanLabel(week, dates)}</td>
                    <td className={TD_NUM}>{week.built_days === 0 ? t("not_built") : f.qty(week.units_sold_qty)}</td>
                    <td className={TD_NUM}>{week.built_days === 0 ? t("not_built") : f.amount(week.revenue)}</td>
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

function Days({ answer }: { answer: Answer<ItemPage> }) {
  const t = useTranslations("analytics");
  const dates = useDateFormat();
  const f = useFigures();
  return (
    <TableFrame>
      <table className="w-full border-collapse text-left text-base">
        <caption className="sr-only">{t("product.by_day")}</caption>
        <thead>
          <tr className="border-b border-neutral-200 text-sm text-neutral-600">
            <th scope="col" className={TH}>{t("col.day")}</th>
            <th scope="col" className={TH_NUM}>{t("col.units")}</th>
            <th scope="col" className={TH_NUM}>{t("col.revenue")}</th>
            <th scope="col" className={TH_NUM}>{t("col.margin")}</th>
            <th scope="col" className={TH_NUM}>{t("col.produced")}</th>
            <th scope="col" className={TH_NUM}>{t("col.waste")}</th>
            <th scope="col" className={TH_NUM}>{t("col.stockouts")}</th>
            <th scope="col" className={TH_NUM}>{t("col.on_hand")}</th>
          </tr>
        </thead>
        <tbody>
          {[...answer.data.series].reverse().map((day) => (
            <tr key={day.day} className="border-b border-neutral-100">
              <th scope="row" className={`${TD} font-medium`}>{seriesLabel(day.day, "day", dates)}</th>
              {!day.built ? (
                <td colSpan={7} className={`${TD} text-right text-neutral-500`}>{t("not_built")}</td>
              ) : (
                <>
                  <td className={TD_NUM}>{f.qty(day.units_sold_qty)}</td>
                  <td className={TD_NUM}>{f.amount(day.revenue)}</td>
                  <td className={TD_NUM}>{day.cogs_complete ? f.amount(day.margin_total) : <Badge tone="amber">{t("cost.incomplete")}</Badge>}</td>
                  <td className={TD_NUM}>{f.qty(day.produced_qty)}</td>
                  <td className={TD_NUM}>{f.qty(day.waste_qty)}</td>
                  <td className={TD_NUM}>{f.count(day.stockout_count)}</td>
                  <td className={TD_NUM}>{f.qty(day.closing_on_hand_qty)}</td>
                </>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </TableFrame>
  );
}
