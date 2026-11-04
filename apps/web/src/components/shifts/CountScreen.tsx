"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { getNotes, getShiftCounts, getVarianceReasons } from "@/lib/api/counts";
import type { Count, HandoverNote, Locale, Shift, ShiftStatus } from "@/lib/api/types";
import { currentCounts, phaseOf } from "@/lib/counts";
import { useErrorText } from "@/lib/errors";
import { authed, useSession } from "@/lib/session";
import { addisClock } from "@/lib/shifts";
import { useActiveStation } from "@/lib/station";
import { useStationHeader } from "@/lib/topBar";
import { useIsoDay } from "@/lib/useDates";
import { ICON_STROKE } from "../icons";
import { StationHeaderActions } from "../StationHeaderActions";
import { focusRing } from "../styles";
import { Alert, Button, EmptyState, Panel } from "../ui";
import { useRouter } from "@/i18n/navigation";
import { CountPanel, StageBadge } from "./CountPanel";
import { HandoverPanel } from "./HandoverPanel";
import { NoteForm, NotesList } from "./Notes";
import { ShiftBar } from "./ShiftBar";
import { useShiftStation } from "./useShiftStation";

// The count screen (step 3.7b; on the Fresh Counter primitives since step 6.3c), /shifts/count?shift=<id>: one component for the opening and
// the closing count. The station top bar names it (lib/topBar.ts): "Shift count" with its Amharic name on the English page, the station,
// the shift's state, day and since when under it, and the station's shift menu and "Back to the station" among the bar's buttons. Under
// the bar: the intro, the handover from the last shift above an opening count, the places to count as a row of station segments (each with the stage
// it is at), the count of the chosen place, and the handover notes. It shows the counts of the shift for the phase it is in (the opening
// ones while the shift is being opened, the closing ones from the moment it is closed), one place at a time, and polls the shift and its
// counts every 6 s so that a hand-in by someone else, an approval or a rejection shows without a reload.

/** Names the screen in the station top bar and is the page's h1 (read by screen readers and the specs; the bar shows the name). */
function Named({ title, titleAm, subtitle }: { title: string; titleAm?: string; subtitle?: string }) {
  useStationHeader({ title, titleAm, subtitle });
  return <h1 className="sr-only">{title}</h1>;
}

const POLL_MS = 6000;

const NOTE_STATUSES: readonly ShiftStatus[] = ["opening", "open", "counting", "pending_approval"];

export function CountScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("counts");
  const params = useSearchParams();
  const raw = params.get("shift") ?? "";
  if (!/^\d+$/.test(raw)) {
    return (
      <div className="max-w-xl space-y-5">
        <Named title={t("title")} titleAm={titleAm} />
        <Alert>{t("no_shift")}</Alert>
      </div>
    );
  }
  return <Loaded shiftId={Number(raw)} titleAm={titleAm} />;
}

