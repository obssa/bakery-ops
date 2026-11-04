"use client";

import { BookOpen, X } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useId, useState, type ReactNode } from "react";
import { Link } from "@/i18n/navigation";
import { useItem, useItemPrices, useProductPageMenu, useSaveProduct, useSetOnMenu } from "@/lib/api/catalog";
import { useCategories } from "@/lib/api/hooks";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { FULFILMENTS, type Fulfilment, type Item, type ItemChanges, type ItemPrice, type Locale, type MenuItem } from "@/lib/api/types";
import { dayText } from "@/lib/dates";
import { compareDecimal, isQuantityText, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { refundSettingsBody } from "@/lib/refund";
import { can, useSession } from "@/lib/session";
import { addisDate } from "@/lib/shiftsAdmin";
import { useDateFormat } from "@/lib/useDates";
import { focusRing } from "../styles";
import { Alert, Button, Drawer, Field, IconButton, MoneyInput, SegmentedControl, Select, Status, TextInput } from "../ui";
import { PhotoCard } from "./PhotoCard";
import { RecipePanels } from "./RecipePanels";
import { RefundToggles } from "./RefundSettings";

// Admin > Catalog > one product (?item=<id>), rebuilt in step 6.4b to the ProductPhoto board (docs/design/fresh-counter.md 4.3):
//   - the breadcrumb ("Catalog / <name>");
//   - the title row: the name (30 / 700) with its Amharic name beside it on the English page, the chips ("On the menu" or "Off the menu",
//     and where it is sold with what is left, from the pad's menu), and on the right "Recipe and cost", "Take off the menu" (or "Put back on
//     the menu") and "Save changes";
//   - two columns: on the left the Details card (the names, the category, the price, the fulfilment) and the Refunds card (the two refund
//     settings of step 4.5g, still the form "Refund settings" with the same checkboxes, drawn as switches); on the right the sticky Photo card
//     (PhotoCard: drop, frame, preview, upload, replace, remove).
// "Save changes" saves both cards at once: PATCH /items/{item} with what changed, then POST /items/{item}/prices for a new price. The recipe
// (price and cost, the versions, publishing the next one, comparing two) is not on the board, so it sits behind "Recipe and cost", a drawer
// (RecipePanels, as it was). Only catalog.manage changes anything; anyone else reads the page.

const CARD = "rounded-[18px] border border-neutral-200 bg-white p-5";
const CHIP = "inline-flex h-6.5 items-center gap-1.5 rounded-full px-2.5 text-xs font-semibold";

interface Draft {
  name_en: string;
  name_am: string;
  /** a category id, or "" for none */
  category: string;
  fulfilment: Fulfilment;
  price: string;
  refundable: boolean;
  restock: boolean;
}

function Breadcrumb({ current }: { current?: string }) {
  const t = useTranslations("product");
  const tcat = useTranslations("catalog");
  return (
    <nav aria-label={t("breadcrumb")} className="flex flex-wrap items-center gap-2 text-sm text-neutral-600">
      <Link href="/admin/catalog" className={`rounded-md font-semibold text-brand-600 hover:text-brand-700 ${focusRing}`}>
        {tcat("title")}
      </Link>
      {current ? (
        <>
          <span aria-hidden="true">/</span>
          <span aria-current="page">{current}</span>
        </>
      ) : null}
    </nav>
  );
}

export function ItemPage({ id }: { id: number }) {
  const tc = useTranslations("common");
  const errorText = useErrorText();
  const item = useItem(id);

  if (item.isPending) {
    return (
      <div className="flex flex-col gap-6">
        <Breadcrumb />
        <p className="text-base text-neutral-600">{tc("loading")}</p>
      </div>
    );
  }
  if (item.isError) {
    return (
      <div className="flex flex-col gap-6">
        <Breadcrumb />
        <div className="space-y-3">
          <Alert>{errorText.message(item.error)}</Alert>
          <Button variant="secondary" onClick={() => void item.refetch()}>
            {tc("retry")}
          </Button>
        </div>
      </div>
    );
  }
  return <ProductPage key={item.data.id} product={item.data} />;
}

/** The day a price started, as the page's dates read: "19 Oct 2026" (the API gives a date, or an instant read in Addis Ababa). */
function sinceDay(price: ItemPrice): string {
  if (/^\d{4}-\d{2}-\d{2}$/.test(price.effective_from)) return price.effective_from;
  const at = new Date(price.effective_from);
  return Number.isNaN(at.getTime()) ? price.effective_from : addisDate(at);
}

function ProductPage({ product }: { product: Item }) {
  const t = useTranslations("product");
  const tcat = useTranslations("catalog");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const fmt = useDateFormat();
  const canManage = useSession((s) => can(s.me, "catalog.manage"));
  const isProduct = product.kind === "product";
  const categories = useCategories();
  const prices = useItemPrices(isProduct ? product.id : null);
  const menu = useProductPageMenu();
  const save = useSaveProduct(product.id);
  const menuSwitch = useSetOnMenu(product.id);
  const changesKey = useIdempotencyKey();
  const priceKey = useIdempotencyKey();
  const menuKey = useIdempotencyKey();
  const [edits, setEdits] = useState<Partial<Draft>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [recipeOpen, setRecipeOpen] = useState(false);

  const current = prices.data?.find((price) => price.is_current) ?? null;
  const draft: Draft = {
    name_en: product.name_en,
    name_am: product.name_am,
    category: product.category_id === null ? "" : String(product.category_id),
    fulfilment: product.fulfilment,
    price: current ? current.price : "",
    refundable: product.refundable,
    restock: product.refund_restock,
    ...edits,
  };
  const edit = (patch: Partial<Draft>) => {
    setEdits((before) => ({ ...before, ...patch }));
    setNotice(null);
  };

  const name = locale === "am" ? product.name_am : product.name_en;
  const entry: MenuItem | null = menu.data?.items.find((row) => row.item_id === product.id) ?? null;
  const ofKind = (categories.data ?? []).filter((category) => category.kind === product.kind);
  const categoryRow = ofKind.find((category) => category.id === product.category_id);
  const categoryName = categoryRow ? (locale === "am" ? categoryRow.name_am : categoryRow.name_en) : null;
  const fieldError = (field: string) => errorText.field(save.error, field);
  const showRecipe = isProduct && product.fulfilment !== "bought_in";

  function onSave() {
    if (!canManage || save.isPending) return;
    setNotice(null);
    setProblem(null);
    const nameEn = draft.name_en.trim();
    const nameAm = draft.name_am.trim();
    if (!nameEn || !nameAm) return setProblem(t("check_names"));
    const changes: ItemChanges = {};
    if (nameEn !== product.name_en) changes.name_en = nameEn;
    if (nameAm !== product.name_am) changes.name_am = nameAm;
    const categoryId = draft.category === "" ? null : Number(draft.category);
    if (categoryId !== product.category_id) changes.category_id = categoryId;
    if (isProduct && draft.fulfilment !== product.fulfilment) changes.fulfilment = draft.fulfilment;
    const refunds = refundSettingsBody(product.kind, draft.fulfilment, draft.refundable, draft.restock);
    if (refunds.refundable !== product.refundable || refunds.refund_restock !== product.refund_restock) Object.assign(changes, refunds);
    let price: string | null = null;
    if (isProduct && edits.price !== undefined) {
      const typed = edits.price.trim();
      if (!isQuantityText(typed, 2) || compareDecimal(typed, "0", 2) <= 0) return setProblem(t("check_price"));
      if (current === null || compareDecimal(typed, current.price, 2) !== 0) price = typed;
    }
    const changed = Object.keys(changes).length > 0;
    if (!changed && price === null) return setNotice(t("nothing_changed"));
    save.mutate(
      {
        changes: changed ? changes : null,
        changesKey: changesKey.keyFor({ item: product.id, changes }),
        price,
        priceKey: priceKey.keyFor({ item: product.id, price }),
      },
      {
        onSuccess: () => {
          changesKey.done();
          priceKey.done();
          setEdits({});
          setNotice(t("saved"));
        },
      },
    );
  }

  function onMenuSwitch() {
    if (!canManage || menuSwitch.isPending) return;
    const active = !product.is_active;
    setNotice(null);
    menuSwitch.mutate(
      { active, idempotencyKey: menuKey.keyFor({ item: product.id, active }) },
      {
        onSuccess: () => {
          menuKey.done();
          setNotice(active ? t("put_back_done", { name }) : t("taken_off", { name }));
        },
      },
    );
  }

  const chips: ReactNode[] = [];
  if (isProduct) {
    chips.push(
      <span key="menu" className={`${CHIP} ${product.is_active ? "bg-brand-50 text-brand-700" : "bg-neutral-100 text-neutral-700"}`}>
        <span aria-hidden="true" className={`size-[7px] rounded-full ${product.is_active ? "bg-brand-600" : "bg-neutral-500"}`} />
        {product.is_active ? t("on_menu") : t("off_menu")}
      </span>,
    );
    const place = product.fulfilment === "to_order" ? t("place_to_order") : t("place_display");
    const stock = !entry ? place : entry.unavailable_reason !== undefined || compareDecimal(entry.available, "0") <= 0 ? t("stock_none", { place }) : t("stock_left", { place, count: trimDecimal(entry.available) });
    chips.push(
      <span key="stock" className={`${CHIP} bg-neutral-100 text-neutral-700`}>
        {stock}
      </span>,
    );
  } else {
    chips.push(
      <span key="kind" className={`${CHIP} bg-neutral-100 text-neutral-700`}>
        {t("ingredient")}
      </span>,
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumb current={name} />
      <header className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex min-w-0 flex-col gap-2">
          <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h1 className="text-3xl font-bold tracking-tight text-neutral-900">{name}</h1>
            {locale === "en" ? (
              <span lang="am" className="text-2xl font-semibold text-neutral-600">
                <span aria-hidden="true">· </span>
                {product.name_am}
              </span>
            ) : null}
          </div>
          <div className="flex flex-wrap gap-2">{chips}</div>
        </div>
        <div className="flex flex-wrap items-center gap-2.5">
          {showRecipe ? (
            <Button variant="secondary" size="md" onClick={() => setRecipeOpen(true)}>
              <BookOpen className="size-[18px]" strokeWidth={2} aria-hidden="true" />
              {t("recipe")}
            </Button>
          ) : null}
          {canManage && isProduct ? (
            <Button variant="secondary" size="md" loading={menuSwitch.isPending} onClick={onMenuSwitch}>
              {product.is_active ? t("take_off") : t("put_back")}
            </Button>
          ) : null}
          {canManage ? (
            <Button size="md" loading={save.isPending} onClick={onSave}>
              {t("save")}
            </Button>
          ) : null}
        </div>
      </header>

      {problem ? <Alert>{problem}</Alert> : null}
      {save.isError ? <Alert>{errorText.message(save.error)}</Alert> : null}
      {menuSwitch.isError ? <Alert>{errorText.message(menuSwitch.error)}</Alert> : null}
      <Status>{notice}</Status>

      <div className="flex flex-wrap items-start gap-5">
        <div className="flex min-w-0 flex-[1_1_380px] flex-col gap-4">
          <DetailsCard
            product={product}
            draft={draft}
            current={current}
            pricesPending={prices.isPending && isProduct}
            categories={ofKind.map((category) => ({ id: category.id, name: locale === "am" ? category.name_am : category.name_en }))}
            canManage={canManage}
            disabled={!canManage || save.isPending}
            since={current ? dayText(sinceDay(current), fmt, { withYear: true }) : null}
            fieldError={fieldError}
            onEdit={edit}
          />
          <form
            aria-label={tcat("refund_settings")}
            onSubmit={(event) => {
              event.preventDefault();
              onSave();
            }}
            className={`${CARD} flex flex-col`}
          >
            <h2 className="mb-1 text-[17px] font-semibold text-neutral-900">{tcat("col_refunds")}</h2>
            <RefundToggles
              kind={product.kind}
              fulfilment={draft.fulfilment}
              refundable={draft.refundable}
              restock={draft.restock}
              disabled={!canManage || save.isPending}
              legend="hidden"
              onRefundable={(value) => edit({ refundable: value })}
              onRestock={(value) => edit({ restock: value })}
            />
            {!canManage ? <p className="pt-2 text-[13px] text-neutral-600">{tcat("refund_read_only")}</p> : null}
          </form>
        </div>
        {isProduct ? (
          <PhotoCard
            product={product}
            menuEntry={entry}
            price={current ? { price: current.price, currency: current.currency } : null}
            category={categoryName}
            canManage={canManage}
            className="min-w-0 max-w-[520px] flex-[1_1_420px] lg:sticky lg:top-6"
          />
        ) : null}
      </div>

      {recipeOpen ? (
        <Drawer label={t("recipe")} size="lg" onClose={() => setRecipeOpen(false)}>
          <div className="flex items-start justify-between gap-4">
            <div className="min-w-0 space-y-1">
              <h2 className="text-xl font-semibold text-neutral-900">{t("recipe")}</h2>
              <p className="text-[15px] text-neutral-600">{name}</p>
            </div>
            <IconButton label={t("close")} size="md" onClick={() => setRecipeOpen(false)}>
              <X className="size-5" strokeWidth={2} aria-hidden="true" />
            </IconButton>
          </div>
          <RecipePanels product={product} />
        </Drawer>
      ) : null}
    </div>
  );
}

function DetailsCard({
  product,
  draft,
  current,
  pricesPending,
  categories,
  canManage,
  disabled,
  since,
  fieldError,
  onEdit,
}: {
  product: Item;
  draft: Draft;
  current: ItemPrice | null;
  pricesPending: boolean;
  categories: { id: number; name: string }[];
  canManage: boolean;
  disabled: boolean;
  since: string | null;
  fieldError: (field: string) => string | undefined;
  onEdit: (patch: Partial<Draft>) => void;
}) {
  const t = useTranslations("product");
  const tcat = useTranslations("catalog");
  const tc = useTranslations("common");
  const headingId = useId();
  const fulfilmentId = useId();
  const isProduct = product.kind === "product";
  return (
    <section aria-labelledby={headingId} className={`${CARD} flex flex-col gap-4`}>
      <h2 id={headingId} className="text-[17px] font-semibold text-neutral-900">
        {t("details")}
      </h2>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-3.5">
        <Field label={t("field_name_en")} error={fieldError("name_en")}>
          {(props) => <TextInput {...props} controlSize="md" lang="en" required maxLength={160} autoComplete="off" disabled={disabled} value={draft.name_en} onChange={(e) => onEdit({ name_en: e.target.value })} />}
        </Field>
        <Field label={t("field_name_am")} error={fieldError("name_am")}>
          {(props) => <TextInput {...props} controlSize="md" lang="am" required maxLength={160} autoComplete="off" disabled={disabled} value={draft.name_am} onChange={(e) => onEdit({ name_am: e.target.value })} />}
        </Field>
        <Field label={tcat("field_category")} error={fieldError("category_id")}>
          {(props) => (
            <Select {...props} controlSize="md" disabled={disabled} value={draft.category} onChange={(e) => onEdit({ category: e.target.value })}>
              <option value="">{tcat("no_category")}</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </Select>
          )}
        </Field>
        {isProduct ? (
          <Field label={t("field_price")} hint={pricesPending ? tc("loading") : since ? t("price_since", { date: since }) : t("price_none")} error={fieldError("price")}>
            {(props) => <MoneyInput {...props} controlSize="md" currency={current?.currency ?? "ETB"} disabled={disabled} value={draft.price} onChange={(e) => onEdit({ price: e.target.value })} />}
          </Field>
        ) : null}
      </div>
      {isProduct ? (
        <div className="flex flex-col gap-2">
          <span id={fulfilmentId} className="text-sm font-semibold text-neutral-900">
            {tcat("field_fulfilment")}
          </span>
          <SegmentedControl
            label={tcat("field_fulfilment")}
            options={FULFILMENTS.map((value) => ({ value, label: tcat(`fulfilment_${value}`) }))}
            value={draft.fulfilment}
            onChange={(value) => (disabled ? undefined : onEdit({ fulfilment: value }))}
            className="self-start"
          />
        </div>
      ) : null}
      {!canManage ? <p className="text-[13px] text-neutral-600">{t("read_only")}</p> : null}
    </section>
  );
}
