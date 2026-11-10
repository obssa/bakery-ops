"use client";

import { useLocale, useTranslations } from "next-intl";
import { useMemo } from "react";
import { Link } from "@/i18n/navigation";
import type { Locale, QueueRow } from "@/lib/api/types";
import { signedQuantity, varianceOf } from "@/lib/counts";
import { trimDecimal } from "@/lib/decimal";
import { createQueueFeed } from "@/lib/live/counts";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { useClock, useIsoDay } from "@/lib/useDates";
import { Notice } from "../admin/kit";
import { Chip, Skeleton } from "../ui";
import { buttonClass } from "../styles";
import { OverviewCard } from "./admin/Overview";
import { itemName } from "./CountLines";

// The admin's queue of counts that wait for a decision (step 3.7b): GET /stock-counts behind an ETag, polled every 5 s. A row says which
// count it is, where and when, who counted, and the lines that differ (expected, counted, the difference) so the approver sees at a glance
// whether it is worth opening; "Review" opens the side-by-side approval. The queue shows what the caller may see (the stations they work at,
// or all of them with stations.all).
//
// Step 6.4d (fresh-counter.md 4.4): a white card titled 17 / 600 with the totals as chips; each count a row on a hairline, its stage and type
// as chips, "Review" the primary button at the row's end, the lines that differ under it in tabular figures.

const SHOWN_LINES = 4;

function Row({ row }: { row: QueueRow }) {
  const clock = useClock();
  const isoDay = useIsoDay();
  const t = useTranslations("counts");
  const locale = useLocale() as Locale;
  const place = row.location ? (locale === "am" ? row.location.name_am || row.location.name_en : row.location.name_en) : "";
  const station = locale === "am" ? row.station.name_am : row.station.name_en;
  const lines = row.variance_lines;
  return (
    <li className="space-y-2.5 py-4 first:pt-0 last:pb-0">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0 space-y-0.5">
          <p className="flex flex-wrap items-center gap-2.5">
            <span className="text-[15px] font-semibold text-neutral-900">{t("count_number", { id: row.id })}</span>
            <Chip tone="amber" dot>
              {t(row.status === "awaiting_cosign" ? "stage_waiting_cosign_badge" : "stage_waiting_approval_badge")}
            </Chip>
            <Chip tone="neutral">{t(row.type === "opening" ? "type_opening" : "type_closing")}</Chip>
          </p>
          <p className="text-[13px] text-neutral-600">
            {station} · {place} · {t("shift_date", { date: isoDay(row.shift.date) })}
            {row.counted_by ? ` · ${t("handed_in_by", { name: row.counted_by.name, time: clock(row.submitted_at) })}` : null}
            {row.cosigned_by ? ` · ${t("cosigned_by", { name: row.cosigned_by.name })}` : ""}
          </p>
        </div>
        <Link href={{ pathname: "/admin/shifts/review", query: { count: row.id } }} aria-label={t("review_link", { id: row.id })} className={buttonClass({ variant: "primary", size: "md" })}>
          {t("review_open")}
        </Link>
      </div>
      {lines.length === 0 ? (
        <p className="text-[13px] text-neutral-600">{t("queue_no_variance")}</p>
      ) : (
        <ul className="divide-y divide-neutral-100 rounded-xl border border-neutral-200 px-4 text-[15px]">
          {lines.slice(0, SHOWN_LINES).map((line) => (
            <li key={line.id} className="flex flex-wrap items-baseline justify-between gap-x-4 py-2">
              <span className="font-medium text-neutral-900">{itemName(line, locale)}</span>
              <span className="tabular-nums text-neutral-700">
                {trimDecimal(line.expected_qty ?? "0")} → {trimDecimal(line.counted_qty ?? "0")} {line.item.unit}{" "}
                <strong className="font-semibold text-neutral-900">{signedQuantity(line.variance_qty ?? varianceOf(line))}</strong>
              </span>
            </li>
          ))}
          {lines.length > SHOWN_LINES ? <li className="py-2 text-[13px] text-neutral-600">{t("queue_more", { count: lines.length - SHOWN_LINES })}</li> : null}
        </ul>
      )}
    </li>
  );
}

export function ApprovalQueue() {
  const t = useTranslations("counts");
  const feed = useMemo(() => createQueueFeed(), []);
  const snapshot = useLiveFeed(feed);
  const state = snapshot?.state;
  const loaded = !!snapshot?.loaded;

  return (
    <OverviewCard
      title={t("queue_title")}
      aside={
        loaded && state ? (
          <span className="flex flex-wrap gap-2">
            <Chip tone="amber">{t("queue_total_approval", { count: state.totals.pending_approval })}</Chip>
            <Chip tone="blue">{t("queue_total_cosign", { count: state.totals.awaiting_cosign })}</Chip>
          </span>
        ) : null
      }
    >
      <p className="-mt-2 text-[13px] text-neutral-600">{t("queue_intro")}</p>
      {!loaded && !snapshot?.error ? (
        <>
          <p role="status" className="sr-only">
            {t("loading")}
          </p>
          <div aria-hidden="true" className="space-y-3">
            <Skeleton className="h-14" />
            <Skeleton className="h-14" />
          </div>
        </>
      ) : null}
      {snapshot?.error ? (
        <Notice tone="amber" live>
          {loaded ? t("queue_stale") : t("queue_failed")}
        </Notice>
      ) : null}
      {loaded && state && state.rows.length === 0 ? <p className="text-[15px] text-neutral-600">{t("queue_empty")}</p> : null}
      {state && state.rows.length > 0 ? (
        <ul className="divide-y divide-neutral-200">
          {state.rows.map((row) => (
            <Row key={row.id} row={row} />
          ))}
        </ul>
      ) : null}
    </OverviewCard>
  );
}
