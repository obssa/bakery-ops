"use client";

import { useLocale, useTranslations } from "next-intl";
import { useRef, useState } from "react";
import { useIdempotencyKey, uuid } from "@/lib/api/idempotency";
import { recordPayment, usePaymentChannels } from "@/lib/api/till";
import type { Locale, Order, PaymentResult } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { CASH, changeDue, pressKey, tenderedOf, type KeypadKey } from "@/lib/payment";
import { Alert, Button, Modal } from "../ui";
import { focusRing } from "../styles";

// The pay sheet of the till: the amount due (the order's balance), the payment channels in their order, and for cash a
// keypad for the cash handed over with the change due computed here on decimal strings; the API's `change_amount`
// confirms it once the payment is recorded. Only cash is accepted in Phase 1: the digital channels are listed but
// disabled, with the reason, because they need a photo of the receipt (Phase 4). The write is online only (the cashier
// needs the answer) and carries the payment's client UUID and an Idempotency-Key.

const KEYS: KeypadKey[] = ["7", "8", "9", "4", "5", "6", "1", "2", "3", "00", "0", "."];

const keyClass = `inline-flex min-h-16 items-center justify-center rounded-md border border-neutral-300 bg-white text-2xl font-semibold text-neutral-900 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:text-neutral-300 ${focusRing}`;

export function PaySheet({ order, onClose, onChanged }: { order: Order; onClose: () => void; onChanged: () => void }) {
  const t = useTranslations("till");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const channels = usePaymentChannels();
  const [entry, setEntry] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [result, setResult] = useState<PaymentResult | null>(null);
  // the payment's own id is fixed for this sheet; the Idempotency-Key follows the payload (a changed cash amount is a new request)
  const clientUuid = useRef(uuid());
  const { keyFor, done } = useIdempotencyKey();

  const due = order.totals.balance;
  const tendered = tenderedOf(entry);
  const change = changeDue(tendered, due);
  const channel = CASH;
  const enabled = (channels.data ?? []).filter((c) => c.is_enabled);

  async function confirm() {
    if (change === null || tendered === null || pending || result) return;
    const body = { client_uuid: clientUuid.current, channel_code: channel, amount: due, tendered_amount: tendered };
    setPending(true);
    setError(null);
    try {
      const paid = await recordPayment(order.id, body, keyFor({ orderId: order.id, ...body }));
      done();
      setResult(paid);
      onChanged();
    } catch (failure) {
      setError(failure);
      // the order may have changed under the cashier (paid or voided elsewhere): look again
      onChanged();
    } finally {
      setPending(false);
    }
  }

  return (
    <Modal label={t("pay_title", { no: String(order.order_no) })} onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{t("pay_title", { no: String(order.order_no) })}</h2>
      <p className="flex items-baseline justify-between rounded-md bg-neutral-100 px-4 py-3 text-xl font-semibold text-neutral-900">
        <span>{t("amount_due")}</span>
        <span>
          {due} {order.currency}
        </span>
      </p>

      {result ? (
        <div className="space-y-4">
          <p role="status" className="space-y-1 rounded-md bg-green-50 px-4 py-3 text-green-900">
            <span className="block text-lg font-semibold">{t("paid")}</span>
            <span className="block text-xl font-semibold">{t("give_change", { amount: result.payment.change_amount ?? "0.00", currency: result.payment.currency })}</span>
          </p>
          <Button type="button" className="min-h-14 w-full text-lg" onClick={onClose}>
            {t("done")}
          </Button>
        </div>
      ) : (
        <>
          <div role="group" aria-label={t("method")} className="flex flex-wrap gap-2">
            {enabled.map((c) => {
              const usable = c.accepted_now && c.code === CASH;
              return (
                <button
                  key={c.code}
                  type="button"
                  aria-pressed={usable && channel === c.code}
                  disabled={!usable}
                  className={`flex min-h-14 flex-col items-start justify-center rounded-md border px-4 text-left text-base font-medium ${
                    usable ? "border-neutral-900 bg-neutral-900 text-white" : "border-neutral-200 bg-neutral-100 text-neutral-500"
                  } disabled:cursor-not-allowed ${focusRing}`}
                >
                  <span>{locale === "am" ? c.name_am : c.name_en}</span>
                  {usable ? null : <span className="text-sm font-normal">{t("digital_reason")}</span>}
                </button>
              );
            })}
          </div>

          <div className="space-y-2">
            <p className="flex items-baseline justify-between text-base text-neutral-700">
              <span>{t("cash_received")}</span>
              <output aria-live="polite" className="text-2xl font-semibold text-neutral-900">
                {entry === "" ? "0" : entry} {order.currency}
              </output>
            </p>
            <div role="group" aria-label={t("keypad")} className="grid grid-cols-3 gap-2">
              {KEYS.map((key) => (
                <button key={key} type="button" className={keyClass} onClick={() => setEntry((current) => pressKey(current, key))}>
                  {key}
                </button>
              ))}
              <button type="button" aria-label={t("key_back")} className={keyClass} onClick={() => setEntry((current) => pressKey(current, "back"))}>
                ⌫
              </button>
              <button type="button" className={`${keyClass} text-base`} onClick={() => setEntry("")}>
                {t("key_clear")}
              </button>
              <button type="button" className={`${keyClass} text-base`} onClick={() => setEntry(due)}>
                {t("exact")}
              </button>
            </div>
          </div>

          <p role="status" className={`text-xl font-semibold ${change === null ? "text-neutral-500" : "text-green-800"}`}>
            {change === null
              ? entry === ""
                ? ""
                : t("cash_short")
              : t("change_due", { amount: change, currency: order.currency })}
          </p>
          {error ? <Alert>{errorText.message(error)}</Alert> : null}
          <div className="flex flex-wrap gap-2">
            <Button type="button" className="min-h-14 flex-1 text-lg" disabled={change === null || pending} onClick={() => void confirm()}>
              {pending ? t("paying") : t("confirm")}
            </Button>
            <Button type="button" variant="secondary" className="min-h-14" onClick={onClose}>
              {t("close")}
            </Button>
          </div>
        </>
      )}
    </Modal>
  );
}
