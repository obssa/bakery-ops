"use client";

import { useLocale, useTranslations } from "next-intl";
import type { Count, CountLine, Locale, VarianceReason } from "@/lib/api/types";
import { reviewOf, signedQuantity, varianceOf } from "@/lib/counts";
import { trimDecimal } from "@/lib/decimal";
import { Alert, Button, Modal } from "../ui";
import { itemName, reasonName } from "./CountLines";

// The summary before a count is handed in (step 3.7b): how many lines, how many differ and with what reason, and what still blocks the
// submit (a line not counted, a difference with no reason, a reason that asks for a note). The same three things the API refuses.

function Names({ lines, locale }: { lines: CountLine[]; locale: Locale }) {
  return <>{lines.map((line) => itemName(line, locale)).join(", ")}</>;
}

export function ReviewDialog({
  count,
  reasons,
  busy,
  failure,
  onSubmit,
  onClose,
}: {
  count: Count;
  reasons: VarianceReason[];
  busy: boolean;
  failure: string | null;
  onSubmit: () => void;
  onClose: () => void;
}) {
  const t = useTranslations("counts");
  const locale = useLocale() as Locale;
  const review = reviewOf(count.lines, reasons);
  const asExpected = review.total - review.untouched.length - review.changed.length;

  return (
    <Modal label={t("review_title")} onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{t("review_title")}</h2>
      <p className="text-base text-neutral-700">{t("review_summary", { total: review.total, same: asExpected, differ: review.changed.length })}</p>

      {review.changed.length > 0 ? (
        <ul className="divide-y divide-neutral-200 rounded-md border border-neutral-200">
          {review.changed.map((line) => (
            <li key={line.id} className="flex flex-wrap items-baseline justify-between gap-x-4 px-3 py-2 text-base">
              <span className="font-medium text-neutral-900">{itemName(line, locale)}</span>
              <span className="text-neutral-700">
                {trimDecimal(line.expected_qty ?? "0")} → {trimDecimal(line.counted_qty ?? "0")} {line.item.unit}{" "}
                <strong className="text-neutral-900">{signedQuantity(varianceOf(line))}</strong>
              </span>
              <span className="w-full text-sm text-neutral-600">
                {line.reason ? reasonName(line.reason, locale) : t("review_no_reason")}
                {line.note ? ` · ${line.note}` : ""}
              </span>
            </li>
          ))}
        </ul>
      ) : null}

      {review.untouched.length > 0 ? (
        <Alert>
          {t("review_untouched", { count: review.untouched.length })} <Names lines={review.untouched} locale={locale} />
        </Alert>
      ) : null}
      {review.missingReason.length > 0 ? (
        <Alert>
          {t("review_missing_reason", { count: review.missingReason.length })} <Names lines={review.missingReason} locale={locale} />
        </Alert>
      ) : null}
      {review.missingNote.length > 0 ? (
        <Alert>
          {t("review_missing_note", { count: review.missingNote.length })} <Names lines={review.missingNote} locale={locale} />
        </Alert>
      ) : null}
      {failure ? <Alert>{failure}</Alert> : null}

      <div className="flex flex-wrap justify-end gap-3">
        <Button type="button" variant="secondary" onClick={onClose}>
          {t("review_back")}
        </Button>
        <Button type="button" disabled={!review.ready || busy} onClick={onSubmit}>
          {busy ? t("working") : t("review_submit")}
        </Button>
      </div>
    </Modal>
  );
}
