"use client";

import { useQuery, useQueryClient } from "@tanstack/react-query";
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
import { Alert, Badge, Button, PageHeader, Panel } from "../ui";
import { useRouter } from "@/i18n/navigation";
import { CountPanel, StageBadge } from "./CountPanel";
import { HandoverPanel } from "./HandoverPanel";
import { NoteForm, NotesList } from "./Notes";

// The count screen (step 3.7b), /shifts/count?shift=<id>: one component for the opening and the closing count. It shows the counts of the
// shift for the phase it is in (the opening ones while the shift is being opened, the closing ones from the moment it is closed), one
// place at a time, with the handover panel above an opening count and the handover notes below. It polls the shift and its counts every
// 6 s so that a hand-in by someone else, an approval or a rejection shows without a reload.

const POLL_MS = 6000;

const SHIFT_TONE: Record<ShiftStatus, "neutral" | "blue" | "green" | "amber"> = {
  scheduled: "neutral",
  opening: "blue",
  open: "green",
  counting: "blue",
  pending_approval: "amber",
  locked: "neutral",
  cancelled: "neutral",
};

const NOTE_STATUSES: readonly ShiftStatus[] = ["opening", "open", "counting", "pending_approval"];

export function CountScreen() {
  const t = useTranslations("counts");
  const params = useSearchParams();
  const raw = params.get("shift") ?? "";
  if (!/^\d+$/.test(raw)) {
    return (
      <div className="max-w-xl space-y-4">
        <PageHeader title={t("title")} />
        <Alert>{t("no_shift")}</Alert>
      </div>
    );
  }
  return <Loaded shiftId={Number(raw)} />;
}

function Loaded({ shiftId }: { shiftId: number }) {
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

  if (shiftQuery.isError && !shift) {
    return (
      <div className="max-w-xl space-y-4">
        <PageHeader title={t("title")} />
        <Alert>{errorText.message(shiftQuery.error)}</Alert>
        <Button type="button" variant="secondary" onClick={() => router.back()}>
          {ts("count_back")}
        </Button>
      </div>
    );
  }
  if (!shift) {
    return (
      <p role="status" className="text-base text-neutral-600">
        {t("loading")}
      </p>
    );
  }

  const stationName = locale === "am" ? shift.station.name_am : shift.station.name_en;
  const canNote = NOTE_STATUSES.includes(shift.status);

  return (
    <div className="space-y-6">
      <PageHeader title={t("title")} intro={t("intro")} />

      <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-neutral-200 bg-white px-5 py-4">
        <p className="text-lg font-semibold text-neutral-900">{stationName}</p>
        <p className="text-base text-neutral-700">{t("shift_date", { date: shift.date })}</p>
        <Badge tone={SHIFT_TONE[shift.status]}>{t(`shift_${shift.status}`)}</Badge>
        {shift.opened_at ? <p className="text-base text-neutral-600">{ts("open_since", { time: addisClock(shift.opened_at) })}</p> : null}
        <div className="ml-auto">
          <Button type="button" variant="secondary" onClick={() => router.back()}>
            {ts("count_back")}
          </Button>
        </div>
      </div>

      {countsQuery.isError && !countsQuery.data ? <Alert>{errorText.message(countsQuery.error)}</Alert> : null}
      {countsQuery.isError && countsQuery.data ? (
        <p role="status" className="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-base text-amber-900">
          {ts("stale")}
        </p>
      ) : null}

      {phase === "opening" && shift.status === "opening" ? <HandoverPanel stationId={shift.station.id} /> : null}

      {shown.length > 1 ? (
        <nav aria-label={t("places")} className="flex flex-wrap gap-3">
          {shown.map((count) => {
            const active = selected?.id === count.id;
            return (
              <button
                key={count.id}
                type="button"
                aria-pressed={active}
                onClick={() => setChosen(count.location?.id ?? null)}
                className={`flex min-h-12 items-center gap-3 rounded-md border px-4 text-base font-medium focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-blue-600/40 ${
                  active ? "border-blue-700 bg-blue-50 text-blue-900" : "border-neutral-300 bg-white text-neutral-900 hover:bg-neutral-100"
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
          <p className="text-base text-neutral-700">{t("nothing_to_count")}</p>
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
