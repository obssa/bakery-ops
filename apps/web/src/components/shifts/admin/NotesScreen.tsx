"use client";

import { useTranslations } from "next-intl";
import { useState } from "react";
import { useShiftList, useShiftNotes, useWorkStations } from "@/lib/api/adminShifts";
import type { ShiftSummary } from "@/lib/api/types";
import { addisClock } from "@/lib/shifts";
import { Button, Field, Panel, Select } from "../../ui";
import { Pager, QueryBox } from "../../inventory/shared";
import { ShiftStatusBadge, useName } from "./common";

// Admin > Shifts > Handover notes (step 3.7c): the notes people left each other, shift by shift, newest shift first. A note is written from
// the shift bar or the count screen (step 3.7b); here the admin and managers read them, with who wrote each and who it was for.

export function NotesScreen() {
  const t = useTranslations("adminShifts");
  const name = useName();
  const stations = useWorkStations();
  const [stationId, setStationId] = useState("");
  const [page, setPage] = useState(1);
  const list = useShiftList({ ...(stationId ? { station_id: Number(stationId) } : {}), page, per_page: 10 });
  return (
    <Panel title={t("notes.title")}>
      <div className="space-y-4">
        <p className="text-base text-neutral-600">{t("notes.intro")}</p>
        <div className="w-64">
          <Field label={t("history.filter_station")}>
            {(props) => (
              <Select {...props} value={stationId} onChange={(event) => { setStationId(event.target.value); setPage(1); }}>
                <option value="">{t("history.all_stations")}</option>
                {(stations.data ?? []).filter((station) => station.tracks_shifts).map((station) => (
                  <option key={station.station_id} value={station.station_id}>{name(station)}</option>
                ))}
              </Select>
            )}
          </Field>
        </div>
        <QueryBox query={list}>
          {(result) => (
            <>
              {result.data.length === 0 ? <p className="text-base text-neutral-700">{t("history.shifts_none")}</p> : null}
              <ul className="space-y-3">
                {result.data.map((shift) => (
                  <ShiftNotes key={shift.id} shift={shift} />
                ))}
              </ul>
              <Pager page={result.meta.current_page} lastPage={result.meta.last_page} onPage={setPage} />
            </>
          )}
        </QueryBox>
      </div>
    </Panel>
  );
}

function ShiftNotes({ shift }: { shift: ShiftSummary }) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const [shown, setShown] = useState(false);
  const label = `${name(shift.station)} · ${shift.template ? name(shift.template) : t("running.ad_hoc")} · ${shift.date}`;
  return (
    <li className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="flex flex-wrap items-center gap-3 text-lg font-semibold text-neutral-900">
          <span>{label}</span>
          <ShiftStatusBadge status={shift.status} />
        </p>
        <Button type="button" variant="secondary" aria-expanded={shown} aria-label={t(shown ? "notes.hide_named" : "notes.show_named", { label })} onClick={() => setShown((value) => !value)}>
          {t(shown ? "notes.hide" : "notes.show")}
        </Button>
      </div>
      {shown ? <NoteList shiftId={shift.id} /> : null}
    </li>
  );
}

function NoteList({ shiftId }: { shiftId: number }) {
  const t = useTranslations("adminShifts");
  const notes = useShiftNotes(shiftId);
  return (
    <QueryBox query={notes}>
      {(rows) =>
        rows.length === 0 ? (
          <p className="text-base text-neutral-700">{t("notes.none")}</p>
        ) : (
          <ul className="divide-y divide-neutral-100">
            {rows.map((note) => (
              <li key={note.id} className="space-y-1 py-3">
                <p className="text-sm text-neutral-600">
                  {t("notes.by", { name: note.from?.name ?? "—", time: addisClock(note.created_at) })}
                  {note.to ? ` · ${t("notes.for", { name: note.to.name })}` : ` · ${t("notes.everyone")}`}
                </p>
                <p className="whitespace-pre-wrap text-base text-neutral-900">{note.body}</p>
              </li>
            ))}
          </ul>
        )
      }
    </QueryBox>
  );
}
