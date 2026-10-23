"use client";

import { useQuery } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useMemo } from "react";
import { ApiError } from "@/lib/api/client";
import { getShiftCounts } from "@/lib/api/counts";
import type { Locale, Shift } from "@/lib/api/types";
import { tallyKeys } from "@/lib/cash";
import { multiplyDecimal } from "@/lib/decimal";
import { createCashFeed } from "@/lib/live/cash";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { useSession } from "@/lib/session";

// The till's cash so far (step 3.7d), beside the shift bar: the opening float by denomination, the cash taken in the shift by channel
// (GET /shifts/{shift}/cash, polled with an ETag like the other feeds), what the drawer should hold now, and the float the last closing
// left. It shows while a shift runs at a till that has a cash drawer; a station without one (422) shows nothing.

export function CashWidget({ shift }: { shift: Shift | null }) {
  if (shift === null || shift.station.code !== "till") return null;
  return <Loaded shift={shift} />;
}

function Loaded({ shift }: { shift: Shift }) {
  const t = useTranslations("shifts");
  const locale = useLocale() as Locale;
  const signedIn = useSession((s) => s.status === "signed-in");
  const feed = useMemo(() => createCashFeed(shift.id), [shift.id]);
  const snapshot = useLiveFeed(feed);
  const counts = useQuery({ queryKey: ["shift-counts", shift.id], queryFn: () => getShiftCounts(shift.id), enabled: signedIn, refetchInterval: 15_000 });

  const summary = snapshot?.state.summary ?? null;
  // the shift has no drawer, or this person may not read it: nothing to show
  if (!summary && snapshot?.error instanceof ApiError && snapshot.error.status !== 0) return null;
  if (!summary) return null;

  // the opening count of the drawer that counts now (a rejected one is replaced by its recount)
  const opening = (counts.data ?? [])
    .filter((count) => count.cash !== null && count.type === "opening" && count.superseded_by_id === null && count.status !== "rejected")
    .sort((a, b) => b.seq - a.seq)[0];
  const held = opening?.cash ? tallyKeys(opening.cash.denominations).filter((key) => (opening.cash?.denominations[key] ?? 0) > 0) : [];
  const currency = summary.currency;

  return (
    <section aria-label={t("cash_title")} className="space-y-4 rounded-lg border border-neutral-200 bg-white p-4">
      <h2 className="text-lg font-semibold text-neutral-900">{t("cash_title")}</h2>
      <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div className="rounded-md border border-neutral-200 px-4 py-3">
          <dt className="text-sm text-neutral-600">{t("cash_opening")}</dt>
          <dd className="mt-1 text-xl font-semibold text-neutral-900">
            {summary.opening_total} <span className="text-sm font-normal text-neutral-600">{currency}</span>
          </dd>
          {held.length > 0 && opening?.cash ? (
            <dd className="mt-1 text-sm text-neutral-600">
              {held.map((key) => t("cash_piece", { count: opening.cash?.denominations[key] ?? 0, value: key, amount: multiplyDecimal(key, String(opening.cash?.denominations[key] ?? 0), 2) })).join(" · ")}
            </dd>
          ) : (
            <dd className="mt-1 text-sm text-neutral-600">{opening ? t("cash_opening_empty") : t("cash_opening_carried")}</dd>
          )}
        </div>
        <div className="rounded-md border border-neutral-200 px-4 py-3">
          <dt className="text-sm text-neutral-600">{t("cash_taken")}</dt>
          <dd className="mt-1 text-xl font-semibold text-neutral-900">
            {summary.cash_sales_total} <span className="text-sm font-normal text-neutral-600">{currency}</span>
          </dd>
          <dd className="mt-1 text-sm text-neutral-600">{t("cash_taken_hint")}</dd>
        </div>
        <div className="rounded-md border border-neutral-200 px-4 py-3">
          <dt className="text-sm text-neutral-600">{t("cash_expected")}</dt>
          <dd className="mt-1 text-xl font-semibold text-neutral-900">
            {summary.expected_total} <span className="text-sm font-normal text-neutral-600">{currency}</span>
          </dd>
          <dd className="mt-1 text-sm text-neutral-600">{t("cash_expected_hint")}</dd>
        </div>
        <div className="rounded-md border border-neutral-200 px-4 py-3">
          <dt className="text-sm text-neutral-600">{t("cash_last_float")}</dt>
          <dd className="mt-1 text-xl font-semibold text-neutral-900">
            {summary.carried_float_total ?? "—"} {summary.carried_float_total !== null ? <span className="text-sm font-normal text-neutral-600">{currency}</span> : null}
          </dd>
          <dd className="mt-1 text-sm text-neutral-600">{summary.carried_float_total === null ? t("cash_last_float_none") : t("cash_last_float_hint")}</dd>
        </div>
      </dl>

      <div className="space-y-2">
        <h3 className="text-base font-semibold text-neutral-900">{t("cash_by_channel")}</h3>
        {summary.by_channel.length === 0 ? (
          <p className="text-base text-neutral-600">{t("cash_none_yet")}</p>
        ) : (
          <ul aria-label={t("cash_by_channel")} className="divide-y divide-neutral-200 rounded-md border border-neutral-200">
            {summary.by_channel.map((row) => (
              <li key={row.code} className="flex flex-wrap items-baseline justify-between gap-x-4 px-4 py-2 text-base">
                <span className="font-medium text-neutral-900">{locale === "am" ? row.name_am || row.name_en : row.name_en}</span>
                <span className="text-neutral-700">
                  {t("cash_channel_row", { receipts: row.receipts, sum: row.sum, currency })}
                </span>
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  );
}
