"use client";

import { ChevronDown } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { useShiftList, useShiftNotes, useWorkStations } from "@/lib/api/adminShifts";
import type { ShiftSummary } from "@/lib/api/types";
import { useClock, useIsoDay } from "@/lib/useDates";
import { ToolbarSelect } from "../../admin/kit";
import { Button, Skeleton } from "../../ui";
import { Pager, QueryBox } from "../../inventory/shared";
import { OverviewCard } from "./Overview";
import { ShiftStatusBadge, useName } from "./common";

// Admin > Shifts > Handover notes (step 3.7c): the notes people left each other, shift by shift, newest shift first. A note is written from
// the shift bar or the count screen (step 3.7b); here the admin and managers read them, with who wrote each and who it was for.
//
// Step 6.4d (fresh-counter.md 4.4): one white card, the station filter at the right of its title; each shift a row on a hairline (its
// station, template and day, its state chip, "Show notes" at the end), its notes opening under it.

export function NotesScreen() {
  const t = useTranslations("adminShifts");
  const name = useName();
  const stations = useWorkStations();
  const [stationId, setStationId] = useState("");
  const [page, setPage] = useState(1);
  const list = useShiftList({ ...(stationId ? { station_id: Number(stationId) } : {}), page, per_page: 10 });
  return (
    <OverviewCard
      title={t("notes.title")}
      aside={
        <ToolbarSelect
          label={t("history.filter_station")}
          value={stationId}
          onChange={(event) => {
            setStationId(event.target.value);
            setPage(1);
          }}
        >
          <option value="">{t("history.all_stations")}</option>
          {(stations.data ?? [])
            .filter((station) => station.tracks_shifts)
            .map((station) => (
              <option key={station.station_id} value={station.station_id}>
                {name(station)}
              </option>
            ))}
        </ToolbarSelect>
      }
    >
      <p className="-mt-2 text-[13px] text-neutral-600">{t("notes.intro")}</p>
      <QueryBox query={list}>
        {(result) => (
          <>
            {result.data.length === 0 ? <p className="text-[15px] text-neutral-600">{t("history.shifts_none")}</p> : null}
            <ul className="divide-y divide-neutral-200 border-t border-neutral-200">
              {result.data.map((shift) => (
                <ShiftNotes key={shift.id} shift={shift} />
              ))}
            </ul>
            <Pager page={result.meta.current_page} lastPage={result.meta.last_page} onPage={setPage} />
          </>
        )}
      </QueryBox>
    </OverviewCard>
  );
}

function ShiftNotes({ shift }: { shift: ShiftSummary }) {
  const isoDay = useIsoDay();
  const t = useTranslations("adminShifts");
  const name = useName();
  const [shown, setShown] = useState(false);
  const label = `${name(shift.station)} · ${shift.template ? name(shift.template) : t("running.ad_hoc")} · ${isoDay(shift.date)}`;
  return (
    <li className="space-y-3 py-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="flex flex-wrap items-center gap-2.5">
          <span className="text-[15px] font-semibold text-neutral-900">{label}</span>
          <ShiftStatusBadge status={shift.status} />
        </p>
        <Button
          type="button"
          variant="ghost"
          size="md"
          aria-expanded={shown}
          aria-label={t(shown ? "notes.hide_named" : "notes.show_named", { label })}
          onClick={() => setShown((value) => !value)}
        >
          {t(shown ? "notes.hide" : "notes.show")}
          <ChevronDown className={`size-4 motion-safe:transition-transform ${shown ? "rotate-180" : ""}`} strokeWidth={2} aria-hidden="true" />
        </Button>
      </div>
      {shown ? <NoteList shiftId={shift.id} /> : null}
    </li>
  );
}

function NoteList({ shiftId }: { shiftId: number }) {
  const clock = useClock();
  const t = useTranslations("adminShifts");
  const notes = useShiftNotes(shiftId);
  if (notes.isPending) return <Skeleton className="h-12" />;
  return (
    <QueryBox query={notes}>
      {(rows) =>
        rows.length === 0 ? (
          <p className="rounded-xl bg-neutral-50 px-4 py-3 text-[15px] text-neutral-600">{t("notes.none")}</p>
        ) : (
          <ul className="divide-y divide-neutral-100 rounded-xl border border-neutral-200 px-4">
            {rows.map((note) => (
              <li key={note.id} className="space-y-1 py-3">
                <p className="text-[13px] text-neutral-600">
                  {t("notes.by", { name: note.from?.name ?? "—", time: clock(note.created_at) })}
                  {note.to ? ` · ${t("notes.for", { name: note.to.name })}` : ` · ${t("notes.everyone")}`}
                </p>
                <p className="whitespace-pre-wrap text-[15px] text-neutral-900">{note.body}</p>
              </li>
            ))}
          </ul>
        )
      }
    </QueryBox>
  );
}
