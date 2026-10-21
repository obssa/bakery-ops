"use client";

import { useLocale, useTranslations } from "next-intl";
import { useRef, useState } from "react";
import { requestTransfer } from "@/lib/api/production";
import { uuid, useIdempotencyKey } from "@/lib/api/idempotency";
import type { CardLine, CardPlace, Locale, ProductionRequest } from "@/lib/api/types";
import { isQuantityText, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { Alert, Button, Field, Modal, TextInput } from "../ui";

// The minimal transfer sheet of the request card: ask the store for what an ingredient is short by. It calls the same
// POST /transfers as the kitchen display's stock strip; step 2.8c builds that full sheet and may replace this one.

export function TransferSheet({
  request,
  line,
  place,
  onClose,
  onRequested,
}: {
  request: ProductionRequest;
  line: CardLine;
  place: CardPlace;
  onClose: () => void;
  onRequested: () => void;
}) {
  const t = useTranslations("production");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const keys = useIdempotencyKey();
  const clientUuid = useRef(uuid());
  const [qty, setQty] = useState(trimDecimal(line.short_qty ?? line.needed));
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [invalid, setInvalid] = useState(false);
  const [sent, setSent] = useState(false);

  const ingredient = locale === "am" ? line.ingredient.name_am : line.ingredient.name_en;
  const placeName = locale === "am" ? place.name_am : place.name_en;
  const hint = line.transfer_hint;

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!hint) return;
    const value = qty.trim();
    if (!isQuantityText(value) || value.replace(/[0.]/g, "") === "") {
      setInvalid(true);
      return;
    }
    setInvalid(false);
    setBusy(true);
    setError(null);
    const body = {
      client_uuid: clientUuid.current,
      item_id: line.ingredient.id,
      to_location_id: place.id,
      from_location_id: hint.from_location_id,
      qty: value,
      ...(note.trim() ? { note: note.trim() } : {}),
      production_request_id: request.id,
    };
    try {
      await requestTransfer(body, keys.keyFor(body));
      keys.done();
      setSent(true);
      onRequested();
    } catch (failure) {
      setError(failure);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal label={t("transfer_title", { name: ingredient })} onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{t("transfer_title", { name: ingredient })}</h2>
      {sent ? (
        <>
          <p role="status" className="rounded-md bg-green-50 px-4 py-3 text-base text-green-800">
            {t("transfer_sent")}
          </p>
          <Button type="button" onClick={onClose}>
            {t("close")}
          </Button>
        </>
      ) : (
        <form onSubmit={submit} className="space-y-4" noValidate>
          <p className="text-base text-neutral-700">{t("transfer_to", { place: placeName })}</p>
          {hint ? <p className="text-base text-neutral-700">{t("transfer_store_has", { qty: trimDecimal(hint.store_available), unit: line.ingredient.unit })}</p> : null}
          <Field label={t("transfer_qty", { unit: line.ingredient.unit })} error={invalid ? t("need_qty") : errorText.field(error, "qty")}>
            {(props) => <TextInput {...props} inputMode="decimal" value={qty} onChange={(event) => setQty(event.target.value)} />}
          </Field>
          <Field label={t("transfer_note")} optionalLabel={t("optional")}>
            {(props) => <TextInput {...props} value={note} maxLength={255} onChange={(event) => setNote(event.target.value)} />}
          </Field>
          {error ? <Alert>{errorText.message(error)}</Alert> : null}
          <div className="flex flex-wrap gap-3">
            <Button type="submit" disabled={busy || !hint}>
              {busy ? t("working") : t("transfer_send")}
            </Button>
            <Button type="button" variant="secondary" onClick={onClose}>
              {t("close")}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  );
}
