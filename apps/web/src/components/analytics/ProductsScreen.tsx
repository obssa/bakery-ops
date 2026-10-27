"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { ITEM_SORTS, marginOf, type ItemSort, type ItemsOptions } from "@/lib/analytics";
import { useItemFigures, type Items } from "@/lib/api/analytics";
import { useCategories } from "@/lib/api/hooks";
import { Badge, Field, PageHeader, Select } from "../ui";
import { focusRing } from "../styles";
import { ExportBar, FreshnessBar, pageQuery, RangeControl, ReadError, Section, SkeletonRows, TableFrame, TD, TD_NUM, TH, TH_NUM, useAnalyticsPage, useFigures, useFreshness, useNames } from "./shared";

// Admin > Analytics > Products (step 4.5e; analytics.view): products and ingredients over the range (GET /analytics/items, the 50 largest
// by revenue, units, margin or waste; a kind and a category narrow it, by the category an item had on each day). Each name opens the
// product page. A cost of sales that was incomplete on a day of the range (a display-case product made in the house has none yet, step
// 4.4a's known gap; a modifier's recipe is not costed) is said in words: no margin is shown as a fact. The options live in the address.

const KINDS = ["", "product", "ingredient"] as const;

export function itemsOptionsOf(params: URLSearchParams): ItemsOptions {
  const kind = params.get("kind");
  const sort = params.get("sort") ?? "";
  const category = params.get("category_id") ?? "";
  return {
    kind: kind === "product" || kind === "ingredient" ? kind : "",
    categoryId: /^[1-9]\d{0,9}$/.test(category) ? category : "",
    sort: (ITEM_SORTS as readonly string[]).includes(sort) ? (sort as ItemSort) : "revenue",
  };
}

export function ProductsScreen() {
  const t = useTranslations("analytics");
  const page = useAnalyticsPage();
  const { range, params, today } = page;
  const options = itemsOptionsOf(params);
  const freshness = useFreshness(range);
  const items = useItemFigures(range, options);
  const query = (next: Partial<{ range: typeof range } & ItemsOptions>) => {
    const merged = { ...options, ...next };
    return pageQuery(next.range ?? range, { kind: merged.kind, category_id: merged.categoryId, sort: merged.sort === "revenue" ? "" : merged.sort });
  };

  return (
    <>
      <PageHeader title={t("products.title")} intro={t("products.intro")} />
      <div className="space-y-6">
        <RangeControl range={range} today={today} onChange={(next) => page.go(query({ range: next }))} />
        <FreshnessBar state={freshness} asOf={items.data?.as_of} />
        <ExportBar report="items" range={range} />
        <Options options={options} onChange={(next) => page.go(query(next))} />
        <Section title={t("products.table")}>
          {items.error ? (
            <ReadError error={items.error} onRetry={() => void items.refetch()} />
          ) : !items.data ? (
            <SkeletonRows rows={8} label={t("loading")} />
          ) : items.data.data.items.length === 0 ? (
            <p className="text-base text-neutral-600">{items.data.as_of === null ? t("overview.not_built") : t("products.empty")}</p>
          ) : (
            <>
              <ItemsTable rows={items.data.data.items} />
              {items.data.data.items.some((row) => !row.cogs_complete) ? <CostNote /> : null}
            </>
          )}
        </Section>
      </div>
    </>
  );
}

