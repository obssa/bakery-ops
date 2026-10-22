"use client";

import { useTranslations } from "next-intl";
import { useRef, useState } from "react";
import { requestTransfer } from "@/lib/api/production";
import { uuid, useIdempotencyKey } from "@/lib/api/idempotency";
import { isQuantityText, trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { Alert, Button, Field, Modal, TextInput } from "../ui";

// The one sheet that asks the store for an ingredient: from the kitchen display's stock strip (a low or out row) and from
// the production card (a short recipe line, which also names the request). The quantity is typed in the item's base unit,
// as every stock figure is, and checked as a decimal string before it is sent (never a number). POST /transfers takes the
// store as the source unless the card knows a better one (its transfer hint). The API's refusals (a station the person
// does not work at, a place that is the same, an item that is not stocked) show inline in the page language.

export interface TransferSubject {
  itemId: number;
  /** the item's name in the page language */
  name: string;
  /** the item's base unit code, which the quantity is in */
  unit: string;
  toLocationId: number;
  /** the name of the place that asks, in the page language */
  toName?: string;
  /** a source other than the store (the card's hint) */
  fromLocationId?: number;
  /** what the source holds, when known (the card's hint) */
  sourceAvailable?: string;
  /** what the quantity starts as (the card's shortage) */
  suggested?: string;
  /** the production request this transfer is for */
  productionRequestId?: number;
}

export function RequestTransferSheet({ subject, onClose, onRequested }: { subject: TransferSubject; onClose: () => void; onRequested?: () => void }) {
  const t = useTranslations("transfer");
  const errorText = useErrorText();
  const keys = useIdempotencyKey();
  const clientUuid = useRef(uuid());
  const [qty, setQty] = useState(subject.suggested ? trimDecimal(subject.suggested) : "");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [invalid, setInvalid] = useState(false);
  const [sent, setSent] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
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
      item_id: subject.itemId,
      to_location_id: subject.toLocationId,
      ...(subject.fromLocationId !== undefined ? { from_location_id: subject.fromLocationId } : {}),
      qty: value,
      ...(note.trim() ? { note: note.trim() } : {}),
      ...(subject.productionRequestId !== undefined ? { production_request_id: subject.productionRequestId } : {}),
    };
    try {
      await requestTransfer(body, keys.keyFor(body));
      keys.done();
      setSent(true);
      onRequested?.();
    } catch (failure) {
      setError(failure);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal label={t("title", { name: subject.name })} onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{t("title", { name: subject.name })}</h2>
      {sent ? (
        <>
          <p role="status" className="rounded-md bg-green-50 px-4 py-3 text-base text-green-800">
            {t("sent")}
          </p>
          <Button type="button" onClick={onClose}>
            {t("close")}
          </Button>
        </>
      ) : (
        <form onSubmit={submit} className="space-y-4" noValidate>
          {subject.toName ? <p className="text-base text-neutral-700">{t("to", { place: subject.toName })}</p> : null}
          {subject.sourceAvailable !== undefined ? <p className="text-base text-neutral-700">{t("source_has", { qty: trimDecimal(subject.sourceAvailable), unit: subject.unit })}</p> : null}
          <Field label={t("qty", { unit: subject.unit })} error={invalid ? t("need_qty") : errorText.field(error, "qty")}>
            {(props) => <TextInput {...props} inputMode="decimal" value={qty} onChange={(event) => setQty(event.target.value)} />}
          </Field>
          <Field label={t("note")} optionalLabel={t("optional")}>
            {(props) => <TextInput {...props} value={note} maxLength={255} onChange={(event) => setNote(event.target.value)} />}
          </Field>
          {error ? <Alert>{errorText.message(error)}</Alert> : null}
          <div className="flex flex-wrap gap-3">
            <Button type="submit" disabled={busy}>
              {busy ? t("working") : t("send")}
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
