"use client";

import { useTranslations } from "next-intl";
import { useEffect, useMemo, useRef, useState } from "react";
import {
  useCancelScheduled,
  useReplaceRoster,
  useRosterPeople,
  useScheduleShift,
  useShift,
  useShiftList,
  useStartNow,
  useTemplates,
  useWorkStations,
} from "@/lib/api/adminShifts";
import { uuid } from "@/lib/api/idempotency";
import { ApiError } from "@/lib/api/client";
import type { ShiftSummary, ShiftTemplate, User, WorkStation } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { addDays, candidatesFor, canStartNow, cellKey, isoWeekday, shiftsByCell, templatesOn, weekOf } from "@/lib/shiftsAdmin";
import { Alert, Button, Field, Modal, Panel, TextInput } from "../../ui";
import { ShiftStatusBadge, useName, useToday } from "./common";

// Admin > Shifts > Roster (step 3.7c): the calendar of a week. One table per station that tracks shifts; a row per template that serves
// it, a column per date; a cell is the shift made from that template on that local date. The admin (shifts.manage) schedules people
// into an empty cell, changes the people of a scheduled shift, cancels it, or presses "Start now" to start it outside its window (the
// only way into a window that is over: the API's force). Everyone else sees the same calendar and no buttons.

interface Target {
  station: WorkStation;
  template: ShiftTemplate;
  date: string;
}

type Action = ({ kind: "schedule" } & Target) | ({ kind: "people"; shift: ShiftSummary } & Target) | ({ kind: "start"; shift: ShiftSummary } & Target);

const SHIFT_STATUSES_WAITING_OR_RUNNING = new Set(["scheduled", "opening", "open", "counting", "pending_approval"]);

export function RosterScreen() {
  const t = useTranslations("adminShifts");
  const today = useToday();
  const [anchor, setAnchor] = useState("");
  const [action, setAction] = useState<Action | null>(null);
  const me = useSession((s) => s.me);
  const manage = can(me, "shifts.manage");
  useEffect(() => {
    if (today && !anchor) setAnchor(today);
  }, [today, anchor]);

  const valid = /^\d{4}-\d{2}-\d{2}$/.test(anchor);
  const days = useMemo(() => (valid ? weekOf(anchor) : []), [anchor, valid]);
  const stations = useWorkStations();
  const templates = useTemplates();
  const shifts = useShiftList({ from: days[0], to: days[6], per_page: 100 }, valid);
  const cells = useMemo(() => shiftsByCell(shifts.data?.data ?? []), [shifts.data]);
  const tracked = (stations.data ?? []).filter((station) => station.tracks_shifts);

  return (
    <div className="space-y-6">
      <Panel title={t("roster.week")}>
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-56">
            <Field label={t("roster.week_of")}>
              {(props) => <TextInput {...props} type="date" value={anchor} onChange={(event) => setAnchor(event.target.value)} />}
            </Field>
          </div>
          <Button type="button" variant="secondary" disabled={!valid} onClick={() => setAnchor(addDays(anchor, -7))}>
            {t("roster.previous_week")}
          </Button>
          <Button type="button" variant="secondary" disabled={!today} onClick={() => setAnchor(today)}>
            {t("roster.this_week")}
          </Button>
          <Button type="button" variant="secondary" disabled={!valid} onClick={() => setAnchor(addDays(anchor, 7))}>
            {t("roster.next_week")}
          </Button>
        </div>
        {valid ? <p className="mt-3 text-sm text-neutral-600">{t("roster.range", { from: days[0], to: days[6] })}</p> : null}
      </Panel>

      {shifts.isError ? <Alert>{t("roster.failed")}</Alert> : null}
      {!valid || stations.isPending || templates.isPending ? <p className="text-base text-neutral-600">{t("roster.loading")}</p> : null}
      {valid && templates.data && stations.data && tracked.length === 0 ? <p className="text-base text-neutral-700">{t("roster.no_stations")}</p> : null}

      {valid && templates.data
        ? tracked.map((station) => (
            <StationWeek key={station.station_id} station={station} templates={templates.data} days={days} cells={cells} today={today} manage={manage} onAction={setAction} />
          ))
        : null}

      {action ? <ActionDialog action={action} onClose={() => setAction(null)} /> : null}
    </div>
  );
}

