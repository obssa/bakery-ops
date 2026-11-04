"use client";

import { useTranslations } from "next-intl";
import { useId } from "react";
import type { Fulfilment, Item, ItemKind } from "@/lib/api/types";
import { restockAllowed } from "@/lib/refund";
import { focusRing } from "../styles";

// The admin's two refund settings of a product (step 4.5g, Ramin's rule R-C of 2026-10-28): whether it may be refunded at all, and whether
// a refunded unit goes back into stock. Only a product sold from stock or bought in can go back (the API refuses the rest with 422
// catalog.item.restock_not_stocked): for a product made to order (its ingredients are used) and for an ingredient the second toggle is
// greyed out, unticked, with the reason under it. Used by the new item form and by the product's page.
//
// Step 6.4b (the ProductPhoto board's Refunds card): each setting is a row, its name (15 / 600) over its hint (13, neutral-600), with a
// 52 x 32 switch at the right, brand teal when on. The switch is the same checkbox as before, drawn as a track and a knob, so its role,
// name ("Refundable", "Goes back to stock when refunded") and description are what the specs and screen readers have always had, and a tap
// anywhere on the row toggles it.

function ToggleRow({
  label,
  hint,
  checked,
  disabled,
  dimmed = false,
  onChange,
}: {
  label: string;
  hint: string;
  checked: boolean;
  disabled: boolean;
  dimmed?: boolean;
  onChange: (value: boolean) => void;
}) {
  const ids = { label: useId(), hint: useId() };
  return (
    <label className={`flex items-center justify-between gap-4 border-b border-neutral-100 py-3 last:border-b-0 ${disabled ? "cursor-not-allowed" : "cursor-pointer"}`}>
      <span className="flex min-w-0 flex-col gap-0.5">
        <span id={ids.label} className={`text-[15px] font-semibold ${dimmed ? "text-neutral-600" : "text-neutral-900"}`}>
          {label}
        </span>
        <span id={ids.hint} className="text-[13px] text-neutral-600">
          {hint}
        </span>
      </span>
      <span className="relative inline-flex h-8 w-13 shrink-0">
        <input
          type="checkbox"
          className={`peer absolute inset-0 m-0 size-full cursor-pointer appearance-none rounded-full bg-neutral-300 checked:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50 motion-safe:transition-colors motion-safe:duration-150 ${focusRing}`}
          checked={checked}
          disabled={disabled}
          aria-labelledby={ids.label}
          aria-describedby={ids.hint}
          onChange={(e) => onChange(e.target.checked)}
        />
        <span
          aria-hidden="true"
          className="pointer-events-none absolute left-1 top-1 size-6 rounded-full bg-white shadow-xs peer-checked:translate-x-5 peer-disabled:opacity-80 motion-safe:transition-transform motion-safe:duration-150"
        />
      </span>
    </label>
  );
}

export function RefundToggles({
  kind,
  fulfilment,
  refundable,
  restock,
  disabled = false,
  legend = "visible",
  onRefundable,
  onRestock,
}: {
  kind: ItemKind;
  fulfilment: Fulfilment;
  refundable: boolean;
  restock: boolean;
  disabled?: boolean;
  /** "hidden" where a card's title already says "Refunds" (the product page) */
  legend?: "visible" | "hidden";
  onRefundable: (value: boolean) => void;
  onRestock: (value: boolean) => void;
}) {
  const t = useTranslations("catalog");
  const canRestock = restockAllowed(kind, fulfilment);
  const restockHint = canRestock ? t("restock_hint") : kind === "ingredient" ? t("restock_off_ingredient") : t("restock_off_to_order");
  return (
    <fieldset>
      <legend className={legend === "hidden" ? "sr-only" : "mb-1 text-sm font-semibold text-neutral-900"}>{t("col_refunds")}</legend>
      <ToggleRow label={t("field_refundable")} hint={t("refundable_hint")} checked={refundable} disabled={disabled} onChange={onRefundable} />
      <ToggleRow label={t("field_restock")} hint={restockHint} checked={canRestock && restock} disabled={disabled || !canRestock} dimmed={!canRestock} onChange={onRestock} />
    </fieldset>
  );
}

/** The two settings as the list shows them beside the other flags. */
export function useRefundSettingsText(): (item: Pick<Item, "refundable" | "refund_restock">) => string {
  const t = useTranslations("catalog");
  return (item) => (!item.refundable ? t("refunds_no") : item.refund_restock ? t("refunds_back") : t("refunds_not_back"));
}
