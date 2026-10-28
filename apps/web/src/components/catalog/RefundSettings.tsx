"use client";

import { useTranslations } from "next-intl";
import { useId, useState, type FormEvent } from "react";
import { useSaveRefundSettings } from "@/lib/api/catalog";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import type { Fulfilment, Item, ItemKind } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { refundSettingsBody, restockAllowed } from "@/lib/refund";
import { Alert, Button, Status } from "../ui";

// The admin's two refund settings of a product (step 4.5g, Ramin's rule R-C of 2026-10-28): whether it may be refunded at all, and whether
// a refunded unit goes back into stock. Only a product sold from stock or bought in can go back (the API refuses the rest with 422
// catalog.item.restock_not_stocked): for a product made to order (its ingredients are used) and for an ingredient the second toggle is
// greyed out, unticked, with the reason under it. Used by the new item form and by the product's page.

export function RefundToggles({
  kind,
  fulfilment,
  refundable,
  restock,
  disabled = false,
  onRefundable,
  onRestock,
}: {
  kind: ItemKind;
  fulfilment: Fulfilment;
  refundable: boolean;
  restock: boolean;
  disabled?: boolean;
  onRefundable: (value: boolean) => void;
  onRestock: (value: boolean) => void;
}) {
  const t = useTranslations("catalog");
  const ids = { refundable: useId(), refundableHint: useId(), restock: useId(), restockHint: useId() };
  const canRestock = restockAllowed(kind, fulfilment);
  const restockHint = canRestock ? t("restock_hint") : kind === "ingredient" ? t("restock_off_ingredient") : t("restock_off_to_order");
  return (
    <fieldset className="space-y-2">
      <legend className="mb-1 text-sm font-medium text-neutral-800">{t("col_refunds")}</legend>
      <label className="flex min-h-12 cursor-pointer items-start gap-3 rounded-md border border-neutral-300 px-4 py-3">
        <input
          type="checkbox"
          className="mt-0.5 h-5 w-5 shrink-0"
          checked={refundable}
          disabled={disabled}
          aria-labelledby={ids.refundable}
          aria-describedby={ids.refundableHint}
          onChange={(e) => onRefundable(e.target.checked)}
        />
        <span>
          <span id={ids.refundable} className="block text-base font-medium text-neutral-900">
            {t("field_refundable")}
          </span>
          <span id={ids.refundableHint} className="block text-sm text-neutral-600">
            {t("refundable_hint")}
          </span>
        </span>
      </label>
      <label className={`flex min-h-12 items-start gap-3 rounded-md border px-4 py-3 ${canRestock ? "cursor-pointer border-neutral-300" : "border-neutral-200 bg-neutral-50"}`}>
        <input
          type="checkbox"
          className="mt-0.5 h-5 w-5 shrink-0"
          checked={canRestock && restock}
          disabled={disabled || !canRestock}
          aria-labelledby={ids.restock}
          aria-describedby={ids.restockHint}
          onChange={(e) => onRestock(e.target.checked)}
        />
        <span>
          <span id={ids.restock} className={`block text-base font-medium ${canRestock ? "text-neutral-900" : "text-neutral-500"}`}>
            {t("field_restock")}
          </span>
          <span id={ids.restockHint} className="block text-sm text-neutral-600">
            {restockHint}
          </span>
        </span>
      </label>
    </fieldset>
  );
}

/** The two settings as the list shows them beside the other flags. */
export function useRefundSettingsText(): (item: Pick<Item, "refundable" | "refund_restock">) => string {
  const t = useTranslations("catalog");
  return (item) => (!item.refundable ? t("refunds_no") : item.refund_restock ? t("refunds_back") : t("refunds_not_back"));
}

/** The product page's panel: the two settings, saved with PATCH /items/{item} (catalog.manage); read-only for anyone else. */
export function RefundSettingsPanel({ product, canManage }: { product: Item; canManage: boolean }) {
  const t = useTranslations("catalog");
  const errorText = useErrorText();
  const headingId = useId();
  const save = useSaveRefundSettings(product.id);
  const idempotency = useIdempotencyKey();
  const [refundable, setRefundable] = useState(product.refundable);
  const [restock, setRestock] = useState(product.refund_restock);
  const [saved, setSaved] = useState(false);
  const body = refundSettingsBody(product.kind, product.fulfilment, refundable, restock);
  const changed = body.refundable !== product.refundable || body.refund_restock !== product.refund_restock;

  function onSubmit(event: FormEvent) {
    event.preventDefault();
    if (!canManage || save.isPending) return;
    setSaved(false);
    save.mutate(
      { body, idempotencyKey: idempotency.keyFor({ id: product.id, ...body }) },
      {
        onSuccess: (item) => {
          idempotency.done();
          setRefundable(item.refundable);
          setRestock(item.refund_restock);
          setSaved(true);
        },
      },
    );
  }

  return (
    <form aria-labelledby={headingId} onSubmit={onSubmit} className="mb-6 space-y-4 rounded-lg border border-neutral-200 bg-white p-5">
      <h2 id={headingId} className="text-lg font-semibold text-neutral-900">
        {t("refund_settings")}
      </h2>
      <p className="text-base text-neutral-700">{t("refund_settings_intro")}</p>
      <RefundToggles
        kind={product.kind}
        fulfilment={product.fulfilment}
        refundable={refundable}
        restock={restock}
        disabled={!canManage || save.isPending}
        onRefundable={(value) => {
          setRefundable(value);
          setSaved(false);
        }}
        onRestock={(value) => {
          setRestock(value);
          setSaved(false);
        }}
      />
      {/* the server's own words when it refuses (catalog.item.restock_not_stocked, a validation error) */}
      {save.isError ? <Alert>{errorText.message(save.error)}</Alert> : null}
      <Status>{saved ? t("refund_saved") : null}</Status>
      {canManage ? (
        <Button type="submit" disabled={!changed || save.isPending}>
          {save.isPending ? t("refund_saving") : t("refund_save")}
        </Button>
      ) : (
        <p className="text-sm text-neutral-600">{t("refund_read_only")}</p>
      )}
    </form>
  );
}
