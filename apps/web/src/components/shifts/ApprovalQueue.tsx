"use client";

import { useLocale, useTranslations } from "next-intl";
import { useMemo } from "react";
import { Link } from "@/i18n/navigation";
import type { Locale, QueueRow } from "@/lib/api/types";
import { signedQuantity, varianceOf } from "@/lib/counts";
import { trimDecimal } from "@/lib/decimal";
import { createQueueFeed } from "@/lib/live/counts";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { addisClock } from "@/lib/shifts";
import { Badge, Panel } from "../ui";
import { focusRing } from "../styles";
import { itemName } from "./CountLines";

// The admin's queue of counts that wait for a decision (step 3.7b): GET /stock-counts behind an ETag, polled every 5 s. A row says which
// count it is, where and when, who counted, and the lines that differ (expected, counted, the difference) so the approver sees at a glance
// whether it is worth opening; "Review" opens the side-by-side approval. The queue shows what the caller may see (the stations they work at,
// or all of them with stations.all).

const SHOWN_LINES = 4;

function Row({ row }: { row: QueueRow }) {
  const t = useTranslations("counts");
  const locale = useLocale() as Locale;
  const place = row.location ? (locale === "am" ? row.location.name_am || row.location.name_en : row.location.name_en) : "";
  const station = locale === "am" ? row.station.name_am : row.station.name_en;
  const lines = row.variance_lines;
  return (
    <li className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="space-y-1">
          <p className="flex flex-wrap items-center gap-3 text-lg font-semibold text-neutral-900">
            <span>{t("count_number", { id: row.id })}</span>
            <Badge tone="amber">{t(row.status === "awaiting_cosign" ? "stage_waiting_cosign_badge" : "stage_waiting_approval_badge")}</Badge>
            <Badge tone="neutral">{t(row.type === "opening" ? "type_opening" : "type_closing")}</Badge>
          </p>
          <p className="text-base text-neutral-700">
            {station} · {place} · {t("shift_date", { date: row.shift.date })}
          </p>
          <p className="text-sm text-neutral-600">
            {row.counted_by ? t("handed_in_by", { name: row.counted_by.name, time: addisClock(row.submitted_at) }) : null}
            {row.cosigned_by ? ` · ${t("cosigned_by", { name: row.cosigned_by.name })}` : ""}
          </p>
        </div>
        <Link
          href={{ pathname: "/admin/shifts/review", query: { count: row.id } }}
          aria-label={t("review_link", { id: row.id })}
          className={`inline-flex min-h-12 items-center justify-center rounded-md bg-blue-700 px-5 text-base font-medium text-white hover:bg-blue-800 ${focusRing}`}
        >
          {t("review_open")}
        </Link>
      </div>
      {lines.length === 0 ? (
        <p className="text-base text-neutral-600">{t("queue_no_variance")}</p>
      ) : (
        <ul className="divide-y divide-neutral-100 text-base">
          {lines.slice(0, SHOWN_LINES).map((line) => (
            <li key={line.id} className="flex flex-wrap items-baseline justify-between gap-x-4 py-1.5">
              <span className="font-medium text-neutral-900">{itemName(line, locale)}</span>
              <span className="text-neutral-700">
                {trimDecimal(line.expected_qty ?? "0")} → {trimDecimal(line.counted_qty ?? "0")} {line.item.unit}{" "}
                <strong className="text-neutral-900">{signedQuantity(line.variance_qty ?? varianceOf(line))}</strong>
              </span>
            </li>
          ))}
          {lines.length > SHOWN_LINES ? <li className="py-1.5 text-sm text-neutral-600">{t("queue_more", { count: lines.length - SHOWN_LINES })}</li> : null}
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
    <Panel title={t("queue_title")}>
      <div className="space-y-4">
        <p className="text-base text-neutral-600">{t("queue_intro")}</p>
        {loaded && state ? (
          <p className="flex flex-wrap gap-3">
            <Badge tone="amber">{t("queue_total_approval", { count: state.totals.pending_approval })}</Badge>
            <Badge tone="blue">{t("queue_total_cosign", { count: state.totals.awaiting_cosign })}</Badge>
          </p>
        ) : null}
        {!loaded && !snapshot?.error ? (
          <p role="status" className="text-base text-neutral-600">
            {t("loading")}
          </p>
        ) : null}
        {snapshot?.error ? (
          <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
            {loaded ? t("queue_stale") : t("queue_failed")}
          </p>
        ) : null}
        {loaded && state && state.rows.length === 0 ? <p className="text-base text-neutral-700">{t("queue_empty")}</p> : null}
        {state && state.rows.length > 0 ? (
          <ul className="space-y-3">
            {state.rows.map((row) => (
              <Row key={row.id} row={row} />
            ))}
          </ul>
        ) : null}
      </div>
    </Panel>
  );
}
