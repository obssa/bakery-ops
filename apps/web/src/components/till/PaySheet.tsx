"use client";

import { useLocale, useTranslations } from "next-intl";
import { useEffect, useId, useMemo, useRef, useState } from "react";
import { ApiError } from "@/lib/api/client";
import { uuid } from "@/lib/api/idempotency";
import { recordPaymentBatch, uploadReceiptPhoto, usePaymentChannels } from "@/lib/api/till";
import type { Locale, Order, PaymentBatchResult } from "@/lib/api/types";
import { addDecimal, compareDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { downscale } from "@/lib/images/downscale";
import { useOrderNo } from "@/lib/orderNo";
import { forgetPicture, keepPicture, pictureOf, usePayDrafts } from "@/lib/payDrafts";
import { CASH } from "@/lib/payment";
import { batchRows, blockReason, keepsKey, MAX_ROWS, newRow, NO_PHOTO, remaining, sheetChannels, type Block, type PayRow } from "@/lib/paySheet";
import type { PayBlock } from "@/lib/shifts";
import { useOnline } from "@/lib/useOnline";
import { Alert, Button, Modal } from "../ui";
import { PayRowEditor, type PhotoFailure } from "./PayRowEditor";
import { ReceiptPanel } from "./ReceiptPanel";

// The till's pay sheet (step 4.5b, docs/phases/phase-4-plan.md section 6 item 5): the amount due and what is left to cover at the top, then
// one row per payment (PayRowEditor), so a bill can be split over channels down to exactly the balance. Complete stays disabled until every
// row is complete (each photo uploaded, each reference valid), the amounts add up to the balance, and the till's shift is running where the
// shift rule is on (every channel since step 4.3b); the reason is always shown. Complete posts POST /orders/{order}/payment-batches once,
// with the batch's client_uuid, each row's own client_uuid and one Idempotency-Key: all the payments are recorded or none is.
//
// A photo is shrunk in the browser (lib/images/downscale.ts) and uploaded as soon as it is taken (POST /files, its own client_uuid and key,
// both reused by a retry), so Complete never waits for an upload. Nothing is lost on a failure: the sheet is kept per order while the page
// lives (lib/payDrafts.ts), with the photos already on the server, and a retry of the same sheet replays the batch the API recorded instead of
// taking the money twice. A refusal (a row, the sum, the shift) wrote nothing: it is shown, the row it names is marked, and the next try is a
// new request. Offline is said before trying; the outbox that queues a payment for later is Phase 5. The rules are lib/paySheet.ts.

function issueText(t: ReturnType<typeof useTranslations>, block: Block, currency: string): string {
  switch (block.kind) {
    case "shift":
      return t(block.reason === "no_shift" ? "pay_blocked_no_shift" : "pay_blocked_not_open");
    case "offline":
      return t("block_offline");
    case "nothing_due":
      return t("block_nothing_due");
    case "no_rows":
      return t("block_no_rows");
    case "too_many_rows":
      return t("block_too_many_rows", { max: MAX_ROWS });
    case "row":
      return t("block_row", { n: block.row + 1, issue: t(`issue_${block.issue}`) });
    case "short":
      return t("block_short", { amount: block.amount, currency });
    case "over":
      return t("block_over", { amount: block.amount, currency });
  }
}

/** The bilingual body of a row's own refusal inside payments.batch.row_invalid. */
interface RowRefusal {
  row: number;
  error: { message_key?: string; message_en?: string; message_am?: string };
}

function rowRefusal(error: unknown): RowRefusal | null {
  if (!(error instanceof ApiError) || error.key !== "payments.batch.row_invalid") return null;
  const row = error.body.row;
  const inner = error.body.error;
  return typeof row === "number" && inner && typeof inner === "object" ? { row, error: inner as RowRefusal["error"] } : null;
}

/** The record without one key. */
function without<T>(all: Record<string, T>, key: string): Record<string, T> {
  const rest = { ...all };
  delete rest[key];
  return rest;
}

interface Completed {
  result: PaymentBatchResult;
  /** the order as it was when the bill was completed, for the receipt preview */
  order: Order;
  references: Record<string, string>;
}

export function PaySheet({ order, shift, onClose, onChanged }: { order: Order; shift: PayBlock | null; onClose: () => void; onChanged: () => void }) {
  const t = useTranslations("till");
  const no = useOrderNo(order);
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const channels = usePaymentChannels();
  const online = useOnline();
  const blockId = useId();
  const dueId = useId();
  const leftId = useId();

  const available = useMemo(() => sheetChannels(channels.data ?? []), [channels.data]);
  const draft = usePayDrafts((s) => s.drafts[order.id]);
  const drafts = usePayDrafts.getState;
  const balance = order.totals.balance;
  const currency = order.currency;

  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [rowErrors, setRowErrors] = useState<Record<string, string>>({});
  const [photoFailures, setPhotoFailures] = useState<Record<string, PhotoFailure>>({});
  const [completed, setCompleted] = useState<Completed | null>(null);
  // set before the completed sheet's draft is dropped, so no new sheet starts behind the receipt
  const finished = useRef(false);

  // a sheet starts with one row, cash for the whole balance, once the channels are known
  useEffect(() => {
    if (!draft && channels.data && !completed && !finished.current) {
      drafts().put(order.id, { batchUuid: uuid(), rows: [newRow(uuid(), balance, [], available)], attempt: null });
    }
  }, [draft, channels.data, completed, order.id, balance, available, drafts]);

  const rows = draft?.rows ?? [];
  const left = remaining(balance, rows);
  const block = blockReason({ balance, rows, channels: available, shift, online });

  function change(rowId: string, patch: Partial<PayRow>) {
    drafts().rows(order.id, (all) => all.map((row) => (row.id === rowId ? { ...row, ...patch } : row)));
    setRowErrors((all) => without(all, rowId));
  }

  function addRow() {
    drafts().rows(order.id, (all) => (all.length >= MAX_ROWS ? all : [...all, newRow(uuid(), balance, all, available)]));
  }

  function removeRow(rowId: string) {
    forgetPicture(rowId);
    drafts().rows(order.id, (all) => all.filter((row) => row.id !== rowId));
  }

  /** Uploads the row's kept picture under the photo's client_uuid and key; a retry sends the same, so the API answers the stored file. */
  async function upload(rowId: string, blob: Blob, clientUuid: string, key: string) {
    drafts().photo(order.id, rowId, (photo) => ({ ...photo, status: "uploading" }));
    setPhotoFailures((all) => without(all, rowId));
    try {
      const stored = await uploadReceiptPhoto(blob, clientUuid, key);
      // a replaced photo's late answer changes nothing
      drafts().photo(order.id, rowId, (photo) => (photo.clientUuid === clientUuid ? { ...photo, status: "uploaded", fileId: stored.id } : photo));
    } catch (failure) {
      drafts().photo(order.id, rowId, (photo) => (photo.clientUuid === clientUuid ? { ...photo, status: "failed", fileId: null } : photo));
      setPhotoFailures((all) => ({ ...all, [rowId]: { message: errorText.message(failure), retryable: keepsKey(failure) } }));
    }
  }

  async function takePhoto(rowId: string, file: File) {
    const clientUuid = uuid();
    const key = uuid();
    drafts().photo(order.id, rowId, () => ({ status: "uploading", fileId: null, clientUuid, key }));
    const blob = await downscale(file);
    keepPicture(rowId, blob);
    await upload(rowId, blob, clientUuid, key);
  }

  async function retryPhoto(rowId: string) {
    const row = drafts().drafts[order.id]?.rows.find((r) => r.id === rowId);
    const kept = pictureOf(rowId);
    if (!row?.photo.clientUuid || !row.photo.key || !kept) return;
    await upload(rowId, kept.blob, row.photo.clientUuid, row.photo.key);
  }

  async function complete() {
    const current = drafts().drafts[order.id];
    if (!current || pending || block !== null) return;
    const body = { client_uuid: current.batchUuid, rows: batchRows(current.rows, available) };
    const fingerprint = JSON.stringify({ orderId: order.id, ...body });
    const key = current.attempt?.fingerprint === fingerprint ? current.attempt.key : uuid();
    drafts().put(order.id, { ...current, attempt: { fingerprint, key } });
    setPending(true);
    setError(null);
    setRowErrors({});
    try {
      const result = await recordPaymentBatch(order.id, body, key);
      const references: Record<string, string> = {};
      for (const row of current.rows) if (row.reference.trim() !== "") references[row.id] = row.reference.trim();
      finished.current = true;
      setCompleted({ result, order, references });
      for (const row of current.rows) forgetPicture(row.id);
      drafts().drop(order.id);
      onChanged();
    } catch (failure) {
      setError(failure);
      const latest = drafts().drafts[order.id];
      if (latest && !keepsKey(failure)) drafts().put(order.id, { ...latest, attempt: null });
      const refused = rowRefusal(failure);
      const target = refused ? current.rows[refused.row] : undefined;
      if (refused && target) {
        const message = (locale === "am" ? refused.error.message_am : refused.error.message_en) || refused.error.message_en || errorText.message(failure);
        setRowErrors({ [target.id]: message });
        // a photo the API cannot use (not found, already on another payment, older than 12 hours) needs a new one
        if (refused.error.message_key === "payments.photo.invalid") {
          forgetPicture(target.id);
          drafts().photo(order.id, target.id, () => NO_PHOTO);
        }
      }
      // the order may have changed under the cashier (a line added, paid or voided elsewhere): look again
      onChanged();
    } finally {
      setPending(false);
    }
  }

  const changeTotal = completed
    ? completed.result.payments.reduce<string | null>((sum, p) => (p.channel_code === CASH ? addDecimal(sum ?? "0.00", p.change_amount ?? "0.00") : sum), null)
    : null;
  const network = error instanceof ApiError && error.isNetwork;

  return (
    // while the batch is on its way the sheet stays open: the cashier must see whether it was taken and the change to give
    <Modal label={t("pay_title", { no })} onClose={pending ? () => {} : onClose} size="lg">
      <h2 className="text-lg font-semibold text-neutral-900">{t("pay_title", { no })}</h2>
      <div className="grid gap-2 sm:grid-cols-2">
        <p className="flex items-baseline justify-between gap-4 rounded-md bg-neutral-100 px-4 py-3 text-xl font-semibold text-neutral-900">
          <span id={dueId}>{t("amount_due")}</span>
          <output aria-labelledby={dueId}>
            {completed ? completed.order.totals.balance : balance} {currency}
          </output>
        </p>
        {completed ? null : (
          <p
            className={`flex items-baseline justify-between gap-4 rounded-md px-4 py-3 text-xl font-semibold ${
              compareDecimal(left, "0", 2) === 0 ? "bg-green-50 text-green-900" : "bg-amber-50 text-amber-900"
            }`}
          >
            <span id={leftId}>{t("left_to_pay")}</span>
            <output aria-labelledby={leftId}>
              {left} {currency}
            </output>
          </p>
        )}
      </div>
      {!completed && compareDecimal(order.totals.paid, "0", 2) > 0 ? (
        <p className="text-base text-neutral-700">{t("already_paid", { amount: order.totals.paid, total: order.totals.total, currency })}</p>
      ) : null}

      {completed ? (
        <div className="space-y-4">
          <p role="status" className="space-y-1 rounded-md bg-green-50 px-4 py-3 text-green-900">
            <span className="block text-lg font-semibold">{t("paid")}</span>
            {changeTotal !== null ? <span className="block text-xl font-semibold">{t("give_change", { amount: changeTotal, currency })}</span> : null}
          </p>
          {completed.result.warnings.length > 0 ? (
            <div role="note" aria-label={t("warnings_title")} className="space-y-1 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
              <p className="font-semibold">{t("warnings_title")}</p>
              <ul className="list-disc space-y-1 pl-5">
                {completed.result.warnings.map((w, i) => (
                  <li key={`${w.code}-${w.payment_id}-${i}`}>{locale === "am" ? w.message_am : w.message_en}</li>
                ))}
              </ul>
            </div>
          ) : null}
          <ReceiptPanel order={completed.order} payments={completed.result.payments} channels={available} references={completed.references} />
          <Button type="button" className="min-h-14 w-full text-lg" onClick={onClose}>
            {t("done")}
          </Button>
        </div>
      ) : !draft ? (
        channels.error ? (
          <Alert>{errorText.message(channels.error)}</Alert>
        ) : (
          <p role="status" className="text-base text-neutral-600">
            {t("channels_loading")}
          </p>
        )
      ) : (
        <>
          <div className="space-y-3">
            {rows.map((row, index) => (
              <PayRowEditor
                key={row.id}
                index={index}
                row={row}
                rows={rows}
                channels={available}
                balance={balance}
                currency={currency}
                error={rowErrors[row.id] ?? null}
                photoFailure={photoFailures[row.id] ?? null}
                onChange={(patch) => change(row.id, patch)}
                onRemove={rows.length > 1 ? () => removeRow(row.id) : null}
                onPhoto={(file) => void takePhoto(row.id, file)}
                onRetryPhoto={() => void retryPhoto(row.id)}
              />
            ))}
          </div>
          <Button type="button" variant="secondary" className="min-h-14" disabled={rows.length >= MAX_ROWS} onClick={addRow}>
            {t("add_payment")}
          </Button>

          {!online ? (
            <p className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">{t("offline_notice")}</p>
          ) : null}
          {error ? (
            <Alert>
              {errorText.message(error)}
              {network ? <span className="mt-1 block">{t("network_kept")}</span> : null}
            </Alert>
          ) : null}
          <p id={blockId} role="status" aria-label={t("block_label")} className={block ? "text-base font-medium text-amber-900" : "sr-only"}>
            {block ? issueText(t, block, currency) : ""}
          </p>
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              className="min-h-14 flex-1 text-lg"
              disabled={block !== null || pending}
              aria-describedby={block ? blockId : undefined}
              onClick={() => void complete()}
            >
              {pending ? t("paying") : t("complete")}
            </Button>
            <Button type="button" variant="secondary" className="min-h-14" disabled={pending} onClick={onClose}>
              {t("close")}
            </Button>
          </div>
        </>
      )}
    </Modal>
  );
}
