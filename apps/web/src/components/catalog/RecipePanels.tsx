"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { CookingPot, Plus, Trash2 } from "lucide-react";
import { useId, useMemo, useState, type FormEvent, type ReactNode } from "react";
import { useItemPrices, usePublishRecipe, useRecipeCost, useRecipeVersions } from "@/lib/api/catalog";
import { useItems, useUnits } from "@/lib/api/hooks";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import type { DecimalString, Item, Locale, Recipe, RecipeCost, RecipeSpec } from "@/lib/api/types";
import { compareDecimal, isQuantityText, scaleDecimal, subtractDecimal, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { authed, can, useSession } from "@/lib/session";
import { useDateFormat } from "@/lib/useDates";
import { formatWhen } from "../production/RequestCard";
import { focusRing } from "../styles";
import { Alert, Badge, Button, Field, IconButton, Panel, Select, Status, TextInput } from "../ui";
import { checkLines, draftKey, linesFrom, RecipeLinesEditor, specLines, type DraftLine } from "./RecipeLinesEditor";

// A product's recipe on its page (Phase 2, step 2.8d; behind "Recipe and cost" since step 6.4b, a drawer from the title row, because the
// ProductPhoto board draws the page as its details, refunds and photo): the price with the cost and margin of the version being looked at
// (only for people holding costs.view; an incomplete roll-up names what is missing and shows no number), the version history of the recipe,
// any version read in full, a form that publishes the next version with a change note above a scaled preview, and a cost comparison
// between two versions. Money and quantities stay decimal strings throughout.

function pick(locale: Locale, row: { name_en: string; name_am: string }): string {
  return locale === "am" ? row.name_am : row.name_en;
}

function useRecipeAt(id: number, version: number | null) {
  const signedIn = useSession((s) => s.status === "signed-in");
  return useQuery({
    queryKey: ["recipe-version", id, version],
    queryFn: () => authed<Recipe>(`/items/${id}/recipe/versions/${version}`),
    enabled: signedIn && version !== null,
    placeholderData: keepPreviousData,
  });
}

/** A recipe as typed (the body of a write) shaped as the publish form reads a version, to start the form from it. */
function draftAsRecipe(product: Item, spec: RecipeSpec): Recipe {
  return {
    id: null,
    product_item_id: product.id,
    version: null,
    is_active: false,
    published_at: null,
    published_by: null,
    change_note: null,
    yield_qty: spec.yield_qty,
    yield_unit_id: spec.yield_unit_id,
    yield_unit_code: null,
    base_unit_code: "",
    lines: spec.lines.map((line, index) => ({ id: -1 - index, ingredient_item_id: line.ingredient_item_id, qty: line.qty, unit_id: line.unit_id, unit_code: "", qty_base: line.qty, base_unit_code: "", is_optional: line.is_optional, waste_pct: line.waste_pct })),
    steps: spec.steps.map((step, index) => ({ id: -1 - index, step_order: index + 1, text_en: step.text_en, text_am: step.text_am })),
  };
}

/**
 * The recipe of a product, or why it has none (an ingredient, a product bought in). `draft`: a recipe the new item page could not save,
 * which the publish form starts from (phase-6/new-item-recipe); `onPublished` tells the page once a version is saved.
 */
export function RecipePanels({ product, draft = null, onPublished, onSetPrice }: { product: Item; draft?: RecipeSpec | null; onPublished?: () => void; onSetPrice?: () => void }) {
  const t = useTranslations("recipe");
  if (product.kind !== "product") return <p className="text-base text-neutral-700">{t("not_a_product")}</p>;
  if (product.fulfilment === "bought_in") return <p className="text-base text-neutral-700">{t("bought_in")}</p>;
  return <Recipes product={product} draft={draft} onPublished={onPublished} onSetPrice={onSetPrice} />;
}

function Recipes({ product, draft, onPublished, onSetPrice }: { product: Item; draft: RecipeSpec | null; onPublished?: () => void; onSetPrice?: () => void }) {
  const tc = useTranslations("common");
  const errorText = useErrorText();
  const versions = useRecipeVersions(product.id);
  const [chosen, setChosen] = useState<number | null>(null);
  const [seed, setSeed] = useState<{ recipe: Recipe; key: string } | null>(() => (draft ? { recipe: draftAsRecipe(product, draft), key: "created" } : null));

  if (versions.isPending) return <p className="text-base text-neutral-600">{tc("loading")}</p>;
  if (versions.isError) {
    return (
      <div className="space-y-3">
        <Alert>{errorText.message(versions.error)}</Alert>
        <Button variant="secondary" onClick={() => void versions.refetch()}>
          {tc("retry")}
        </Button>
      </div>
    );
  }

  const rows = versions.data;
  const active = rows.find((row) => row.is_active) ?? rows[0] ?? null;
  // the version on screen: the one asked for, else the active one (a version that no longer exists falls back to it)
  const viewed = chosen !== null && rows.some((row) => row.version === chosen) ? chosen : (active?.version ?? null);

  return (
    <div className="space-y-6">
      <PriceAndCost product={product} version={viewed} onSetPrice={onSetPrice} />
      {rows.length > 0 ? <History rows={rows} viewed={viewed} onView={setChosen} /> : <NoRecipe product={product} />}
      {viewed !== null ? <VersionView product={product} version={viewed} onStartFrom={(recipe) => setSeed({ recipe, key: `from-${recipe.version}-${Date.now()}` })} /> : null}
      <PublishPanel
        product={product}
        activeVersion={active?.version ?? null}
        seed={seed}
        onPublished={() => {
          setSeed(null);
          setChosen(null);
          onPublished?.();
        }}
      />
      {active && viewed !== null ? <ComparePanel product={product} viewed={viewed} versions={rows.map((row) => row.version)} /> : null}
    </div>
  );
}

/** No version yet: what that means for this product (made to order, it cannot be sold), and that the form below saves the first one. */
function NoRecipe({ product }: { product: Item }) {
  const t = useTranslations("recipe");
  return (
    <div className="flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
      <span aria-hidden="true" className="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-white text-amber-800">
        <CookingPot className="size-5" strokeWidth={1.75} aria-hidden="true" />
      </span>
      <div className="min-w-0 space-y-1">
        <p className="text-base font-semibold text-neutral-900">{t("no_recipe_title")}</p>
        <p className="text-[15px] text-neutral-700">{product.fulfilment === "to_order" ? t("no_recipe_to_order") : t("no_recipe_to_stock")}</p>
      </div>
    </div>
  );
}

// --- price, cost and margin -------------------------------------------------------------------------------------

function PriceAndCost({ product, version, onSetPrice }: { product: Item; version: number | null; onSetPrice?: () => void }) {
  const t = useTranslations("recipe");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const me = useSession((s) => s.me);
  const showCosts = can(me, "costs.view");
  const prices = useItemPrices(product.id);
  const cost = useRecipeCost(product.id, version);
  const items = useItems(null);
  const names = useMemo(() => new Map((items.data ?? []).map((row) => [row.id, pick(locale, row)])), [items.data, locale]);
  const current = prices.data?.find((price) => price.is_current) ?? null;

  return (
    <Panel title={t("price_and_cost")}>
      <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Figure
          label={t("price")}
          value={prices.isPending ? tc("loading") : current ? `${current.price} ${current.currency}` : t("no_price")}
          action={
            !prices.isPending && !current && onSetPrice && can(me, "catalog.manage") ? (
              <button type="button" className={`rounded text-[15px] font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800 ${focusRing}`} onClick={onSetPrice}>
                {t("set_price")}
              </button>
            ) : null
          }
        />
        {showCosts && version !== null ? (
          cost.isPending ? (
            <Figure label={t("cost_per_unit")} value={tc("loading")} />
          ) : cost.isError ? null : cost.data.complete ? (
            <>
              <Figure label={t("cost_per_unit")} value={`${cost.data.cost_per_unit ?? ""} ${current?.currency ?? ""}`.trim()} note={t("cost_of_version", { version })} />
              <Figure label={t("margin")} value={cost.data.margin_amount !== null ? `${cost.data.margin_amount} ${current?.currency ?? ""}`.trim() : t("no_price")} />
              <Figure label={t("margin_pct")} value={cost.data.margin_pct !== null ? `${cost.data.margin_pct}%` : "—"} />
            </>
          ) : (
            <div className="sm:col-span-2 lg:col-span-3">
              <dt className="text-sm font-medium text-neutral-600">{t("cost_per_unit")}</dt>
              <dd className="space-y-1">
                <Badge tone="amber">{t("cost_incomplete")}</Badge>
                <span className="block text-base text-neutral-800">
                  {t("missing_items", { names: cost.data.missing_cost_item_ids.map((missing) => names.get(missing) ?? `#${missing}`).join(", ") })}
                </span>
              </dd>
            </div>
          )
        ) : null}
      </dl>
      {showCosts && cost.isError ? <Alert>{t("cost_unavailable")}</Alert> : null}
      {showCosts && cost.data && cost.data.lines.length > 0 ? <CostLines cost={cost.data} names={names} /> : null}
    </Panel>
  );
}

function Figure({ label, value, note, action }: { label: string; value: string; note?: string; action?: ReactNode }) {
  return (
    <div>
      <dt className="text-sm font-medium text-neutral-600">{label}</dt>
      <dd className="text-xl font-semibold text-neutral-900">{value}</dd>
      {note ? <dd className="text-sm text-neutral-600">{note}</dd> : null}
      {action ? <dd className="mt-1">{action}</dd> : null}
    </div>
  );
}

function CostLines({ cost, names }: { cost: RecipeCost; names: Map<number, string> }) {
  const t = useTranslations("recipe");
  const locale = useLocale() as Locale;
  return (
    <div className="mt-5 overflow-x-auto">
      <table className="w-full border-collapse text-left text-base">
        <caption className="sr-only">{t("cost_lines", { version: cost.version })}</caption>
        <thead>
          <tr className="border-b border-neutral-200 text-sm text-neutral-600">
            <th scope="col" className="py-2 pr-4 font-medium">{t("col_ingredient")}</th>
            <th scope="col" className="py-2 pr-4 font-medium">{t("col_qty")}</th>
            <th scope="col" className="py-2 pr-4 font-medium">{t("col_unit_cost")}</th>
            <th scope="col" className="py-2 font-medium">{t("col_line_cost")}</th>
          </tr>
        </thead>
        <tbody>
          {cost.lines.map((line) => (
            <tr key={line.ingredient.id} className="border-b border-neutral-100 last:border-0">
              <td className="py-2 pr-4">{(locale === "am" ? line.ingredient.name_am : line.ingredient.name_en) ?? names.get(line.ingredient.id) ?? `#${line.ingredient.id}`}</td>
              <td className="py-2 pr-4">{`${trimDecimal(line.qty_base)} ${line.unit}`}</td>
              <td className="py-2 pr-4">{line.unit_cost ?? <Badge tone="amber">{t("missing")}</Badge>}</td>
              <td className="py-2">{line.line_cost ?? "—"}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// --- the history and one version in full -------------------------------------------------------------------------

function History({ rows, viewed, onView }: { rows: { version: number; is_active: boolean; line_count: number; published_at: string | null; published_by: { name: string } | null; change_note: string | null }[]; viewed: number | null; onView: (version: number) => void }) {
  const dates = useDateFormat();
  const t = useTranslations("recipe");
  return (
    <Panel title={t("history")}>
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-left text-base">
          <caption className="sr-only">{t("history_caption")}</caption>
          <thead>
            <tr className="border-b border-neutral-200 text-sm text-neutral-600">
              <th scope="col" className="py-2 pr-4 font-medium">{t("col_version")}</th>
              <th scope="col" className="py-2 pr-4 font-medium">{t("col_published")}</th>
              <th scope="col" className="py-2 pr-4 font-medium">{t("col_by")}</th>
              <th scope="col" className="py-2 pr-4 font-medium">{t("col_note")}</th>
              <th scope="col" className="py-2 pr-4 font-medium">{t("col_status")}</th>
              <th scope="col" className="py-2 font-medium"><span className="sr-only">{t("col_actions")}</span></th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.version} className="border-b border-neutral-100 align-top last:border-0">
                <td className="py-3 pr-4 font-medium text-neutral-900">{row.version}</td>
                <td className="py-3 pr-4">{row.published_at ? formatWhen(row.published_at, dates) : "—"}</td>
                <td className="py-3 pr-4">{row.published_by?.name ?? "—"}</td>
                <td className="py-3 pr-4">{row.change_note ?? "—"}</td>
                <td className="py-3 pr-4">{row.is_active ? <Badge tone="green">{t("active")}</Badge> : <Badge>{t("older")}</Badge>}</td>
                <td className="py-3">
                  <Button variant="secondary" aria-pressed={row.version === viewed} onClick={() => onView(row.version)}>
                    {t("view_version", { version: row.version })}
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}

function VersionView({ product, version, onStartFrom }: { product: Item; version: number; onStartFrom: (recipe: Recipe) => void }) {
  const t = useTranslations("recipe");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const recipe = useRecipeAt(product.id, version);
  const items = useItems(null);
  const names = useMemo(() => new Map((items.data ?? []).map((row) => [row.id, pick(locale, row)])), [items.data, locale]);
  const manage = can(useSession((s) => s.me), "catalog.manage");

  return (
    <Panel title={t("version_title", { version })}>
      {recipe.isPending ? (
        <p className="text-base text-neutral-600">{tc("loading")}</p>
      ) : recipe.isError ? (
        <Alert>{errorText.message(recipe.error)}</Alert>
      ) : (
        <div className="space-y-4">
          <p className="text-base text-neutral-800">
            {t("yield_line", { qty: trimDecimal(recipe.data.yield_qty ?? "0"), unit: recipe.data.yield_unit_code ?? "" })}
            {recipe.data.change_note ? ` · ${recipe.data.change_note}` : ""}
          </p>
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-left text-base">
              <caption className="sr-only">{t("lines_caption", { version })}</caption>
              <thead>
                <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                  <th scope="col" className="py-2 pr-4 font-medium">{t("col_ingredient")}</th>
                  <th scope="col" className="py-2 pr-4 font-medium">{t("col_qty")}</th>
                  <th scope="col" className="py-2 pr-4 font-medium">{t("col_waste")}</th>
                  <th scope="col" className="py-2 font-medium">{t("col_optional")}</th>
                </tr>
              </thead>
              <tbody>
                {recipe.data.lines.map((line) => (
                  <tr key={line.id} className="border-b border-neutral-100 last:border-0">
                    <td className="py-2 pr-4">{names.get(line.ingredient_item_id) ?? `#${line.ingredient_item_id}`}</td>
                    <td className="py-2 pr-4">{`${trimDecimal(line.qty)} ${line.unit_code}`}</td>
                    <td className="py-2 pr-4">{`${trimDecimal(line.waste_pct)}%`}</td>
                    <td className="py-2">{line.is_optional ? t("yes") : t("no")}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {recipe.data.steps.length > 0 ? (
            <ol className="list-decimal space-y-1 pl-6 text-base text-neutral-800">
              {recipe.data.steps.map((step) => (
                <li key={step.id}>{locale === "am" ? step.text_am : step.text_en}</li>
              ))}
            </ol>
          ) : null}
          {manage ? (
            <Button variant="secondary" onClick={() => onStartFrom(recipe.data)}>
              {t("start_from", { version })}
            </Button>
          ) : null}
        </div>
      )}
    </Panel>
  );
}

// --- publish a new version, with the scaled preview ------------------------------------------------------------

interface DraftStep {
  key: number;
  en: string;
  am: string;
}

function PublishPanel({ product, activeVersion, seed, onPublished }: { product: Item; activeVersion: number | null; seed: { recipe: Recipe; key: string } | null; onPublished: () => void }) {
  const tc = useTranslations("common");
  const manage = can(useSession((s) => s.me), "catalog.manage");
  const active = useRecipeAt(product.id, activeVersion);
  if (!manage) return null;
  if (activeVersion !== null && !seed && active.isPending) return <p className="text-base text-neutral-600">{tc("loading")}</p>;
  const base = seed?.recipe ?? (activeVersion !== null ? (active.data ?? null) : null);
  return <PublishForm key={seed?.key ?? `active-${base?.version ?? "none"}`} product={product} base={base} first={activeVersion === null} onPublished={onPublished} />;
}

// a step: its number with the trash, then the two languages one under the other; with room, one row (number, English, Amharic, trash)
const STEP_ROW =
  "grid gap-3 p-3 grid-cols-[minmax(0,1fr)_2.75rem] [grid-template-areas:'no_del'_'en_en'_'am_am'] " +
  "@2xl:items-center @2xl:grid-cols-[2rem_minmax(0,1fr)_minmax(0,1fr)_2.75rem] @2xl:[grid-template-areas:'no_en_am_del']";

function PublishForm({ product, base, first, onPublished }: { product: Item; base: Recipe | null; first: boolean; onPublished: () => void }) {
  const t = useTranslations("recipe");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const units = useUnits();
  const items = useItems("ingredient");
  const publish = usePublishRecipe(product.id);
  const idempotency = useIdempotencyKey();

  const [yieldQty, setYieldQty] = useState(base?.yield_qty ? trimDecimal(base.yield_qty) : "1");
  const [yieldUnit, setYieldUnit] = useState(String(base?.yield_unit_id ?? product.unit_id));
  const [lines, setLines] = useState<DraftLine[]>(() => linesFrom(base));
  const [steps, setSteps] = useState<DraftStep[]>(() => (base?.steps ?? []).map((step) => ({ key: draftKey(), en: step.text_en, am: step.text_am })));
  const [note, setNote] = useState("");
  const [previewQty, setPreviewQty] = useState(() => (base?.yield_qty ? trimDecimal(base.yield_qty) : "1"));
  const [problem, setProblem] = useState<string | null>(null);
  const [saved, setSaved] = useState<string | null>(null);
  const yieldId = useId();

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(null);
    if (!isQuantityText(yieldQty) || compareDecimal(yieldQty, "0") <= 0) return setProblem(t("check_yield"));
    const linesProblem = checkLines(lines);
    if (linesProblem) return setProblem(t(linesProblem));
    const kept = steps.filter((step) => step.en.trim() || step.am.trim());
    if (kept.some((step) => !step.en.trim() || !step.am.trim())) return setProblem(t("check_steps"));
    setProblem(null);
    const body: RecipeSpec = {
      yield_qty: yieldQty.trim(),
      yield_unit_id: Number(yieldUnit),
      lines: specLines(lines),
      steps: kept.map((step) => ({ text_en: step.en.trim(), text_am: step.am.trim() })),
      ...(first ? {} : { change_note: note.trim() }),
    };
    if (!first && !note.trim()) delete body.change_note;
    publish.mutate(
      { body, idempotencyKey: idempotency.keyFor({ first, body }), first },
      {
        onSuccess: (recipe) => {
          idempotency.done();
          setNote("");
          setSaved(t("published", { version: recipe.version ?? 1 }));
          onPublished();
        },
      },
    );
  }

  const unitOptions = (units.data ?? []).map((unit) => (
    <option key={unit.id} value={unit.id}>
      {unit.code}
    </option>
  ));

  return (
    <div className="grid items-start gap-6">
      <Panel title={first ? t("first_title") : t("publish_title")}>
        <form onSubmit={onSubmit} className="space-y-5">
          {/* the yield as one sentence, "This recipe makes [1] [pcs]": the quantities below are for that much */}
          <div role="group" aria-labelledby={yieldId} aria-describedby={`${yieldId}-hint`} className="space-y-1.5">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
              <span id={yieldId} className="text-base font-semibold text-neutral-900">
                {t("yield_makes")}
              </span>
              <div className="flex items-center gap-2">
                <TextInput controlSize="md" aria-label={t("field_yield_qty")} inputMode="decimal" required autoComplete="off" className="w-24 tabular-nums" value={yieldQty} onChange={(e) => setYieldQty(e.target.value)} />
                <Select controlSize="md" aria-label={t("field_yield_unit")} className="w-28" value={yieldUnit} onChange={(e) => setYieldUnit(e.target.value)}>
                  {unitOptions}
                </Select>
              </div>
            </div>
            <p id={`${yieldId}-hint`} className="text-sm text-neutral-600">
              {t("yield_hint")}
            </p>
          </div>

          <RecipeLinesEditor lines={lines} onLines={setLines} legend={t("lines")} />

          <fieldset className="min-w-0 space-y-3">
            <legend className="mb-2 text-base font-semibold text-neutral-900">{t("steps")}</legend>
            {steps.length > 0 ? (
              <div className="@container">
                <ol className="divide-y divide-neutral-200 rounded-xl border border-neutral-200">
                  {steps.map((step, index) => {
                    const n = index + 1;
                    return (
                      <li key={step.key} className={STEP_ROW}>
                        <span aria-hidden="true" className="self-center text-[13px] font-semibold text-neutral-700 [grid-area:no] @2xl:text-center @2xl:text-[15px] @2xl:text-neutral-500">
                          <span className="@2xl:hidden">{t("step_n", { n })}</span>
                          <span className="hidden @2xl:inline">{n}</span>
                        </span>
                        <TextInput controlSize="md" aria-label={t("field_step_en", { n })} placeholder={t("step_en_placeholder")} lang="en" className="[grid-area:en]" value={step.en} onChange={(e) => setSteps((current) => current.map((row) => (row.key === step.key ? { ...row, en: e.target.value } : row)))} />
                        <TextInput controlSize="md" aria-label={t("field_step_am", { n })} placeholder={t("step_am_placeholder")} lang="am" className="[grid-area:am]" value={step.am} onChange={(e) => setSteps((current) => current.map((row) => (row.key === step.key ? { ...row, am: e.target.value } : row)))} />
                        <IconButton label={t("remove_step", { n })} size="md" className="group [grid-area:del]" onClick={() => setSteps((current) => current.filter((row) => row.key !== step.key))}>
                          <Trash2 className="size-[18px] text-neutral-500 group-hover:text-red-700" strokeWidth={1.75} aria-hidden="true" />
                        </IconButton>
                      </li>
                    );
                  })}
                </ol>
              </div>
            ) : (
              <p className="text-[15px] text-neutral-600">{t("steps_none")}</p>
            )}
            <Button type="button" variant="secondary" size="md" onClick={() => setSteps((current) => [...current, { key: draftKey(), en: "", am: "" }])}>
              <Plus className="size-[18px]" strokeWidth={2} aria-hidden="true" />
              {t("add_step")}
            </Button>
          </fieldset>

          {!first ? (
            <Field label={t("field_note")} optionalLabel={t("optional")} hint={t("note_hint")} error={errorText.field(publish.error, "change_note")}>
              {(props) => <TextInput {...props} maxLength={255} autoComplete="off" value={note} onChange={(e) => setNote(e.target.value)} />}
            </Field>
          ) : null}

          {problem ? <Alert>{problem}</Alert> : null}
          {publish.isError ? <Alert>{errorText.message(publish.error)}</Alert> : null}
          <Status>{saved}</Status>
          <Button type="submit" size="md" disabled={publish.isPending}>
            {publish.isPending ? tc("saving") : first ? t("save_first") : t("publish")}
          </Button>
        </form>
      </Panel>

      <ScaledPreview locale={locale} yieldQty={yieldQty} lines={lines} previewQty={previewQty} onQty={setPreviewQty} unitCode={(id) => (units.data ?? []).find((unit) => String(unit.id) === id)?.code ?? ""} nameOf={(id) => { const row = (items.data ?? []).find((candidate) => String(candidate.id) === id); return row ? pick(locale, row) : "—"; }} />
    </div>
  );
}

/**
 * The draft scaled to a quantity, computed here with exact decimal arithmetic. The API exposes no scaling for a recipe that is
 * not published yet, so this is labelled a preview; the production card shows the server's figures for a real request.
 */
function ScaledPreview({ locale, yieldQty, lines, previewQty, onQty, unitCode, nameOf }: { locale: Locale; yieldQty: string; lines: DraftLine[]; previewQty: string; onQty: (qty: string) => void; unitCode: (unitId: string) => string; nameOf: (id: string) => string }) {
  const t = useTranslations("recipe");
  const ready = isQuantityText(previewQty) && isQuantityText(yieldQty) && compareDecimal(yieldQty, "0") > 0;
  return (
    <Panel title={t("preview_title")}>
      <div className="space-y-4">
        <Field label={t("field_preview_qty")} error={previewQty && !ready ? t("check_preview") : undefined}>
          {(props) => <TextInput {...props} inputMode="decimal" autoComplete="off" value={previewQty} onChange={(e) => onQty(e.target.value)} />}
        </Field>
        <p className="text-sm text-neutral-600">{t("preview_note")}</p>
        {ready ? (
          <table className="w-full border-collapse text-left text-base">
            <caption className="sr-only">{t("preview_caption", { qty: previewQty })}</caption>
            <thead>
              <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                <th scope="col" className="py-2 pr-3 font-medium">{t("col_ingredient")}</th>
                <th scope="col" className="py-2 font-medium">{t("col_qty")}</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((line) => (
                <tr key={line.key} className="border-b border-neutral-100 last:border-0" lang={locale}>
                  <td className="py-2 pr-3">{line.ingredient ? nameOf(line.ingredient) : "—"}</td>
                  <td className="py-2">{isQuantityText(line.qty) ? `${trimDecimal(scaleDecimal(line.qty, previewQty, yieldQty))} ${unitCode(line.unit)}` : "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : null}
      </div>
    </Panel>
  );
}

// --- version against version -------------------------------------------------------------------------------------

function ComparePanel({ product, viewed, versions }: { product: Item; viewed: number; versions: number[] }) {
  const t = useTranslations("recipe");
  const me = useSession((s) => s.me);
  const [other, setOther] = useState<string>("");
  const otherVersion = other ? Number(other) : null;
  const here = useRecipeCost(product.id, viewed);
  const there = useRecipeCost(product.id, otherVersion);
  if (!can(me, "costs.view")) return null;

  const others = versions.filter((version) => version !== viewed);
  const figures = (cost: RecipeCost | undefined) => (cost?.complete ? { cost: cost.cost_per_unit, margin: cost.margin_amount, pct: cost.margin_pct } : null);
  const a = figures(there.data);
  const b = figures(here.data);
  const delta = (x: DecimalString | null | undefined, y: DecimalString | null | undefined, dp: number) => (x != null && y != null ? subtractDecimal(y, x, dp) : "—");

  return (
    <Panel title={t("compare_title")}>
      {others.length === 0 ? (
        <p className="text-base text-neutral-700">{t("compare_none")}</p>
      ) : (
        <div className="space-y-4">
          <div className="max-w-xs">
            <Field label={t("field_compare")}>
              {(props) => (
                <Select {...props} value={other} onChange={(e) => setOther(e.target.value)}>
                  <option value="">{t("choose_version")}</option>
                  {others.map((version) => (
                    <option key={version} value={version}>
                      {t("version_n", { version })}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          </div>
          {otherVersion !== null ? (
            <table className="w-full border-collapse text-left text-base">
              <caption className="sr-only">{t("compare_caption", { a: otherVersion, b: viewed })}</caption>
              <thead>
                <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                  <th scope="col" className="py-2 pr-4 font-medium"><span className="sr-only">{t("col_figure")}</span></th>
                  <th scope="col" className="py-2 pr-4 font-medium">{t("version_n", { version: otherVersion })}</th>
                  <th scope="col" className="py-2 pr-4 font-medium">{t("version_n", { version: viewed })}</th>
                  <th scope="col" className="py-2 font-medium">{t("col_change")}</th>
                </tr>
              </thead>
              <tbody>
                {[
                  { label: t("cost_per_unit"), x: a?.cost, y: b?.cost, dp: 4, unit: "" },
                  { label: t("margin"), x: a?.margin, y: b?.margin, dp: 4, unit: "" },
                  { label: t("margin_pct"), x: a?.pct, y: b?.pct, dp: 2, unit: "%" },
                ].map((row) => (
                  <tr key={row.label} className="border-b border-neutral-100 last:border-0">
                    <th scope="row" className="py-2 pr-4 font-medium text-neutral-800">{row.label}</th>
                    <td className="py-2 pr-4">{a ? `${row.x ?? "—"}${row.x ? row.unit : ""}` : there.isPending ? "…" : t("cost_incomplete")}</td>
                    <td className="py-2 pr-4">{b ? `${row.y ?? "—"}${row.y ? row.unit : ""}` : here.isPending ? "…" : t("cost_incomplete")}</td>
                    <td className="py-2">{a && b ? `${delta(row.x, row.y, row.dp)}${row.x && row.y ? row.unit : ""}` : "—"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : null}
        </div>
      )}
    </Panel>
  );
}
