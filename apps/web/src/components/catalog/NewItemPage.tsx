"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Check, ChefHat, ChevronDown, Croissant, Layers, Plus, Truck, Wheat, type LucideIcon } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from "react";
import { Link, useRouter } from "@/i18n/navigation";
import { useAddPrice, usePutRecipe } from "@/lib/api/catalog";
import { useCategories, useCreateCategory, useCreateItem, useItems, useUnits } from "@/lib/api/hooks";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { FULFILMENTS, type Category, type Fulfilment, type ItemKind, type Locale, type NewCategory, type NewItem, type RecipeSpec, type Unit } from "@/lib/api/types";
import { handCreated } from "@/lib/createdItem";
import { compareDecimal, isQuantityText } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { refundSettingsBody } from "@/lib/refund";
import { buttonClass, focusRing } from "../styles";
import { Alert, Button, Field, Modal, MoneyInput, Select, Status, TextInput } from "../ui";
import { Breadcrumb } from "./Breadcrumb";
import { blankLine, checkLines, isBlank, RecipeLinesEditor, specLines, type DraftLine } from "./RecipeLinesEditor";
import { RefundToggles } from "./RefundSettings";

// Admin > Catalog > New product / New ingredient (phase-6/new-item-page, docs/design/fresh-counter.md 4.3): a page of its own, where the
// 480 px drawer of step 6.4a stacked every field as a plain list. No board draws it; it follows the ProductPhoto board's cards (white,
// 18 px, 20 px in, 17 / 600 titles, 44 px controls) and the spec's forms (two columns from 1024 px, one below):
//   1. "What are you adding?": Product or Ingredient, two choice cards, one radio group named "Kind" (?kind= picks the first one);
//   2. Basics: the two names side by side, then the category (with "New category", a small dialog, so a missing one never sends the admin
//      away) and the unit, its common ones first;
//   3. "How it's sold" (a product): Made to order, Made in batches, Bought in, three choice cards, the radio group "Fulfilment";
//   4. the recipe (phase-6/new-item-recipe, a product that is made): its ingredients and how much of each, the product page's own lines
//      (RecipeLinesEditor). Made to order: what goes into one, required, since a made-to-order product without a recipe can never be
//      sold (the send holds each ingredient and refuses when one is short; the ingredients come off the stock when the line is ready).
//      Made in batches: what one batch uses and how many it makes, optional (the batch takes them off the stock when it is recorded);
//   5. Price (a product, optional): saved after the item, by the product page's own call (POST /items/{item}/prices);
//   6. "More options", folded: the SKU, and for a product the two refund settings (RefundToggles, as the product page has them). An
//      ingredient is never sold, so it shows no refund section and sends none;
//   7. a footer that stays in view: Cancel, and "Create product" or "Create ingredient".
// The checks run in the page first (the names, the unit, the price), then the API's: each error shows under its field and the first one
// takes the focus. On success the item's own page opens (?item=<id>), saying it was created and what comes next (the photo, the recipe).
// Up to three writes (the item, its price, its recipe's version 1), each with its own Idempotency-Key: a retry replays, and when the price
// or the recipe is refused the item still exists once and its page says why (a refused recipe opens the page's recipe drawer, filled in).

const CARD = "rounded-[18px] border border-neutral-200 bg-white p-5";
const KINDS: readonly ItemKind[] = ["product", "ingredient"];
const KIND_ICONS: Record<ItemKind, LucideIcon> = { product: Croissant, ingredient: Wheat };
const FULFILMENT_ICONS: Record<Fulfilment, LucideIcon> = { to_order: ChefHat, to_stock: Layers, bought_in: Truck };
/** The units a bakery names first for each kind; the rest follow in the page's language. */
const COMMON_UNITS: Record<ItemKind, readonly string[]> = { product: ["pcs", "slice", "tray"], ingredient: ["kg", "g", "L", "ml", "pcs"] };

type FieldName = "name_en" | "name_am" | "category_id" | "unit_id" | "fulfilment" | "price" | "sku" | "recipe" | "yield_qty";

function kindFrom(raw: string | null): ItemKind {
  return raw === "ingredient" ? "ingredient" : "product";
}