function StationWeek({
  station,
  templates,
  days,
  cells,
  today,
  manage,
  onAction,
}: {
  station: WorkStation;
  templates: ShiftTemplate[];
  days: string[];
  cells: Map<string, ShiftSummary[]>;
  today: string;
  manage: boolean;
  onAction: (action: Action) => void;
}) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const rows = templates.filter((template) => template.is_active && template.station_ids.includes(station.station_id)).sort((a, b) => a.start_time.localeCompare(b.start_time));
  return (
    <Panel title={name(station)}>
      {rows.length === 0 ? (
        <p className="text-base text-neutral-700">{t("roster.no_templates")}</p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[56rem] table-fixed border-collapse text-left text-base">
            <caption className="sr-only">{t("roster.caption", { station: name(station) })}</caption>
            <thead>
              <tr className="border-b border-neutral-200 text-sm text-neutral-600">
                <th scope="col" className="w-36 py-2 pr-3 font-medium">{t("roster.template")}</th>
                {days.map((date) => (
                  <th key={date} scope="col" className={`py-2 pr-3 font-medium ${date === today ? "text-blue-800" : ""}`}>
                    {t(`weekday_short.${isoWeekday(date)}`)}
                    <span className="block text-xs font-normal">{date.slice(5)}</span>
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((template) => (
                <tr key={template.id} className="border-b border-neutral-100 last:border-0">
                  <th scope="row" className="py-3 pr-3 align-top font-medium text-neutral-900">
                    {name(template)}
                    <span className="block text-sm font-normal text-neutral-600">
                      {template.start_time}–{template.end_time}
                    </span>
                  </th>
                  {days.map((date) => (
                    <td key={date} className="py-3 pr-3 align-top">
                      {templatesOn([template], station.station_id, date).length === 0 ? (
                        <span className="text-neutral-400" aria-hidden="true">—</span>
                      ) : (
                        <Cell station={station} template={template} date={date} shifts={cells.get(cellKey(station.station_id, template.id, date)) ?? []} manage={manage} onAction={onAction} />
                      )}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Panel>
  );
}

function Cell({ station, template, date, shifts, manage, onAction }: Target & { shifts: ShiftSummary[]; manage: boolean; onAction: (action: Action) => void }) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const label = `${name(station)} · ${name(template)} · ${date}`;
  const busy = shifts.some((shift) => SHIFT_STATUSES_WAITING_OR_RUNNING.has(shift.status));
  const live = shifts.filter((shift) => shift.status !== "cancelled");
  return (
    <div className="space-y-2">
      {live.map((shift) => (
        <ShiftInCell key={shift.id} shift={shift} target={{ station, template, date }} label={label} manage={manage} onAction={onAction} />
      ))}
      {manage && !busy ? (
        <Button type="button" variant="secondary" className="w-full !px-2 text-sm" aria-label={t("roster.schedule_named", { label })} onClick={() => onAction({ kind: "schedule", station, template, date })}>
          {t("roster.schedule")}
        </Button>
      ) : null}
    </div>
  );
}

function ShiftInCell({ shift, target, label, manage, onAction }: { shift: ShiftSummary; target: Target; label: string; manage: boolean; onAction: (action: Action) => void }) {
  const t = useTranslations("adminShifts");
  const scheduled = canStartNow(shift);
  // the people of a scheduled shift come with the single read (the list leaves the roster out)
  const detail = useShift(scheduled ? shift.id : null);
  const people = detail.data?.roster.map((row) => row.user?.name).filter((value): value is string => !!value) ?? [];
  return (
    <div className="space-y-2 rounded-md bg-neutral-50 p-2">
      <ShiftStatusBadge status={shift.status} />
      {scheduled ? <p className="text-sm text-neutral-700">{people.length > 0 ? people.join(", ") : t("roster.nobody")}</p> : null}
      {scheduled && manage ? (
        <div className="flex flex-col gap-2">
          <Button type="button" className="w-full !px-2 text-sm" aria-label={t("roster.start_now_named", { label })} onClick={() => onAction({ kind: "start", shift, ...target })}>
            {t("roster.start_now")}
          </Button>
          <Button type="button" variant="secondary" className="w-full !px-2 text-sm" aria-label={t("roster.people_named", { label })} onClick={() => onAction({ kind: "people", shift, ...target })}>
            {t("roster.people")}
          </Button>
        </div>
      ) : null}
    </div>
  );
}

function ActionDialog({ action, onClose }: { action: Action; onClose: () => void }) {
  if (action.kind === "start") return <StartNowDialog action={action} onClose={onClose} />;
  return <PeopleDialog action={action} onClose={onClose} />;
}

/** "Start now": starts a scheduled shift outside its window (the admin's force). Without counting opens it carried. */
function StartNowDialog({ action, onClose }: { action: Extract<Action, { kind: "start" }>; onClose: () => void }) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const errorText = useErrorText();
  const start = useStartNow();
  const key = useRef<string | null>(null);
  const [carry, setCarry] = useState(false);
  const [failure, setFailure] = useState<unknown>(null);

  function confirm() {
    // the start's client_uuid is its Idempotency-Key and stays the same for a retry of this attempt
    key.current ??= uuid();
    setFailure(null);
    start.mutate(
      { stationId: action.station.station_id, shiftId: action.shift.id, carry, key: key.current },
      {
        onSuccess: onClose,
        onError: (error) => {
          setFailure(error);
          if (!(error instanceof ApiError) || error.status !== 0) key.current = null;
        },
      },
    );
  }

  return (
    <Modal label={t("start.title")} onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{t("start.title")}</h2>
      <p className="text-base text-neutral-700">{t("start.body", { template: name(action.template), station: name(action.station), date: action.date })}</p>
      <p className="text-sm text-neutral-600">{t("start.hint")}</p>
      <label className="flex min-h-12 items-center gap-3 text-base">
        <input type="checkbox" className="size-5" checked={carry} onChange={(event) => setCarry(event.target.checked)} />
        {t("start.carry")}
      </label>
      {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
      <div className="flex flex-wrap justify-end gap-3">
        <Button type="button" variant="secondary" onClick={onClose}>
          {t("cancel")}
        </Button>
        <Button type="button" disabled={start.isPending} onClick={confirm}>
          {start.isPending ? t("working") : t("start.confirm")}
        </Button>
      </div>
    </Modal>
  );
}

/** Schedules the template for the date with people, or changes the people of a scheduled shift (or cancels it). */
function PeopleDialog({ action, onClose }: { action: Exclude<Action, { kind: "start" }>; onClose: () => void }) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const errorText = useErrorText();
  const people = useRosterPeople();
  const schedule = useScheduleShift();
  const replace = useReplaceRoster();
  const cancel = useCancelScheduled();
  const existing = useShift(action.kind === "people" ? action.shift.id : null);
  const key = useRef<string | null>(null);
  const [chosen, setChosen] = useState<number[] | null>(null);
  const [failure, setFailure] = useState<unknown>(null);

  const stationId = action.station.station_id;
  const candidates: User[] = people.data ? candidatesFor(people.data, stationId) : [];
  const current = existing.data?.roster.map((row) => row.user?.id).filter((id): id is number => typeof id === "number") ?? [];
  const selected = chosen ?? (action.kind === "people" ? current : []);
  const waiting = action.kind === "people" && existing.isPending;
  const toggle = (id: number) => setChosen(selected.includes(id) ? selected.filter((item) => item !== id) : [...selected, id]);

  function submit() {
    key.current ??= uuid();
    setFailure(null);
    const done = { onSuccess: onClose, onError: (error: unknown) => { setFailure(error); if (!(error instanceof ApiError) || error.status !== 0) key.current = null; } };
    if (action.kind === "schedule") schedule.mutate({ key: key.current, stationId, templateId: action.template.id, date: action.date, userIds: selected }, done);
    else replace.mutate({ shiftId: action.shift.id, userIds: selected, key: key.current }, done);
  }

  function cancelShift() {
    if (action.kind !== "people") return;
    const cancelKey = uuid();
    setFailure(null);
    cancel.mutate({ shiftId: action.shift.id, key: cancelKey }, { onSuccess: onClose, onError: setFailure });
  }

  const title = action.kind === "schedule" ? t("people.schedule_title") : t("people.edit_title");
  const pending = schedule.isPending || replace.isPending || cancel.isPending;
  return (
    <Modal label={title} onClose={onClose}>
      <h2 className="text-lg font-semibold text-neutral-900">{title}</h2>
      <p className="text-base text-neutral-700">{t("people.for", { template: name(action.template), station: name(action.station), date: action.date })}</p>
      {people.isPending || waiting ? <p className="text-base text-neutral-600">{t("roster.loading")}</p> : null}
      {people.isError ? <Alert>{errorText.message(people.error)}</Alert> : null}
      {people.data && candidates.length === 0 ? <p className="text-base text-neutral-700">{t("people.nobody_assigned")}</p> : null}
      {candidates.length > 0 && !waiting ? (
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium text-neutral-800">{t("people.legend")}</legend>
          <div className="flex flex-col gap-2">
            {candidates.map((person) => (
              <label key={person.id} className="flex min-h-12 cursor-pointer items-center gap-3 rounded-md border border-neutral-300 bg-white px-4 text-base has-[:checked]:border-blue-700 has-[:checked]:bg-blue-50">
                <input type="checkbox" className="size-5" checked={selected.includes(person.id)} onChange={() => toggle(person.id)} />
                {person.name}
              </label>
            ))}
          </div>
        </fieldset>
      ) : null}
      {failure ? <Alert>{errorText.message(failure)}</Alert> : null}
      <div className="flex flex-wrap justify-between gap-3">
        <div>
          {action.kind === "people" ? (
            <Button type="button" variant="secondary" disabled={pending} onClick={cancelShift}>
              {t("people.cancel_shift")}
            </Button>
          ) : null}
        </div>
        <div className="flex flex-wrap gap-3">
          <Button type="button" variant="secondary" onClick={onClose}>
            {t("cancel")}
          </Button>
          <Button type="button" disabled={pending || waiting} onClick={submit}>
            {pending ? t("working") : action.kind === "schedule" ? t("people.schedule_confirm") : t("people.save")}
          </Button>
        </div>
      </div>
    </Modal>
  );
}
