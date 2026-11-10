"use client";

import { ChevronRight, Tags, LayoutGrid, List, Plus, Search, X } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useId, useMemo, useState, type FormEvent } from "react";
import { ICON_STROKE } from "@/components/icons";
import { ProductCard, type ProductCardItem } from "@/components/pad/ProductCard";
import { focusRing } from "@/components/styles";
import { Alert, Button, Chip, Drawer, EmptyState, Field, IconButton, PageHeader, ProductImage, Select, Skeleton, Status, TextInput } from "@/components/ui";
import { ItemPage } from "@/components/catalog/ItemPage";
import { Link, usePathname, useRouter } from "@/i18n/navigation";
import { useItemPrices, useStations } from "@/lib/api/catalog";
import { useCategories, useCreateCategory, useItems } from "@/lib/api/hooks";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import type { Category, Item, ItemKind, Locale, NewCategory } from "@/lib/api/types";
import { groupDigits } from "@/lib/analytics";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { useSeen } from "@/lib/useSeen";

// Admin > Catalog (Phase 0 step 0.7; step 6.4a, fresh-counter.md 4.3 and the CatalogList board): both inventories, one at a time.
//   - The title row: "Catalog" with its Amharic twin, a line that counts the products and those still without a photo, and "Add product"
//     ("Add ingredient" in the ingredients) for the admin (catalog.manage), beside "Categories".
//   - The toolbar: Products or Ingredients (a two-segment navigation of links, ?kind=, products by default), the search (either name, in
//     the browser), the category filter and, for products, the table or pad-card view (?view=grid).
//   - The table: a product's 64 × 48 photo (the thumb rendition, or its category's placeholder) and "Add photo" when it has none (it opens
//     the product's page at its Photo card, step 6.4b), the name in the page's language over the other one, the category, where it is sold
//     from (the stations its lines go to, or the display case for one sold from stock) over how it is made, the current price, the status
//     (New, On the menu, Hidden) and the chevron that opens the product's page. An ingredient: its name, category, unit, SKU and status.
//   - The grid: the products on the menu as the waiter sees them, 6.3a's ProductCard in preview mode, each one a link to its page.
//   - "Add product" opens the new item page (admin/catalog/new, phase-6/new-item-page; it was a drawer of step 6.4a), and "Categories"
//     opens the categories of both kinds in a drawer, as they were under the drawer's form.
// The price is the product's current one (GET /items/{item}/prices, the product page's own read), asked only for the rows in view
// (lib/useSeen.ts): the list has no price, and a branch may hold thousands of items. Names are always entered in both languages (rule 9).
// One product's page (recipe versions, cost and margin, refund settings, photo) is the same route with ?item=<id>: a static export has no
// dynamic segment for an id the build does not know.

type View = "table" | "grid";

const KINDS: readonly ItemKind[] = ["ingredient", "product"];
const SECTION = "overflow-x-auto rounded-[18px] border border-neutral-200 bg-white";
const TH = "px-3 py-3.5 font-semibold";
const TD = "px-3 py-2.5";

function named(locale: Locale, row: { name_en: string; name_am: string }): { primary: string; secondary: string } {
  return locale === "am" ? { primary: row.name_am, secondary: row.name_en } : { primary: row.name_en, secondary: row.name_am };
}

function kindFrom(raw: string | null): ItemKind {
  return raw === "ingredient" ? "ingredient" : "product";
}

/** The case and spacing a search ignores, in either script. */
function fold(text: string): string {
  return text.toLocaleLowerCase().replace(/\s+/g, " ").trim();
}

/** A price as the board writes it: the currency, then the amount with its digits grouped ("ETB 1,250.00"). */
function priceText(currency: string, price: string): string {
  return `${currency} ${groupDigits(price)}`;
}

/** `titleAm`: the Amharic title, set beside the English one on the English page (the page server-renders it from am.json). */
export function CatalogScreen({ titleAm }: { titleAm?: string }) {
  const params = useSearchParams();
  // one product's page (recipe versions, cost and margin) is the same route with ?item=<id>
  const itemId = Number(params.get("item"));
  if (Number.isInteger(itemId) && itemId > 0) return <ItemPage id={itemId} />;
  return <CatalogList titleAm={titleAm} kind={kindFrom(params.get("kind"))} view={params.get("view") === "grid" ? "grid" : "table"} />;
}

