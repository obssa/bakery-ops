"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useMemo, useState, type FormEvent } from "react";
import { Link } from "@/i18n/navigation";
import { useItem, useItemPrices, usePublishRecipe, useRecipeCost, useRecipeVersions } from "@/lib/api/catalog";
import { useItems, useUnits } from "@/lib/api/hooks";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import type { DecimalString, Item, Locale, Recipe, RecipeCost, RecipeSpec } from "@/lib/api/types";
import { compareDecimal, isQuantityText, scaleDecimal, subtractDecimal, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { authed, can, useSession } from "@/lib/session";
import { formatWhen } from "../production/RequestCard";
import { focusRing } from "../styles";
import { Alert, Badge, Button, Field, PageHeader, Panel, Select, Status, TextInput } from "../ui";

// Admin > Catalog > one product (Phase 2, step 2.8d): the price with the cost and margin of the version being looked at
// (only for people holding costs.view; an incomplete roll-up names what is missing and shows no number), the version
// history of the recipe, any version read in full, a form that publishes the next version with a change note beside a
// scaled preview, and a cost comparison between two versions. Money and quantities stay decimal strings throughout.

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

export function ItemPage({ id }: { id: number }) {
  const t = useTranslations("recipe");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const item = useItem(id);

  const back = (
    <Link href="/admin/catalog" className={`mb-4 inline-flex min-h-12 items-center rounded-md text-base text-blue-800 underline ${focusRing}`}>
      {t("back")}
    </Link>
  );

  if (item.isPending) {
    return (
      <>
        {back}
        <p className="text-base text-neutral-600">{tc("loading")}</p>
      </>
    );
  }
  if (item.isError) {
    return (
      <>
        {back}
        <div className="space-y-3">
          <Alert>{errorText.message(item.error)}</Alert>
          <Button variant="secondary" onClick={() => void item.refetch()}>
            {tc("retry")}
          </Button>
        </div>
      </>
    );
  }

  const product = item.data;
  return (
    <>
      {back}
      <PageHeader title={pick(locale, product)} intro={t("intro")} />
      {product.kind !== "product" ? (
        <p className="text-base text-neutral-700">{t("not_a_product")}</p>
      ) : product.fulfilment === "bought_in" ? (
        <p className="text-base text-neutral-700">{t("bought_in")}</p>
      ) : (
        <Recipes product={product} />
      )}
    </>
  );
}

function Recipes({ product }: { product: Item }) {
  const t = useTranslations("recipe");
  const tc = useTranslations("common");
  const errorText = useErrorText();
  const versions = useRecipeVersions(product.id);
  const [chosen, setChosen] = useState<number | null>(null);
  const [seed, setSeed] = useState<{ recipe: Recipe; key: string } | null>(null);

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
      <PriceAndCost product={product} version={viewed} />
      {rows.length > 0 ? <History rows={rows} viewed={viewed} onView={setChosen} /> : <p className="text-base text-neutral-700">{t("no_recipe")}</p>}
      {viewed !== null ? <VersionView product={product} version={viewed} onStartFrom={(recipe) => setSeed({ recipe, key: `from-${recipe.version}-${Date.now()}` })} /> : null}
      <PublishPanel
        product={product}
        activeVersion={active?.version ?? null}
        seed={seed}
        onPublished={() => {
          setSeed(null);
          setChosen(null);
        }}
      />
      {active && viewed !== null ? <ComparePanel product={product} viewed={viewed} versions={rows.map((row) => row.version)} /> : null}
    </div>
  );
}

// --- price, cost and margin -------------------------------------------------------------------------------------

