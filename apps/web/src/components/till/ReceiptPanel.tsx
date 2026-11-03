"use client";

import { Copy } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useId, useRef, useState } from "react";
import { uuid } from "@/lib/api/idempotency";
import { receiptLink, receiptPdf, useReceiptSettings } from "@/lib/api/till";
import type { Locale, Order, PaymentChannel, ReceiptLink, RecordedPaymentRow } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { visibleLines } from "@/lib/live/pad";
import { useOrderNo } from "@/lib/orderNo";
import { keepsKey, referenceTail } from "@/lib/paySheet";
import { whenText, type DateFormat } from "@/lib/dates";
import { can, useSession } from "@/lib/session";
import { useDateFormat } from "@/lib/useDates";
import { Alert, Button, Field, TextInput } from "../ui";
import { PrintButton, PrintOffButton, PrintStatus, ReceiptQr, usePrintReceipt } from "./PrintReceipt";

// The receipt of a bill the pay sheet just completed (step 4.5b): a preview on screen, the PDF (GET /orders/{order}/receipt.pdf, the
// bilingual receipt of step 4.3e, downloaded with the till's token) and a link to send the customer (POST /orders/{order}/receipt-link: a
// signed link to the public receipt, 72 hours). The preview is the order as the till holds it and the payments as the API recorded them (with
// the references the cashier typed, which the API does not echo back); the PDF is the API's and has the last word. A reference shows its last
// 4 characters only, as the printed receipt does. The two actions are `ReceiptActions`, which the order record page (step 4.5d) shows too,
// each to the holders of its permission (the PDF: payments.view; the link: payments.take). Step 5.3c adds a third, Print (print.create:
// PrintReceipt.tsx), and the link's QR code; when the receipt cannot be printed the link is made at once for the customer to scan.
// Step 6.3b (fresh-counter.md 3.4): on the till this is the receipt zone at the sheet's right, so the actions are one under the other
// (`layout="stack"`: the code, the link, Print, the PDF); with printing off in the receipt settings, Print stays in its place, disabled,
// saying "off in Admin". Before the bill is paid the zone is ReceiptPending (the lines, the total, how the receipt will reach the customer).

const ADDIS = "Africa/Addis_Ababa";

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
  const settings = useReceiptSettings();
  // dated as the printed receipt is: the Gregorian date, and the Ethiopian one too when the receipt settings print it (step 4.5f)
  const dates = useDateFormat();
  const receiptDates: DateFormat = { ...dates, display: settings.data?.ethiopian_dates ? "both" : "gregorian" };
  const titleId = useId();

  const channelName = (code: string) => {
    const found = channels.find((c) => c.code === code);
    return found ? (locale === "am" ? found.name_am : found.name_en) : code;
  };
  const business = settings.data ? (locale === "am" ? settings.data.business_name_am : settings.data.business_name_en) : null;
  const footer = settings.data ? (locale === "am" ? settings.data.footer_am : settings.data.footer_en) : null;

  return (
    <div className="space-y-4">
      <section aria-labelledby={titleId} className="space-y-3 rounded-[14px] border border-neutral-200 bg-neutral-50 p-3.5 text-sm text-neutral-900">
        <h3 id={titleId} className="text-[13px] font-semibold uppercase tracking-[0.08em] text-neutral-600">
          {t("receipt_preview")}
        </h3>
        <div className="space-y-0.5 text-center">
          {business ? <p className="text-base font-semibold">{business}</p> : null}
          <p>{t("order_heading", { no })}</p>
          <p className="text-[13px] text-neutral-600">{whenText(payments[0]?.received_at ?? new Date().toISOString(), receiptDates, { withYear: true, timeZone: ADDIS })}</p>
        </div>
        <ReceiptLines order={order} />
        <p className="flex justify-between gap-3 border-t border-neutral-300 pt-2 text-base font-semibold">
          <span>{t("receipt_total")}</span>
          <span className="tabular-nums">
            {order.totals.total} {order.currency}
          </span>
        </p>
        <ul className="space-y-1">
          {payments.map((payment) => (
            <li key={payment.id} className="space-y-0.5">
              <p className="flex justify-between gap-3">
                <span>{channelName(payment.channel_code)}</span>
                <span className="tabular-nums">{payment.amount}</span>
              </p>
              {referenceTail(references[payment.client_uuid]) ? (
                <p className="text-[13px] text-neutral-600">{t("receipt_reference_line", { tail: referenceTail(references[payment.client_uuid]) ?? "" })}</p>
              ) : null}
              {payment.tendered_amount !== null && payment.change_amount !== null ? (
                <p className="text-[13px] text-neutral-600">{t("receipt_cash_line", { tendered: payment.tendered_amount, change: payment.change_amount, currency: payment.currency })}</p>
              ) : null}
            </li>
          ))}
        </ul>
        {footer ? <p className="text-center text-[13px] text-neutral-600">{footer}</p> : null}
      </section>

      <ReceiptActions order={order} layout="stack" />
    </div>
  );
}

/** The order's lines as a receipt reads them: the name and quantity, the line's total right-aligned in tabular figures. */
function ReceiptLines({ order }: { order: Order }) {
  const locale = useLocale() as Locale;
  return (
    <ul className="space-y-1.5">
      {visibleLines(order).map((line) => (
        <li key={line.id} className="flex justify-between gap-3">
          <span className="min-w-0">
            {locale === "am" ? line.item.name_am : line.item.name_en} × {trimDecimal(line.qty)}
          </span>
          <span className="shrink-0 tabular-nums">{line.line_total}</span>
        </li>
      ))}
    </ul>
  );
}

