"use client";

import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { useEffect, useRef, useState, type FormEvent } from "react";
import { createPortal } from "react-dom";
import { useRetireTemplate, useSaveTemplate, useTemplates, useWorkStations } from "@/lib/api/adminShifts";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import type { ShiftTemplate, WorkStation } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { crossesMidnight, emptyTemplateForm, formOf, templateBody, templateIssues, type TemplateForm, type TemplateIssue } from "@/lib/shiftsAdmin";
import { ACTIONS_CELL, EmptyRow, FORM_GRID, TableCard } from "../../admin/kit";
import { QueryRows } from "../../admin/rows";
import { Alert, Button, Chip, Field, Modal, Panel, Status, TextInput } from "../../ui";
import { useName } from "./common";

// Admin > Shifts > Templates (step 3.7c): the named windows of the day that shifts are scheduled from (Day, Night). Everyone who reads
// shifts sees them; the admin (shifts.manage) creates, edits and retires. A retired template leaves the calendar, and the shifts already
// made from it keep their own window (phase-3 plan section 4).
//
// Step 6.4d (fresh-counter.md 4.4, the CatalogList board's table): the templates a table card the width of the page (the window in
// tabular figures, the days, the stations, the state as a chip, Edit and Retire at the row's end); the form a white card under it in two
// columns, its days and stations as pill checkboxes (checked ink-50 on an ink-900 line), brought into view by Edit.

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7] as const;

/** A checkbox drawn as a 44 px pill (a day, a station): checked in ink-50 on an ink-900 line; its label names the checkbox. */
const PILL =
  "inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-neutral-300 bg-white px-3.5 text-[15px] font-medium text-neutral-900 hover:bg-neutral-50 " +
  "has-[:checked]:border-ink-900 has-[:checked]:bg-ink-50 has-[:checked]:text-ink-900 has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-brand-600/35 has-[:focus-visible]:ring-offset-1";

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
  const span = manage ? 6 : 5;

  const tracked = (stations.data ?? []).filter((station) => station.tracks_shifts);
  return (
    <div className="flex flex-col gap-6">
      <TableCard caption={t("templates.list")} title={t("templates.list")} minWidth="min-w-[760px]">
        <thead>
          <tr>
            <th scope="col">{t("templates.col_name")}</th>
            <th scope="col">{t("templates.col_window")}</th>
            <th scope="col">{t("templates.col_days")}</th>
            <th scope="col">{t("templates.col_stations")}</th>
            <th scope="col">{t("templates.col_status")}</th>
            {manage ? (
              <th scope="col" className="text-right">
                <span className="sr-only">{t("templates.col_actions")}</span>
              </th>
            ) : null}
          </tr>
        </thead>
        <tbody>
          <QueryRows query={templates} span={span}>
            {(rows) => (rows.length === 0 ? <EmptyRow span={span}>{t("templates.none")}</EmptyRow> : <TemplateRows rows={rows} stations={stations.data ?? []} manage={manage} onEdit={setEditing} />)}
          </QueryRows>
        </tbody>
      </TableCard>
      {manage ? <TemplateForm key={editing?.id ?? `new-${wanted ?? 0}`} editing={editing} stations={tracked} presetStation={wanted} onDone={() => setEditing(null)} /> : null}
    </div>
  );
}

function TemplateRows({ rows, stations, manage, onEdit }: { rows: ShiftTemplate[]; stations: WorkStation[]; manage: boolean; onEdit: (row: ShiftTemplate) => void }) {
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
    <>
      {retire.isError ? (
        <tr>
          <td colSpan={manage ? 6 : 5} className="py-3">
            <Alert>{errorText.message(retire.error)}</Alert>
          </td>
        </tr>
      ) : null}
      {rows.map((row) => (
        <tr key={row.id}>
          <th scope="row" className="font-semibold text-neutral-900">
            {name(row)}
          </th>
          <td className="whitespace-nowrap tabular-nums">
            {row.start_time}–{row.end_time}
            {crossesMidnight(row.start_time, row.end_time) ? <span className="block text-[13px] text-neutral-600">{t("templates.through_midnight")}</span> : null}
          </td>
          <td className="text-neutral-700">
            <DaysText days={row.days_of_week} />
          </td>
          <td className="text-neutral-700">{stationNames(row.station_ids) || "—"}</td>
          <td>
            {row.is_active ? (
              <Chip tone="positive" dot>
                {t("templates.active")}
              </Chip>
            ) : (
              <Chip dot>{t("templates.off")}</Chip>
            )}
          </td>
          {manage ? (
            <td className={ACTIONS_CELL}>
              <span className="inline-flex gap-2">
                <Button variant="secondary" size="md" compact onClick={() => onEdit(row)} aria-label={t("templates.edit_named", { name: name(row) })}>
                  {t("edit")}
                </Button>
                <Button variant="ghost" size="md" compact onClick={() => setConfirming(row)} aria-label={t("templates.retire_named", { name: name(row) })}>
                  {t("templates.retire")}
                </Button>
              </span>
              {/* the dialog leaves the table (a portal on the body), so the row's cell does not shape its text */}
              {confirming?.id === row.id ? createPortal(
                <Modal label={t("templates.retire_title")} onClose={() => setConfirming(null)}>
                  <h2 className="text-lg font-semibold text-neutral-900">{t("templates.retire_title")}</h2>
                  <p className="text-[15px] text-neutral-700">{t("templates.retire_body", { name: name(confirming) })}</p>
                  <div className="flex flex-wrap justify-end gap-2.5">
                    <Button type="button" variant="secondary" size="md" onClick={() => setConfirming(null)}>
                      {t("cancel")}
                    </Button>
                    <Button
                      type="button"
                      variant="danger"
                      size="md"
                      loading={retire.isPending}
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
                      {t("templates.retire")}
                    </Button>
                  </div>
                </Modal>,
                document.body,
              ) : null}
            </td>
          ) : null}
        </tr>
      ))}
    </>
  );
}

