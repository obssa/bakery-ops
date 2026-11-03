"use client";

import { useLocale, useTranslations } from "next-intl";
import { Check, CircleCheck, Split } from "lucide-react";
import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from "react";
import { ApiError } from "@/lib/api/client";
import { uuid } from "@/lib/api/idempotency";
import { uploadReceiptPhoto, usePaymentChannels } from "@/lib/api/till";
import type { Locale, Order, PaymentBatchResult } from "@/lib/api/types";
import { addDecimal, compareDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { downscale } from "@/lib/images/downscale";
import { photoNeeds } from "@/lib/outbox/logic";
import { keepPhoto, queuePhotoUpload, releasePhotos, submit } from "@/lib/outbox/store";
import { useOrderNo } from "@/lib/orderNo";
import { forgetPicture, keepPicture, usePayDrafts } from "@/lib/payDrafts";
import { CASH } from "@/lib/payment";
import {
  batchRows,
  blockReason,
  changeDue,
  keepsKey,
  keptPhotos,
  MAX_ROWS,
  newRow,
  NO_PHOTO,
  refusedPhoto,
  remaining,
  sheetChannels,
  type Block,
  type PayRow,
} from "@/lib/paySheet";
import type { PayBlock } from "@/lib/shifts";
import { useOnline } from "@/lib/useOnline";
import { visibleLines } from "@/lib/live/pad";
import { Alert, Button, Chip, Modal } from "../ui";
import { PayRowEditor, type PhotoFailure } from "./PayRowEditor";
import { ReceiptPanel, ReceiptPending } from "./ReceiptPanel";

// The till's pay sheet (step 4.5b, docs/phases/phase-4-plan.md section 6 item 5): the amount due and what is left to cover at the top, then
// one row per payment (PayRowEditor), so a bill can be split over channels down to exactly the balance. Complete stays disabled until every
// row is complete (each photo uploaded or kept on the tablet, each reference valid), the amounts add up to the balance, and the till's
// shift is running where the shift rule is on (every channel since step 4.3b); the reason is always shown. Complete posts
// POST /orders/{order}/payment-batches once, with the batch's client_uuid, each row's own client_uuid and one Idempotency-Key: all the
// payments are recorded or none is.
//
// A photo is shrunk in the browser (lib/images/downscale.ts) and uploaded as soon as it is taken (POST /files, its own client_uuid and key,
// both reused by a retry), so Complete never waits for an upload. Nothing is lost on a failure: the sheet is kept per order while the page
// lives (lib/payDrafts.ts), with the photos already on the server, and a retry of the same sheet replays the batch the API recorded instead of
// taking the money twice. A refusal (a row, the sum, the shift) wrote nothing: it is shown, the row it names is marked, and the next try is a
// new request. Offline (or when the connection fails on Complete) the batch joins the outbox with its key and client_uuid (step 5.2a): the
// sheet says it is saved on this tablet and shows the change to give, and the till holds the order until it was sent. The rules are
// lib/paySheet.ts.
//
// Step 5.2b, the photo queue: every photo is first kept on the tablet (lib/outbox keepPhoto: within NEXT_PUBLIC_PHOTO_QUEUE_MB, or refused
// with how much waits). Online it is uploaded at once as before; offline, or when its upload fails on the network, it stays kept and the row
// is complete all the same: Complete queues one upload of the kept photos, then the batch, which waits for it and takes each file id from
// its answer. A photo replaced, removed or paid for is let go of; the outbox drops it once nothing needs it.
//
// Step 6.3b (fresh-counter.md 3.4): from 1024 px the sheet is the till's centre, beside the waiting list (`inline`: a dialog that leaves the
// page in view, so the cashier can turn to another order), and a dialog over the screen below that. The amount due at 48 / 700 with what is
// left to pay and "All lines served"; "Pay with" as a grid of channel buttons; the cash received and the change; the quick cash keys and
// "Add a payment" for a split; the xl "Complete payment"; and at its right the 236 px receipt zone: what the receipt will hold before the
// bill is paid, the preview with Print, the link and its code after.

/** The change of the sheet's cash rows, which the cashier hands back now; null when no row is cash. */
/** The photos the sheet's rows hold (by client_uuid). */
function photosHeld(rows: PayRow[]): string[] {
  return rows.flatMap((row) => (row.photo.clientUuid ? [row.photo.clientUuid] : []));
}

/** Bytes as MB with one decimal, for the quota message. */
function megabytes(bytes: number): string {
  return (bytes / (1024 * 1024)).toFixed(1);
}

function localChange(rows: PayRow[]): string | null {
  return rows.reduce<string | null>((sum, row) => {
    const change = changeDue(row);
    return change === null ? sum : addDecimal(sum ?? "0.00", change);
  }, null);
}

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

export function PaySheet({
  order,
  shift,
  inline = false,
  onClose,
  onChanged,
  onPendingChange,
}: {
  order: Order;
  shift: PayBlock | null;
  /** the till's centre from 1024 px (beside the waiting list), else a dialog over the screen */
  inline?: boolean;
  onClose: () => void;
  onChanged: () => void;
  /** told while a Complete is on its way, so the till keeps the sheet on this order until it is answered */
  onPendingChange?: (pending: boolean) => void;
}) {
  const t = useTranslations("till");
  const o = useTranslations("outbox");
  const no = useOrderNo(order);
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const channels = usePaymentChannels();
  const online = useOnline();
  const blockId = useId();
  const dueId = useId();
  const leftId = useId();
  const k = useTranslations("kds");
  const heading = useRef<HTMLHeadingElement>(null);
  // the sheet opened (or turned to another order): the focus goes to its title, so a keyboard or a screen reader starts there and the
  // browser brings it into view
  useEffect(() => {
    heading.current?.focus({ preventScroll: false });
  }, [order.id]);

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
  // the batch was saved on this tablet for the outbox to send (offline, or the connection failed): the change to give
  const [queued, setQueued] = useState<{ change: string | null } | null>(null);
  // set before the completed sheet's draft is dropped, so no new sheet starts behind the receipt
  const finished = useRef(false);

  useEffect(() => {
    onPendingChange?.(pending);
  }, [pending, onPendingChange]);
  useEffect(() => () => onPendingChange?.(false), [onPendingChange]);

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
    const photo = drafts().drafts[order.id]?.rows.find((row) => row.id === rowId)?.photo.clientUuid ?? null;
    forgetPicture(rowId);
    drafts().rows(order.id, (all) => all.filter((row) => row.id !== rowId));
    if (photo) void releasePhotos([photo]);
  }

  /**
   * Uploads the row's photo now (online) under its client_uuid and key. No answer, or a server error: the photo stays kept on the tablet and
   * Complete queues its upload, with the same key, before the payment. A refusal: the row needs another photo.
   */
  async function upload(rowId: string, blob: Blob, clientUuid: string, key: string) {
    try {
      const stored = await uploadReceiptPhoto(blob, clientUuid, key);
      // a replaced photo's late answer changes nothing
      drafts().photo(order.id, rowId, (photo) => (photo.clientUuid === clientUuid ? { ...photo, status: "uploaded", fileId: stored.id } : photo));
    } catch (failure) {
      if (keepsKey(failure)) {
        drafts().photo(order.id, rowId, (photo) => (photo.clientUuid === clientUuid ? { ...photo, status: "kept", fileId: null } : photo));
        return;
      }
      drafts().photo(order.id, rowId, (photo) => (photo.clientUuid === clientUuid ? { ...NO_PHOTO, status: "failed" } : photo));
      setPhotoFailures((all) => ({ ...all, [rowId]: { message: errorText.message(failure) } }));
      void releasePhotos([clientUuid]);
    }
  }

  async function takePhoto(rowId: string, file: File) {
    const replaced = drafts().drafts[order.id]?.rows.find((row) => row.id === rowId)?.photo.clientUuid ?? null;
    drafts().photo(order.id, rowId, () => ({ ...NO_PHOTO, status: "uploading" }));
    setPhotoFailures((all) => without(all, rowId));
    if (replaced) void releasePhotos([replaced]);
    const blob = await downscale(file);
    const kept = await keepPhoto(blob);
    if (!kept.kept) {
      // the tablet already keeps as much as it may: the photos waiting must go first
      forgetPicture(rowId);
      drafts().photo(order.id, rowId, () => ({ ...NO_PHOTO, status: "failed" }));
      setPhotoFailures((all) => ({ ...all, [rowId]: { message: t("photo_quota", { waiting: megabytes(kept.waitingBytes), limit: megabytes(kept.limitBytes) }) } }));
      return;
    }
    const { clientUuid, key } = kept.photo;
    keepPicture(rowId, blob);
    if (typeof navigator !== "undefined" && navigator.onLine === false) {
      // offline: the photo waits on the tablet, and Complete queues its upload before the payment
      drafts().photo(order.id, rowId, () => ({ status: "kept", fileId: null, clientUuid, key }));
      return;
    }
    drafts().photo(order.id, rowId, () => ({ status: "uploading", fileId: null, clientUuid, key }));
    await upload(rowId, blob, clientUuid, key);
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
      // the photos still kept on the tablet go up first, in one write; the batch waits for it and takes their file ids from its answer
      const photos = keptPhotos(current.rows, available);
      const upload = photos.length > 0 ? queuePhotoUpload(photos, order.id, order.client_uuid) : null;
      if (photos.length > 0 && !upload) throw new ApiError(401, { message_key: "auth.unauthenticated" });
      const outcome = await submit<PaymentBatchResult>({
        kind: "pay_batch",
        method: "POST",
        path: `/orders/${order.id}/payment-batches`,
        body,
        subjectId: order.id,
        subjectUuid: order.client_uuid,
        idempotencyKey: key,
        ...(upload ? { dependsOn: upload.id, needs: photoNeeds(photos) } : {}),
      });
      finished.current = true;
      if (!outcome.sent) {
        // saved on this tablet: the outbox sends it with the same key when it can; the cash is taken now, so the change is given now
        setQueued({ change: localChange(current.rows) });
        for (const row of current.rows) forgetPicture(row.id);
        drafts().drop(order.id);
        return;
      }
      const result = outcome.answer;
      const references: Record<string, string> = {};
      for (const row of current.rows) if (row.reference.trim() !== "") references[row.id] = row.reference.trim();
      setCompleted({ result, order, references });
      for (const row of current.rows) forgetPicture(row.id);
      drafts().drop(order.id);
      // the photos are on the payments now: the tablet keeps them no longer
      void releasePhotos(photosHeld(current.rows));
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
          void releasePhotos(photosHeld([target]));
        }
      }
      // a photo the API refused to store, or one no longer on the tablet (step 5.2b): its row needs a new one
      const photo = refusedPhoto(failure);
      const photoRow = photo ? current.rows.find((row) => row.photo.clientUuid === photo) : undefined;
      if (photo && photoRow) {
        forgetPicture(photoRow.id);
        drafts().photo(order.id, photoRow.id, () => NO_PHOTO);
        setRowErrors((all) => ({ ...all, [photoRow.id]: errorText.message(failure) }));
        void releasePhotos([photo]);
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
  // no answer at all (a refusal made on the tablet has no status either, but says what it is)
  const network = error instanceof ApiError && error.isNetwork && !error.messages.en;
  const shown = completed ? completed.order : order;
  const where = shown.type === "takeaway" ? k("takeaway") : shown.dining_table ? k("table", { code: shown.dining_table.code }) : "";
  const lines = visibleLines(shown);
  const allServed = lines.length > 0 && lines.every((line) => line.status === "served");
  // while the batch is on its way the sheet stays open: the cashier must see whether it was taken and the change to give
  const close = pending ? () => {} : onClose;

  const title = (
    <div className="min-w-0 space-y-0.5">
      <h2 ref={heading} tabIndex={-1} className="text-lg font-semibold text-neutral-900 outline-none">
        {t("pay_title", { no })}
      </h2>
      <p className="text-sm text-neutral-600">
        {where}
        {where && shown.owner.name ? <span aria-hidden="true"> · </span> : null}
        {shown.owner.name ? t("waiter", { name: shown.owner.name }) : null}
      </p>
    </div>
  );

  // the amount due at 48 / 700, and what is left to cover while the sheet is open (fresh-counter.md 3.4)
  const due = (
    <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
      <div className="min-w-0 space-y-1">
        <p id={dueId} className="text-[13px] font-semibold uppercase tracking-[0.08em] text-neutral-600">
          {t("amount_due")}
        </p>
        <output aria-labelledby={dueId} className="block text-5xl font-bold tracking-tight tabular-nums text-neutral-900">
          {completed ? completed.order.totals.balance : balance}
          <span className="text-3xl font-semibold text-neutral-600"> {currency}</span>
        </output>
      </div>
      <div className="flex flex-col items-end gap-2">
        {allServed && !completed ? <Chip tone="brand">{t("all_served")}</Chip> : null}
        {completed || queued ? null : (
          <p
            className={`flex items-baseline gap-3 rounded-xl px-3.5 py-2 text-base font-semibold ${
              compareDecimal(left, "0", 2) === 0 ? "bg-brand-50 text-brand-700" : "bg-amber-50 text-amber-900"
            }`}
          >
            <span id={leftId}>{t("left_to_pay")}</span>
            <output aria-labelledby={leftId} className="tabular-nums">
              {left} {currency}
            </output>
          </p>
        )}
      </div>
    </div>
  );

  let body: ReactNode;
  if (completed) {
    body = (
      <div className="space-y-4">
        {/* money taken: green, the one place it means "confirmed" (fresh-counter.md 1.3) */}
        <p role="status" className="space-y-1 rounded-[20px] bg-green-50 px-5 py-4 text-green-900">
          <span className="flex items-center gap-2 text-lg font-semibold">
            <CircleCheck className="size-6" strokeWidth={2} aria-hidden="true" />
            {t("paid")}
          </span>
          {changeTotal !== null ? <span className="block text-3xl font-bold tabular-nums">{t("give_change", { amount: changeTotal, currency })}</span> : null}
        </p>
        {completed.result.warnings.length > 0 ? (
          <div role="note" aria-label={t("warnings_title")} className="space-y-1 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
            <p className="font-semibold">{t("warnings_title")}</p>
            <ul className="list-disc space-y-1 pl-5">
              {completed.result.warnings.map((w, i) => (
                <li key={`${w.code}-${w.payment_id}-${i}`}>{locale === "am" ? w.message_am : w.message_en}</li>
              ))}
            </ul>
          </div>
        ) : null}
        <Button type="button" size="xl" className="w-full" onClick={onClose}>
          {t("done")}
        </Button>
      </div>
    );
  } else if (queued) {
    body = (
      <div className="space-y-4">
        <p role="status" className="space-y-1 rounded-[20px] bg-blue-50 px-5 py-4 text-blue-900">
          <span className="block text-lg font-semibold">{o("queued")}</span>
          {queued.change !== null ? <span className="block text-3xl font-bold tabular-nums">{t("give_change", { amount: queued.change, currency })}</span> : null}
        </p>
        <Button type="button" size="xl" className="w-full" onClick={onClose}>
          {t("done")}
        </Button>
      </div>
    );
  } else if (!draft) {
    body = channels.error ? (
      <Alert>{errorText.message(channels.error)}</Alert>
    ) : (
      <p role="status" className="text-base text-neutral-600">
        {t("channels_loading")}
      </p>
    );
  } else {
    body = (
      <div className="space-y-5">
        {compareDecimal(order.totals.paid, "0", 2) > 0 ? (
          <p className="text-base text-neutral-700">{t("already_paid", { amount: order.totals.paid, total: order.totals.total, currency })}</p>
        ) : null}
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
            />
          ))}
        </div>
        {/* a bill split over channels ("Split payment" in the design): one more payment for what is left */}
        <Button type="button" variant="ghost" size="lg" className="text-brand-700" disabled={rows.length >= MAX_ROWS} onClick={addRow}>
          <Split className="size-5" strokeWidth={1.75} aria-hidden="true" />
          {t("add_payment")}
        </Button>

        {!online ? <p className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">{t("offline_notice")}</p> : null}
        {error ? (
          <Alert>
            {errorText.message(error)}
            {network ? <span className="mt-1 block">{t("network_kept")}</span> : null}
          </Alert>
        ) : null}
        <p id={blockId} role="status" aria-label={t("block_label")} className={block ? "text-base font-medium text-amber-900" : "sr-only"}>
          {block ? issueText(t, block, currency) : ""}
        </p>
        <div className="flex flex-wrap gap-2.5">
          <Button
            type="button"
            size="xl"
            className="min-w-56 flex-1"
            disabled={block !== null || pending}
            aria-describedby={block ? blockId : undefined}
            onClick={() => void complete()}
          >
            <Check className="size-[22px]" strokeWidth={2.2} aria-hidden="true" />
            {pending ? t("paying") : t("complete")}
          </Button>
          <Button type="button" variant="secondary" size="xl" disabled={pending} onClick={onClose}>
            {t("close")}
          </Button>
        </div>
      </div>
    );
  }

  const content = (
    // the centre (the amount due, how it is paid, Complete) and the receipt zone at 236 px beside it once there is room (a container query:
    // the sheet's own width, inline or in a dialog)
    <div className="@container">
      <div className="grid gap-6 @[44rem]:grid-cols-[minmax(0,1fr)_236px]">
        <div className="min-w-0 space-y-5">
          {title}
          {due}
          {body}
        </div>
        <div className="self-start rounded-[20px] border border-neutral-200 bg-white p-[18px]">
          {completed ? (
            <ReceiptPanel order={completed.order} payments={completed.result.payments} channels={available} references={completed.references} />
          ) : (
            <ReceiptPending order={order} />
          )}
        </div>
      </div>
    </div>
  );

  if (!inline) {
    return (
      <Modal label={t("pay_title", { no })} onClose={close} size="lg">
        {content}
      </Modal>
    );
  }
  // from 1024 px the sheet is the till's centre, beside the waiting list (fresh-counter.md 3.4): a dialog that does not hide the page, so
  // the cashier can turn to another order; Escape inside it closes it as the dialog's Escape does
  return (
    <section
      role="dialog"
      aria-label={t("pay_title", { no })}
      onKeyDown={(event) => {
        if (event.key === "Escape") close();
      }}
      className="min-w-0"
    >
      {content}
    </section>
  );
}
