"use client";

import { useLocale, useTranslations } from "next-intl";
import { useMemo, useState } from "react";
import type { Locale, StripRow } from "@/lib/api/types";
import { trimDecimal } from "@/lib/decimal";
import { useErrorText } from "@/lib/errors";
import { createStripFeed } from "@/lib/live/stations";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { can, useSession } from "@/lib/session";
import { RequestTransferSheet } from "../inventory/RequestTransferSheet";
import { Alert, Badge, Button, Panel } from "../ui";

// The stock strip of a kitchen or bar display: the ingredients held at the station's own place, the ones running out
// first, each with "Request transfer" to ask the store for more. A LiveFeed over GET /stations/{station}/stock-strip
// (ETag, 304) at the display's own cadence. Quantities show in the item's base unit as the API sends them ("1000 g").

export function StockStrip({ stationId }: { stationId: number }) {
  const t = useTranslations("stations");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const mayAsk = can(me, "transfers.request");
  const feed = useMemo(() => createStripFeed(stationId), [stationId]);
  const snapshot = useLiveFeed(feed);
  const [asking, setAsking] = useState<StripRow | null>(null);

  const place = snapshot?.state.locationId ?? null;
  const rows = snapshot?.state.rows ?? [];

  return (
    <Panel title={t("strip_title")}>
      {!snapshot?.loaded ? (
        snapshot?.error ? (
          <Alert>{errorText.message(snapshot.error)}</Alert>
        ) : (
          <p role="status" className="text-base text-neutral-600">
            {t("strip_loading")}
          </p>
        )
      ) : (
        <div className="space-y-3">
          {snapshot.error ? (
            <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
              {t("strip_stale")}
            </p>
          ) : null}
          {place === null ? (
            <p className="text-base text-neutral-700">{t("strip_no_place")}</p>
          ) : rows.length === 0 ? (
            <p className="text-base text-neutral-700">{t("strip_empty")}</p>
          ) : (
            <ul className="divide-y divide-neutral-200">
              {rows.map((row) => {
                const name = locale === "am" ? row.item.name_am : row.item.name_en;
                return (
                  <li key={row.item.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div className="min-w-0">
                      <p className="text-base font-medium text-neutral-900">{name}</p>
                      <p className="text-sm text-neutral-700">
                        {t("available", { qty: trimDecimal(row.available), unit: row.unit })}
                        {row.min_qty !== null ? ` · ${t("minimum", { qty: trimDecimal(row.min_qty), unit: row.unit })}` : ""}
                      </p>
                    </div>
                    <div className="flex items-center gap-3">
                      {row.state !== "ok" ? <Badge tone={row.state === "out" ? "red" : "amber"}>{t(row.state === "out" ? "state_out" : "state_low")}</Badge> : null}
                      {mayAsk ? (
                        <Button type="button" variant="secondary" aria-label={t("request_transfer_of", { name })} onClick={() => setAsking(row)}>
                          {t("request_transfer")}
                        </Button>
                      ) : null}
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}

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
    </Panel>
  );
}