function PriceAndCost({ product, version }: { product: Item; version: number | null }) {
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
        <Figure label={t("price")} value={prices.isPending ? tc("loading") : current ? `${current.price} ${current.currency}` : t("no_price")} />
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

function Figure({ label, value, note }: { label: string; value: string; note?: string }) {
  return (
    <div>
      <dt className="text-sm font-medium text-neutral-600">{label}</dt>
      <dd className="text-xl font-semibold text-neutral-900">{value}</dd>
      {note ? <dd className="text-sm text-neutral-600">{note}</dd> : null}
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
  const t = useTranslations("recipe");
  const locale = useLocale() as Locale;
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
                <td className="py-3 pr-4">{row.published_at ? formatWhen(row.published_at, locale) : "—"}</td>
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

interface DraftLine {
  key: number;
  ingredient: string;
  qty: string;
  unit: string;
  optional: boolean;
  waste: string;
}

interface DraftStep {
  key: number;
  en: string;
  am: string;
}

let nextKey = 1;

function PublishPanel({ product, activeVersion, seed, onPublished }: { product: Item; activeVersion: number | null; seed: { recipe: Recipe; key: string } | null; onPublished: () => void }) {
  const tc = useTranslations("common");
  const manage = can(useSession((s) => s.me), "catalog.manage");
  const active = useRecipeAt(product.id, activeVersion);
  if (!manage) return null;
  if (activeVersion !== null && !seed && active.isPending) return <p className="text-base text-neutral-600">{tc("loading")}</p>;
  const base = seed?.recipe ?? (activeVersion !== null ? (active.data ?? null) : null);
  return <PublishForm key={seed?.key ?? `active-${base?.version ?? "none"}`} product={product} base={base} first={activeVersion === null} onPublished={onPublished} />;
}

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
  const [lines, setLines] = useState<DraftLine[]>(() =>
    base && base.lines.length > 0
      ? base.lines.map((line) => ({ key: nextKey++, ingredient: String(line.ingredient_item_id), qty: trimDecimal(line.qty), unit: String(line.unit_id), optional: line.is_optional, waste: trimDecimal(line.waste_pct) }))
      : [{ key: nextKey++, ingredient: "", qty: "", unit: "", optional: false, waste: "0" }],
  );
  const [steps, setSteps] = useState<DraftStep[]>(() => (base?.steps ?? []).map((step) => ({ key: nextKey++, en: step.text_en, am: step.text_am })));
  const [note, setNote] = useState("");
  const [previewQty, setPreviewQty] = useState(() => (base?.yield_qty ? trimDecimal(base.yield_qty) : "1"));
  const [problem, setProblem] = useState<string | null>(null);
  const [saved, setSaved] = useState<string | null>(null);

  function change(key: number, patch: Partial<DraftLine>) {
    setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)));
  }

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(null);
    if (!isQuantityText(yieldQty) || compareDecimal(yieldQty, "0") <= 0) return setProblem(t("check_yield"));
    for (const line of lines) {
      if (!line.ingredient || !line.unit) return setProblem(t("check_lines"));
      if (!isQuantityText(line.qty) || compareDecimal(line.qty, "0") <= 0) return setProblem(t("check_lines"));
      if (!isQuantityText(line.waste || "0", 2) || compareDecimal(line.waste || "0", "100", 2) >= 0) return setProblem(t("check_waste"));
    }
    const kept = steps.filter((step) => step.en.trim() || step.am.trim());
    if (kept.some((step) => !step.en.trim() || !step.am.trim())) return setProblem(t("check_steps"));
    setProblem(null);
    const body: RecipeSpec = {
      yield_qty: yieldQty.trim(),
      yield_unit_id: Number(yieldUnit),
      lines: lines.map((line) => ({ ingredient_item_id: Number(line.ingredient), qty: line.qty.trim(), unit_id: Number(line.unit), is_optional: line.optional, waste_pct: (line.waste || "0").trim() })),
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
    <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
      <Panel title={first ? t("first_title") : t("publish_title")}>
        <form onSubmit={onSubmit} className="space-y-5">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t("field_yield_qty")}>{(props) => <TextInput {...props} inputMode="decimal" required autoComplete="off" value={yieldQty} onChange={(e) => setYieldQty(e.target.value)} />}</Field>
            <Field label={t("field_yield_unit")}>
              {(props) => (
                <Select {...props} value={yieldUnit} onChange={(e) => setYieldUnit(e.target.value)}>
                  {unitOptions}
                </Select>
              )}
            </Field>
          </div>

          <fieldset className="space-y-4">
            <legend className="mb-2 text-base font-semibold text-neutral-900">{t("lines")}</legend>
            {lines.map((line, index) => {
              const n = index + 1;
              return (
                <div key={line.key} className="grid items-end gap-3 rounded-md border border-neutral-200 p-3 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto_auto]">
                  <Field label={t("field_ingredient", { n })}>
                    {(props) => (
                      <Select
                        {...props}
                        value={line.ingredient}
                        onChange={(e) => {
                          const picked = (items.data ?? []).find((row) => String(row.id) === e.target.value);
                          change(line.key, { ingredient: e.target.value, unit: picked ? String(picked.unit_id) : line.unit });
                        }}
                      >
                        <option value="" disabled>
                          {items.isPending ? tc("loading") : t("choose_ingredient")}
                        </option>
                        {(items.data ?? []).map((row) => (
                          <option key={row.id} value={row.id}>
                            {pick(locale, row)}
                          </option>
                        ))}
                      </Select>
                    )}
                  </Field>
                  <Field label={t("field_qty", { n })}>{(props) => <TextInput {...props} inputMode="decimal" autoComplete="off" value={line.qty} onChange={(e) => change(line.key, { qty: e.target.value })} />}</Field>
                  <Field label={t("field_unit", { n })}>
                    {(props) => (
                      <Select {...props} value={line.unit} onChange={(e) => change(line.key, { unit: e.target.value })}>
                        <option value="" disabled>
                          {t("choose_unit")}
                        </option>
                        {unitOptions}
                      </Select>
                    )}
                  </Field>
                  <Field label={t("field_waste", { n })}>{(props) => <TextInput {...props} inputMode="decimal" autoComplete="off" value={line.waste} onChange={(e) => change(line.key, { waste: e.target.value })} />}</Field>
                  <label className="flex min-h-12 items-center gap-2 text-base text-neutral-800">
                    <input type="checkbox" className="size-5" aria-label={t("field_optional", { n })} checked={line.optional} onChange={(e) => change(line.key, { optional: e.target.checked })} />
                    {t("optional")}
                  </label>
                  <Button type="button" variant="secondary" aria-label={t("remove_line", { n })} disabled={lines.length === 1} onClick={() => setLines((current) => current.filter((row) => row.key !== line.key))}>
                    {t("remove")}
                  </Button>
                </div>
              );
            })}
            <Button type="button" variant="secondary" onClick={() => setLines((current) => [...current, { key: nextKey++, ingredient: "", qty: "", unit: "", optional: false, waste: "0" }])}>
              {t("add_line")}
            </Button>
          </fieldset>

          <fieldset className="space-y-4">
            <legend className="mb-2 text-base font-semibold text-neutral-900">{t("steps")}</legend>
            {steps.map((step, index) => {
              const n = index + 1;
              return (
                <div key={step.key} className="grid items-end gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                  <Field label={t("field_step_en", { n })}>{(props) => <TextInput {...props} lang="en" value={step.en} onChange={(e) => setSteps((current) => current.map((row) => (row.key === step.key ? { ...row, en: e.target.value } : row)))} />}</Field>
                  <Field label={t("field_step_am", { n })}>{(props) => <TextInput {...props} lang="am" value={step.am} onChange={(e) => setSteps((current) => current.map((row) => (row.key === step.key ? { ...row, am: e.target.value } : row)))} />}</Field>
                  <Button type="button" variant="secondary" aria-label={t("remove_step", { n })} onClick={() => setSteps((current) => current.filter((row) => row.key !== step.key))}>
                    {t("remove")}
                  </Button>
                </div>
              );
            })}
            <Button type="button" variant="secondary" onClick={() => setSteps((current) => [...current, { key: nextKey++, en: "", am: "" }])}>
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
          <Button type="submit" disabled={publish.isPending}>
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