function Loaded({ shiftId, titleAm }: { shiftId: number; titleAm?: string }) {
  const isoDay = useIsoDay();
  const t = useTranslations("counts");
  const ts = useTranslations("shifts");
  const locale = useLocale() as Locale;
  const router = useRouter();
  const errorText = useErrorText();
  const client = useQueryClient();
  const signedIn = useSession((s) => s.status === "signed-in");
  const [chosen, setChosen] = useState<number | null>(null);

  const shiftQuery = useQuery({
    queryKey: ["shift", shiftId],
    queryFn: () => authed<Shift>(`/shifts/${shiftId}`),
    enabled: signedIn,
    refetchInterval: POLL_MS,
  });
  const countsQuery = useQuery({
    queryKey: ["shift-counts", shiftId],
    queryFn: async () => {
      const fresh = await getShiftCounts(shiftId);
      // a write of this screen may have answered with a newer revision than the list a slow poll started from: keep the newer one
      const known = client.getQueryData<Count[]>(["shift-counts", shiftId]) ?? [];
      return fresh.map((count) => {
        const have = known.find((other) => other.id === count.id);
        return have && have.version > count.version ? have : count;
      });
    },
    enabled: signedIn,
    refetchInterval: POLL_MS,
  });
  const reasonsQuery = useQuery({ queryKey: ["variance-reasons"], queryFn: getVarianceReasons, enabled: signedIn, staleTime: 60_000 });
  const notesQuery = useQuery({ queryKey: ["shift-notes", shiftId], queryFn: () => getNotes(shiftId), enabled: signedIn, refetchInterval: 8000 });

  const shift = shiftQuery.data ?? null;
  const stationId = shift?.station.id ?? null;

  // every call this screen makes names the station it works at (X-Station-Id)
  useEffect(() => {
    if (stationId === null) return;
    useActiveStation.getState().set(stationId);
    return () => useActiveStation.getState().set(null);
  }, [stationId]);

  // the station's shift as the menu in the station top bar (ShiftBar look="menu", as on every station screen): its state as a chip, its
  // actions in the panel on a tap; `quiet`, because the count in progress its strip would point to is the one on this screen
  const shiftStation = useShiftStation({ stationId });

  const all = useMemo(() => countsQuery.data ?? [], [countsQuery.data]);
  const current = useMemo(() => currentCounts(all), [all]);
  const phase = phaseOf(shift?.status ?? "opening", current);
  const shown = useMemo(() => current.filter((count) => count.type === phase), [current, phase]);
  const selected = shown.find((count) => count.location?.id === chosen) ?? shown.find((count) => count.status === "draft") ?? shown[0] ?? null;

  function replace(next: Count) {
    client.setQueryData<Count[]>(["shift-counts", shiftId], (list) => {
      const rest = (list ?? []).filter((count) => count.id !== next.id);
      return [...rest, next].sort((a, b) => a.id - b.id);
    });
    void client.invalidateQueries({ queryKey: ["shift-counts", shiftId] });
  }

  function placeName(count: Count): string {
    const place = count.location;
    if (!place) return "";
    return locale === "am" ? place.name_am || place.name_en : place.name_en;
  }

  // "Back to the station", in the station top bar before the sync chip
  const back = (
    <StationHeaderActions>
      <Button type="button" variant="secondary" size="md" onClick={() => router.back()}>
        <ArrowLeft className="size-[18px]" strokeWidth={ICON_STROKE} aria-hidden="true" />
        {ts("count_back")}
      </Button>
    </StationHeaderActions>
  );

  if (shiftQuery.isError && !shift) {
    return (
      <div className="max-w-xl space-y-5">
        <Named title={t("title")} titleAm={titleAm} />
        {back}
        <Alert>{errorText.message(shiftQuery.error)}</Alert>
      </div>
    );
  }
  if (!shift) {
    return (
      <>
        <Named title={t("title")} titleAm={titleAm} />
        <p role="status" className="text-base text-neutral-600">
          {t("loading")}
        </p>
      </>
    );
  }

  const stationName = locale === "am" ? shift.station.name_am : shift.station.name_en;
  // the bar's line: the station, this shift's state (the shift counted here may not be the station's running one), its day and open time
  const subtitle = [
    stationName,
    t(`shift_${shift.status}`),
    t("shift_date", { date: isoDay(shift.date) }),
    shift.opened_at ? ts("open_since", { time: addisClock(shift.opened_at) }) : null,
  ]
    .filter(Boolean)
    .join(" · ");
  const canNote = NOTE_STATUSES.includes(shift.status);

  return (
    <div className="flex flex-col gap-5">
      <Named title={t("title")} titleAm={titleAm} subtitle={subtitle} />
      <ShiftBar model={{ ...shiftStation, choices: [] }} look="menu" quiet />
      {back}
      {/* what the screen is for */}
      <p className="max-w-3xl text-[15px] text-neutral-600">{t("intro")}</p>

      {countsQuery.isError && !countsQuery.data ? <Alert>{errorText.message(countsQuery.error)}</Alert> : null}
      {countsQuery.isError && countsQuery.data ? (
        <p role="status" className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {ts("stale")}
        </p>
      ) : null}

      {phase === "opening" && shift.status === "opening" ? <HandoverPanel stationId={shift.station.id} /> : null}

      {shown.length > 1 ? (
        <nav aria-label={t("places")} className="flex max-w-full flex-wrap gap-1 self-start rounded-2xl border border-neutral-200 bg-white p-1">
          {shown.map((count) => {
            const active = selected?.id === count.id;
            return (
              <button
                key={count.id}
                type="button"
                aria-pressed={active}
                onClick={() => setChosen(count.location?.id ?? null)}
                className={`inline-flex min-h-12 items-center gap-2.5 rounded-xl px-4 text-base font-semibold motion-safe:transition-colors motion-safe:duration-150 ${focusRing} ${
                  active ? "bg-neutral-900 text-white" : "text-neutral-700 hover:bg-neutral-100"
                }`}
              >
                {placeName(count)}
                <StageBadge count={count} />
              </button>
            );
          })}
        </nav>
      ) : null}

      {selected ? (
        <CountPanel
          key={selected.id}
          shiftId={shiftId}
          count={selected}
          all={all}
          reasons={reasonsQuery.data ?? []}
          placeName={placeName(selected)}
          onChange={replace}
        />
      ) : countsQuery.isSuccess ? (
        <Panel title={t("nothing_title")}>
          <EmptyState title={t("nothing_title")} body={t("nothing_to_count")} level={3} className="py-6" />
        </Panel>
      ) : null}

      <NotesPanel shiftId={shiftId} shift={shift} canNote={canNote} notes={notesQuery.data ?? []} />
    </div>
  );
}

function NotesPanel({ shiftId, shift, canNote, notes }: { shiftId: number; shift: Shift; canNote: boolean; notes: HandoverNote[] }) {
  const t = useTranslations("counts");
  const client = useQueryClient();
  const canWrite = useSession((s) => s.me?.permissions.includes("shifts.count") ?? false);
  return (
    <Panel title={t("notes_title")}>
      <div className="space-y-5">
        <NotesList notes={notes} />
        {canNote && canWrite ? (
          <NoteForm
            shiftId={shiftId}
            roster={shift.roster}
            onSaved={() => {
              void client.invalidateQueries({ queryKey: ["shift-notes", shiftId] });
              void client.invalidateQueries({ queryKey: ["shift", shiftId] });
            }}
          />
        ) : null}
      </div>
    </Panel>
  );
}