function named(locale: Locale, row: { name_en: string; name_am: string }): string {
  return locale === "am" ? row.name_am : row.name_en;
}

/** The units in two groups: the common ones for the kind, in that order, then every other one by name. */
export function unitGroups(units: readonly Unit[], kind: ItemKind, locale: Locale): { common: Unit[]; other: Unit[] } {
  const order = COMMON_UNITS[kind];
  const common = order.map((code) => units.find((u) => u.code === code)).filter((u): u is Unit => u !== undefined);
  const other = units.filter((u) => !order.includes(u.code)).sort((a, b) => named(locale, a).localeCompare(named(locale, b), locale));
  return { common, other };
}

export function NewItemPage() {
  const params = useSearchParams();
  return <NewItemForm initialKind={kindFrom(params.get("kind"))} />;
}

function NewItemForm({ initialKind }: { initialKind: ItemKind }) {
  const t = useTranslations("catalog.new");
  const tcat = useTranslations("catalog");
  const tc = useTranslations("common");
  const trecipe = useTranslations("recipe");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const router = useRouter();
  const client = useQueryClient();
  const units = useUnits();
  const categories = useCategories();
  const ingredients = useItems("ingredient");
  const create = useCreateItem();
  const addPrice = useAddPrice();
  const putRecipe = usePutRecipe();
  const itemKey = useIdempotencyKey();
  const priceKey = useIdempotencyKey();
  const recipeKey = useIdempotencyKey();
  const recipeErrorId = useId();
  const formRef = useRef<HTMLFormElement>(null);

  const [kind, setKind] = useState<ItemKind>(initialKind);
  const [fulfilment, setFulfilment] = useState<Fulfilment>("to_order");
  const [nameEn, setNameEn] = useState("");
  const [nameAm, setNameAm] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [unitId, setUnitId] = useState("");
  const [price, setPrice] = useState("");
  const [sku, setSku] = useState("");
  const [lines, setLines] = useState<DraftLine[]>(() => [blankLine()]);
  const [yieldQty, setYieldQty] = useState("");
  // the refund settings (step 4.5g): untouched, the API gives the natural values (refundable, back into stock when sold from stock)
  const [refundable, setRefundable] = useState<boolean | null>(null);
  const [restock, setRestock] = useState<boolean | null>(null);
  const [more, setMore] = useState(false);
  const [checks, setChecks] = useState<Partial<Record<FieldName, string>>>({});
  const [focusTurn, setFocusTurn] = useState(0);
  const [addingCategory, setAddingCategory] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [leaving, setLeaving] = useState(false);

  const isProduct = kind === "product";
  const title = isProduct ? t("title_product") : t("title_ingredient");
  const busy = create.isPending || addPrice.isPending || putRecipe.isPending || leaving;
  const made = isProduct && fulfilment !== "bought_in";
  const unitCode = (units.data ?? []).find((u) => String(u.id) === unitId)?.code ?? null;
  const ofKind: Category[] = (categories.data ?? []).filter((c) => c.kind === kind);
  const groups = unitGroups(units.data ?? [], kind, locale);

  const fieldError = (field: FieldName): string | undefined => checks[field] ?? errorText.field(create.error, field);
  const clear = (field: FieldName) => setChecks((before) => (before[field] ? { ...before, [field]: undefined } : before));
  const anyFieldError = (["name_en", "name_am", "category_id", "unit_id", "fulfilment", "price", "sku", "recipe", "yield_qty"] as const).some((field) => fieldError(field));

  // after a refused submit, the first field marked invalid takes the focus (a radio group gives it to its chosen card)
  useEffect(() => {
    if (focusTurn === 0) return;
    const first = formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]');
    if (!first) return;
    const target = first.matches("input, select, textarea") ? first : first.querySelector<HTMLElement>("input:checked, input");
    target?.focus();
  }, [focusTurn]);

  function chooseKind(next: ItemKind) {
    setKind(next);
    setCategoryId("");
    clear("category_id");
    setNotice(null);
    // the address says what is being added, so a reload and Cancel keep it
    router.replace({ pathname: "/admin/catalog/new", query: { kind: next } });
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    const typedPrice = price.trim();
    const found: Partial<Record<FieldName, string>> = {};
    if (!nameEn.trim()) found.name_en = t("check_name_en");
    if (!nameAm.trim()) found.name_am = t("check_name_am");
    if (!unitId) found.unit_id = t("check_unit");
    if (isProduct && typedPrice && (!isQuantityText(typedPrice, 2) || compareDecimal(typedPrice, "0", 2) <= 0)) found.price = t("check_price");
    // the recipe: the lines typed into (a blank one is left out); one at least for a product made to order
    const typedLines = lines.filter((line) => !isBlank(line));
    if (made) {
      const linesProblem = checkLines(typedLines);
      if (fulfilment === "to_order" && typedLines.length === 0) found.recipe = t("check_recipe_required");
      else if (linesProblem) found.recipe = trecipe(linesProblem);
      if (fulfilment === "to_stock" && typedLines.length > 0 && (!isQuantityText(yieldQty.trim()) || compareDecimal(yieldQty.trim(), "0") <= 0)) found.yield_qty = t("check_yield");
    }
    create.reset();
    setChecks(found);
    setNotice(null);
    if (Object.keys(found).length > 0) {
      setFocusTurn((turn) => turn + 1);
      return;
    }

    const body: NewItem = {
      kind,
      ...(isProduct ? { fulfilment } : {}),
      name_en: nameEn.trim(),
      name_am: nameAm.trim(),
      category_id: categoryId ? Number(categoryId) : null,
      unit_id: Number(unitId),
      sku: sku.trim() || null,
      // an ingredient sends no refund settings at all; a product only those the admin touched
      ...(isProduct && (refundable !== null || restock !== null) ? refundSettingsBody(kind, fulfilment, refundable ?? true, restock ?? true) : {}),
    };
    let id: number;
    try {
      id = (await create.mutateAsync({ body, idempotencyKey: itemKey.keyFor(body) })).id;
    } catch (error) {
      if (errorText.field(error, "sku")) setMore(true);
      setFocusTurn((turn) => turn + 1);
      return;
    }
    itemKey.done();
    setLeaving(true);
    // made to order, the recipe is for one of the product's unit; made in batches, for the batch it makes
    const recipe: RecipeSpec | null =
      made && typedLines.length > 0 ? { yield_qty: fulfilment === "to_order" ? "1" : yieldQty.trim(), yield_unit_id: Number(unitId), lines: specLines(typedLines), steps: [] } : null;
    let priceError: string | null = null;
    if (isProduct && typedPrice) {
      try {
        await addPrice.mutateAsync({ id, price: typedPrice, idempotencyKey: priceKey.keyFor({ item: id, price: typedPrice }) });
        priceKey.done();
      } catch (error) {
        priceError = errorText.message(error);
      }
    }
    let recipeError: string | null = null;
    if (recipe) {
      try {
        await putRecipe.mutateAsync({ id, body: recipe, idempotencyKey: recipeKey.keyFor({ item: id, recipe }) });
        recipeKey.done();
      } catch (error) {
        recipeError = errorText.message(error);
      }
    }
    handCreated({ id, priceError, recipeSaved: recipe !== null && recipeError === null, recipeError, recipeDraft: recipeError ? recipe : null });
    // the item's page replaces this one: Back from it goes to the list, not to a form that would create the item again
    router.replace({ pathname: "/admin/catalog", query: { item: id } });
  }

  return (
    <div className="flex flex-col gap-6">
      <Breadcrumb current={title} />
      <header className="flex flex-col gap-1">
        <h1 className="text-3xl font-bold tracking-tight text-neutral-900">{title}</h1>
        <p className="text-base text-neutral-600">{isProduct ? t("intro_product") : t("intro_ingredient")}</p>
      </header>

      <form ref={formRef} aria-label={title} noValidate onSubmit={onSubmit} className="flex max-w-[960px] flex-col gap-4">
        <Section title={t("kind_title")}>
          <ChoiceGroup
            label={tcat("field_kind")}
            name="kind"
            value={kind}
            onChange={chooseKind}
            disabled={busy}
            className="sm:grid-cols-2"
            options={KINDS.map((value) => ({ value, title: tcat(`kind_${value}`), hint: t(`kind_${value}_hint`), icon: KIND_ICONS[value] }))}
          />
        </Section>

        <Section title={t("basics")}>
          <div className="grid gap-4 lg:grid-cols-2">
            <Field label={tcat("field_name_en")} error={fieldError("name_en")}>
              {(props) => (
                <TextInput
                  {...props}
                  controlSize="md"
                  lang="en"
                  required
                  maxLength={160}
                  autoComplete="off"
                  disabled={busy}
                  value={nameEn}
                  onChange={(e) => {
                    setNameEn(e.target.value);
                    clear("name_en");
                  }}
                />
              )}
            </Field>
            <Field label={tcat("field_name_am")} error={fieldError("name_am")}>
              {(props) => (
                <TextInput
                  {...props}
                  controlSize="md"
                  lang="am"
                  required
                  maxLength={160}
                  autoComplete="off"
                  disabled={busy}
                  value={nameAm}
                  onChange={(e) => {
                    setNameAm(e.target.value);
                    clear("name_am");
                  }}
                />
              )}
            </Field>
            <Field label={tcat("field_category")} optionalLabel={tcat("optional")} error={fieldError("category_id")}>
              {(props) => (
                <div className="flex gap-2">
                  <Select
                    {...props}
                    controlSize="md"
                    disabled={busy}
                    className="min-w-0 flex-1"
                    value={categoryId}
                    onChange={(e) => {
                      setCategoryId(e.target.value);
                      clear("category_id");
                    }}
                  >
                    <option value="">{tcat("no_category")}</option>
                    {ofKind.map((c) => (
                      <option key={c.id} value={c.id}>
                        {named(locale, c)}
                      </option>
                    ))}
                  </Select>
                  <Button type="button" variant="secondary" size="md" compact disabled={busy} className="shrink-0" onClick={() => setAddingCategory(true)}>
                    <Plus className="size-[18px]" strokeWidth={2} aria-hidden="true" />
                    {t("new_category")}
                  </Button>
                </div>
              )}
            </Field>
            <Field label={tcat("field_unit")} error={fieldError("unit_id")}>
              {(props) => (
                <Select
                  {...props}
                  controlSize="md"
                  required
                  disabled={busy}
                  value={unitId}
                  onChange={(e) => {
                    setUnitId(e.target.value);
                    clear("unit_id");
                  }}
                >
                  <option value="" disabled>
                    {units.isPending ? tc("loading") : tcat("choose_unit")}
                  </option>
                  {groups.common.length > 0 ? (
                    <optgroup label={t("units_common")}>
                      {groups.common.map((u) => (
                        <option key={u.id} value={u.id}>
                          {`${named(locale, u)} (${u.code})`}
                        </option>
                      ))}
                    </optgroup>
                  ) : null}
                  {groups.other.length > 0 ? (
                    <optgroup label={t("units_other")}>
                      {groups.other.map((u) => (
                        <option key={u.id} value={u.id}>
                          {`${named(locale, u)} (${u.code})`}
                        </option>
                      ))}
                    </optgroup>
                  ) : null}
                </Select>
              )}
            </Field>
          </div>
          <Status>{notice}</Status>
        </Section>

        {isProduct ? (
          <Section title={t("fulfilment_title")}>
            <ChoiceGroup
              label={tcat("field_fulfilment")}
              name="fulfilment"
              value={fulfilment}
              onChange={(value) => {
                setFulfilment(value);
                clear("fulfilment");
              }}
              disabled={busy}
              error={fieldError("fulfilment")}
              className="md:grid-cols-3"
              options={FULFILMENTS.map((value) => ({ value, title: tcat(`fulfilment_${value}`), hint: t(`fulfilment_${value}_hint`), icon: FULFILMENT_ICONS[value] }))}
            />
          </Section>
        ) : null}

        {made ? (
          <Section title={t("recipe_title")}>
            <p className="-mt-2 text-[15px] text-neutral-600">{fulfilment === "to_order" ? t("recipe_hint_to_order") : t("recipe_hint_to_stock")}</p>
            {fulfilment === "to_order" ? (
              <p className="text-[15px] font-medium text-neutral-800">{unitCode ? t("recipe_for_one", { unit: unitCode }) : t("recipe_for_one_unit")}</p>
            ) : (
              <div className="grid gap-4 lg:grid-cols-2">
                <Field label={t("yield_label")} hint={unitCode ? t("yield_hint", { unit: unitCode }) : t("yield_hint_unit")} error={fieldError("yield_qty")}>
                  {(props) => (
                    <TextInput
                      {...props}
                      controlSize="md"
                      inputMode="decimal"
                      autoComplete="off"
                      disabled={busy}
                      value={yieldQty}
                      onChange={(e) => {
                        setYieldQty(e.target.value);
                        clear("yield_qty");
                      }}
                    />
                  )}
                </Field>
              </div>
            )}
            {ingredients.data && ingredients.data.length === 0 ? (
              <p className="text-[15px] text-neutral-700">
                {t("recipe_no_ingredients")}{" "}
                <Link href={{ pathname: "/admin/catalog/new", query: { kind: "ingredient" } }} className="font-semibold text-brand-700 underline">
                  {t("recipe_new_ingredient")}
                </Link>
              </p>
            ) : null}
            <RecipeLinesEditor
              lines={lines}
              onLines={(update) => {
                setLines(update);
                clear("recipe");
              }}
              legend={trecipe("lines")}
              error={fieldError("recipe")}
              errorId={recipeErrorId}
              disabled={busy}
            />
          </Section>
        ) : null}

        {isProduct ? (
          <Section title={t("price_title")}>
            <div className="grid gap-4 lg:grid-cols-2">
              <Field label={t("price_label")} optionalLabel={tcat("optional")} hint={t("price_hint")} error={fieldError("price")}>
                {(props) => (
                  <MoneyInput
                    {...props}
                    controlSize="md"
                    disabled={busy}
                    value={price}
                    onChange={(e) => {
                      setPrice(e.target.value);
                      clear("price");
                    }}
                  />
                )}
              </Field>
            </div>
          </Section>
        ) : null}

        <details open={more} onToggle={(event) => setMore(event.currentTarget.open)} className="group rounded-[18px] border border-neutral-200 bg-white">
          <summary
            className={`flex min-h-16 cursor-pointer list-none items-center justify-between gap-4 rounded-[18px] px-5 py-3.5 hover:bg-neutral-50 [&::-webkit-details-marker]:hidden ${focusRing}`}
          >
            <span className="flex min-w-0 flex-col gap-0.5">
              <span className="text-[17px] font-semibold text-neutral-900">{t("more")}</span>
              <span className="text-[13px] text-neutral-600">{isProduct ? t("more_hint_product") : t("more_hint_ingredient")}</span>
            </span>
            <ChevronDown className="size-5 shrink-0 text-neutral-600 group-open:rotate-180 motion-safe:transition-transform motion-safe:duration-150" strokeWidth={2} aria-hidden="true" />
          </summary>
          <div className="flex flex-col gap-4 border-t border-neutral-200 px-5 pb-5 pt-4">
            <div className="grid gap-4 lg:grid-cols-2">
              <Field label={tcat("field_sku")} optionalLabel={tcat("optional")} error={fieldError("sku")}>
                {(props) => (
                  <TextInput
                    {...props}
                    controlSize="md"
                    maxLength={64}
                    autoComplete="off"
                    disabled={busy}
                    value={sku}
                    onChange={(e) => {
                      setSku(e.target.value);
                      clear("sku");
                    }}
                  />
                )}
              </Field>
            </div>
            {isProduct ? (
              <RefundToggles
                kind={kind}
                fulfilment={fulfilment}
                refundable={refundable ?? true}
                restock={restock ?? true}
                disabled={busy}
                onRefundable={setRefundable}
                onRestock={setRestock}
              />
            ) : null}
          </div>
        </details>

        {anyFieldError ? <Alert>{t("check_fields")}</Alert> : create.isError ? <Alert>{errorText.message(create.error)}</Alert> : null}

        <div className="sticky bottom-4 z-10 flex flex-wrap items-center justify-end gap-2.5 rounded-2xl border border-neutral-200 bg-white p-3 shadow-float">
          <Link href={{ pathname: "/admin/catalog", query: { kind } }} className={`${buttonClass({ variant: "secondary", size: "md" })} flex-1 sm:flex-none`}>
            {t("cancel")}
          </Link>
          <Button type="submit" size="md" loading={busy} className="flex-1 sm:flex-none">
            {isProduct ? t("create_product") : t("create_ingredient")}
          </Button>
        </div>
      </form>

      {addingCategory ? (
        <NewCategoryModal
          kind={kind}
          onClose={() => setAddingCategory(false)}
          onCreated={(category) => {
            // the list shows it at once (the read the create starts again comes after), and the form chooses it
            client.setQueryData<Category[]>(["categories"], (before) => (before && !before.some((c) => c.id === category.id) ? [...before, category] : before));
            setCategoryId(String(category.id));
            clear("category_id");
            setAddingCategory(false);
            setNotice(t("category_added", { name: named(locale, category) }));
          }}
        />
      ) : null}
    </div>
  );
}