function Options({ options, onChange }: { options: ItemsOptions; onChange: (next: Partial<ItemsOptions>) => void }) {
  const t = useTranslations("analytics");
  const names = useNames();
  const categories = useCategories();
  const listed = (categories.data ?? []).filter((category) => category.deleted_at === null && (options.kind === "" || category.kind === options.kind));
  return (
    <form role="search" aria-label={t("products.options")} onSubmit={(event) => event.preventDefault()} className="grid gap-4 rounded-lg border border-neutral-200 bg-white p-4 sm:grid-cols-3">
      <Field label={t("products.kind")}>
        {(props) => (
          <Select {...props} value={options.kind} onChange={(event) => onChange({ kind: event.target.value as ItemsOptions["kind"], categoryId: "" })}>
            {KINDS.map((kind) => (
              <option key={kind || "all"} value={kind}>
                {t(`products.kind_${kind || "all"}`)}
              </option>
            ))}
          </Select>
        )}
      </Field>
      <Field label={t("products.category")}>
        {(props) => (
          <Select {...props} value={options.categoryId} onChange={(event) => onChange({ categoryId: event.target.value })}>
            <option value="">{t("products.category_all")}</option>
            {listed.map((category) => (
              <option key={category.id} value={String(category.id)}>
                {names.item({ id: category.id, name_en: category.name_en, name_am: category.name_am })}
              </option>
            ))}
          </Select>
        )}
      </Field>
      <Field label={t("products.sort")}>
        {(props) => (
          <Select {...props} value={options.sort} onChange={(event) => onChange({ sort: event.target.value as ItemSort })}>
            {ITEM_SORTS.map((sort) => (
              <option key={sort} value={sort}>
                {t(`products.sort_${sort}`)}
              </option>
            ))}
          </Select>
        )}
      </Field>
    </form>
  );
}

/** Why a margin is not shown: the cost of sales of those days is incomplete (said, never estimated). */
export function CostNote({ days }: { days?: number }) {
  const t = useTranslations("analytics");
  return (
    <p role="note" className="mt-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-base text-amber-900">
      {days === undefined ? t("cost.note") : t("cost.note_days", { count: days })}
    </p>
  );
}

function ItemsTable({ rows }: { rows: Items["items"] }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  const names = useNames();
  const page = useAnalyticsPage();
  return (
    <TableFrame>
      <table className="w-full border-collapse text-left text-base">
        <caption className="sr-only">{t("products.table")}</caption>
        <thead>
          <tr className="border-b border-neutral-200 text-sm text-neutral-600">
            <th scope="col" className={TH}>{t("col.item")}</th>
            <th scope="col" className={TH_NUM}>{t("col.units")}</th>
            <th scope="col" className={TH_NUM}>{t("col.revenue")}</th>
            <th scope="col" className={TH_NUM}>{t("col.cost")}</th>
            <th scope="col" className={TH_NUM}>{t("col.margin")}</th>
            <th scope="col" className={TH_NUM}>{t("col.margin_pct")}</th>
            <th scope="col" className={TH_NUM}>{t("col.produced")}</th>
            <th scope="col" className={TH_NUM}>{t("col.waste")}</th>
            <th scope="col" className={TH_NUM}>{t("col.stockouts")}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            const margin = marginOf(row);
            const category = names.category(row.item);
            return (
              <tr key={row.item.id} className="border-b border-neutral-100">
                <th scope="row" className={`${TD} font-medium`}>
                  <span className="flex flex-wrap items-center gap-2">
                    <Link href={{ pathname: "/admin/analytics/product", query: page.withRange({ id: String(row.item.id) }) }} className={`inline-flex min-h-12 items-center text-blue-800 underline ${focusRing}`}>
                      {names.item(row.item)}
                    </Link>
                    {row.item.is_new ? <Badge tone="blue">{t("products.new")}</Badge> : null}
                  </span>
                  {category ? <span className="block text-sm font-normal text-neutral-600">{category}</span> : null}
                </th>
                <td className={TD_NUM}>{f.qty(row.units_sold_qty)}</td>
                <td className={TD_NUM}>{f.amount(row.revenue)}</td>
                <td className={TD_NUM}>{margin.complete ? f.amount(row.cogs_total) : <Badge tone="amber">{t("cost.incomplete")}</Badge>}</td>
                <td className={TD_NUM}>{margin.complete ? f.amount(margin.margin) : "—"}</td>
                <td className={TD_NUM}>{margin.complete ? f.pct(margin.pct) : "—"}</td>
                <td className={TD_NUM}>{f.qty(row.produced_qty)}</td>
                <td className={TD_NUM}>{f.qty(row.waste_qty)}</td>
                <td className={TD_NUM}>{f.count(row.stockout_count)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </TableFrame>
  );
}
