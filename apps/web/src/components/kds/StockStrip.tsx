"use client";

import { PackagePlus, Plus } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useId, useMemo, useState } from "react";
import type { Locale, StockState, StripRow } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { createStripFeed } from "@/lib/live/stations";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { can, useSession } from "@/lib/session";
import { RequestTransferSheet } from "../inventory/RequestTransferSheet";
import { focusRing } from "../styles";
import { Alert } from "../ui";

// The stock strip of a kitchen or bar display (step 6.3b, fresh-counter.md 3.3): the ingredients held at the station's own place, the ones
// running out first, as a strip of chips along the bottom of the window, amber with "Low" when an ingredient runs low and red with "Out" when
// it is out (the word is there as well as the colour). Each chip's button asks the store for more ("Request transfer of <name>"). A LiveFeed
// over GET /stations/{station}/stock-strip (ETag, 304) at the display's own cadence. Quantities show in the item's base unit as the API sends
// them ("1000 g").
//
// phase-6/stock-requests (Ramin: the strip shows only 8 ingredients and there was no way to ask for anything else): the strip stays at its
// STRIP_ROWS rows, and "Request stock" at its end opens the request sheet with the item picked there (every item kept in stock, the ones the
// station does not hold yet among them, each with what the source has), the destination fixed to this station's place and the store as the
// source. Shown to a person who may request transfers, whenever the station has a place of its own.

const CHIP: Record<StockState, string> = {
  ok: "bg-night-divider text-neutral-700",
  low: "bg-night-waiting text-night-waiting-text",
  out: "bg-night-late text-night-late-text",
};

export function StockStrip({ stationId }: { stationId: number }) {
  const t = useTranslations("stations");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const titleId = useId();
  const me = useSession((s) => s.me);
  const mayAsk = can(me, "transfers.request");
  const feed = useMemo(() => createStripFeed(stationId), [stationId]);
  const snapshot = useLiveFeed(feed);
  const [asking, setAsking] = useState<StripRow | null>(null);
  const [picking, setPicking] = useState(false);

  const place = snapshot?.state.locationId ?? null;
  const rows = snapshot?.state.rows ?? [];

  return (
    <>
      {/* on a wall tablet the strip stays at the bottom of the window while the rail scrolls behind it */}
      <section
        aria-labelledby={titleId}
        className="-mx-4 border-t border-night-divider bg-night-ground px-4 py-3 sm:-mx-6 sm:px-6 lg:sticky lg:bottom-0 lg:z-10"
      >
      <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:gap-4">
        <h2 id={titleId} className="shrink-0 text-[13px] font-semibold uppercase tracking-[0.08em] text-night-text-muted">
          {t("strip_title")}
        </h2>
        {!snapshot?.loaded ? (
          snapshot?.error ? (
            <Alert>{errorText.message(snapshot.error)}</Alert>
          ) : (
            <p role="status" className="text-[15px] text-night-text-muted">
              {t("strip_loading")}
            </p>
          )
        ) : (
          <div className="min-w-0 flex-1 space-y-2">
            {snapshot.error ? (
              <p role="status" className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-2 text-[15px] text-amber-900">
                {t("strip_stale")}
              </p>
            ) : null}
            {place === null ? (
              <p className="text-[15px] text-night-text-muted">{t("strip_no_place")}</p>
            ) : rows.length === 0 ? (
              <p className="text-[15px] text-night-text-muted">{t("strip_empty")}</p>
            ) : (
              <ul className="flex max-h-[7.5rem] flex-wrap gap-2 overflow-y-auto">
                {rows.map((row) => {
                  const name = locale === "am" ? row.item.name_am : row.item.name_en;
                  return (
                    <li key={row.item.id} className={`inline-flex min-h-11 items-center gap-1 rounded-full pl-3.5 text-sm font-semibold ${mayAsk ? "pr-0" : "pr-3.5"} ${CHIP[row.state]}`}>
                      <span>
                        {name}
                        <span aria-hidden="true"> · </span>
                        <span className="tabular-nums">{t("available", { qty: trimDecimal(row.available), unit: row.unit })}</span>
                        {row.min_qty !== null ? (
                          <span className="font-medium opacity-90">
                            <span aria-hidden="true"> · </span>
                            {t("minimum", { qty: trimDecimal(row.min_qty), unit: row.unit })}
                          </span>
                        ) : null}
                        {row.state !== "ok" ? (
                          <>
                            <span aria-hidden="true"> · </span>
                            <span className="font-bold uppercase tracking-wide">{t(row.state === "out" ? "state_out" : "state_low")}</span>
                          </>
                        ) : null}
                      </span>
                      {mayAsk ? (
                        <button
                          type="button"
                          aria-label={t("request_transfer_of", { name })}
                          title={t("request_transfer")}
                          onClick={() => setAsking(row)}
                          className={`inline-flex size-11 shrink-0 items-center justify-center rounded-full hover:bg-black/25 motion-safe:transition-colors motion-safe:duration-150 ${focusRing}`}
                        >
                          <PackagePlus className="size-[18px]" strokeWidth={2} aria-hidden="true" />
                        </button>
                      ) : null}
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        )}
        {/* the way to the rest: any item kept in stock, the ones this place does not hold yet among them */}
        {mayAsk && place !== null ? (
          <button
            type="button"
            onClick={() => setPicking(true)}
            className={`inline-flex min-h-11 shrink-0 items-center gap-1.5 self-start rounded-full border border-night-border px-4 text-sm font-semibold text-night-text hover:bg-night-divider motion-safe:transition-colors motion-safe:duration-150 lg:ml-auto lg:self-center ${focusRing}`}
          >
            <Plus className="size-[18px]" strokeWidth={2} aria-hidden="true" />
            {t("request_stock")}
          </button>
        ) : null}
      </div>
      </section>

      {/* outside the sticky strip, so the sheet is not held in the strip's layer */}
      {asking && place !== null ? (
        <RequestTransferSheet
          subject={{
            itemId: asking.item.id,
            name: locale === "am" ? asking.item.name_am : asking.item.name_en,
            unit: asking.unit,
            toLocationId: place,
          }}
          onClose={() => setAsking(null)}
          onRequested={() => feed.refresh()}
        />
      ) : null}
      {picking && place !== null ? (
        <RequestTransferSheet pick={{ title: t("request_stock"), toLocationId: place }} onClose={() => setPicking(false)} onRequested={() => feed.refresh()} />
      ) : null}
    </>
  );
}
