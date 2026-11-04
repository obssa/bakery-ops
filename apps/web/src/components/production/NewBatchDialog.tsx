"use client";

import { useLocale, useTranslations } from "next-intl";
import { useMemo, useRef, useState } from "react";
import { useItems } from "@/lib/api/hooks";
import { uuid, useIdempotencyKey } from "@/lib/api/idempotency";
import { requestProduction } from "@/lib/api/production";
import type { Locale } from "@/lib/api/types";
import { isQuantityText, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { Alert, Button, Field, Modal, Select, TextInput } from "../ui";

// "New batch" in the board's head (the Production board, step 6.3c): ask for a product to be made, by hand, as the till's display-case
// panel does, with the quantity (the product's usual batch when it is left empty) and a note for the baker. POST /production/requests
// (production.request) with its client UUID and one Idempotency-Key, both reused by a retry (ADR 0003); the request lands in Requested.
// The products offered are the ones a production station makes (to stock), at the station the board shows when it shows one.

export function NewBatchDialog({ stationId, onClose, onAsked }: { stationId: number | "all"; onClose: () => void; onAsked: () => void }) {
  const t = useTranslations("production");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const items = useItems("product");
  const keys = useIdempotencyKey();
  const clientUuid = useRef(uuid());

  const [productId, setProductId] = useState("");
  const [qty, setQty] = useState("");
  const [note, setNote] = useState("");
  const [invalid, setInvalid] = useState<"product" | "qty" | null>(null);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<unknown>(null);

  const products = useMemo(
    () =>
      (items.data ?? [])
        .filter((item) => item.is_active && item.fulfilment === "to_stock" && item.production_station_id !== null && (stationId === "all" || item.production_station_id === stationId))
        .map((item) => ({ id: item.id, name: locale === "am" ? item.name_am || item.name_en : item.name_en, unit: item.base_unit_code ?? item.unit_code, batch: item.default_batch_qty }))
        .sort((a, b) => a.name.localeCompare(b.name)),
    [items.data, stationId, locale],
  );
  const chosen = products.find((product) => String(product.id) === productId) ?? null;

  async function ask() {
    if (!chosen) return setInvalid("product");
    const amount = qty.trim();
    if (amount !== "" && !isQuantityText(amount)) return setInvalid("qty");
    setInvalid(null);
    const body = { client_uuid: clientUuid.current, product_item_id: chosen.id, ...(amount ? { qty: amount } : {}), ...(note.trim() ? { note: note.trim() } : {}) };
    setBusy(true);
    setFailure(null);
    try {
      await requestProduction(body, keys.keyFor(body));
      keys.done();
      onAsked();
      onClose();
    } catch (error) {
      setFailure(error);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal label={t("new_batch_title")} onClose={onClose}>
      <h2 className="text-xl font-semibold text-neutral-900">{t("new_batch_title")}</h2>
      <form
        noValidate
        className="space-y-4"
        onSubmit={(event) => {
          event.preventDefault();
          void ask();
        }}
      >
        {items.isSuccess && products.length === 0 ? <p className="text-[15px] text-neutral-600">{t("new_batch_none")}</p> : null}
        <Field label={t("new_batch_product")} error={invalid === "product" ? t("new_batch_need_product") : errorText.field(failure, "product_item_id")}>
          {(props) => (
            <Select {...props} controlSize="lg" value={productId} onChange={(event) => setProductId(event.target.value)}>
              <option value="">{t("new_batch_choose")}</option>
              {products.map((product) => (
                <option key={product.id} value={product.id}>
                  {product.name}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field
          label={chosen ? t("new_batch_qty_in", { unit: chosen.unit }) : t("new_batch_qty")}
          optionalLabel={t("optional")}
          hint={chosen?.batch ? t("new_batch_qty_hint", { qty: trimDecimal(chosen.batch), unit: chosen.unit }) : undefined}
          error={invalid === "qty" ? t("need_qty") : errorText.field(failure, "qty")}
        >
          {(props) => <TextInput {...props} controlSize="lg" inputMode="decimal" value={qty} onChange={(event) => setQty(event.target.value)} />}
        </Field>
        <Field label={t("new_batch_note")} optionalLabel={t("optional")} error={errorText.field(failure, "note")}>
          {(props) => <TextInput {...props} controlSize="lg" value={note} maxLength={500} onChange={(event) => setNote(event.target.value)} />}
        </Field>
        {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
        <div className="flex flex-wrap justify-end gap-3 pt-1">
          <Button type="button" variant="secondary" size="lg" onClick={onClose}>
            {t("new_batch_cancel")}
          </Button>
          <Button type="submit" size="lg" loading={busy}>
            {t("new_batch_send")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
