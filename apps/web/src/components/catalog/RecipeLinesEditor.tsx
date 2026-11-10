"use client";

import { Plus, Trash2 } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useItems, useUnits } from "@/lib/api/hooks";
import type { Locale, Recipe, RecipeSpec } from "@/lib/api/types";
import { compareDecimal, isQuantityText, trimDecimal } from "@/lib/decimal";
import { Button, IconButton, Select, TextInput } from "../ui";

// A recipe's ingredient lines as a form (phase-6/new-item-recipe): one row per ingredient, its quantity per yield, the unit, the waste
// and whether it is optional, with "Add ingredient" and a trash to remove one. The product page's publish form (RecipePanels) and the new
// item page share it, so a recipe is written the same way in both places. Quantities stay decimal strings.
//
// phase-6/recipe-editor-layout: the lines are laid out by the room the editor has, not by the window (a container query), since the product
// page's drawer is narrow on a wide screen and the new item page wide. With room (42rem and up), a table: the column names once above the
// rows, one row per line divided by hairlines, the trash at the row's end. Without, each line is a small card: the ingredient with the trash
// beside it, then the quantity and the unit, then the waste and "Optional". The words above the controls are only drawn (aria-hidden): each
// control is named by its line ("Unit, line 1"), so a reader hears which line it is in, and a page's own "Unit" stays the only "Unit".

export interface DraftLine {
  key: number;
  ingredient: string;
  qty: string;
  unit: string;
  optional: boolean;
  waste: string;
}

let nextKey = 1;

export function draftKey(): number {
  return nextKey++;
}

export function blankLine(): DraftLine {
  return { key: draftKey(), ingredient: "", qty: "", unit: "", optional: false, waste: "0" };
}

export function linesFrom(recipe: Pick<Recipe, "lines"> | null): DraftLine[] {
  return recipe && recipe.lines.length > 0
    ? recipe.lines.map((line) => ({ key: draftKey(), ingredient: String(line.ingredient_item_id), qty: trimDecimal(line.qty), unit: String(line.unit_id), optional: line.is_optional, waste: trimDecimal(line.waste_pct) }))
    : [blankLine()];
}

/** A line nothing was typed into (no ingredient, no quantity). */
export function isBlank(line: DraftLine): boolean {
  return !line.ingredient && !line.qty.trim();
}

/** The message key (in "recipe") of the first problem of the lines, or null when every line can be sent. */
export function checkLines(lines: readonly DraftLine[]): "check_lines" | "check_waste" | null {
  for (const line of lines) {
    if (!line.ingredient || !line.unit) return "check_lines";
    if (!isQuantityText(line.qty) || compareDecimal(line.qty, "0") <= 0) return "check_lines";
    if (!isQuantityText(line.waste || "0", 2) || compareDecimal(line.waste || "0", "100", 2) >= 0) return "check_waste";
  }
  return null;
}

export function specLines(lines: readonly DraftLine[]): RecipeSpec["lines"] {
  return lines.map((line) => ({ ingredient_item_id: Number(line.ingredient), qty: line.qty.trim(), unit_id: Number(line.unit), is_optional: line.optional, waste_pct: (line.waste || "0").trim() }));
}

function pick(locale: Locale, row: { name_en: string; name_am: string }): string {
  return locale === "am" ? row.name_am : row.name_en;
}

// a card's three rows, or the table's one row (the header uses the same columns)
const ROW =
  "grid gap-3 grid-cols-[minmax(0,1fr)_minmax(0,1fr)_2.75rem] [grid-template-areas:'ing_ing_del'_'qty_unit_unit'_'waste_opt_opt'] " +
  "@2xl:items-center @2xl:grid-cols-[minmax(0,1fr)_5.5rem_6.25rem_5.5rem_5.5rem_2.75rem] @2xl:[grid-template-areas:'ing_qty_unit_waste_opt_del']";
const CAPTION = "mb-1.5 block text-[13px] font-semibold text-neutral-700 @2xl:hidden";

/**
 * The lines, as a fieldset named `legend`: each row's controls are named with the line's number ("Ingredient, line 1"), and choosing an
 * ingredient sets the unit to the ingredient's own. `error` shows under the rows and marks the fieldset invalid.
 */
