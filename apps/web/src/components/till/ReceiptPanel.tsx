"use client";

import { useLocale, useTranslations } from "next-intl";
import { useId, useRef, useState } from "react";
import { uuid } from "@/lib/api/idempotency";
import { receiptLink, receiptPdf, useReceiptSettings } from "@/lib/api/till";
import type { Locale, Order, PaymentChannel, ReceiptLink, RecordedPaymentRow } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { visibleLines } from "@/lib/live/pad";
import { useOrderNo } from "@/lib/orderNo";
import { keepsKey } from "@/lib/paySheet";
import { Alert, Button, Field, TextInput } from "../ui";

// The receipt of a bill the pay sheet just completed (step 4.5b): a preview on screen, the PDF (GET /orders/{order}/receipt.pdf, the
// bilingual receipt of step 4.3e, downloaded with the till's token) and a link to send the customer (POST /orders/{order}/receipt-link: a
// signed link to the public receipt, 72 hours). The preview is the order as the till holds it and the payments as the API recorded them (with
// the references the cashier typed, which the API does not echo back); the PDF is the API's and has the last word. A reference shows its last
// 4 characters only, as the printed receipt does.

const ADDIS = "Africa/Addis_Ababa";

function when(iso: string | undefined, locale: Locale): string {
  const date = iso ? new Date(iso) : new Date();
  return date.toLocaleString(locale === "am" ? "am-ET" : "en-GB", { timeZone: ADDIS, day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

export function ReceiptPanel({
  order,
  payments,
  channels,
  references,
}: {
  order: Order;
  payments: RecordedPaymentRow[];
  channels: PaymentChannel[];
  /** the references the cashier typed, by payment client_uuid (the API does not echo a reference back) */
  references: Record<string, string>;
}) {
  const t = useTranslations("till");
  const locale = useLocale() as Locale;
  const no = useOrderNo(order);
  const errorText = useErrorText();
  const settings = useReceiptSettings();
  const titleId = useId();
  const [busy, setBusy] = useState<"pdf" | "link" | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [link, setLink] = useState<ReceiptLink | null>(null);
  const [copied, setCopied] = useState<boolean | null>(null);
  // one link per receipt: a retry after a lost answer is the same request (the API replays the link it made)
  const linkKey = useRef(uuid());

  const channelName = (code: string) => {
    const found = channels.find((c) => c.code === code);
    return found ? (locale === "am" ? found.name_am : found.name_en) : code;
  };
  const business = settings.data ? (locale === "am" ? settings.data.business_name_am : settings.data.business_name_en) : null;
  const footer = settings.data ? (locale === "am" ? settings.data.footer_am : settings.data.footer_en) : null;

  async function downloadPdf() {
    setBusy("pdf");
    setError(null);
    try {
      const blob = await receiptPdf(order.id);
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = `receipt-${order.business_date ?? ""}-${order.order_no}.pdf`;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch (failure) {
      setError(failure);
    } finally {
      setBusy(null);
    }
  }

  async function copyLink() {
    setBusy("link");
    setError(null);
    try {
      const made = await receiptLink(order.id, linkKey.current);
      setLink(made);
      try {
        await navigator.clipboard.writeText(made.url);
        setCopied(true);
      } catch {
        // no clipboard here (a page that is not https, or permission refused): the link is shown to copy by hand
        setCopied(false);
      }
    } catch (failure) {
      setError(failure);
      if (!keepsKey(failure)) linkKey.current = uuid();
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="space-y-4">
      <section aria-labelledby={titleId} className="space-y-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-base text-neutral-900">
        <h3 id={titleId} className="text-sm font-semibold uppercase tracking-wide text-neutral-600">
          {t("receipt_preview")}
        </h3>
        <div className="space-y-0.5 text-center">
          {business ? <p className="text-lg font-semibold">{business}</p> : null}
          <p>{t("order_heading", { no })}</p>
          <p className="text-sm text-neutral-600">{when(payments[0]?.received_at, locale)}</p>
        </div>
        <ul className="divide-y divide-neutral-200">
          {visibleLines(order).map((line) => (
            <li key={line.id} className="flex justify-between gap-4 py-1">
              <span>
                {trimDecimal(line.qty)} × {locale === "am" ? line.item.name_am : line.item.name_en}
              </span>
              <span className="tabular-nums">{line.line_total}</span>
            </li>
          ))}
        </ul>
        <p className="flex justify-between border-t border-neutral-300 pt-2 text-lg font-semibold">
          <span>{t("receipt_total")}</span>
          <span className="tabular-nums">
            {order.totals.total} {order.currency}
          </span>
        </p>
        <ul className="space-y-1">
          {payments.map((payment) => (
            <li key={payment.id} className="space-y-0.5">
              <p className="flex justify-between gap-4">
                <span>{channelName(payment.channel_code)}</span>
                <span className="tabular-nums">{payment.amount}</span>
              </p>
              {referenceTail(references[payment.client_uuid]) ? (
                <p className="text-sm text-neutral-600">{t("receipt_reference_line", { tail: referenceTail(references[payment.client_uuid]) ?? "" })}</p>
              ) : null}
              {payment.tendered_amount !== null && payment.change_amount !== null ? (
                <p className="text-sm text-neutral-600">{t("receipt_cash_line", { tendered: payment.tendered_amount, change: payment.change_amount, currency: payment.currency })}</p>
              ) : null}
            </li>
          ))}
        </ul>
        {footer ? <p className="text-center text-sm text-neutral-600">{footer}</p> : null}
      </section>

      {error ? <Alert>{errorText.message(error)}</Alert> : null}
      <div className="flex flex-wrap gap-2">
        <Button type="button" variant="secondary" className="min-h-14" disabled={busy !== null} onClick={() => void downloadPdf()}>
          {busy === "pdf" ? t("receipt_pdf_busy") : t("receipt_pdf")}
        </Button>
        <Button type="button" variant="secondary" className="min-h-14" disabled={busy !== null} onClick={() => void copyLink()}>
          {busy === "link" ? t("receipt_link_busy") : t("receipt_link")}
        </Button>
      </div>
      {link ? (
        <Field label={t("receipt_link_field")} hint={copied ? t("receipt_link_copied") : t("receipt_link_copy_by_hand")}>
          {(props) => <TextInput {...props} readOnly value={link.url} onFocus={(event) => event.currentTarget.select()} />}
        </Field>
      ) : null}
    </div>
  );
}

/** The reference's last 4 characters, as the printed receipt shows it. */
export function referenceTail(reference: string | null | undefined): string | null {
  if (!reference) return null;
  const trimmed = reference.trim();
  return trimmed.length <= 4 ? trimmed : `…${trimmed.slice(-4)}`;
}
