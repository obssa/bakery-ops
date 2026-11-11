"use client";

import { useLocale, useTranslations } from "next-intl";
import { useMemo } from "react";
import { Link } from "@/i18n/navigation";
import type { Locale, QueueRow } from "@/lib/api/types";
import { signedQuantity, varianceOf } from "@/lib/counts";
import { trimDecimal } from "@/lib/decimal";
import { createQueueFeed, type QueueState } from "@/lib/live/counts";
import type { FeedSnapshot } from "@/lib/live/feed";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { useClock, useIsoDay } from "@/lib/useDates";
import { countWait } from "@/lib/waiting";
import { Notice } from "../admin/kit";
import { Chip, HelpTip, Skeleton } from "../ui";
import { buttonClass } from "../styles";
import { HowShiftsWork, OverviewCard, RunningShifts, SetupCheck } from "./admin/Overview";
import { itemName } from "./CountLines";

// The admin's queue of counts that wait for a decision (step 3.7b): GET /stock-counts behind an ETag, polled every 5 s. A row says which
// count it is, where and when, who counted, and the lines that differ (expected, counted, the difference) so the approver sees at a glance
// whether it is worth opening; "Review" opens the side-by-side approval. The queue shows what the caller may see (the stations they work at,
// or all of them with stations.all).
//
// Step 6.4d (fresh-counter.md 4.4): a white card titled 17 / 600 with the totals as chips; each count a row on a hairline, its stage and type
// as chips, "Review" the primary button at the row's end, the lines that differ under it in tabular figures.
//
// The explain-itself follow-up (Ramin 2026-11-10, "why someone must wait"): each stage chip has a "?" saying what it means, a line under
// the row says why it waits and who can act (never the person who counted) and that the place's next count waits for it, and the button
// says what the review is for: "Review and co-sign" or "Review and approve". The overview reads the queue once (ShiftsOverview below), and
// the running shifts read it as well, to say what each of them waits for.

const SHOWN_LINES = 4;

function Row({ row }: { row: QueueRow }) {
  const clock = useClock();
  const isoDay = useIsoDay();
  const t = useTranslations("counts");
  const locale = useLocale() as Locale;
  const place = row.location ? (locale === "am" ? row.location.name_am || row.location.name_en : row.location.name_en) : "";
  const station = locale === "am" ? row.station.name_am : row.station.name_en;
  const lines = row.variance_lines;
  const wait = countWait(row);
  const cosign = wait?.kind === "cosign";
  const why = wait ? t(`why_${wait.kind}${wait.counter ? "" : "_anon"}`, { station, place: place || t("this_place"), name: wait.counter ?? "" }) : null;
  return (
    <li className="space-y-2.5 py-4 first:pt-0 last:pb-0">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0 space-y-0.5">
          <p className="flex flex-wrap items-center gap-2.5">
            <span className="text-[15px] font-semibold text-neutral-900">{t("count_number", { id: row.id })}</span>
            <span className="inline-flex items-center gap-1">
              <Chip tone="amber" dot>
                {t(cosign ? "stage_waiting_cosign_badge" : "stage_waiting_approval_badge")}
              </Chip>
              <HelpTip label={t(cosign ? "chip_cosign_label" : "chip_approval_label")}>{t(cosign ? "chip_cosign_help" : "chip_approval_help")}</HelpTip>
            </span>
            <Chip tone="neutral">{t(row.type === "opening" ? "type_opening" : "type_closing")}</Chip>
          </p>
          <p className="text-[13px] text-neutral-600">
            {station} · {place} · {t("shift_date", { date: isoDay(row.shift.date) })}
            {row.counted_by ? ` · ${t("handed_in_by", { name: row.counted_by.name, time: clock(row.submitted_at) })}` : null}
            {row.cosigned_by ? ` · ${t("cosigned_by", { name: row.cosigned_by.name })}` : ""}
          </p>
        </div>
        <Link
          href={{ pathname: "/admin/shifts/review", query: { count: row.id } }}
          aria-label={t(cosign ? "review_link_cosign" : "review_link_approve", { id: row.id })}
          className={buttonClass({ variant: "primary", size: "md" })}
        >
          {t(cosign ? "review_cosign" : "review_approve")}
        </Link>
      </div>
      {why ? <p className="text-[13px] leading-snug text-amber-900">{why}</p> : null}
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

/** The counts waiting for a decision, from the queue feed the overview reads (ShiftsOverview). */
export function ApprovalQueue({ snapshot }: { snapshot: FeedSnapshot<QueueState> | null }) {
  const t = useTranslations("counts");
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

/**
 * The admin shifts overview under its tabs: how shifts work (folded away once read), the shifts running now beside the setup check from
 * 1280 px, and the counts waiting for a decision. The queue is read once here, for the queue itself and for what each running shift waits for.
 */
export function ShiftsOverview() {
  const feed = useMemo(() => createQueueFeed(), []);
  const snapshot = useLiveFeed(feed);
  return (
    <>
      <HowShiftsWork />
      <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <RunningShifts queue={snapshot?.state.rows ?? []} />
        <SetupCheck />
      </div>
      <ApprovalQueue snapshot={snapshot} />
    </>
  );
}
