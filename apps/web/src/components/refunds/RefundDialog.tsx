"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useId, useMemo, useRef, useState, type ChangeEvent, type FormEvent } from "react";
import { ApiError } from "@/lib/api/client";
import { useItems } from "@/lib/api/hooks";
import { uuid } from "@/lib/api/idempotency";
import { refundOrder, uploadReceiptPhoto, useOrderPayments } from "@/lib/api/till";
import type { Line, Locale, Order, PaymentView, RefundBody, RefundResult } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { downscale } from "@/lib/images/downscale";
import { useOrderNo } from "@/lib/orderNo";
import { keepsKey } from "@/lib/paySheet";
import {
  allocationBody,
  allocationIssue,
  allocationReasonOf,
  initialChoice,
  lifoSplit,
  needsReference,
  openAmount,
  paymentsLastFirst,
  refundAmount,
  refundBlock,
  refundField,
  refundLineViews,
  refundResultView,
  refundSettingsOf,
  REFUND_REASON_MAX,
  requestLines,
  sharesFromInputs,
  toggleLine,
  wholeOnly,
  type RefundLineView,
} from "@/lib/refund";
import { useChannelName } from "../payments/shared";
import { ReceiptActions } from "../till/ReceiptPanel";
import { Alert, Button, Field, Modal, TextInput } from "../ui";
import { OnlineOnlyButton } from "../OnlineOnly";

// The refund (step 4.5g, Ramin's rule R-C of 2026-10-28), one dialog for the till's closed orders (through 4.5b's VoidDialog, whose
// refund path this is) and for the order record page. The admin's two settings decide each line: a product that cannot be refunded is
// shown but cannot be chosen, and each chosen line says whether it goes back to stock (read from the catalog, GET /items?kind=product: the
// order read does not carry the settings; a product the catalog no longer lists is left to the API). The amount is the chosen lines'
// totals (the order's total when every charged line comes back), shared over the payments last in, first out, or by hand (`allocation`).
// A share on a digital channel needs the reference of the transfer back and may carry its photo (POST /files purpose refund_proof, its
// own client_uuid and key, a retry reuses both, as 4.5b's pay sheet). Reason and the manager's PIN, then POST /orders/{order}/refund
// with the refund's client_uuid (one for the dialog's life) and a key that is new after a refusal and kept when the outcome is unknown.
// After it: what came back, from which payments, which lines went back to stock, and the receipt (which now prints the refund).

type PhotoState =
  | { status: "none" }
  | { status: "uploading" | "failed"; clientUuid: string; key: string; blob: Blob; url: string; message?: string; retryable?: boolean }
  | { status: "uploaded"; clientUuid: string; key: string; blob: Blob; url: string; fileId: number };

function lineName(line: Pick<Line, "item">, locale: Locale): string {
  return locale === "am" ? line.item.name_am || line.item.name_en : line.item.name_en || line.item.name_am;
}

