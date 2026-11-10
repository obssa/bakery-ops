"use client";

import { useLocale, useTranslations } from "next-intl";
import { useItems, useUnits } from "@/lib/api/hooks";
import type { Locale, Recipe, RecipeSpec } from "@/lib/api/types";
import { compareDecimal, isQuantityText, trimDecimal } from "@/lib/decimal";
import { Button, Field, Select, TextInput } from "../ui";

// A recipe's ingredient lines as a form (phase-6/new-item-recipe): one row per ingredient, its quantity per yield, the unit, the waste
// and whether it is optional, with "Add ingredient" and Remove. The product page's publish form (RecipePanels) and the new item page
// share it, so a recipe is written the same way in both places. Quantities stay decimal strings.

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
  controlSize,
}: {
  lines: DraftLine[];
  onLines: (update: (current: DraftLine[]) => DraftLine[]) => void;
  legend: string;
  error?: string;
  errorId?: string;
  disabled?: boolean;
  controlSize?: "md";
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
    <fieldset className="space-y-4" aria-invalid={error ? true : undefined} aria-describedby={error ? errorId : undefined}>
      <legend className="mb-2 text-base font-semibold text-neutral-900">{legend}</legend>
      {lines.map((line, index) => {
        const n = index + 1;
        return (
          <div key={line.key} className="grid items-end gap-3 rounded-md border border-neutral-200 p-3 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto_auto]">
            <Field label={t("field_ingredient", { n })}>
              {(props) => (
                <Select
                  {...props}
                  controlSize={controlSize}
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
              )}
            </Field>
            <Field label={t("field_qty", { n })}>
              {(props) => <TextInput {...props} controlSize={controlSize} disabled={disabled} inputMode="decimal" autoComplete="off" value={line.qty} onChange={(e) => change(line.key, { qty: e.target.value })} />}
            </Field>
            <Field label={t("field_unit", { n })}>
              {(props) => (
                <Select {...props} controlSize={controlSize} disabled={disabled} value={line.unit} onChange={(e) => change(line.key, { unit: e.target.value })}>
                  <option value="" disabled>
                    {t("choose_unit")}
                  </option>
                  {unitOptions}
                </Select>
              )}
            </Field>
            <Field label={t("field_waste", { n })}>
              {(props) => <TextInput {...props} controlSize={controlSize} disabled={disabled} inputMode="decimal" autoComplete="off" value={line.waste} onChange={(e) => change(line.key, { waste: e.target.value })} />}
            </Field>
            <label className="flex min-h-12 items-center gap-2 text-base text-neutral-800">
              <input type="checkbox" className="size-5" disabled={disabled} aria-label={t("field_optional", { n })} checked={line.optional} onChange={(e) => change(line.key, { optional: e.target.checked })} />
              {t("optional")}
            </label>
            <Button type="button" variant="secondary" size={controlSize} disabled={disabled || lines.length === 1} aria-label={t("remove_line", { n })} onClick={() => onLines((current) => current.filter((row) => row.key !== line.key))}>
              {t("remove")}
            </Button>
          </div>
        );
      })}
      {error ? (
        <p id={errorId} className="text-sm font-medium text-red-700">
          {error}
        </p>
      ) : null}
      <Button type="button" variant="secondary" size={controlSize} disabled={disabled} onClick={() => onLines((current) => [...current, blankLine()])}>
        {t("add_line")}
      </Button>
    </fieldset>
  );
}
