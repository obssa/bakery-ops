"use client";

import { useQuery } from "@tanstack/react-query";
import { Bell, BellOff } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useId, useMemo, useRef, useState } from "react";
import { authed, can, useSession } from "@/lib/session";
import type { Collection, Locale, Station, StationCode, Ticket } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { columnsOf, KDS_COLUMNS, playChime, storeMuted, storedMuted, unlockAudio, type KdsColumn } from "@/lib/kds";
import { createTicketsFeed, lineIdsOf, railOf } from "@/lib/live/tickets";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { onOutboxApplied } from "@/lib/outbox/store";
import { addisClock } from "@/lib/shifts";
import { useStationHeader } from "@/lib/topBar";
import { useNow } from "@/lib/useNow";
import { Alert, Chip, IconButton, Select } from "../ui";
import { StationHeaderActions } from "../StationHeaderActions";
import { ICON_STROKE } from "../icons";
import { ShiftBar } from "../shifts/ShiftBar";
import { useShiftStation } from "../shifts/useShiftStation";

import { StockStrip } from "./StockStrip";
import { TicketCard } from "./TicketCard";
import type { KdsTwins } from "./twins";

// One display for a station kind (/kds/kitchen, /kds/bar): the user own station of that kind (a picker when there are several), its ticket
// rail through the live feed, a chime on new tickets with a mute toggle. Step 6.3b (fresh-counter.md 3.3): kitchen night, the one dark
// screen (the shell scopes the night palette to this route, globals.css `.kitchen-night`). The title row carries the feed's state ("Live"),
// the clock and the mute toggle; then the shift bar; then three columns, New, Cooking and Ready, each with its count, the tickets standing
// in the column of their least advanced line (lib/kds.ts columnOf); the stock strip stays at the bottom of the window. No product photos:
// the display is read at a glance, and its lines are text on purpose (phase-6 plan 10.2 Q17).

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
  // the stations this person may work at: their assignments, or every station for stations.all (GET /me work_stations)
  const worksAt = (me?.work_stations ?? []).filter((s) => s.code === kind).map((s) => ({ id: s.station_id, name_en: s.name_en, name_am: s.name_am }));
  const assigned = [...(me?.user.stations ?? []).filter((s) => s.code === kind).map((s) => ({ id: s.station_id, name_en: s.name_en, name_am: s.name_am })), ...worksAt];
  const merged = new Map<number, StationChoice>(assigned.map((s) => [s.id, s]));
  if (lists) for (const s of all.data ?? []) if (s.code === kind) merged.set(s.id, { id: s.id, name_en: s.name_en, name_am: s.name_am });
  return { stations: [...merged.values()].sort((a, b) => a.id - b.id), loading: lists && all.isLoading };
}

/** The wall clock of the display, in Addis Ababa time (big, tabular: read across the room). */
function WallClock() {
  const now = useNow(15_000);
  const iso = new Date(now).toISOString();
  return (
    <time dateTime={iso} className="text-[26px] font-semibold leading-none tabular-nums text-night-text">
      {addisClock(iso)}
    </time>
  );
}

// the middle column is "Cooking" in the kitchen and "Making" at the bar
const COLUMN_TITLE: Record<"kitchen" | "bar", Record<KdsColumn, string>> = {
  kitchen: { new: "column_new", cooking: "column_cooking", ready: "column_ready" },
  bar: { new: "column_new", cooking: "column_making", ready: "column_ready" },
};
const COLUMN_EMPTY: Record<"kitchen" | "bar", Record<KdsColumn, string>> = {
  kitchen: { new: "column_new_empty", cooking: "column_cooking_empty", ready: "column_ready_empty" },
  bar: { new: "column_new_empty", cooking: "column_making_empty", ready: "column_ready_empty" },
};

