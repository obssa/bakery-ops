"use client";

import { ArrowLeft } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import type { ReactNode } from "react";
import { Link } from "@/i18n/navigation";
import { marginOf, seriesLabel, spanLabel } from "@/lib/analytics";
import { ApiError } from "@/lib/api/client";
import { useItemPage, type ItemPage, type Answer } from "@/lib/api/analytics";
import type { Locale } from "@/lib/api/types";
import { useProductImages } from "@/lib/productImages";
import { useDateFormat } from "@/lib/useDates";
import { InnerTable, TableCard } from "../admin/kit";
import { Bars } from "../charts/Bars";
import { Line } from "../charts/Line";
import { Alert, Chip, PageHeader, ProductImage } from "../ui";
import { buttonClass } from "../styles";
import { CostNote } from "./ProductsScreen";
import {
  AnalyticsNav,
  FreshnessChip,
  FreshnessNotice,
  Kpi,
  NOT_BUILT,
  NUM,
  pageQuery,
  RangeControl,
  ReadError,
  Section,
  SkeletonRows,
  useAnalyticsPage,
  useDayLabel,
  useFigures,
  useFreshness,
  useNames,
} from "./shared";

// Admin > Analytics > a product (step 4.5e; analytics.view): the record page of one item, /admin/analytics/product?id= (a query, not a
// path segment: the static export has no dynamic routes). GET /analytics/items/{item}: its totals over the range, a point a day (a day not
// built is a gap), and since launch: the local day of its first sale in all the built history and its units week by week from that day
// (seven-day weeks, at most 12, cut at today), whatever the range. A new product is flagged. A cost of sales that was incomplete on a day
// of the range (a display-case product made in the house, 4.4a) is said in words: "cost incomplete", never a margin of 100 % or 0 %.
//
// Step 6.4d (fresh-counter.md 4.3 and 4.4): the title row is the product, its photo beside the name, the name in the other language beside
// it on the English page (as the catalog's rows), its chips (New brand teal) and "Back to Products" as the action; the range as the
// toolbar; the totals as the board's KPI row; the weeks since launch Today's bars (the latest week brand-600) beside their table card; the
// days a brand-600 line over their table card.

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
        <PageHeader title={t("product.title")} actions={<BackToProducts />} />
        <div className="flex flex-col gap-6">
          <AnalyticsNav />
          <Alert>{t("product.no_id")}</Alert>
        </div>
      </>
    );
  }

  const notFound = answer.error instanceof ApiError && answer.error.status === 404;
  return (
    <>
      <Header answer={answer.data} loading={!answer.data && !answer.error} fresh={<FreshnessChip state={freshness} asOf={answer.data?.as_of} />} />
      <div className="flex flex-col gap-6">
        <AnalyticsNav />
        <RangeControl range={range} today={today} onChange={(next) => page.go(pageQuery(next, { id: String(id) }))} />
        <FreshnessNotice state={freshness} />
        {notFound ? (
          <Alert>{t("product.not_found")}</Alert>
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
    <Link href={{ pathname: "/admin/analytics/products", query: page.withRange() }} className={buttonClass({ variant: "ghost", size: "md" })}>
      <ArrowLeft className="size-[18px]" strokeWidth={2} aria-hidden="true" />
      {t("product.back")}
    </Link>
  );
}

function Header({ answer, loading, fresh }: { answer: Answer<ItemPage> | undefined; loading: boolean; fresh?: ReactNode }) {
  const t = useTranslations("analytics");
  const locale = useLocale() as Locale;
  const names = useNames();
  const images = useProductImages();
  const item = answer?.data.item;
  if (loading || !item) return <PageHeader title={loading ? t("product.loading") : t("product.title")} actions={<BackToProducts />} />;
  const name = names.item(item);
  const category = names.category(item);
  // the name in the other language, as the catalog's rows show it (in its own lang)
  const other = locale === "am" ? item.name_en : item.name_am;
  const facts = [category ? t("product.category", { category }) : null, item.unit ? t("product.unit", { unit: item.unit }) : null].filter(Boolean).join(" · ");
  return (
    <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div className="flex min-w-0 flex-[1_1_24rem] items-center gap-4">
        {item.kind !== "ingredient" ? (
          <span aria-hidden="true" className="shrink-0">
            <ProductImage image={images.get(item.id) ?? null} name={name} category={category} size="thumb" className="w-20 rounded-xl" />
          </span>
        ) : null}
        <div className="min-w-0 space-y-1.5">
          <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 className="text-3xl font-bold tracking-tight text-neutral-900">{name}</h1>
            {other && other !== name ? (
              <span lang={locale === "am" ? "en" : "am"} className="text-2xl font-semibold text-neutral-600">
                <span aria-hidden="true">· </span>
                {other}
              </span>
            ) : null}
          </div>
          <div className="flex flex-wrap items-center gap-2 text-[15px] text-neutral-600">
            {item.is_new ? <Chip tone="brand">{t("products.new")}</Chip> : null}
            {item.kind ? <Chip>{t(`product.kind_${item.kind}`)}</Chip> : null}
            {item.is_active === false ? <Chip tone="amber">{t("product.inactive")}</Chip> : null}
            {facts ? <span>{facts}</span> : null}
          </div>
        </div>
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2.5">
        {fresh}
        <BackToProducts />
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
      <section aria-label={t("product.totals")} className="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(min(100%,220px),1fr))]">
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
      <Section title={t("product.by_day")} actions={<span className="text-[13px] text-neutral-600">{t("col.units")}</span>}>
        {!answer ? (
          <SkeletonRows rows={6} label={t("loading")} />
        ) : (
          <Line label={t("product.chart_label", { range: spanLabel(range, dates) })} points={answer.data.series.map((day) => ({ key: day.day, label: seriesLabel(day.day, "day", dates), value: day.units_sold_qty }))} format={f.qty} />
        )}
      </Section>
      {answer ? <Days answer={answer} /> : null}
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
    <Section title={t("product.launch")} actions={launch && launch.first_sale_day !== null ? <span className="text-[13px] text-neutral-600">{t("product.first_sale", { date: day(launch.first_sale_day, true) })}</span> : undefined}>
      {!answer ? (
        <SkeletonRows rows={4} label={t("loading")} />
      ) : !launch || launch.first_sale_day === null ? (
        <p className="text-[15px] text-neutral-600">{t("product.never_sold")}</p>
      ) : (
        <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
          <Bars label={t("product.launch_label")} points={launch.weekly_units.map((week) => ({ key: String(week.week), label: t("product.week", { n: week.week }), value: week.units_sold_qty }))} format={f.qty} height={140} ticks="all" tick={(point) => point.key} />
          <InnerTable caption={t("product.launch_table")} minWidth="min-w-[420px]">
              <thead>
                <tr>
                  <th scope="col">{t("col.week")}</th>
                  <th scope="col">{t("col.days")}</th>
                  <th scope="col" className={NUM}>
                    {t("col.units")}
                  </th>
                  <th scope="col" className={NUM}>
                    {t("col.revenue")}
                  </th>
                </tr>
              </thead>
              <tbody>
                {launch.weekly_units.map((week) => (
                  <tr key={week.week}>
                    <th scope="row" className="font-semibold">
                      {t("product.week", { n: week.week })}
                    </th>
                    <td className="tabular-nums text-neutral-700">{spanLabel(week, dates)}</td>
                    <td className={NUM}>{week.built_days === 0 ? t("not_built") : f.qty(week.units_sold_qty)}</td>
                    <td className={NUM}>{week.built_days === 0 ? t("not_built") : f.amount(week.revenue)}</td>
                  </tr>
                ))}
              </tbody>
          </InnerTable>
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
    <TableCard caption={t("product.by_day")} minWidth="min-w-[860px]">
      <thead>
        <tr>
          <th scope="col">{t("col.day")}</th>
          <th scope="col" className={NUM}>
            {t("col.units")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.revenue")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.margin")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.produced")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.waste")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.stockouts")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.on_hand")}
          </th>
        </tr>
      </thead>
      <tbody>
        {[...answer.data.series].reverse().map((day) => (
          <tr key={day.day}>
            <th scope="row" className="whitespace-nowrap font-semibold">
              {seriesLabel(day.day, "day", dates)}
            </th>
            {!day.built ? (
              <td colSpan={7} className={NOT_BUILT}>
                {t("not_built")}
              </td>
            ) : (
              <>
                <td className={NUM}>{f.qty(day.units_sold_qty)}</td>
                <td className={`${NUM} font-semibold`}>{f.amount(day.revenue)}</td>
                <td className={NUM}>{day.cogs_complete ? f.amount(day.margin_total) : <Chip tone="amber">{t("cost.incomplete")}</Chip>}</td>
                <td className={NUM}>{f.qty(day.produced_qty)}</td>
                <td className={NUM}>{f.qty(day.waste_qty)}</td>
                <td className={NUM}>{f.count(day.stockout_count)}</td>
                <td className={NUM}>{f.qty(day.closing_on_hand_qty)}</td>
              </>
            )}
          </tr>
        ))}
      </tbody>
    </TableCard>
  );
}