export function RefundDialog({ order, onClose, onRefunded }: { order: Order; onClose: () => void; onRefunded: () => void }) {
  const t = useTranslations("refund");
  const no = useOrderNo(order);
  const errorText = useErrorText();
  const queries = useQueryClient();
  const channelName = useChannelName();
  const payments = useOrderPayments(order.id);
  const products = useItems("product");
  const amountId = useId();
  const sharesId = useId();
  const fileInput = useRef<HTMLInputElement>(null);

  const [chosen, setChosen] = useState<Set<number> | null>(null);
  const [byHand, setByHand] = useState(false);
  const [inputs, setInputs] = useState<Record<number, string>>({});
  const [reference, setReference] = useState("");
  const [photo, setPhoto] = useState<PhotoState>({ status: "none" });
  const [reason, setReason] = useState("");
  const [pin, setPin] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [result, setResult] = useState<RefundResult | null>(null);
  // one refund for the dialog's life; one key per request (a new one after a refusal, the same one when the outcome is unknown)
  const [clientUuid] = useState(() => uuid());
  const [attempt, setAttempt] = useState(() => uuid());

  // the local picture's address is let go with the dialog
  const photoUrl = photo.status === "none" ? null : photo.url;
  useEffect(() => () => (photoUrl ? URL.revokeObjectURL(photoUrl) : undefined), [photoUrl]);

  const settings = useMemo(() => (products.data ? refundSettingsOf(products.data) : null), [products.data]);
  const views = useMemo(() => refundLineViews(order.lines, settings), [order.lines, settings]);
  const effective = chosen ?? initialChoice(views);
  const whole = wholeOnly(order.totals);
  const amount = refundAmount(views, effective, order.totals.total);
  const list: PaymentView[] = payments.data?.data ?? [];
  const givers = paymentsLastFirst(list);
  const lifo = lifoSplit(list, amount);
  const shares = byHand ? sharesFromInputs(inputs, givers.map((p) => p.id)) : lifo.shares;
  const allocation = byHand ? allocationIssue(list, shares, amount) : null;
  const digital = needsReference(list, shares);
  const block = refundBlock({
    chosen: views.filter((v) => v.state === "open" && effective.has(v.id)).length,
    amount,
    short: byHand ? "0.00" : lifo.short,
    allocation,
    needsReference: digital,
    reference,
    photoUploading: digital && photo.status === "uploading",
    reason,
    pin,
  });
  const loading = payments.isLoading || products.isLoading;
  const field = error ? refundField(error) : null;
  const allocationReason = allocationReasonOf(error);
  const openLines = views.filter((v) => v.state === "open");
  const closed = order.status === "closed";

  function toggle(lineId: number) {
    setChosen(toggleLine(views, effective, lineId, whole));
    setError(null);
  }

  function shareByHand() {
    // the typed amounts start from the usual split
    const start: Record<number, string> = {};
    for (const share of lifo.shares) start[share.paymentId] = share.amount;
    setInputs(start);
    setByHand(true);
  }

  async function upload(blob: Blob, url: string, photoUuid: string, key: string) {
    setPhoto({ status: "uploading", clientUuid: photoUuid, key, blob, url });
    try {
      const stored = await uploadReceiptPhoto(blob, photoUuid, key, "refund_proof");
      setPhoto((current) => (current.status !== "none" && current.clientUuid === photoUuid ? { status: "uploaded", clientUuid: photoUuid, key, blob, url, fileId: stored.id } : current));
    } catch (failure) {
      setPhoto((current) =>
        current.status !== "none" && current.clientUuid === photoUuid
          ? { status: "failed", clientUuid: photoUuid, key, blob, url, message: errorText.message(failure), retryable: keepsKey(failure) }
          : current,
      );
    }
  }

  async function picked(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file) return;
    const blob = await downscale(file);
    // a new photo: its own client_uuid and key; a replaced one is left for the orphan purge
    await upload(blob, URL.createObjectURL(blob), uuid(), uuid());
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (block !== null || pending || loading) return;
    const lines = requestLines(views, effective);
    const shareOut = byHand ? allocationBody(shares, lifo.shares) : undefined;
    const body: RefundBody = {
      client_uuid: clientUuid,
      reason: reason.trim(),
      pin,
      ...(lines ? { lines } : {}),
      ...(shareOut ? { allocation: shareOut } : {}),
      ...(digital ? { refund_reference: reference.trim() } : {}),
      ...(digital && photo.status === "uploaded" ? { refund_proof_file_id: photo.fileId } : {}),
    };
    setPending(true);
    setError(null);
    try {
      const answer = await refundOrder(order.id, body, attempt);
      setResult(answer);
      void queries.invalidateQueries({ queryKey: ["order", order.id] });
      void queries.invalidateQueries({ queryKey: ["order-payments", order.id] });
      void queries.invalidateQueries({ queryKey: ["closed-orders"] });
      for (const row of answer.payments) void queries.invalidateQueries({ queryKey: ["payment", row.payment_id] });
      onRefunded();
    } catch (failure) {
      setError(failure);
      setPin("");
      // a refusal wrote nothing: the next try is a new request; no answer (or a 5xx) may have refunded, so the same key replays it
      if (!keepsKey(failure)) setAttempt(uuid());
      // a photo the API cannot use (not found, claimed, too old) needs a new one
      if (refundField(failure) === "proof") setPhoto({ status: "none" });
    } finally {
      setPending(false);
    }
  }

  const title = t("title", { no });
  if (result) return <RefundDone order={order} title={title} result={result} onClose={onClose} />;

  const errorMessage =
    error instanceof ApiError && error.isNetwork ? t("network") : allocationReason ? t(`allocation_${allocationReason}`) : error ? errorText.message(error) : null;

  return (
    <Modal label={title} onClose={pending ? () => {} : onClose} size="lg">
      <form onSubmit={submit} className="space-y-5" noValidate>
        <h2 className="text-lg font-semibold text-neutral-900">{title}</h2>
        <p className="text-base text-neutral-700">{t("intro")}</p>
        {!closed ? <Alert>{t("not_closed")}</Alert> : null}
        {loading ? (
          <p role="status" className="text-base text-neutral-600">
            {t("loading")}
          </p>
        ) : null}
        {payments.error ? <Alert>{errorText.message(payments.error)}</Alert> : null}

        <fieldset className={`space-y-2 rounded-lg ${field === "lines" ? "border-2 border-red-300 p-3" : ""}`}>
          <legend className="mb-1 text-base font-semibold text-neutral-900">{t("lines")}</legend>
          {whole ? <p className="text-sm text-neutral-700">{t("whole_only")}</p> : null}
          {openLines.length === 0 && !loading ? <p className="text-base text-neutral-700">{t("nothing_left")}</p> : null}
          <ul className="space-y-2">
            {order.lines.map((line) => {
              const view = views.find((v) => v.id === line.id);
              return view ? <RefundLineRow key={line.id} line={line} view={view} checked={view.state === "open" && effective.has(line.id)} currency={order.currency} onToggle={() => toggle(line.id)} /> : null;
            })}
          </ul>
        </fieldset>

        <div className="flex items-baseline justify-between gap-4 rounded-md bg-neutral-100 px-4 py-3 text-xl font-semibold text-neutral-900">
          <span id={amountId}>{t("amount")}</span>
          <output aria-labelledby={amountId} className="tabular-nums">
            {amount} {order.currency}
          </output>
        </div>

        <section className={`space-y-2 ${field === "allocation" ? "rounded-lg border-2 border-red-300 p-3" : ""}`}>
          <h3 id={sharesId} className="text-base font-semibold text-neutral-900">
            {t("paid_back_from")}
          </h3>
          {byHand ? (
            <div className="space-y-3">
              {givers.map((payment) => (
                <Field key={payment.id} label={t("share_input", { id: payment.id, channel: channelName(payment.channel) })} hint={t("share_holds", { amount: openAmount(payment), currency: payment.currency })}>
                  {(props) => (
                    <TextInput
                      {...props}
                      inputMode="decimal"
                      autoComplete="off"
                      value={inputs[payment.id] ?? ""}
                      onChange={(e) => setInputs((all) => ({ ...all, [payment.id]: e.target.value.replace(/[^\d.]/g, "") }))}
                    />
                  )}
                </Field>
              ))}
              {allocation ? <p className="text-sm text-red-800">{t(`allocation_${allocation.reason}`)}</p> : null}
              <Button type="button" variant="secondary" compact onClick={() => setByHand(false)}>
                {t("share_default")}
              </Button>
            </div>
          ) : (
            <>
              <ul aria-labelledby={sharesId} className="space-y-1">
                {shares.map((share) => {
                  const payment = list.find((p) => p.id === share.paymentId);
                  return (
                    <li key={share.paymentId} className="flex justify-between gap-3 rounded-md border border-neutral-200 px-4 py-2 text-base">
                      <span>{payment ? channelName(payment.channel) : `#${share.paymentId}`}</span>
                      <span className="tabular-nums">
                        {share.amount} {order.currency}
                      </span>
                    </li>
                  );
                })}
              </ul>
              <p className="text-sm text-neutral-600">{t("lifo_hint")}</p>
              {lifo.short !== "0.00" ? <p className="text-sm text-red-800">{t("short", { amount: lifo.short, currency: order.currency })}</p> : null}
              {givers.length > 1 ? (
                <Button type="button" variant="secondary" compact onClick={shareByHand}>
                  {t("share_by_hand")}
                </Button>
              ) : null}
            </>
          )}
        </section>

        {digital ? (
          <div className="space-y-4">
            <Field label={t("reference")} hint={t("reference_hint")} error={field === "reference" ? errorMessage ?? undefined : undefined}>
              {(props) => <TextInput {...props} maxLength={64} autoComplete="off" value={reference} onChange={(e) => setReference(e.target.value)} />}
            </Field>
            <div className="space-y-2">
              <p className="text-sm font-medium text-neutral-800">{t("photo")}</p>
              <input ref={fileInput} type="file" accept="image/*" capture="environment" aria-label={t("photo_file")} tabIndex={-1} className="sr-only" onChange={(e) => void picked(e)} />
              {photo.status === "uploaded" ? (
                <div className="flex flex-wrap items-center gap-4">
                  {/* a local object URL of the shrunk picture: next/image has nothing to optimise in a static export */}
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={photo.url} alt={t("photo_thumb_alt")} className="h-24 w-24 rounded-md border border-neutral-200 object-cover" />
                  <p className="text-base font-medium text-green-800">{t("photo_attached")}</p>
                  <Button type="button" variant="secondary" onClick={() => fileInput.current?.click()}>
                    {t("photo_replace")}
                  </Button>
                </div>
              ) : photo.status === "uploading" ? (
                <p role="status" className="text-base text-neutral-700">
                  {t("photo_uploading")}
                </p>
              ) : (
                <div className="space-y-2">
                  {photo.status === "failed" ? <Alert>{photo.message ?? t("photo_failed")}</Alert> : null}
                  <div className="flex flex-wrap gap-2">
                    <Button type="button" variant="secondary" onClick={() => fileInput.current?.click()}>
                      {t("photo_take")}
                    </Button>
                    {photo.status === "failed" && photo.retryable !== false ? (
                      <Button type="button" variant="secondary" onClick={() => void upload(photo.blob, photo.url, photo.clientUuid, photo.key)}>
                        {t("photo_retry")}
                      </Button>
                    ) : null}
                  </div>
                </div>
              )}
            </div>
          </div>
        ) : null}

        <Field label={t("reason")} hint={t("reason_hint")}>
          {(props) => <TextInput {...props} value={reason} maxLength={REFUND_REASON_MAX} onChange={(e) => setReason(e.target.value)} />}
        </Field>
        <Field label={t("pin")} hint={t("pin_hint")}>
          {(props) => (
            <TextInput {...props} type="password" inputMode="numeric" autoComplete="off" maxLength={6} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ""))} />
          )}
        </Field>

        {errorMessage && field !== "reference" ? <Alert>{errorMessage}</Alert> : null}
        {field === "shift" ? <p className="text-sm text-neutral-700">{t("shift_hint")}</p> : null}
        {block !== null && !loading ? <p className="text-sm text-neutral-600">{t(`block_${block}`)}</p> : null}

        <div className="flex flex-wrap gap-2">
          <OnlineOnlyButton type="submit" disabled={block !== null || pending || loading || !closed}>
            {pending ? t("submitting") : t("submit")}
          </OnlineOnlyButton>
          <Button type="button" variant="secondary" disabled={pending} onClick={onClose}>
            {t("back")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}

