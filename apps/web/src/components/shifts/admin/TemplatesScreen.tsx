"use client";

import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { useRetireTemplate, useSaveTemplate, useTemplates, useWorkStations } from "@/lib/api/adminShifts";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import type { ShiftTemplate, WorkStation } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { crossesMidnight, emptyTemplateForm, formOf, templateBody, templateIssues, type TemplateForm, type TemplateIssue } from "@/lib/shiftsAdmin";
import { Alert, Badge, Button, Field, Modal, Panel, Status, TextInput } from "../../ui";
import { QueryBox } from "../../inventory/shared";
import { TD, TH, useName } from "./common";

// Admin > Shifts > Templates (step 3.7c): the named windows of the day that shifts are scheduled from (Day, Night). Everyone who reads
// shifts sees them; the admin (shifts.manage) creates, edits and retires. A retired template leaves the calendar, and the shifts already
// made from it keep their own window (phase-3 plan section 4).

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;

function DaysText({ days }: { days: number[] }) {
  const t = useTranslations("adminShifts");
  if (days.length === 7) return <>{t("templates.daily")}</>;
  return <>{[...days].sort((a, b) => a - b).map((day) => t(`weekday_short.${day}`)).join(", ")}</>;
}

export function TemplatesScreen() {
  const t = useTranslations("adminShifts");
  const templates = useTemplates();
  const stations = useWorkStations();
  const me = useSession((s) => s.me);
  const manage = can(me, "shifts.manage");
  const wanted = Number(useSearchParams().get("station")) || null;
  const [editing, setEditing] = useState<ShiftTemplate | null>(null);

  const tracked = (stations.data ?? []).filter((station) => station.tracks_shifts);
  return (
    <div className={manage ? "grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_28rem]" : ""}>
      <Panel title={t("templates.list")}>
        <QueryBox query={templates}>{(rows) => <TemplateTable rows={rows} stations={stations.data ?? []} manage={manage} onEdit={setEditing} />}</QueryBox>
      </Panel>
      {manage ? <TemplateForm key={editing?.id ?? `new-${wanted ?? 0}`} editing={editing} stations={tracked} presetStation={wanted} onDone={() => setEditing(null)} /> : null}
    </div>
  );
}

function TemplateTable({ rows, stations, manage, onEdit }: { rows: ShiftTemplate[]; stations: WorkStation[]; manage: boolean; onEdit: (row: ShiftTemplate) => void }) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const errorText = useErrorText();
  const retire = useRetireTemplate();
  const idempotency = useIdempotencyKey();
  const [confirming, setConfirming] = useState<ShiftTemplate | null>(null);

  const stationNames = (ids: number[]) =>
    ids
      .map((id) => stations.find((station) => station.station_id === id))
      .filter((station): station is WorkStation => !!station)
      .map((station) => name(station))
      .join(", ");

  return (
    <div className="space-y-3">
      {retire.isError ? <Alert>{errorText.message(retire.error)}</Alert> : null}
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-left text-base">
          <caption className="sr-only">{t("templates.list")}</caption>
          <thead>
            <tr className="border-b border-neutral-200 text-sm text-neutral-600">
              <th scope="col" className={TH}>{t("templates.col_name")}</th>
              <th scope="col" className={TH}>{t("templates.col_window")}</th>
              <th scope="col" className={TH}>{t("templates.col_days")}</th>
              <th scope="col" className={TH}>{t("templates.col_stations")}</th>
              <th scope="col" className={TH}>{t("templates.col_status")}</th>
              {manage ? <th scope="col" className="py-2 font-medium">{t("templates.col_actions")}</th> : null}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={manage ? 6 : 5} className="py-6 text-neutral-600">{t("templates.none")}</td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                  <th scope="row" className={`${TD} font-medium text-neutral-900`}>{name(row)}</th>
                  <td className={TD}>
                    {row.start_time}–{row.end_time}
                    {crossesMidnight(row.start_time, row.end_time) ? <span className="block text-sm text-neutral-600">{t("templates.through_midnight")}</span> : null}
                  </td>
                  <td className={TD}><DaysText days={row.days_of_week} /></td>
                  <td className={TD}>{stationNames(row.station_ids) || "—"}</td>
                  <td className={TD}>{row.is_active ? <Badge tone="green">{t("templates.active")}</Badge> : <Badge>{t("templates.off")}</Badge>}</td>
                  {manage ? (
                    <td className="py-3">
                      <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" onClick={() => onEdit(row)} aria-label={t("templates.edit_named", { name: name(row) })}>
                          {t("edit")}
                        </Button>
                        <Button variant="secondary" onClick={() => setConfirming(row)} aria-label={t("templates.retire_named", { name: name(row) })}>
                          {t("templates.retire")}
                        </Button>
                      </div>
                    </td>
                  ) : null}
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
      {confirming ? (
        <Modal label={t("templates.retire_title")} onClose={() => setConfirming(null)}>
          <h2 className="text-lg font-semibold text-neutral-900">{t("templates.retire_title")}</h2>
          <p className="text-base text-neutral-700">{t("templates.retire_body", { name: name(confirming) })}</p>
          <div className="flex flex-wrap justify-end gap-3">
            <Button type="button" variant="secondary" onClick={() => setConfirming(null)}>
              {t("cancel")}
            </Button>
            <Button
              type="button"
              disabled={retire.isPending}
              onClick={() =>
                retire.mutate(
                  { id: confirming.id, idempotencyKey: idempotency.keyFor({ retire: confirming.id }) },
                  {
                    onSuccess: () => {
                      idempotency.done();
                      setConfirming(null);
                    },
                  },
                )
              }
            >
              {retire.isPending ? t("working") : t("templates.retire")}
            </Button>
          </div>
        </Modal>
      ) : null}
    </div>
  );
}