/** A card of the form, a region named by its title (17 / 600). */
function Section({ title, children }: { title: string; children: ReactNode }) {
  const id = useId();
  return (
    <section aria-labelledby={id} className={`${CARD} flex flex-col gap-4`}>
      <h2 id={id} className="text-[17px] font-semibold text-neutral-900">
        {title}
      </h2>
      {children}
    </section>
  );
}

interface Choice<T extends string> {
  value: T;
  title: string;
  hint: string;
  icon: LucideIcon;
}

/**
 * One choice among a few, as cards: a radio group named `label`, each card a real radio (named by its title, described by its sentence)
 * spread over the whole card, so a tap anywhere chooses it and the arrow keys move between them. The chosen card is ink-50 with a 2 px
 * ink-900 border and a check; the focus ring goes round the card.
 */
function ChoiceGroup<T extends string>({
  label,
  name,
  options,
  value,
  onChange,
  disabled = false,
  error,
  className = "",
}: {
  label: string;
  name: string;
  options: readonly Choice<T>[];
  value: T;
  onChange: (value: T) => void;
  disabled?: boolean;
  error?: string;
  className?: string;
}) {
  const id = useId();
  const errorId = error ? `${id}-error` : undefined;
  return (
    <div className="flex flex-col gap-1.5">
      <div role="radiogroup" aria-label={label} aria-describedby={errorId} aria-invalid={error ? true : undefined} className={`grid gap-3 ${className}`}>
        {options.map((option) => (
          <ChoiceCard key={option.value} name={name} option={option} checked={option.value === value} disabled={disabled} onChange={onChange} />
        ))}
      </div>
      {error ? (
        <p id={errorId} className="text-sm font-medium text-red-700">
          {error}
        </p>
      ) : null}
    </div>
  );
}