function TemplateForm({ editing, stations, presetStation, onDone }: { editing: ShiftTemplate | null; stations: WorkStation[]; presetStation: number | null; onDone: () => void }) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const errorText = useErrorText();
  const save = useSaveTemplate();
  const idempotency = useIdempotencyKey();
  const box = useRef<HTMLDivElement>(null);
  const [form, setForm] = useState<TemplateForm>(() => (editing ? formOf(editing) : { ...emptyTemplateForm(), station_ids: presetStation ? [presetStation] : [] }));
  const [issues, setIssues] = useState<TemplateIssue[]>([]);
  const [saved, setSaved] = useState<string | null>(null);

  // Edit (and the setup check's "Add a template" for a station) opens the form under the list: it is brought into view
  useEffect(() => {
    if (editing || presetStation) box.current?.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }, [editing, presetStation]);

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
    <div ref={box} className="scroll-mt-6">
      <Panel title={editing ? t("templates.edit") : t("templates.new")}>
        <form onSubmit={onSubmit} className="space-y-5" noValidate>
          <div className={FORM_GRID}>
            <Field label={t("templates.name_en")} error={issue("name_en") ?? fieldError("name_en")}>
              {(props) => <TextInput {...props} controlSize="md" value={form.name_en} maxLength={80} onChange={(event) => setForm({ ...form, name_en: event.target.value })} />}
            </Field>
            <Field label={t("templates.name_am")} error={issue("name_am") ?? fieldError("name_am")}>
              {(props) => <TextInput {...props} controlSize="md" lang="am" value={form.name_am} maxLength={80} onChange={(event) => setForm({ ...form, name_am: event.target.value })} />}
            </Field>
            <div className="grid grid-cols-2 gap-4">
              <Field label={t("templates.start")} error={issue("times")}>
                {(props) => <TextInput {...props} controlSize="md" type="time" value={form.start_time} onChange={(event) => setForm({ ...form, start_time: event.target.value })} />}
              </Field>
              <Field label={t("templates.end")}>
                {(props) => <TextInput {...props} controlSize="md" type="time" value={form.end_time} onChange={(event) => setForm({ ...form, end_time: event.target.value })} />}
              </Field>
            </div>
            <div className="flex flex-col justify-end gap-2">
              {crossesMidnight(form.start_time, form.end_time) ? <p className="text-[13px] text-neutral-600">{t("templates.midnight_hint")}</p> : null}
              <label className="flex min-h-11 cursor-pointer items-center gap-3 text-[15px] text-neutral-900">
                <input type="checkbox" className="size-5 accent-ink-900" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} />
                {t("templates.is_active")}
              </label>
            </div>

            <fieldset className="space-y-2">
              <legend className="mb-2 text-sm font-semibold text-neutral-900">{t("templates.days")}</legend>
              <div className="flex flex-wrap gap-2">
                {WEEKDAYS.map((day) => (
                  <label key={day} className={PILL}>
                    <input type="checkbox" className="size-[18px] accent-ink-900 outline-none" checked={form.days.includes(day)} onChange={() => setForm({ ...form, days: toggle(form.days, day) })} />
                    {t(`weekday_short.${day}`)}
                  </label>
                ))}
              </div>
              {issue("days") ? <p className="text-sm font-medium text-red-700">{issue("days")}</p> : null}
            </fieldset>

            <fieldset className="space-y-2">
              <legend className="mb-2 text-sm font-semibold text-neutral-900">{t("templates.stations")}</legend>
              <div className="flex flex-wrap gap-2">
                {stations.map((station) => (
                  <label key={station.station_id} className={PILL}>
                    <input
                      type="checkbox"
                      className="size-[18px] accent-ink-900 outline-none"
                      checked={form.station_ids.includes(station.station_id)}
                      onChange={() => setForm({ ...form, station_ids: toggle(form.station_ids, station.station_id) })}
                    />
                    {name(station)}
                  </label>
                ))}
              </div>
              {issue("stations") ? <p className="text-sm font-medium text-red-700">{issue("stations")}</p> : null}
            </fieldset>
          </div>

          {save.isError && Object.keys((save.error as { fieldErrors?: object }).fieldErrors ?? {}).length === 0 ? <Alert>{errorText.message(save.error)}</Alert> : null}
          <Status>{saved ? t("templates.saved", { name: saved }) : null}</Status>
          <div className="flex flex-wrap gap-2.5">
            <Button type="submit" size="md" loading={save.isPending}>
              {editing ? t("templates.save_changes") : t("templates.add")}
            </Button>
            {editing ? (
              <Button type="button" variant="secondary" size="md" onClick={onDone}>
                {t("cancel")}
              </Button>
            ) : null}
          </div>
        </form>
      </Panel>
    </div>
  );
}
