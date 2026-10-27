"use client";

import { useLocale, useTranslations } from "next-intl";
import type { Locale, ProductionRequest } from "@/lib/api/types";
import { whenText, type DateFormatLike } from "@/lib/dates";
import { trimDecimal } from "@/lib/decimal";
import { useDateFormat } from "@/lib/useDates";
import { Badge } from "../ui";
import { focusRing } from "../styles";

// One card of the board: product, quantity, why it was asked, when it is due, who asked and who has it. Tapping it opens
// the request card (RequestDetail). The words carry the status; colour only adds to them.

/** A due time or a publish time: "27 Oct, 14:05", in the person's calendar with their date format (lib/dates.ts whenText, step 4.5f). */
export function formatWhen(iso: string, fmt: DateFormatLike): string {
  return whenText(iso, fmt);
}

export function RequestCard({ request, selected, onOpen }: { request: ProductionRequest; selected: boolean; onOpen: (request: ProductionRequest) => void }) {
  const t = useTranslations("production");
  const locale = useLocale() as Locale;
  const dates = useDateFormat();
  const name = locale === "am" ? request.product.name_am : request.product.name_en;
  const batch = request.batch;
  const made = batch?.produced_qty != null && batch.waste_qty != null;

  return (
    <article className={`space-y-2 rounded-lg border-2 bg-white p-3 ${selected ? "border-blue-700" : "border-neutral-200"}`}>
      <button type="button" aria-label={t("open", { name })} aria-pressed={selected} onClick={() => onOpen(request)} className={`block w-full rounded-md text-left ${focusRing}`}>
        <span className="block text-lg font-semibold text-neutral-900">{name}</span>
        <span className="block text-base text-neutral-800">{t("qty", { qty: trimDecimal(request.requested_qty), unit: request.product.unit })}</span>
      </button>
      <div className="flex flex-wrap gap-1.5">
        <Badge tone={request.reason === "stock_out" ? "red" : "neutral"}>{t(`reason_${request.reason}` as "reason_stock_out")}</Badge>
        {request.priority !== "normal" ? <Badge tone={request.priority === "urgent" ? "red" : "amber"}>{t(`priority_${request.priority}` as "priority_high")}</Badge> : null}
        {request.status === "accepted" ? <Badge tone="blue">{t("status_accepted")}</Badge> : null}
      </div>
      <div className="space-y-0.5 text-base text-neutral-700">
        {request.due_at ? <p>{t("due", { time: formatWhen(request.due_at, dates) })}</p> : null}
        {request.requested_by ? <p>{t("asked_by", { name: request.requested_by.name })}</p> : null}
        {request.assigned_to ? <p>{request.status === "accepted" ? t("accepted_by", { name: request.assigned_to.name }) : t("baker", { name: request.assigned_to.name })}</p> : null}
        {made && batch ? (
          <p>
            {t("made", { produced: trimDecimal(batch.produced_qty ?? "0"), waste: trimDecimal(batch.waste_qty ?? "0"), unit: request.product.unit })}
            {batch.finished_by ? ` · ${t("finished_by", { name: batch.finished_by.name })}` : ""}
          </p>
        ) : null}
        {batch?.confirmed_by ? <p>{t("confirmed_by", { name: batch.confirmed_by.name })}</p> : null}
        {batch?.send_back_note && request.status === "in_progress" ? <p className="font-medium text-amber-900">{t("sent_back", { note: batch.send_back_note })}</p> : null}
      </div>
    </article>
  );
}