function RefundLineRow({ line, view, checked, currency, onToggle }: { line: Line; view: RefundLineView; checked: boolean; currency: string; onToggle: () => void }) {
  const t = useTranslations("refund");
  const locale = useLocale() as Locale;
  const nameId = useId();
  const noteId = useId();
  const note =
    view.state === "not_refundable"
      ? t("not_refundable")
      : view.state === "refunded"
        ? t("already_refunded")
        : view.state === "not_charged"
          ? t("not_charged")
          : view.restock === true
            ? t("stock_back")
            : view.restock === false
              ? t("stock_not_back")
              : t("stock_unknown");
  const tone = view.state === "open" ? (view.restock ? "text-green-800" : "text-neutral-700") : view.state === "not_refundable" ? "text-amber-800" : "text-neutral-500";
  const disabled = view.state !== "open";
  return (
    <li>
      <label className={`flex min-h-14 items-center gap-3 rounded-md border px-4 py-2 ${disabled ? "border-neutral-200 bg-neutral-50" : "cursor-pointer border-neutral-300"}`}>
        <input type="checkbox" className="h-5 w-5 shrink-0" checked={checked} disabled={disabled} aria-labelledby={nameId} aria-describedby={noteId} onChange={onToggle} />
        <span className="min-w-0 flex-1">
          <span id={nameId} className={`block text-base font-medium ${disabled ? "text-neutral-600" : "text-neutral-900"}`}>
            {lineName(line, locale)}
          </span>
          <span id={noteId} className={`block text-sm ${tone}`}>
            {note}
          </span>
        </span>
        <span className="shrink-0 text-right text-sm text-neutral-700 tabular-nums">{t("line_total", { qty: trimDecimal(line.qty), amount: line.line_total, currency })}</span>
      </label>
    </li>
  );
}

