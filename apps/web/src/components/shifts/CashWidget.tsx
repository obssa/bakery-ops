"use client";

import { useQuery } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { ChevronDown } from "lucide-react";
import { useMemo, useState } from "react";
import { ApiError } from "@/lib/api/client";
import { getShiftCounts } from "@/lib/api/counts";
import type { Locale, Shift } from "@/lib/api/types";
import { tallyKeys } from "@/lib/cash";
import { multiplyDecimal } from "@/lib/decimal";
import { createCashFeed } from "@/lib/live/cash";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { useSession } from "@/lib/session";
import { focusRing } from "../styles";

// The till's cash so far (step 3.7d), beside the shift bar: the opening float by denomination, the cash taken in the shift by channel
// (GET /shifts/{shift}/cash, polled with an ETag like the other feeds), what the drawer should hold now, and the float the last closing
// left. It shows while a shift runs at a till that has a cash drawer; a station without one (422) shows nothing.

export function CashWidget({ shift, look = "panel" }: { shift: Shift | null; look?: "panel" | "summary" }) {
  if (shift === null || shift.station.code !== "till") return null;
  return <Loaded shift={shift} look={look} />;
}

function Loaded({ shift, look }: { shift: Shift; look: "panel" | "summary" }) {
  const t = useTranslations("shifts");
  const locale = useLocale() as Locale;
  const signedIn = useSession((s) => s.status === "signed-in");
  const feed = useMemo(() => createCashFeed(shift.id), [shift.id]);
  const snapshot = useLiveFeed(feed);
  const counts = useQuery({ queryKey: ["shift-counts", shift.id], queryFn: () => getShiftCounts(shift.id), enabled: signedIn, refetchInterval: 15_000 });
  // the till board puts the drawer's figure in the title row: the rest opens under it (step 6.3b)
  const [open, setOpen] = useState(false);

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

  const details = (
    <div className="space-y-4">
      <h2 className="text-lg font-semibold text-neutral-900">{t("cash_title")}</h2>
      <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div className="rounded-xl border border-neutral-200 px-4 py-3">
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
        <div className="rounded-xl border border-neutral-200 px-4 py-3">
          <dt className="text-sm text-neutral-600">{t("cash_taken")}</dt>
          <dd className="mt-1 text-xl font-semibold text-neutral-900">
            {summary.cash_sales_total} <span className="text-sm font-normal text-neutral-600">{currency}</span>
          </dd>
          <dd className="mt-1 text-sm text-neutral-600">{t("cash_taken_hint")}</dd>
        </div>
        <div className="rounded-xl border border-neutral-200 px-4 py-3">
          <dt className="text-sm text-neutral-600">{t("cash_expected")}</dt>
          <dd className="mt-1 text-xl font-semibold text-neutral-900">
            {summary.expected_total} <span className="text-sm font-normal text-neutral-600">{currency}</span>
          </dd>
          <dd className="mt-1 text-sm text-neutral-600">{t("cash_expected_hint")}</dd>
        </div>
        <div className="rounded-xl border border-neutral-200 px-4 py-3">
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
          <ul aria-label={t("cash_by_channel")} className="divide-y divide-neutral-200 rounded-xl border border-neutral-200">
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
    </div>
  );

  if (look === "panel") {
    return (
      <section aria-label={t("cash_title")} className="rounded-2xl border border-neutral-200 bg-white p-4">
        {details}
      </section>
    );
  }
  // `summary` (the Till board's title row, "Cash in drawer ETB 4,815.00"): what the drawer should hold now, as one button that opens the
  // opening float, the cash taken by channel and the last float under it
  return (
    <section aria-label={t("cash_title")} className="relative">
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((shown) => !shown)}
        className={`inline-flex min-h-11 items-center gap-2 rounded-xl px-3 text-sm text-neutral-600 hover:bg-neutral-100 motion-safe:transition-colors motion-safe:duration-150 ${focusRing}`}
      >
        {t("cash_title")}
        <span className="font-semibold tabular-nums text-neutral-900">
          {summary.expected_total} {currency}
        </span>
        <ChevronDown className={`size-4 motion-safe:transition-transform ${open ? "rotate-180" : ""}`} strokeWidth={2} aria-hidden="true" />
      </button>
      {open ? (
        <div className="absolute right-0 top-full z-30 mt-2 w-[min(44rem,calc(100vw-2rem))] rounded-[20px] border border-neutral-200 bg-white p-5 shadow-float">
          {details}
        </div>
      ) : null}
    </section>
  );
}