function Column({ kind, column, tickets, twin }: { kind: "kitchen" | "bar"; column: KdsColumn; tickets: Ticket[]; twin?: string }) {
  const t = useTranslations("kds");
  const titleId = useId();
  return (
    <section aria-labelledby={titleId} className="flex min-w-0 flex-col gap-3">
      <div className="flex items-center justify-between gap-3 px-1">
        <div className="flex min-w-0 items-baseline gap-2.5">
          <h2 id={titleId} className="text-lg font-semibold text-night-text">
            {t(COLUMN_TITLE[kind][column])}
          </h2>
          {twin ? (
            <span lang="am" className="truncate text-[15px] font-medium text-night-text-muted">
              {twin}
            </span>
          ) : null}
        </div>
        <span className="inline-flex h-8 min-w-8 items-center justify-center rounded-full bg-night-divider px-2.5 text-[15px] font-semibold tabular-nums text-night-text">
          {tickets.length}
        </span>
      </div>
      {tickets.length === 0 ? (
        <p className="rounded-[18px] border border-dashed border-night-border px-4 py-6 text-center text-[15px] text-night-text-muted">{t(COLUMN_EMPTY[kind][column])}</p>
      ) : (
        <ul aria-labelledby={titleId} className="flex flex-col gap-3">
          {tickets.map((ticket) => (
            <li key={ticket.order.id}>
              <TicketCard ticket={ticket} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

export function KdsScreen({ kind, twins }: { kind: "kitchen" | "bar"; /** the Amharic names beside the English ones (the English page) */ twins?: KdsTwins }) {
  const t = useTranslations("kds");
  const locale = useLocale() as Locale;
  const errorText = useErrorText();
  const { stations, loading } = useStations(kind);
  const [chosen, setChosen] = useState<number | null>(null);
  const stationId = stations.find((s) => s.id === chosen)?.id ?? stations[0]?.id ?? null;
  const shiftStation = useShiftStation({ stationId });

  const feed = useMemo(() => (stationId === null ? null : createTicketsFeed(stationId)), [stationId]);
  const snapshot = useLiveFeed(feed);

  // a write of this screen was taken by the API: look at once instead of waiting for the next poll
  useEffect(() => onOutboxApplied(() => feed?.refresh()), [feed]);

  const rail = useMemo(() => (snapshot ? railOf(snapshot.state) : []), [snapshot]);
  const columns = useMemo(() => columnsOf(rail), [rail]);

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

  const title = kind === "kitchen" ? t("kitchen_title") : t("bar_title");
  const loaded = !!snapshot?.loaded;
  const MuteIcon = muted ? BellOff : Bell;
  const twin: Record<KdsColumn, string | undefined> = { new: twins?.new, cooking: twins?.cooking, ready: twins?.ready };
  // the top bar names the display, in its night look; its line is the shift and who is on it (the shift feed's, as on every station)
  useStationHeader({ title, titleAm: twins?.title, night: true });

  return (
    // the first tap on the screen unlocks audio, as browsers require
    <div className="flex flex-col gap-5" onPointerDown={unlockAudio} onKeyDown={unlockAudio}>
      {/* the Kitchen board's header is the one station top bar (lib/topBar.ts): the display's name, its Amharic name and the shift's line
          there, in its night look; the shift's menu, the feed's state, the wall clock and the chime on its right. The page's heading is the
          same name, for a reader */}
      <h1 className="sr-only">{title}</h1>
      <StationHeaderActions>
        {stations.length > 1 ? (
          <label className="flex items-center gap-2 text-sm font-semibold text-night-text-muted">
            <span className="sr-only">{t("station")}</span>
            <Select controlSize="md" value={stationId ?? ""} onChange={(e) => setChosen(Number(e.target.value))} className="w-48">
              {stations.map((s) => (
                <option key={s.id} value={s.id}>
                  {locale === "am" ? s.name_am : s.name_en}
                </option>
              ))}
            </Select>
          </label>
        ) : null}
        {stationId !== null && loaded ? (
          // the rail's own state: live, or showing what was last read (the stale line below says why)
          <Chip tone={snapshot?.error ? "amber" : "brand"} dot className="min-h-[34px] px-3.5 text-sm">
            {snapshot?.error ? t("feed_stale") : t("feed_live")}
          </Chip>
        ) : null}
        <WallClock />
        <IconButton label={muted ? t("unmute") : t("mute")} title={muted ? t("unmute") : t("mute")} aria-pressed={muted} variant={muted ? "dark" : "ghost"} onClick={toggleMute}>
          <MuteIcon className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
        </IconButton>
      </StationHeaderActions>
      <ShiftBar model={shiftStation} look="menu" />

      {loading ? (
        <p role="status" className="text-base text-night-text-muted">
          {t("loading")}
        </p>
      ) : stationId === null ? (
        <Alert>{kind === "kitchen" ? t("no_kitchen_station") : t("no_bar_station")}</Alert>
      ) : !loaded ? (
        snapshot?.error ? (
          <Alert>{errorText.message(snapshot.error)}</Alert>
        ) : (
          <p role="status" className="text-base text-night-text-muted">
            {t("loading")}
          </p>
        )
      ) : (
        <>
          {snapshot?.error ? (
            <p role="status" className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
              {t("stale")}
            </p>
          ) : null}
          {/* said once for a screen reader when the rail is empty; each column also shows that it has nothing */}
          <p role="status" className="sr-only">
            {rail.length === 0 ? t("no_tickets") : ""}
          </p>
          <div className="grid min-h-[calc(100dvh-24rem)] items-start gap-5 md:grid-cols-3">
            {KDS_COLUMNS.map((column) => (
              <Column key={column} kind={kind} column={column} tickets={columns[column]} twin={twin[column]} />
            ))}
          </div>
        </>
      )}

      {stationId !== null ? <StockStrip stationId={stationId} /> : null}
    </div>
  );
}