function CatalogList({ titleAm, kind, view }: { titleAm?: string; kind: ItemKind; view: View }) {
  const t = useTranslations("catalog");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const manage = can(me, "catalog.manage");
  const items = useItems(kind);
  const categories = useCategories();
  const [query, setQuery] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const router = useRouter();
  const [categoriesOpen, setCategoriesOpen] = useState(false);
  const shownView: View = kind === "product" ? view : "table";

  const ofKind = useMemo(() => (categories.data ?? []).filter((c) => c.kind === kind), [categories.data, kind]);
  const categoryOf = useMemo(() => {
    const map = new Map<number, Category>();
    for (const c of categories.data ?? []) map.set(c.id, c);
    return (id: number | null) => (id === null ? undefined : map.get(id));
  }, [categories.data]);

  const all = useMemo(() => items.data ?? [], [items.data]);
  const rows = useMemo(() => {
    const needle = fold(query);
    return all.filter(
      (item) =>
        (categoryId === "" || String(item.category_id) === categoryId) &&
        (needle === "" || fold(item.name_en).includes(needle) || fold(item.name_am).includes(needle)),
    );
  }, [all, query, categoryId]);

  const active = all.filter((item) => item.is_active);
  const intro = items.data
    ? kind === "product"
      ? t("intro_products", { count: active.length, missing: active.filter((item) => !item.image).length })
      : t("intro_ingredients", { count: active.length })
    : t("intro");

  return (
    <>
      <PageHeader
        title={t("title")}
        intro={intro}
        twin={titleAm ? { text: titleAm, lang: "am" } : undefined}
        actions={
          manage ? (
            <>
              <Button variant="secondary" size="md" onClick={() => setCategoriesOpen(true)}>
                <Tags className="size-[18px]" strokeWidth={2} aria-hidden="true" />
                {t("categories")}
              </Button>
              {/* a button (as it was), going to the page: the specs and screen readers keep the role they have always had */}
              <Button size="md" onClick={() => router.push({ pathname: "/admin/catalog/new", query: { kind } })}>
                <Plus className="size-[18px]" strokeWidth={2.2} aria-hidden="true" />
                {kind === "product" ? t("add_product") : t("add_ingredient")}
              </Button>
            </>
          ) : undefined
        }
      />

      <div className="flex flex-col gap-6">
        <Toolbar
          kind={kind}
          view={shownView}
          count={items.data ? active.length : null}
          query={query}
          onQuery={setQuery}
          categoryId={categoryId}
          categories={ofKind}
          onCategory={setCategoryId}
        />

        {items.isPending ? (
          <div className={`${SECTION} space-y-3 p-5`}>
            {Array.from({ length: 6 }, (_, i) => (
              <Skeleton key={i} className="h-12" />
            ))}
          </div>
        ) : items.isError ? (
          <div className="space-y-3">
            <Alert>{errorText.message(items.error)}</Alert>
            <Button variant="secondary" onClick={() => void items.refetch()}>
              {tc("retry")}
            </Button>
          </div>
        ) : all.length === 0 ? (
          <div className={SECTION}>
            <EmptyState title={t("no_items")} />
          </div>
        ) : rows.length === 0 ? (
          <div className={SECTION}>
            <EmptyState
              title={t("no_match")}
              action={
                <Button
                  variant="secondary"
                  size="md"
                  onClick={() => {
                    setQuery("");
                    setCategoryId("");
                  }}
                >
                  {t("clear_search")}
                </Button>
              }
            />
          </div>
        ) : shownView === "grid" ? (
          <ProductGrid rows={rows.filter((item) => item.is_active)} manage={manage} categoryOf={categoryOf} locale={locale} />
        ) : kind === "product" ? (
          <ProductTable rows={rows} manage={manage} categoryOf={categoryOf} />
        ) : (
          <IngredientTable rows={rows} categoryOf={categoryOf} />
        )}
      </div>

      {categoriesOpen ? (
        <Drawer label={t("categories")} onClose={() => setCategoriesOpen(false)}>
          <CategoriesSection onClose={() => setCategoriesOpen(false)} />
        </Drawer>
      ) : null}
    </>
  );
}

// --- the toolbar ----------------------------------------------------------------------------------------------------------------------------