export function RecipeLinesEditor({
  lines,
  onLines,
  legend,
  error,
  errorId,
  disabled = false,
}: {
  lines: DraftLine[];
  onLines: (update: (current: DraftLine[]) => DraftLine[]) => void;
  legend: string;
  error?: string;
  errorId?: string;
  disabled?: boolean;
}) {
  const t = useTranslations("recipe");
  const tc = useTranslations("common");
  const locale = useLocale() as Locale;
  const units = useUnits();
  const items = useItems("ingredient");

  function change(key: number, patch: Partial<DraftLine>) {
    onLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)));
  }

  const unitOptions = (units.data ?? []).map((unit) => (
    <option key={unit.id} value={unit.id}>
      {unit.code}
    </option>
  ));

  return (
    <fieldset className="min-w-0 space-y-3" aria-invalid={error ? true : undefined} aria-describedby={error ? errorId : undefined}>
      <legend className="mb-2 text-base font-semibold text-neutral-900">{legend}</legend>
      <div className="@container">
        <div className={`overflow-hidden rounded-xl border bg-white ${error ? "border-red-300" : "border-neutral-200"}`}>
          <div aria-hidden="true" className={`${ROW} hidden border-b border-neutral-200 bg-neutral-50 px-3 py-2 text-[13px] font-semibold text-neutral-600 @2xl:grid`}>
            <span>{t("col_ingredient")}</span>
            <span>{t("col_qty")}</span>
            <span>{t("col_unit")}</span>
            <span>{t("col_waste")}</span>
            <span className="text-center">{t("col_optional")}</span>
            <span />
          </div>
          <div className="divide-y divide-neutral-200">
            {lines.map((line, index) => {
              const n = index + 1;
              return (
                <div key={line.key} className={`${ROW} p-3 @2xl:py-2.5`}>
                  <div className="min-w-0 [grid-area:ing]">
                    <span aria-hidden="true" className={CAPTION}>
                      {t("line_n", { n })}
                    </span>
                    <Select
                      controlSize="md"
                      aria-label={t("field_ingredient", { n })}
                      disabled={disabled}
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
                  </div>
                  <div className="min-w-0 [grid-area:qty]">
                    <span aria-hidden="true" className={CAPTION}>
                      {t("col_qty")}
                    </span>
                    <TextInput
                      controlSize="md"
                      aria-label={t("field_qty", { n })}
                      disabled={disabled}
                      inputMode="decimal"
                      autoComplete="off"
                      placeholder="0"
                      className="tabular-nums"
                      value={line.qty}
                      onChange={(e) => change(line.key, { qty: e.target.value })}
                    />
                  </div>
                  <div className="min-w-0 [grid-area:unit]">
                    <span aria-hidden="true" className={CAPTION}>
                      {t("col_unit")}
                    </span>
                    <Select controlSize="md" aria-label={t("field_unit", { n })} disabled={disabled} value={line.unit} onChange={(e) => change(line.key, { unit: e.target.value })}>
                      <option value="" disabled>
                        {t("choose_unit")}
                      </option>
                      {unitOptions}
                    </Select>
                  </div>
                  <div className="min-w-0 [grid-area:waste]">
                    <span aria-hidden="true" className={CAPTION}>
                      {t("col_waste")}
                    </span>
                    <div className="relative">
                      <TextInput
                        controlSize="md"
                        aria-label={t("field_waste", { n })}
                        disabled={disabled}
                        inputMode="decimal"
                        autoComplete="off"
                        className="pr-8 tabular-nums"
                        value={line.waste}
                        onChange={(e) => change(line.key, { waste: e.target.value })}
                      />
                      <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[15px] text-neutral-500">
                        %
                      </span>
                    </div>
                  </div>
                  <label className="flex min-h-11 cursor-pointer items-center gap-2.5 self-end text-[15px] text-neutral-800 [grid-area:opt] @2xl:justify-center @2xl:self-center">
                    <input
                      type="checkbox"
                      className="size-5 shrink-0 cursor-pointer accent-brand-600 disabled:cursor-not-allowed"
                      disabled={disabled}
                      aria-label={t("field_optional", { n })}
                      checked={line.optional}
                      onChange={(e) => change(line.key, { optional: e.target.checked })}
                    />
                    <span aria-hidden="true" className="@2xl:hidden">
                      {t("col_optional")}
                    </span>
                  </label>
                  {/* with one line there is nothing to remove: the trash keeps its place, unseen, so the row does not shift */}
                  <IconButton
                    label={t("remove_line", { n })}
                    size="md"
                    disabled={disabled}
                    className={`group self-end [grid-area:del] @2xl:self-center ${lines.length === 1 ? "invisible" : ""}`}
                    onClick={() => onLines((current) => current.filter((row) => row.key !== line.key))}
                  >
                    <Trash2 className="size-[18px] text-neutral-500 group-hover:text-red-700" strokeWidth={1.75} aria-hidden="true" />
                  </IconButton>
                </div>
              );
            })}
          </div>
        </div>
      </div>
      {error ? (
        <p id={errorId} className="text-sm font-medium text-red-700">
          {error}
        </p>
      ) : null}
      <Button type="button" variant="secondary" size="md" disabled={disabled} onClick={() => onLines((current) => [...current, blankLine()])}>
        <Plus className="size-[18px]" strokeWidth={2} aria-hidden="true" />
        {t("add_line")}
      </Button>
    </fieldset>
  );
}
