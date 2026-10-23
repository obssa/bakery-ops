"use client";

import { useTranslations } from "next-intl";
import type { CashBlock, Count, VarianceReason } from "@/lib/api/types";
import { cashReview, signedMoney } from "@/lib/cash";
import { Alert, Button, Modal } from "../ui";
import { CashSummaryView } from "./CashSummaryView";

// The summary before a drawer count is handed in (step 3.7d): the figures as the server holds them, and what still blocks the submit
// (nothing declared, no float left at a closing, a float above what was counted, a difference with no reason, a reason that wants a note).

export function CashReviewDialog({
  count,
  cash,
  reasons,
  busy,
  failure,
  onSubmit,
  onClose,
}: {
  count: Count;
  cash: CashBlock;
  reasons: VarianceReason[];
  busy: boolean;
  failure: string | null;
  onSubmit: () => void;
  onClose: () => void;
}) {
  const t = useTranslations("counts");
  const review = cashReview(count.type, cash, reasons);

  return (
    <Modal label={t("cash_review_title")} size="lg" onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{t("cash_review_title")}</h2>
      <CashSummaryView count={count} cash={cash} />

      {review.missingDeclaration ? <Alert>{t("cash_block_declaration")}</Alert> : null}
      {review.missingFloat ? <Alert>{t("cash_block_float")}</Alert> : null}
      {review.floatTooLarge ? <Alert>{t("cash_float_too_large")}</Alert> : null}
      {review.missingReason ? (
        <Alert>{t("cash_block_reason", { variance: signedMoney(cash.variance_total), channels: review.channelDiffs.length })}</Alert>
      ) : null}
      {review.missingNote ? <Alert>{t("cash_block_note")}</Alert> : null}
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