/**
 * The till's receipt zone while the bill is not paid yet (step 6.3b, fresh-counter.md 3.4): what the receipt will hold, the lines and the
 * total, and how it will reach the customer: printed, or (printing off in the receipt settings) the code the customer scans. The receipt
 * link and its code exist only once the order is paid (the API answers payments.receipt.unpaid before), so they come with the payment.
 */
export function ReceiptPending({ order }: { order: Order }) {
  const t = useTranslations("till");
  const titleId = useId();
  const mayPrint = useSession((s) => can(s.me, "print.create"));
  const settings = useReceiptSettings(mayPrint);
  const printingOff = mayPrint && settings.data?.printing_enabled === false;
  return (
    <section aria-labelledby={titleId} className="space-y-3 text-sm text-neutral-900">
      <div className="space-y-0.5">
        <h3 id={titleId} className="text-base font-semibold text-neutral-900">
          {t("receipt_title")}
        </h3>
        <p className="text-[13px] text-neutral-600">{printingOff ? t("receipt_printing_off") : t("receipt_after_payment")}</p>
      </div>
      {printingOff ? <PrintOffButton className="w-full" /> : null}
      <div className="space-y-2 border-t border-neutral-100 pt-3 text-[13px] text-neutral-600">
        <ReceiptLines order={order} />
        <p className="flex justify-between gap-3 border-t border-neutral-100 pt-2 text-sm font-semibold text-neutral-900">
          <span>{t("receipt_total")}</span>
          <span className="tabular-nums">
            {order.totals.total} {order.currency}
          </span>
        </p>
      </div>
    </section>
  );
}

/**
 * The receipt's actions: print it on the shop's receipt printer (POST /orders/{order}/print, print.create, step 5.3c), download the PDF
 * (GET /orders/{order}/receipt.pdf, payments.view) and copy a signed link for the customer (POST /orders/{order}/receipt-link,
 * payments.take), each shown to the holders of its permission; nothing when none is held. The order must be paid in full (the API
 * answers 409 payments.receipt.unpaid otherwise). The link shows with its QR code; when a print fails, expires or is refused for no
 * printer, the link is made without a press (once) so the customer can scan it.
 */
export function ReceiptActions({ order, layout = "row" }: { order: Pick<Order, "id" | "order_no" | "business_date">; layout?: "row" | "stack" }) {
  const t = useTranslations("till");
  const errorText = useErrorText();
  const canPdf = useSession((s) => can(s.me, "payments.view"));
  const canLink = useSession((s) => can(s.me, "payments.take"));
  const printing = usePrintReceipt(order.id);
  const [busy, setBusy] = useState<"pdf" | "link" | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [link, setLink] = useState<ReceiptLink | null>(null);
  const [copied, setCopied] = useState<boolean | null>(null);
  // one link per receipt: a retry after a lost answer is the same request (the API replays the link it made)
  const linkKey = useRef(uuid());

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

  async function makeLink(copy: boolean) {
    setBusy("link");
    setError(null);
    try {
      const made = await receiptLink(order.id, linkKey.current);
      setLink(made);
      if (!copy) {
        setCopied(null);
        return;
      }
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

  // the receipt cannot be printed: make the link once, without a press, for the customer to scan
  const fallbackAsked = useRef(false);
  useEffect(() => {
    if (!printing.fallback || !canLink || link || fallbackAsked.current) return;
    fallbackAsked.current = true;
    void makeLink(false);
    // makeLink is the same function every render; the ref keeps this to one link per screen
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [printing.fallback, canLink, link]);

  if (!canPdf && !canLink && !printing.canPrint && !printing.printingOff) return null;
  const stack = layout === "stack";
  const button = stack ? "w-full" : "min-h-14";
  const linkField = link ? (
    <Field label={t("receipt_link_field")} hint={copied === null ? t("receipt_link_scan") : copied ? t("receipt_link_copied") : t("receipt_link_copy_by_hand")}>
      {(props) => <TextInput {...props} readOnly value={link.url} onFocus={(event) => event.currentTarget.select()} />}
    </Field>
  ) : null;
  const copyButton = canLink ? (
    <Button type="button" variant="secondary" className={button} disabled={busy !== null} onClick={() => void makeLink(true)}>
      <Copy className="size-5" strokeWidth={1.75} aria-hidden="true" />
      {busy === "link" ? t("receipt_link_busy") : t("receipt_link")}
    </Button>
  ) : null;
  const printButton = printing.canPrint ? (
    <PrintButton state={printing} disabled={busy !== null} className={button} />
  ) : printing.printingOff ? (
    <PrintOffButton className={button} />
  ) : null;
  const pdfButton = canPdf ? (
    <Button type="button" variant="secondary" className={button} disabled={busy !== null} onClick={() => void downloadPdf()}>
      {busy === "pdf" ? t("receipt_pdf_busy") : t("receipt_pdf")}
    </Button>
  ) : null;

  if (stack) {
    // the till's receipt zone (fresh-counter.md 3.4): the code to scan on top, then the link, Print and the PDF, one under the other
    return (
      <div className="space-y-3">
        {error ? <Alert>{errorText.message(error)}</Alert> : null}
        <PrintStatus state={printing} />
        {link ? <ReceiptQr url={link.url} size={196} /> : null}
        {copyButton}
        {linkField}
        {printButton}
        {pdfButton}
      </div>
    );
  }
  return (
    <div className="space-y-4">
      {error ? <Alert>{errorText.message(error)}</Alert> : null}
      <PrintStatus state={printing} />
      <div className="flex flex-wrap gap-2">
        {printButton}
        {pdfButton}
        {copyButton}
      </div>
      {link ? (
        <div className="space-y-3">
          {linkField}
          <ReceiptQr url={link.url} />
        </div>
      ) : null}
    </div>
  );
}
