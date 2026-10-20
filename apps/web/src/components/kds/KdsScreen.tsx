"use client";

import { useQuery } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useMemo, useRef, useState } from "react";
import { authed, can, useSession } from "@/lib/session";
import type { Collection, Locale, Station, StationCode } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { playChime, storeMuted, storedMuted, unlockAudio } from "@/lib/kds";
import { createTicketsFeed, lineIdsOf, railOf } from "@/lib/live/tickets";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { onOutboxApplied } from "@/lib/outbox/store";
import { Alert, Field, PageHeader, Select } from "../ui";
import { focusRing } from "../styles";
import { TicketCard } from "./TicketCard";

// One display for a station kind (/kds/kitchen, /kds/bar): the user own station of that kind (a picker when there are
// several), its ticket rail through the live feed, a chime on new tickets with a mute toggle.

interface StationChoice {
  id: number;
  name_en: string;
  name_am: string;
}

function useStations(kind: StationCode): { stations: StationChoice[]; loading: boolean } {
  const me = useSession((s) => s.me);
  // admin and manager may work any station, and may list them (people.manage); others have only their assignments
  const lists = !!me && can(me, "stations.all") && can(me, "people.manage");
  const all = useQuery({
    queryKey: ["stations"],
    queryFn: async () => (await authed<Collection<Station>>("/stations")).data,
    enabled: lists,
  });
  const assigned = (me?.user.stations ?? []).filter((s) => s.code === kind).map((s) => ({ id: s.station_id, name_en: s.name_en, name_am: s.name_am }));
  const merged = new Map<number, StationChoice>(assigned.map((s) => [s.id, s]));
  if (lists) for (const s of all.data ?? []) if (s.code === kind) merged.set(s.id, { id: s.id, name_en: s.name_en, name_am: s.name_am });
  return { stations: [...merged.values()].sort((a, b) => a.id - b.id), loading: lists && all.isLoading };
}

export function KdsScreen({ kind }: { kind: "kitchen" | "bar" }) {
  const t = useTranslations("kds");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const { stations, loading } = useStations(kind);
  const [chosen, setChosen] = useState<number | null>(null);
  const stationId = stations.find((s) => s.id === chosen)?.id ?? stations[0]?.id ?? null;

  const feed = useMemo(() => (stationId === null ? null : createTicketsFeed(stationId)), [stationId]);
  const snapshot = useLiveFeed(feed);

  // a write of this screen was taken by the API: look at once instead of waiting for the next poll
  useEffect(() => onOutboxApplied(() => feed?.refresh()), [feed]);

  const rail = useMemo(() => (snapshot ? railOf(snapshot.state) : []), [snapshot]);

  // the chime: a line that was not on the rail before, after the first load
  const [muted, setMuted] = useState(false);
  const mutedRef = useRef(false);
  useEffect(() => {
    const stored = storedMuted();
    mutedRef.current = stored;
    setMuted(stored);
  }, []);
  const seen = useRef<Set<number> | null>(null);
  useEffect(() => {
    seen.current = null;
  }, [stationId]);
  useEffect(() => {
    if (!snapshot?.loaded) return;
    const now = lineIdsOf(snapshot.state);
    if (seen.current && !mutedRef.current && [...now].some((id) => !seen.current?.has(id))) playChime();
    seen.current = now;
  }, [snapshot]);

  function toggleMute() {
    unlockAudio();
    const next = !muted;
    mutedRef.current = next;
    setMuted(next);
    storeMuted(next);
  }

  const station = stations.find((s) => s.id === stationId);
  const title = kind === "kitchen" ? t("kitchen_title") : t("bar_title");

  return (
    // the first tap on the screen unlocks audio, as browsers require
    <div className="space-y-4" onPointerDown={unlockAudio} onKeyDown={unlockAudio}>
      <PageHeader title={title} />

      <div className="flex flex-wrap items-end gap-4">
        {stations.length > 1 ? (
          <div className="w-64">
            <Field label={t("station")}>
              {(props) => (
                <Select {...props} value={stationId ?? ""} onChange={(e) => setChosen(Number(e.target.value))}>
                  {stations.map((s) => (
                    <option key={s.id} value={s.id}>
                      {locale === "am" ? s.name_am : s.name_en}
                    </option>
                  ))}
                </Select>
              )}
            </Field>
          </div>
        ) : station ? (
          <p className="text-base text-neutral-600">{t("station_name", { name: locale === "am" ? station.name_am : station.name_en })}</p>
        ) : null}
        <button
          type="button"
          aria-pressed={muted}
          onClick={toggleMute}
          className={`inline-flex min-h-12 items-center rounded-md border border-neutral-300 px-4 text-base font-medium ${
            muted ? "bg-neutral-900 text-white" : "bg-white text-neutral-900 hover:bg-neutral-100"
          } ${focusRing}`}
        >
          {muted ? t("unmute") : t("mute")}
        </button>
      </div>

      {loading ? (
        <p role="status" className="text-base text-neutral-600">
          {t("loading")}
        </p>
      ) : stationId === null ? (
        <Alert>{kind === "kitchen" ? t("no_kitchen_station") : t("no_bar_station")}</Alert>
      ) : !snapshot?.loaded ? (
        snapshot?.error ? (
          <Alert>{errorText.message(snapshot.error)}</Alert>
        ) : (
          <p role="status" className="text-base text-neutral-600">
            {t("loading")}
          </p>
        )
      ) : (
        <>
          {snapshot.error ? (
            <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
              {t("stale")}
            </p>
          ) : null}
          {rail.length === 0 ? (
            <p role="status" className="rounded-md border border-neutral-200 bg-white px-4 py-6 text-base text-neutral-700">
              {t("no_tickets")}
            </p>
          ) : (
            <ul aria-label={t("tickets")} className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {rail.map((ticket) => (
                <li key={ticket.order.id}>
                  <TicketCard ticket={ticket} />
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  );
}
