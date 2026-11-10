"use client";

import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { ITEM_SORTS, marginOf, type ItemSort, type ItemsOptions } from "@/lib/analytics";
import { useItemFigures, type ItemName, type Items } from "@/lib/api/analytics";
import { useCategories } from "@/lib/api/hooks";
import { useProductImages } from "@/lib/productImages";
import { EmptyRow, Notice, TableCard, Toolbar, ToolbarSelect } from "../admin/kit";
import { Chip, ProductImage } from "../ui";
import {
  AnalyticsHeader,
  AnalyticsNav,
  FreshnessChip,
  FreshnessNotice,
  NUM,
  pageQuery,
  RangeControl,
  ReadError,
  ROW_LINK,
  SkeletonRows,
  useAnalyticsPage,
  useFigures,
  useFreshness,
  useNames,
} from "./shared";

// Admin > Analytics > Products (step 4.5e; analytics.view): products and ingredients over the range (GET /analytics/items, the 50 largest
// by revenue, units, margin or waste; a kind and a category narrow it, by the category an item had on each day). Each name opens the
// product page. A cost of sales that was incomplete on a day of the range (a display-case product made in the house has none yet, step
// 4.4a's known gap; a modifier's recipe is not costed) is said in words: no margin is shown as a fact. The options live in the address.
//
// Step 6.4d (fresh-counter.md 4.3 and 4.4, the CatalogList board): the title row with its twin and the exports; the range as the toolbar
// and the kind, category and sort as a second row of 44 px selects; the items a table card, each product with its 52 x 39 photo (an
// ingredient has none) and its category under its name, New in brand red, an incomplete cost an amber chip, the figures right-aligned.

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

export function ProductsScreen({ titleAm }: { titleAm?: string }) {
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
      <AnalyticsHeader title={t("products.title")} intro={t("products.intro")} titleAm={titleAm} report="items" range={range} fresh={<FreshnessChip state={freshness} asOf={items.data?.as_of} />} />
      <div className="flex flex-col gap-6">
        <AnalyticsNav />
        <div className="flex flex-col gap-3">
          <RangeControl range={range} today={today} onChange={(next) => page.go(query({ range: next }))} />
          <Options options={options} onChange={(next) => page.go(query(next))} />
        </div>
        <FreshnessNotice state={freshness} />
        {items.error ? (
          <ReadError error={items.error} onRetry={() => void items.refetch()} />
        ) : (
          <>
            <ItemsTable rows={items.data?.data.items} empty={items.data?.as_of === null ? t("overview.not_built") : t("products.empty")} />
            {items.data?.data.items.some((row) => !row.cogs_complete) ? <CostNote /> : null}
          </>
        )}
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
    <form role="search" aria-label={t("products.options")} onSubmit={(event) => event.preventDefault()}>
      <Toolbar>
        <ToolbarSelect label={t("products.kind")} value={options.kind} onChange={(event) => onChange({ kind: event.target.value as ItemsOptions["kind"], categoryId: "" })}>
          {KINDS.map((kind) => (
            <option key={kind || "all"} value={kind}>
              {t(`products.kind_${kind || "all"}`)}
            </option>
          ))}
        </ToolbarSelect>
        <ToolbarSelect label={t("products.category")} value={options.categoryId} onChange={(event) => onChange({ categoryId: event.target.value })}>
          <option value="">{t("products.category_all")}</option>
          {listed.map((category) => (
            <option key={category.id} value={String(category.id)}>
              {names.item({ id: category.id, name_en: category.name_en, name_am: category.name_am })}
            </option>
          ))}
        </ToolbarSelect>
        <ToolbarSelect label={t("products.sort")} showLabel value={options.sort} onChange={(event) => onChange({ sort: event.target.value as ItemSort })}>
          {ITEM_SORTS.map((sort) => (
            <option key={sort} value={sort}>
              {t(`products.sort_${sort}`)}
            </option>
          ))}
        </ToolbarSelect>
      </Toolbar>
    </form>
  );
}

/** Why a margin is not shown: the cost of sales of those days is incomplete (said, never estimated). */
export function CostNote({ days }: { days?: number }) {
  const t = useTranslations("analytics");
  return (
    <div role="note">
      <Notice tone="amber">{days === undefined ? t("cost.note") : t("cost.note_days", { count: days })}</Notice>
    </div>
  );
}

/** A product's photo as the catalog shows it (52 x 39, its category's placeholder without one); an ingredient has none. */
export function ItemThumb({ item, name }: { item: ItemName; name: string }) {
  const images = useProductImages();
  const names = useNames();
  if (item.kind === "ingredient") return null;
  return (
    <span aria-hidden="true" className="shrink-0">
      <ProductImage image={images.get(item.id) ?? null} name={name} category={names.category(item)} size="thumb" className="w-13 rounded-lg" />
    </span>
  );
}

function ItemsTable({ rows, empty }: { rows: Items["items"] | undefined; empty: string }) {
  const t = useTranslations("analytics");
  const f = useFigures();
  const names = useNames();
  const page = useAnalyticsPage();
  return (
    <TableCard caption={t("products.table")} title={t("products.table")} minWidth="min-w-[1000px]">
      <thead>
        <tr>
          <th scope="col">{t("col.item")}</th>
          <th scope="col" className={NUM}>
            {t("col.units")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.revenue")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.cost")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.margin")}
          </th>
          <th scope="col" className={NUM}>
            {t("col.margin_pct")}
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
        </tr>
      </thead>
      <tbody>
        {rows === undefined ? (
          <tr>
            <td colSpan={9}>
              <SkeletonRows rows={8} label={t("loading")} />
            </td>
          </tr>
        ) : rows.length === 0 ? (
          <EmptyRow span={9}>{empty}</EmptyRow>
        ) : (
          rows.map((row) => {
            const margin = marginOf(row);
            const category = names.category(row.item);
            const name = names.item(row.item);
            return (
              <tr key={row.item.id}>
                <th scope="row">
                  <span className="flex items-center gap-3">
                    <ItemThumb item={row.item} name={name} />
                    <span className="min-w-0">
                      <span className="flex flex-wrap items-center gap-2">
                        <Link href={{ pathname: "/admin/analytics/product", query: page.withRange({ id: String(row.item.id) }) }} className={ROW_LINK}>
                          {name}
                        </Link>
                        {row.item.is_new ? <Chip tone="brand">{t("products.new")}</Chip> : null}
                      </span>
                      {category ? <span className="block text-[13px] font-normal text-neutral-600">{category}</span> : null}
                    </span>
                  </span>
                </th>
                <td className={NUM}>{f.qty(row.units_sold_qty)}</td>
                <td className={`${NUM} font-semibold`}>{f.amount(row.revenue)}</td>
                <td className={NUM}>{margin.complete ? f.amount(row.cogs_total) : <Chip tone="amber">{t("cost.incomplete")}</Chip>}</td>
                <td className={NUM}>{margin.complete ? f.amount(margin.margin) : "—"}</td>
                <td className={NUM}>{margin.complete ? f.pct(margin.pct) : "—"}</td>
                <td className={NUM}>{f.qty(row.produced_qty)}</td>
                <td className={NUM}>{f.qty(row.waste_qty)}</td>
                <td className={NUM}>{f.count(row.stockout_count)}</td>
              </tr>
            );
          })
        )}
      </tbody>
    </TableCard>
  );
}
