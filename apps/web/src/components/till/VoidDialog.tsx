"use client";

import { useQueryClient } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { uuid } from "@/lib/api/idempotency";
import { useOrderPayments, voidOrder, voidPayment } from "@/lib/api/till";
import type { Locale, Order, PaymentView } from "@/lib/api/types";
import { compareDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { isSettled, UNPAID_STATUSES } from "@/lib/live/orders";
import { useOrderNo } from "@/lib/orderNo";
import { keepsKey } from "@/lib/paySheet";
import { Alert, Button, Field, Modal, TextInput } from "../ui";

// Voiding at the till: a reason, and the PIN of a manager or admin (their short code) typed on this screen. The API checks the PIN and
// answers with its own bilingual message when it is refused (403) or locked (423); the dialog shows that message and keeps the reason.
// Used by the till queue, the admin Orders board and the order record (step 4.5d, which opens it on one payment: `payment`).
//
// - An order with no payment is voided as a whole (POST /orders/{order}/void, Phase 1): the stock held for it is released.
// - An order with payments that is not closed (step 4.5b, the void of step 4.3c): one payment is voided (POST /payments/{payment}/void),
//   its money comes off the order and the order is owed it again. A part-paid order can still be voided as a whole; a settled one (paid
//   in full, not served yet) cannot (409 orders.order.paid), so only its payments are offered.
// - REFUND (steps 4.1b and 4.3d, ON HOLD for Ramin's answer on R-C): a paid or closed order is refunded, never voided. The plan puts the
//   refund path here (the lines to refund, a disposition per line with the 10.2 R-C defaults, the reason, the manager's PIN, POST
//   /orders/{order}/refund). It is not built: the dialog says refunds are not available yet. See `RefundPlaceholder` below.
//   TODO(p4): the refund path of 4.1b/4.3d, once R-C is answered.

const PIN = /^\d{4,6}$/;

type Target = "order" | number;

function paymentLabel(payment: PaymentView, locale: Locale): string {
  const name = (locale === "am" ? payment.channel.name_am : payment.channel.name_en) ?? payment.channel.code ?? "";
  const time = new Date(payment.received_at).toLocaleTimeString(locale === "am" ? "am-ET" : "en-GB", { timeZone: "Africa/Addis_Ababa", hour: "2-digit", minute: "2-digit" });
  return `${name} ${payment.amount} ${payment.currency} · ${time}`;
}

/** The place of the refund path (on hold, see the header): a paid or closed order is refunded, and that is not available yet. */
function RefundPlaceholder({ onClose }: { onClose: () => void }) {
  const t = useTranslations("till");
  return (
    <div className="space-y-4">
      <p className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">{t("refund_not_yet")}</p>
      <Button type="button" variant="secondary" onClick={onClose}>
        {t("void_back")}
      </Button>
    </div>
  );
}

export function VoidDialog({
  order,
  payment,
  onClose,
  onVoided,
}: {
  order: Order;
  /** the payment to void, chosen already (the order record's "Void payment" of one payment); the others stay offered */
  payment?: number;
  onClose: () => void;
  onVoided: () => void;
}) {
  const t = useTranslations("till");
  const locale = useLocale() as Locale;
  const no = useOrderNo(order);
  const errorText = useErrorText();
  const queries = useQueryClient();
  const closed = !UNPAID_STATUSES.includes(order.status);
  const hasPayments = compareDecimal(order.totals.paid, "0", 2) > 0;
  const wholeAllowed = !closed && !isSettled(order);
  const payments = useOrderPayments(hasPayments && !closed ? order.id : null);
  const voidable = (payments.data?.data ?? []).filter((p) => p.status !== "voided" && compareDecimal(p.refunded_amount, "0", 2) === 0);

  const [target, setTarget] = useState<Target | null>(payment ?? (hasPayments ? null : "order"));
  const [reason, setReason] = useState("");
  const [pin, setPin] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<unknown>(null);
  // one key per request: a retry of the same request (a double tap, a lost answer) is the same write
  const [attempt, setAttempt] = useState(() => uuid());

  const title = hasPayments && !wholeAllowed ? t("void_payment_title", { no }) : t("void_title", { no });
  const valid = target !== null && reason.trim() !== "" && PIN.test(pin);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!valid || pending || target === null) return;
    setPending(true);
    setError(null);
    try {
      if (target === "order") await voidOrder(order.id, { reason: reason.trim(), pin }, attempt);
      else await voidPayment(target, { reason: reason.trim(), pin }, attempt);
      void queries.invalidateQueries({ queryKey: ["order-payments", order.id] });
      onVoided();
      onClose();
    } catch (failure) {
      setError(failure);
      setPin("");
      // a refusal (a wrong PIN, a void the order no longer allows) wrote nothing: the next try is a new request with a new key. When the
      // outcome is unknown (no answer, a 5xx) the key is kept, so a void that did go through is answered again, not refused (4.5b's rule)
      if (!keepsKey(failure)) setAttempt(uuid());
      setPending(false);
    }
  }

  return (
    <Modal label={title} onClose={onClose}>
      <form onSubmit={submit} className="space-y-4">
        <h2 className="text-lg font-semibold text-neutral-900">{title}</h2>
        {closed ? (
          <RefundPlaceholder onClose={onClose} />
        ) : (
          <>
            <p className="text-base text-neutral-700">{hasPayments ? t("void_payment_intro") : t("void_intro")}</p>
            {error ? <Alert>{errorText.message(error)}</Alert> : null}
            {hasPayments ? (
              <fieldset className="space-y-2">
                <legend className="mb-1 text-sm font-medium text-neutral-800">{t("void_what")}</legend>
                {payments.isLoading ? (
                  <p role="status" className="text-base text-neutral-600">
                    {t("void_payments_loading")}
                  </p>
                ) : payments.error ? (
                  <Alert>{errorText.message(payments.error)}</Alert>
                ) : null}
                {wholeAllowed ? (
                  <label className="flex min-h-12 cursor-pointer items-center gap-3 rounded-md border border-neutral-300 px-4">
                    <input type="radio" name="void-target" className="h-5 w-5" checked={target === "order"} onChange={() => setTarget("order")} />
                    <span className="text-base">{t("void_whole_order")}</span>
                  </label>
                ) : null}
                {voidable.map((payment) => (
                  <label key={payment.id} className="flex min-h-12 cursor-pointer items-center gap-3 rounded-md border border-neutral-300 px-4">
                    <input type="radio" name="void-target" className="h-5 w-5" checked={target === payment.id} onChange={() => setTarget(payment.id)} />
                    <span className="text-base">{paymentLabel(payment, locale)}</span>
                  </label>
                ))}
              </fieldset>
            ) : null}
            <Field label={t("void_reason")}>
              {(props) => <TextInput {...props} value={reason} maxLength={255} autoFocus={!hasPayments} onChange={(event) => setReason(event.target.value)} />}
            </Field>
            <Field label={t("void_pin")} hint={t("void_pin_hint")}>
              {(props) => (
                <TextInput
                  {...props}
                  type="password"
                  inputMode="numeric"
                  autoComplete="off"
                  maxLength={6}
                  value={pin}
                  onChange={(event) => setPin(event.target.value.replace(/\D/g, ""))}
                />
              )}
            </Field>
            <div className="flex flex-wrap gap-2">
              <Button type="submit" disabled={!valid || pending}>
                {pending ? t("voiding") : target === "order" || (target === null && wholeAllowed && !hasPayments) ? t("void_submit") : t("void_payment_submit")}
              </Button>
              <Button type="button" variant="secondary" onClick={onClose}>
                {t("void_back")}
              </Button>
            </div>
          </>
        )}
      </form>
    </Modal>
  );
}