function Toolbar({
  kind,
  view,
  count,
  query,
  onQuery,
  categoryId,
  categories,
  onCategory,
}: {
  kind: ItemKind;
  view: View;
  count: number | null;
  query: string;
  onQuery: (query: string) => void;
  categoryId: string;
  categories: Category[];
  onCategory: (id: string) => void;
}) {
  const t = useTranslations("catalog");
  const locale = useLocale() as Locale;
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const searchId = useId();

  const segments: { value: ItemKind; label: string }[] = [
    { value: "product", label: t("filter_product") },
    { value: "ingredient", label: t("filter_ingredient") },
  ];

  function chooseView(next: View) {
    const query: Record<string, string> = Object.fromEntries(params.entries());
    if (next === "grid") query.view = "grid";
    else delete query.view;
    router.replace({ pathname, query });
  }

  return (
    <div className="flex flex-wrap items-center gap-2.5">
      <nav aria-label={t("filter")} className="flex rounded-xl border border-neutral-200 bg-white p-1">
        {segments.map(({ value, label }) => {
          const current = value === kind;
          return (
            <Link
              key={value}
              href={{ pathname: "/admin/catalog", query: { kind: value } }}
              aria-current={current ? "page" : undefined}
              className={`inline-flex h-9 items-center gap-1 rounded-[9px] px-3.5 text-sm font-semibold motion-safe:transition-colors motion-safe:duration-150 ${
                current ? "bg-brand-50 text-brand-700" : "text-neutral-700 hover:bg-neutral-100 hover:text-neutral-900"
              } ${focusRing}`}
            >
              {label}
              {current && count !== null ? <span aria-hidden="true" className="tabular-nums">{`· ${count}`}</span> : null}
            </Link>
          );
        })}
      </nav>

      <div className="flex h-11 min-w-0 flex-[1_1_260px] items-center gap-2.5 rounded-xl border border-neutral-300 bg-white px-3.5 focus-within:border-ink-900 focus-within:inset-ring-1 focus-within:inset-ring-ink-900 focus-within:ring-4 focus-within:ring-brand-600/35 focus-within:ring-offset-1">
        <Search className="size-[18px] shrink-0 text-neutral-600" strokeWidth={2} aria-hidden="true" />
        <label htmlFor={searchId} className="sr-only">
          {t("search_label")}
        </label>
        <input
          id={searchId}
          type="search"
          value={query}
          onChange={(event) => onQuery(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Escape") onQuery("");
          }}
          placeholder={t("search")}
          autoComplete="off"
          className="min-w-0 flex-1 bg-transparent text-[15px] text-neutral-900 outline-none placeholder:text-neutral-500 [&::-webkit-search-cancel-button]:hidden"
        />
        {query ? (
          <button type="button" aria-label={t("clear_search")} onClick={() => onQuery("")} className={`-mr-2 inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-neutral-600 hover:bg-neutral-100 ${focusRing}`}>
            <X className="size-4" strokeWidth={2} aria-hidden="true" />
          </button>
        ) : null}
      </div>

      <select
        aria-label={t("filter_category")}
        value={categoryId}
        onChange={(event) => onCategory(event.target.value)}
        className={`h-11 rounded-xl border border-neutral-300 bg-white pl-3.5 pr-9 text-sm font-semibold text-neutral-900 hover:bg-neutral-50 ${focusRing}`}
      >
        <option value="">{t("all_categories")}</option>
        {categories.map((c) => (
          <option key={c.id} value={c.id}>
            {named(locale, c).primary}
          </option>
        ))}
      </select>

      {kind === "product" ? (
        <div role="group" aria-label={t("view")} className="flex rounded-xl border border-neutral-200 bg-white p-1">
          {(
            [
              { value: "table", label: t("view_table"), Icon: List },
              { value: "grid", label: t("view_grid"), Icon: LayoutGrid },
            ] as const
          ).map(({ value, label, Icon }) => {
            const pressed = value === view;
            return (
              <button
                key={value}
                type="button"
                aria-label={label}
                title={label}
                aria-pressed={pressed}
                onClick={() => chooseView(value)}
                className={`inline-flex size-9 items-center justify-center rounded-[9px] motion-safe:transition-colors motion-safe:duration-150 ${
                  pressed ? "bg-neutral-900 text-white" : "text-neutral-700 hover:bg-neutral-100"
                } ${focusRing}`}
              >
                <Icon className="size-[18px]" strokeWidth={2} aria-hidden="true" />
              </button>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

// --- the tables --------------------------------------------------------------------------------------------------------------------------------

/** Where a product is sold from: the stations its lines go to, or the display case for one sold from stock; null when neither is known. */
function useSoldFrom(): (item: Item) => string | null {
  const t = useTranslations("catalog");
  const locale = useLocale() as Locale;
  const me = useSession((s) => s.me);
  // the branch's stations for the admin (people.manage); the person's own work stations otherwise (every station for stations.all)
  const stations = useStations();
  const names = useMemo(() => {
    const map = new Map<number, string>();
    for (const s of me?.work_stations ?? []) map.set(s.station_id, locale === "am" ? s.name_am : s.name_en);
    for (const s of stations.data ?? []) map.set(s.id, locale === "am" ? s.name_am : s.name_en);
    return map;
  }, [me, stations.data, locale]);
  return (item) => {
    const routed = item.station_ids.map((id) => names.get(id)).filter((name): name is string => !!name);
    if (routed.length > 0) return routed.join(", ");
    return item.fulfilment === "to_order" ? null : t("sold_display");
  };
}

function StatusChip({ item }: { item: Item }) {
  const t = useTranslations("catalog");
  if (!item.is_active) return <Chip tone="neutral">{t("status_hidden")}</Chip>;
  if (item.kind === "product" && item.is_new)
    return <span className="inline-flex min-h-7 items-center rounded-full bg-brand-600 px-3 text-[13px] font-bold text-white">{t("status_new")}</span>;
  return (
    <Chip tone="positive" dot>
      {item.kind === "product" ? t("status_active") : t("status_in_use")}
    </Chip>
  );
}

/** A product's current price, read once its row or card comes into view. */
function usePrice(id: number, seen: boolean): { text: string | null; loading: boolean } {
  const prices = useItemPrices(seen ? id : null);
  const current = prices.data?.find((price) => price.is_current) ?? null;
  return { text: current ? priceText(current.currency, current.price) : null, loading: !prices.data && !prices.isError };
}

function PriceCell({ id }: { id: number }) {
  const t = useTranslations("catalog");
  const [ref, seen] = useSeen<HTMLTableCellElement>();
  const price = usePrice(id, seen);
  return (
    <td ref={ref} className={`${TD} whitespace-nowrap text-right font-semibold tabular-nums text-neutral-900`}>
      {price.text ?? (price.loading ? <Skeleton className="ml-auto h-4 w-20" /> : <span className="font-normal text-neutral-600">{t("price_none")}</span>)}
    </td>
  );
}

/**
 * "Add photo": the product's page at its Photo card (step 6.4b), for the admin, on a product without one. In a row it is a link under the
 * names; over a pad card (`chip`) it is a white chip on the placeholder's corner, beside the card's own link, never inside it.
 */
function AddPhotoLink({ item, name, chip = false }: { item: Item; name: string; chip?: boolean }) {
  const t = useTranslations("catalog");
  return (
    <Link
      href={{ pathname: "/admin/catalog", query: { item: item.id }, hash: "photo" }}
      className={
        chip
          ? `absolute left-2 top-2 inline-flex min-h-8 items-center rounded-full border border-neutral-200 bg-white px-3 text-[13px] font-semibold text-brand-700 shadow-xs hover:bg-brand-50 ${focusRing}`
          : `self-start rounded-md text-[13px] font-semibold text-brand-600 hover:text-brand-700 hover:underline ${focusRing}`
      }
    >
      {t("add_photo")}
      <span className="sr-only">{`: ${name}`}</span>
    </Link>
  );
}

function ProductTable({ rows, manage, categoryOf }: { rows: Item[]; manage: boolean; categoryOf: (id: number | null) => Category | undefined }) {
  const t = useTranslations("catalog");
  const locale = useLocale() as Locale;
  const soldFrom = useSoldFrom();
  return (
    <section className={SECTION}>
      <table className="w-full min-w-[760px] border-collapse text-left text-[15px] text-neutral-900">
        <caption className="sr-only">{t("items")}</caption>
        <thead>
          <tr className="text-xs font-semibold uppercase tracking-[0.08em] text-neutral-600">
            <th scope="col" className="px-5 py-3.5 font-semibold">
              {t("col_product")}
            </th>
            <th scope="col" className={TH}>
              {t("col_category")}
            </th>
            <th scope="col" className={TH}>
              {t("col_sold_from")}
            </th>
            <th scope="col" className={`${TH} text-right`}>
              {t("col_price")}
            </th>
            <th scope="col" className={TH}>
              {t("col_status")}
            </th>
            <th scope="col" className="px-5 py-3.5">
              <span className="sr-only">{t("col_open")}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((item) => {
            const name = named(locale, item);
            const category = categoryOf(item.category_id);
            const where = soldFrom(item);
            return (
              <tr key={item.id} className="border-t border-neutral-200 hover:bg-neutral-50">
                <td className="px-5 py-2.5">
                  <div className="flex items-center gap-3.5">
                    <ProductImage
                      image={item.image ?? null}
                      name={name.primary}
                      category={category ? category.name_en : null}
                      size="thumb"
                      className="w-16 shrink-0 rounded-[10px]"
                    />
                    <div className="flex min-w-0 flex-col gap-px">
                      <span className="font-semibold">{name.primary}</span>
                      <span lang={locale === "am" ? "en" : "am"} className="text-[13px] text-neutral-600">
                        {name.secondary}
                      </span>
                      {manage && !item.image ? <AddPhotoLink item={item} name={name.primary} /> : null}
                    </div>
                  </div>
                </td>
                <td className={`${TD} text-neutral-700`}>{category ? named(locale, category).primary : "—"}</td>
                <td className={`${TD} text-neutral-700`}>
                  <span className="block">{where ?? "—"}</span>
                  <span className="block text-[13px] text-neutral-600">{t(`fulfilment_${item.fulfilment}`)}</span>
                </td>
                <PriceCell id={item.id} />
                <td className={TD}>
                  <StatusChip item={item} />
                </td>
                <td className="px-5 py-2.5 text-right">
                  <Link
                    href={{ pathname: "/admin/catalog", query: { item: item.id } }}
                    aria-label={t("open_item", { name: name.primary })}
                    className={`inline-flex size-10 items-center justify-center rounded-[10px] text-neutral-600 hover:bg-neutral-100 hover:text-neutral-900 ${focusRing}`}
                  >
                    <ChevronRight className="size-[18px]" strokeWidth={2} aria-hidden="true" />
                  </Link>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </section>
  );
}

function IngredientTable({ rows, categoryOf }: { rows: Item[]; categoryOf: (id: number | null) => Category | undefined }) {
  const t = useTranslations("catalog");
  const locale = useLocale() as Locale;
  return (
    <section className={SECTION}>
      <table className="w-full min-w-[640px] border-collapse text-left text-[15px] text-neutral-900">
        <caption className="sr-only">{t("items")}</caption>
        <thead>
          <tr className="text-xs font-semibold uppercase tracking-[0.08em] text-neutral-600">
            <th scope="col" className="px-5 py-3.5 font-semibold">
              {t("col_ingredient")}
            </th>
            <th scope="col" className={TH}>
              {t("col_category")}
            </th>
            <th scope="col" className={TH}>
              {t("col_unit")}
            </th>
            <th scope="col" className={TH}>
              {t("col_sku")}
            </th>
            <th scope="col" className={`${TH} pr-5`}>
              {t("col_status")}
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((item) => {
            const name = named(locale, item);
            const category = categoryOf(item.category_id);
            return (
              <tr key={item.id} className="border-t border-neutral-200 hover:bg-neutral-50">
                <td className="px-5 py-2.5">
                  <span className="flex min-h-12 flex-col justify-center gap-px">
                    <span className="font-semibold">{name.primary}</span>
                    <span lang={locale === "am" ? "en" : "am"} className="text-[13px] text-neutral-600">
                      {name.secondary}
                    </span>
                  </span>
                </td>
                <td className={`${TD} text-neutral-700`}>{category ? named(locale, category).primary : "—"}</td>
                <td className={`${TD} text-neutral-700`}>{item.unit_code}</td>
                <td className={`${TD} font-mono text-sm text-neutral-700`}>{item.sku ?? ""}</td>
                <td className={`${TD} pr-5`}>
                  <StatusChip item={item} />
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </section>
  );
}

// --- the grid ----------------------------------------------------------------------------------------------------------------------------------

function GridCard({ item, manage, category, locale }: { item: Item; manage: boolean; category: Category | undefined; locale: Locale }) {
  const [ref, seen] = useSeen<HTMLLIElement>();
  const prices = useItemPrices(seen ? item.id : null);
  const current = prices.data?.find((price) => price.is_current) ?? null;
  const name = named(locale, item).primary;
  // the card as the waiter sees it: its price, and nothing about the stock (the pad's menu knows it, the catalog does not)
  const card: ProductCardItem = {
    item_id: item.id,
    name_en: item.name_en,
    name_am: item.name_am,
    price: current ? groupDigits(current.price) : "—",
    currency: current ? current.currency : "",
    available: "1",
    low: false,
    is_new: item.is_new,
    image: item.image ?? null,
  };
  return (
    <li ref={ref} className="relative">
      {/* named by what it shows (the photo's alt, the names and the price), so the link's name holds its visible text */}
      <Link href={{ pathname: "/admin/catalog", query: { item: item.id } }} className={`block h-full rounded-2xl ${focusRing}`}>
        <ProductCard item={card} locale={locale} category={category ? category.name_en : null} preview />
      </Link>
      {manage && !item.image ? <AddPhotoLink item={item} name={name} chip /> : null}
    </li>
  );
}

function ProductGrid({
  rows,
  manage,
  categoryOf,
  locale,
}: {
  rows: Item[];
  manage: boolean;
  categoryOf: (id: number | null) => Category | undefined;
  locale: Locale;
}) {
  const t = useTranslations("catalog");
  return (
    <section aria-label={t("grid")}>
      <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 min-[72rem]:grid-cols-4">
        {rows.map((item) => (
          <GridCard key={item.id} item={item} manage={manage} category={categoryOf(item.category_id)} locale={locale} />
        ))}
      </ul>
    </section>
  );
}

// --- the drawer: the categories --------------------------------------------------------------------------------------------------------------

/** The categories of both kinds, and a new one: what the new item page's Category list offers. */
function CategoriesSection({ onClose }: { onClose: () => void }) {
  const t = useTranslations("catalog");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const categories = useCategories();
  const create = useCreateCategory();
  const idempotency = useIdempotencyKey();

  const [kind, setKind] = useState<ItemKind>("ingredient");
  const [nameEn, setNameEn] = useState("");
  const [nameAm, setNameAm] = useState("");
  const [created, setCreated] = useState<string | null>(null);
  const headingId = useId();

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const body: NewCategory = { kind, name_en: nameEn.trim(), name_am: nameAm.trim() };
    setCreated(null);
    create.mutate(
      { body, idempotencyKey: idempotency.keyFor(body) },
      {
        onSuccess: (category) => {
          idempotency.done();
          setCreated(named(locale, category).primary);
          setNameEn("");
          setNameAm("");
        },
      },
    );
  }

  return (
    <section aria-labelledby={headingId} className="space-y-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 space-y-1">
          <h2 id={headingId} className="text-xl font-semibold text-neutral-900">
            {t("categories")}
          </h2>
          <p className="text-[13px] text-neutral-600">{t("categories_intro")}</p>
        </div>
        <IconButton label={t("close")} size="md" onClick={onClose}>
          <X className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
        </IconButton>
      </div>
      {categories.isPending ? (
        <p className="text-base text-neutral-600">{tc("loading")}</p>
      ) : categories.isError ? (
        <Alert>{errorText.message(categories.error)}</Alert>
      ) : categories.data.length === 0 ? (
        <p className="text-base text-neutral-600">{t("no_categories")}</p>
      ) : (
        <ul className="divide-y divide-neutral-100 rounded-xl border border-neutral-200 px-3">
          {categories.data.map((c) => {
            const name = named(locale, c);
            return (
              <li key={c.id} className="flex items-center justify-between gap-3 py-2">
                <span className="min-w-0">
                  <span className="block text-[15px] text-neutral-900">{name.primary}</span>
                  <span className="block text-[13px] text-neutral-600" lang={locale === "am" ? "en" : "am"}>
                    {name.secondary}
                  </span>
                </span>
                <Chip className="shrink-0">{t(`kind_${c.kind}`)}</Chip>
              </li>
            );
          })}
        </ul>
      )}
      <form onSubmit={onSubmit} className="space-y-4">
        <Field label={t("field_kind")}>
          {(props) => (
            <Select {...props} controlSize="md" value={kind} onChange={(e) => setKind(e.target.value as ItemKind)}>
              {KINDS.map((k) => (
                <option key={k} value={k}>
                  {t(`kind_${k}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t("field_name_en")} error={errorText.field(create.error, "name_en")}>
          {(props) => <TextInput {...props} controlSize="md" lang="en" required maxLength={120} value={nameEn} onChange={(e) => setNameEn(e.target.value)} />}
        </Field>
        <Field label={t("field_name_am")} error={errorText.field(create.error, "name_am")}>
          {(props) => <TextInput {...props} controlSize="md" lang="am" required maxLength={120} value={nameAm} onChange={(e) => setNameAm(e.target.value)} />}
        </Field>
        {create.isError ? <Alert>{errorText.message(create.error)}</Alert> : null}
        <Status>{created ? t("category_created", { name: created }) : null}</Status>
        <Button type="submit" variant="secondary" size="md" disabled={create.isPending}>
          {create.isPending ? tc("saving") : t("add_category")}
        </Button>
      </form>
    </section>
  );
}