function TemplateForm({ editing, stations, presetStation, onDone }: { editing: ShiftTemplate | null; stations: WorkStation[]; presetStation: number | null; onDone: () => void }) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const errorText = useErrorText();
  const save = useSaveTemplate();
  const idempotency = useIdempotencyKey();
  const [form, setForm] = useState<TemplateForm>(() => (editing ? formOf(editing) : { ...emptyTemplateForm(), station_ids: presetStation ? [presetStation] : [] }));
  const [issues, setIssues] = useState<TemplateIssue[]>([]);
  const [saved, setSaved] = useState<string | null>(null);

  const toggle = (list: number[], value: number) => (list.includes(value) ? list.filter((item) => item !== value) : [...list, value]);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(null);
    const found = templateIssues(form);
    setIssues(found);
    if (found.length > 0) return;
    const body = templateBody(form);
    save.mutate(
      { id: editing?.id ?? null, body, idempotencyKey: idempotency.keyFor({ id: editing?.id ?? null, ...body }) },
      {
        onSuccess: () => {
          idempotency.done();
          setSaved(body.name_en);
          if (editing) onDone();
          else setForm(emptyTemplateForm());
        },
      },
    );
  }

  const issue = (which: TemplateIssue) => (issues.includes(which) ? t(`templates.issue_${which}`) : undefined);
  const fieldError = (field: string) => errorText.field(save.error, field);
  return (
    <Panel title={editing ? t("templates.edit") : t("templates.new")}>
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        <Field label={t("templates.name_en")} error={issue("name_en") ?? fieldError("name_en")}>
          {(props) => <TextInput {...props} value={form.name_en} maxLength={80} onChange={(event) => setForm({ ...form, name_en: event.target.value })} />}
        </Field>
        <Field label={t("templates.name_am")} error={issue("name_am") ?? fieldError("name_am")}>
          {(props) => <TextInput {...props} value={form.name_am} maxLength={80} onChange={(event) => setForm({ ...form, name_am: event.target.value })} />}
        </Field>
        <div className="grid grid-cols-2 gap-4">
          <Field label={t("templates.start")} error={issue("times")}>
            {(props) => <TextInput {...props} type="time" value={form.start_time} onChange={(event) => setForm({ ...form, start_time: event.target.value })} />}
          </Field>
          <Field label={t("templates.end")}>
            {(props) => <TextInput {...props} type="time" value={form.end_time} onChange={(event) => setForm({ ...form, end_time: event.target.value })} />}
          </Field>
        </div>
        {crossesMidnight(form.start_time, form.end_time) ? <p className="text-sm text-neutral-600">{t("templates.midnight_hint")}</p> : null}

        <fieldset className="space-y-2">
          <legend className="text-sm font-medium text-neutral-800">{t("templates.days")}</legend>
          <div className="flex flex-wrap gap-2">
            {WEEKDAYS.map((day) => (
              <label key={day} className="inline-flex min-h-12 cursor-pointer items-center gap-2 rounded-md border border-neutral-300 bg-white px-4 text-base has-[:checked]:border-blue-700 has-[:checked]:bg-blue-50">
                <input type="checkbox" className="size-5" checked={form.days.includes(day)} onChange={() => setForm({ ...form, days: toggle(form.days, day) })} />
                {t(`weekday_short.${day}`)}
              </label>
            ))}
          </div>
          {issue("days") ? <p className="text-sm font-medium text-red-700">{issue("days")}</p> : null}
        </fieldset>

        <fieldset className="space-y-2">
          <legend className="text-sm font-medium text-neutral-800">{t("templates.stations")}</legend>
          <div className="flex flex-wrap gap-2">
            {stations.map((station) => (
              <label key={station.station_id} className="inline-flex min-h-12 cursor-pointer items-center gap-2 rounded-md border border-neutral-300 bg-white px-4 text-base has-[:checked]:border-blue-700 has-[:checked]:bg-blue-50">
                <input
                  type="checkbox"
                  className="size-5"
                  checked={form.station_ids.includes(station.station_id)}
                  onChange={() => setForm({ ...form, station_ids: toggle(form.station_ids, station.station_id) })}
                />
                {name(station)}
              </label>
            ))}
          </div>
          {issue("stations") ? <p className="text-sm font-medium text-red-700">{issue("stations")}</p> : null}
        </fieldset>

        <label className="flex min-h-12 items-center gap-3 text-base">
          <input type="checkbox" className="size-5" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} />
          {t("templates.is_active")}
        </label>

        {save.isError && Object.keys((save.error as { fieldErrors?: object }).fieldErrors ?? {}).length === 0 ? <Alert>{errorText.message(save.error)}</Alert> : null}
        <Status>{saved ? t("templates.saved", { name: saved }) : null}</Status>
        <div className="flex gap-3">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? t("working") : editing ? t("templates.save_changes") : t("templates.add")}
          </Button>
          {editing ? (
            <Button type="button" variant="secondary" onClick={onDone}>
              {t("cancel")}
            </Button>
          ) : null}
        </div>
      </form>
    </Panel>
  );
}