function ChoiceCard<T extends string>({
  name,
  option,
  checked,
  disabled,
  onChange,
}: {
  name: string;
  option: Choice<T>;
  checked: boolean;
  disabled: boolean;
  onChange: (value: T) => void;
}) {
  const titleId = useId();
  const hintId = useId();
  const Icon = option.icon;
  return (
    <label
      className={`relative flex min-h-20 items-start gap-3.5 rounded-2xl border p-4 motion-safe:transition-colors motion-safe:duration-150 has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-600/35 has-[:focus-visible]:ring-offset-1 ${
        checked ? "border-ink-900 bg-ink-50 inset-ring-1 inset-ring-ink-900" : "border-neutral-200 bg-white hover:border-neutral-300 hover:bg-neutral-50"
      } ${disabled ? "cursor-not-allowed opacity-70" : "cursor-pointer"}`}
    >
      <input
        type="radio"
        name={name}
        value={option.value}
        checked={checked}
        disabled={disabled}
        aria-labelledby={titleId}
        aria-describedby={hintId}
        onChange={() => onChange(option.value)}
        className="absolute inset-0 m-0 size-full cursor-[inherit] appearance-none rounded-2xl opacity-0 focus:outline-none"
      />
      <span
        aria-hidden="true"
        className={`inline-flex size-11 shrink-0 items-center justify-center rounded-xl ${checked ? "bg-white text-ink-900" : "bg-neutral-100 text-neutral-700"}`}
      >
        <Icon className="size-[22px]" strokeWidth={1.75} aria-hidden="true" />
      </span>
      <span className="flex min-w-0 flex-1 flex-col gap-0.5 pr-7">
        <span id={titleId} className={`text-[15px] font-semibold ${checked ? "text-ink-900" : "text-neutral-900"}`}>
          {option.title}
        </span>
        <span id={hintId} className="text-[13px] leading-snug text-neutral-600">
          {option.hint}
        </span>
      </span>
      <span
        aria-hidden="true"
        className={`absolute right-4 top-4 inline-flex size-5 items-center justify-center rounded-full border ${checked ? "border-ink-900 bg-ink-900 text-white" : "border-neutral-300 bg-white"}`}
      >
        {checked ? <Check className="size-3.5" strokeWidth={3} aria-hidden="true" /> : null}
      </span>
    </label>
  );
}

