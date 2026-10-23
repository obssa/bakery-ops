"use client";

import { useTranslations } from "next-intl";
import { useState, type FormEvent } from "react";
import { useAllReasons, useSaveReason } from "@/lib/api/adminShifts";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import type { VarianceReason } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { reasonIssues, type ReasonIssue } from "@/lib/shiftsAdmin";
import { Alert, Badge, Button, Field, Panel, Status, TextInput } from "../../ui";
import { QueryBox } from "../../inventory/shared";
import { TD, TH, useName } from "./common";

// Admin > Shifts > Variance reasons (step 3.7c): the reasons a counter picks when a count differs from what was expected, each named in
// English and Amharic. A reason is never deleted (counts point at it); retiring one takes it out of the picker and keeps the history. Its
// code is typed once and never changes. The admin (shifts.manage) keeps them; everyone who reads shifts sees them.

export function ReasonsScreen() {
  const t = useTranslations("adminShifts");
  const reasons = useAllReasons();
  const me = useSession((s) => s.me);
  const manage = can(me, "shifts.manage");
  const [editing, setEditing] = useState<VarianceReason | null>(null);
  return (
    <div className={manage ? "grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]" : ""}>
      <Panel title={t("reasons.list")}>
        <QueryBox query={reasons}>{(rows) => <ReasonTable rows={rows} manage={manage} onEdit={setEditing} />}</QueryBox>
      </Panel>
      {manage ? <ReasonForm key={editing?.id ?? "new"} editing={editing} onDone={() => setEditing(null)} /> : null}
    </div>
  );
}

function ReasonTable({ rows, manage, onEdit }: { rows: VarianceReason[]; manage: boolean; onEdit: (row: VarianceReason) => void }) {
  const t = useTranslations("adminShifts");
  const name = useName();
  const errorText = useErrorText();
  const save = useSaveReason();
  const idempotency = useIdempotencyKey();

  function toggle(row: VarianceReason) {
    const body = { name_en: row.name_en, name_am: row.name_am, is_active: !row.is_active };
    save.mutate({ id: row.id, body, idempotencyKey: idempotency.keyFor({ id: row.id, ...body }) }, { onSuccess: () => idempotency.done() });
  }

  return (
    <div className="space-y-3">
      {save.isError ? <Alert>{errorText.message(save.error)}</Alert> : null}
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-left text-base">
          <caption className="sr-only">{t("reasons.list")}</caption>
          <thead>
            <tr className="border-b border-neutral-200 text-sm text-neutral-600">
              <th scope="col" className={TH}>{t("reasons.col_name")}</th>
              <th scope="col" className={TH}>{t("reasons.col_code")}</th>
              <th scope="col" className={TH}>{t("reasons.col_note")}</th>
              <th scope="col" className={TH}>{t("reasons.col_status")}</th>
              {manage ? <th scope="col" className="py-2 font-medium">{t("reasons.col_actions")}</th> : null}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={manage ? 5 : 4} className="py-6 text-neutral-600">{t("reasons.none")}</td>
              </tr>
            ) : (
              rows.map((row) => (
                <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                  <th scope="row" className={`${TD} font-medium text-neutral-900`}>
                    {name(row)}
                    <span className="block text-sm font-normal text-neutral-600">{row.name_am === name(row) ? row.name_en : row.name_am}</span>
                  </th>
                  <td className={`${TD} font-mono text-sm`}>{row.code}</td>
                  <td className={TD}>{row.requires_note ? t("reasons.note_required") : t("reasons.note_optional")}</td>
                  <td className={TD}>{row.is_active ? <Badge tone="green">{t("reasons.active")}</Badge> : <Badge>{t("reasons.retired")}</Badge>}</td>
                  {manage ? (
                    <td className="py-3">
                      <div className="flex flex-wrap gap-2">
                        <Button variant="secondary" onClick={() => onEdit(row)} aria-label={t("reasons.edit_named", { name: name(row) })}>
                          {t("edit")}
                        </Button>
                        <Button variant="secondary" disabled={save.isPending} onClick={() => toggle(row)} aria-label={t(row.is_active ? "reasons.retire_named" : "reasons.restore_named", { name: name(row) })}>
                          {t(row.is_active ? "reasons.retire" : "reasons.restore")}
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
    </div>
  );
}

function ReasonForm({ editing, onDone }: { editing: VarianceReason | null; onDone: () => void }) {
  const t = useTranslations("adminShifts");
  const errorText = useErrorText();
  const save = useSaveReason();
  const idempotency = useIdempotencyKey();
  const [code, setCode] = useState(editing?.code ?? "");
  const [nameEn, setNameEn] = useState(editing?.name_en ?? "");
  const [nameAm, setNameAm] = useState(editing?.name_am ?? "");
  const [requiresNote, setRequiresNote] = useState(editing?.requires_note ?? false);
  const [issues, setIssues] = useState<ReasonIssue[]>([]);
  const [saved, setSaved] = useState<string | null>(null);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(null);
    const found = reasonIssues({ code, name_en: nameEn, name_am: nameAm }, editing === null);
    setIssues(found);
    if (found.length > 0) return;
    const body = { ...(editing ? {} : { code }), name_en: nameEn.trim(), name_am: nameAm.trim(), requires_note: requiresNote };
    save.mutate(
      { id: editing?.id ?? null, body, idempotencyKey: idempotency.keyFor({ id: editing?.id ?? null, ...body }) },
      {
        onSuccess: () => {
          idempotency.done();
          setSaved(body.name_en);
          if (editing) onDone();
          else {
            setCode("");
            setNameEn("");
            setNameAm("");
            setRequiresNote(false);
          }
        },
      },
    );
  }

  const issue = (which: ReasonIssue) => (issues.includes(which) ? t(`reasons.issue_${which}`) : undefined);
  const fieldError = (field: string) => errorText.field(save.error, field);
  return (
    <Panel title={editing ? t("reasons.edit") : t("reasons.new")}>
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        <Field label={t("reasons.code")} hint={editing ? t("reasons.code_fixed") : t("reasons.code_hint")} error={issue("code") ?? fieldError("code")}>
          {(props) => <TextInput {...props} value={code} maxLength={32} disabled={editing !== null} onChange={(event) => setCode(event.target.value)} />}
        </Field>
        <Field label={t("reasons.name_en")} error={issue("name_en") ?? fieldError("name_en")}>
          {(props) => <TextInput {...props} value={nameEn} maxLength={80} onChange={(event) => setNameEn(event.target.value)} />}
        </Field>
        <Field label={t("reasons.name_am")} error={issue("name_am") ?? fieldError("name_am")}>
          {(props) => <TextInput {...props} value={nameAm} maxLength={80} onChange={(event) => setNameAm(event.target.value)} />}
        </Field>
        <label className="flex min-h-12 items-center gap-3 text-base">
          <input type="checkbox" className="size-5" checked={requiresNote} onChange={(event) => setRequiresNote(event.target.checked)} />
          {t("reasons.requires_note")}
        </label>
        {save.isError && Object.keys((save.error as { fieldErrors?: object }).fieldErrors ?? {}).length === 0 ? <Alert>{errorText.message(save.error)}</Alert> : null}
        <Status>{saved ? t("reasons.saved", { name: saved }) : null}</Status>
        <div className="flex gap-3">
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? t("working") : editing ? t("reasons.save_changes") : t("reasons.add")}
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
