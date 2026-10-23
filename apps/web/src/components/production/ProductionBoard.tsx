"use client";

import { useQuery } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import type { Collection, Locale, Station } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { boardColumns, COLUMNS, createProductionFeed, ownStationId } from "@/lib/live/production";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { authed, useSession } from "@/lib/session";
import { Alert, Field, PageHeader, Select } from "../ui";
import { ShiftBar } from "../shifts/ShiftBar";
import { useShiftStation } from "../shifts/useShiftStation";
import { RequestCard } from "./RequestCard";
import { RequestDetail } from "./RequestDetail";

// The production board (/production, docs/architecture.md section 6): Requested, In progress, Pending confirmation and
// Done today, polled every 5 s (30 s while hidden) through a LiveFeed over GET /production/requests?view=board with
// ETag / 304. Accepted cards sit in Requested with their baker shown. A baker who works at one production station sees
// that station first; everyone can widen the board to all stations. ?request=<id> (a notification link) opens that card.

const PRODUCTION_KINDS = ["cake_room", "bakery"] as const;

export function ProductionBoard() {
  const t = useTranslations("production");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const me = useSession((s) => s.me);
  const params = useSearchParams();

  const shiftStation = useShiftStation({ kinds: PRODUCTION_KINDS });
  const feed = useMemo(() => createProductionFeed(), []);
  const snapshot = useLiveFeed(feed);

  const [stationId, setStationId] = useState<number | "all">(() => ownStationId(me) ?? "all");
  const [openId, setOpenId] = useState<number | null>(() => {
    const requested = Number(params.get("request"));
    return Number.isInteger(requested) && requested > 0 ? requested : null;
  });

  // a notification link to this page while it is open names another card
  const linked = Number(params.get("request"));
  useEffect(() => {
    if (Number.isInteger(linked) && linked > 0) setOpenId(linked);
  }, [linked]);

  // the stations to filter by: the ones with requests on the board plus the person's own
  const stations = useQuery({
    queryKey: ["stations-of-board"],
    queryFn: async () => (await authed<Collection<Station>>("/stations")).data,
    enabled: !!me && me.permissions.includes("people.manage"),
  });
  const choices = useMemo(() => {
    const found = new Map<number, { id: number; name_en: string; name_am: string }>();
    for (const request of snapshot?.state.requests ?? []) found.set(request.station.id, request.station);
    for (const station of me?.user.stations ?? []) if (station.code === "cake_room" || station.code === "bakery") found.set(station.station_id, { id: station.station_id, name_en: station.name_en, name_am: station.name_am });
    for (const station of stations.data ?? []) if (station.code === "cake_room" || station.code === "bakery") found.set(station.id, station);
    return [...found.values()].sort((a, b) => a.id - b.id);
  }, [snapshot, me, stations.data]);

  const columns = useMemo(() => (snapshot ? boardColumns(snapshot.state, stationId) : null), [snapshot, stationId]);
  const total = columns ? COLUMNS.reduce((n, column) => n + columns[column].length, 0) : 0;
  const opened = openId !== null ? snapshot?.state.requests.find((request) => request.id === openId) : undefined;

  return (
    <div className="space-y-4">
      <PageHeader title={t("title")} intro={t("intro")} />
      <ShiftBar model={shiftStation} />

      {choices.length > 1 ? (
        <div className="w-64">
          <Field label={t("station")}>
            {(props) => (
              <Select {...props} value={String(stationId)} onChange={(event) => setStationId(event.target.value === "all" ? "all" : Number(event.target.value))}>
                <option value="all">{t("all_stations")}</option>
                {choices.map((station) => (
                  <option key={station.id} value={station.id}>
                    {locale === "am" ? station.name_am : station.name_en}
                  </option>
                ))}
              </Select>
            )}
          </Field>
        </div>
      ) : null}

      {snapshot?.error && snapshot.loaded ? (
        <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {t("stale")}
        </p>
      ) : null}

      {!snapshot?.loaded || !columns ? (
        snapshot?.error ? (
          <Alert>{errorText.message(snapshot.error)}</Alert>
        ) : (
          <p role="status" className="text-base text-neutral-600">
            {t("loading")}
          </p>
        )
      ) : (
        <div className={`grid gap-4 ${opened ? "xl:grid-cols-[minmax(0,1fr)_28rem]" : ""}`}>
          <div>
            {total === 0 ? <p className="mb-3 text-base text-neutral-600">{t("empty")}</p> : null}
            <div role="group" aria-label={t("board")} className="grid gap-3 md:grid-cols-2 2xl:grid-cols-4">
              {COLUMNS.map((column) => (
                <section key={column} aria-label={t(`col_${column}` as "col_requested")} className="space-y-3 rounded-lg bg-neutral-100 p-3">
                  <h2 className="flex items-center justify-between text-base font-semibold text-neutral-900">
                    <span>{t(`col_${column}` as "col_requested")}</span>
                    <span className="rounded-full bg-white px-2 py-0.5 text-sm text-neutral-700">{columns[column].length}</span>
                  </h2>
                  {columns[column].length === 0 ? (
                    <p className="text-sm text-neutral-500">{t("col_empty")}</p>
                  ) : (
                    columns[column].map((request) => <RequestCard key={request.id} request={request} selected={request.id === openId} onOpen={(r) => setOpenId(r.id === openId ? null : r.id)} />)
                  )}
                </section>
              ))}
            </div>
          </div>
          {opened ? <RequestDetail key={opened.id} summary={opened} onClose={() => setOpenId(null)} onChanged={() => feed.refresh()} /> : null}
        </div>
      )}
    </div>
  );
}