/** "New category": a small dialog for a category of the kind being added (POST /categories), chosen in the form once it exists. */
function NewCategoryModal({ kind, onClose, onCreated }: { kind: ItemKind; onClose: () => void; onCreated: (category: Category) => void }) {
  const t = useTranslations("catalog.new");
  const tcat = useTranslations("catalog");
  const errorText = useErrorText();
  const create = useCreateCategory();
  const idempotency = useIdempotencyKey();
  const headingId = useId();
  const form = useRef<HTMLFormElement>(null);
  const [nameEn, setNameEn] = useState("");
  const [nameAm, setNameAm] = useState("");
  const [checks, setChecks] = useState<{ name_en?: string; name_am?: string }>({});

  useEffect(() => {
    form.current?.querySelector("input")?.focus();
  }, []);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const found: { name_en?: string; name_am?: string } = {};
    if (!nameEn.trim()) found.name_en = t("check_name_en");
    if (!nameAm.trim()) found.name_am = t("check_name_am");
    setChecks(found);
    if (found.name_en || found.name_am) return;
    const body: NewCategory = { kind, name_en: nameEn.trim(), name_am: nameAm.trim() };
    create.mutate(
      { body, idempotencyKey: idempotency.keyFor(body) },
      {
        onSuccess: (category) => {
          idempotency.done();
          onCreated(category);
        },
      },
    );
  }

  return (
    <Modal label={t("new_category")} onClose={onClose}>
      <form ref={form} onSubmit={onSubmit} noValidate aria-labelledby={headingId} className="flex flex-col gap-4">
        <div className="space-y-1">
          <h2 id={headingId} className="text-xl font-semibold text-neutral-900">
            {t("new_category")}
          </h2>
          <p className="text-[15px] text-neutral-600">{kind === "product" ? t("new_category_intro_product") : t("new_category_intro_ingredient")}</p>
        </div>
        <Field label={tcat("field_name_en")} error={checks.name_en ?? errorText.field(create.error, "name_en")}>
          {(props) => <TextInput {...props} controlSize="md" lang="en" required maxLength={120} autoComplete="off" value={nameEn} onChange={(e) => setNameEn(e.target.value)} />}
        </Field>
        <Field label={tcat("field_name_am")} error={checks.name_am ?? errorText.field(create.error, "name_am")}>
          {(props) => <TextInput {...props} controlSize="md" lang="am" required maxLength={120} autoComplete="off" value={nameAm} onChange={(e) => setNameAm(e.target.value)} />}
        </Field>
        {create.isError && !errorText.field(create.error, "name_en") && !errorText.field(create.error, "name_am") ? <Alert>{errorText.message(create.error)}</Alert> : null}
        <div className="flex flex-wrap justify-end gap-2.5">
          <Button type="button" variant="secondary" size="md" onClick={onClose}>
            {t("cancel")}
          </Button>
          <Button type="submit" size="md" loading={create.isPending}>
            {tcat("add_category")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