function RefundDone({ order, title, result, onClose }: { order: Order; title: string; result: RefundResult; onClose: () => void }) {
  const t = useTranslations("refund");
  const locale = useLocale() as Locale;
  const headingId = useId();
  const paidId = useId();
  const backId = useId();
  const notBackId = useId();
  const view = refundResultView(result);
  const nameOfLine = (lineId: number) => {
    const line = order.lines.find((l) => l.id === lineId);
    return line ? lineName(line, locale) : `#${lineId}`;
  };
  return (
    <Modal label={title} onClose={onClose} size="lg">
      <h2 className="text-lg font-semibold text-neutral-900">{title}</h2>
      <section aria-labelledby={headingId} className="space-y-4 rounded-lg border border-green-300 bg-green-50 p-4">
        <h3 id={headingId} className="text-lg font-semibold text-green-900">
          {t("done")}
        </h3>
        <p className="text-xl font-semibold text-neutral-900 tabular-nums">{t("done_amount", { amount: view.amount, currency: order.currency })}</p>
        <div className="space-y-1">
          <p id={paidId} className="text-sm font-medium text-neutral-700">
            {t("done_payments")}
          </p>
          <ul aria-labelledby={paidId} className="space-y-1 text-base">
            {view.payments.map((p) => (
              <li key={p.paymentId}>{t("done_payment", { channel: (locale === "am" ? p.nameAm : p.nameEn) || p.channelCode, amount: p.amount, currency: order.currency })}</li>
            ))}
          </ul>
        </div>
        {view.restocked.length > 0 ? (
          <div className="space-y-1">
            <p id={backId} className="text-sm font-medium text-neutral-700">
              {t("back_in_stock")}
            </p>
            <ul aria-labelledby={backId} className="list-inside list-disc text-base">
              {view.restocked.map((id) => (
                <li key={id}>{nameOfLine(id)}</li>
              ))}
            </ul>
          </div>
        ) : null}
        {view.notRestocked.length > 0 ? (
          <div className="space-y-1">
            <p id={notBackId} className="text-sm font-medium text-neutral-700">
              {t("not_back_in_stock")}
            </p>
            <ul aria-labelledby={notBackId} className="list-inside list-disc text-base">
              {view.notRestocked.map((id) => (
                <li key={id}>{nameOfLine(id)}</li>
              ))}
            </ul>
          </div>
        ) : null}
        <p className="text-base text-neutral-700">{t("done_net", { amount: view.netPaid, currency: order.currency })}</p>
      </section>
      <p className="text-sm text-neutral-600">{t("receipt_hint")}</p>
      <ReceiptActions order={order} />
      <Button type="button" variant="secondary" onClick={onClose}>
        {t("close")}
      </Button>
    </Modal>
  );
}
