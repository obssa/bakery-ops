"use client";

import { useTranslations } from "next-intl";
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from "react";
import { useAllReasons, useSaveReason } from "@/lib/api/adminShifts";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import type { VarianceReason } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";
import { can, useSession } from "@/lib/session";
import { reasonIssues, type ReasonIssue } from "@/lib/shiftsAdmin";
import { ACTIONS_CELL, EmptyRow, FORM_GRID, TableCard } from "../../admin/kit";
import { QueryRows } from "../../admin/rows";
import { Alert, Button, Chip, Field, Panel, Status, TextInput } from "../../ui";
import { useName } from "./common";

// Admin > Shifts > Variance reasons (step 3.7c): the reasons a counter picks when a count differs from what was expected, each named in
// English and Amharic. A reason is never deleted (counts point at it); retiring one takes it out of the picker and keeps the history. Its
// code is typed once and never changes. The admin (shifts.manage) keeps them; everyone who reads shifts sees them.
//
// Step 6.4d (fresh-counter.md 4.4, the CatalogList board's table): the reasons a table card the width of the page (the name over its other
// language, the code, the note rule, the state as a chip, Edit and Retire at the row's end); the form a white card under it in two
// columns, brought into view by Edit.

export function ReasonsScreen() {
  const t = useTranslations("adminShifts");
  const reasons = useAllReasons();
  const me = useSession((s) => s.me);
  const manage = can(me, "shifts.manage");
  const [editing, setEditing] = useState<VarianceReason | null>(null);
  const span = manage ? 5 : 4;
  return (
    <div className="flex flex-col gap-6">
      <ReasonTable manage={manage}>
        <QueryRows query={reasons} span={span}>
          {(rows) => (rows.length === 0 ? <EmptyRow span={span}>{t("reasons.none")}</EmptyRow> : <ReasonRows rows={rows} manage={manage} onEdit={setEditing} />)}
        </QueryRows>
      </ReasonTable>
      {manage ? <ReasonForm key={editing?.id ?? "new"} editing={editing} onDone={() => setEditing(null)} /> : null}
    </div>
  );
}

function ReasonTable({ manage, children }: { manage: boolean; children: ReactNode }) {
  const t = useTranslations("adminShifts");
  return (
    <TableCard caption={t("reasons.list")} title={t("reasons.list")} minWidth="min-w-[720px]">
      <thead>
        <tr>
          <th scope="col">{t("reasons.col_name")}</th>
          <th scope="col">{t("reasons.col_code")}</th>
          <th scope="col">{t("reasons.col_note")}</th>
          <th scope="col">{t("reasons.col_status")}</th>
          {manage ? (
            <th scope="col" className="text-right">
              <span className="sr-only">{t("reasons.col_actions")}</span>
            </th>
          ) : null}
        </tr>
      </thead>
      <tbody>{children}</tbody>
    </TableCard>
  );
}

function ReasonRows({ rows, manage, onEdit }: { rows: VarianceReason[]; manage: boolean; onEdit: (row: VarianceReason) => void }) {
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
    <>
      {save.isError ? (
        <tr>
          <td colSpan={manage ? 5 : 4} className="py-3">
            <Alert>{errorText.message(save.error)}</Alert>
          </td>
        </tr>
      ) : null}
      {rows.map((row) => (
        <tr key={row.id}>
          <th scope="row" className="font-semibold text-neutral-900">
            {name(row)}
            <span className="block text-[13px] font-normal text-neutral-600" lang={row.name_am === name(row) ? "en" : "am"}>
              {row.name_am === name(row) ? row.name_en : row.name_am}
            </span>
          </th>
          <td className="font-mono text-sm text-neutral-700" translate="no">
            {row.code}
          </td>
          <td className="text-neutral-700">{row.requires_note ? t("reasons.note_required") : t("reasons.note_optional")}</td>
          <td>
            {row.is_active ? (
              <Chip tone="brand" dot>
                {t("reasons.active")}
              </Chip>
            ) : (
              <Chip dot>{t("reasons.retired")}</Chip>
            )}
          </td>
          {manage ? (
            <td className={ACTIONS_CELL}>
              <span className="inline-flex gap-2">
                <Button variant="secondary" size="md" compact onClick={() => onEdit(row)} aria-label={t("reasons.edit_named", { name: name(row) })}>
                  {t("edit")}
                </Button>
                <Button
                  variant="ghost"
                  size="md"
                  compact
                  disabled={save.isPending}
                  onClick={() => toggle(row)}
                  aria-label={t(row.is_active ? "reasons.retire_named" : "reasons.restore_named", { name: name(row) })}
                >
                  {t(row.is_active ? "reasons.retire" : "reasons.restore")}
                </Button>
              </span>
            </td>
          ) : null}
        </tr>
      ))}
    </>
  );
}

function ReasonForm({ editing, onDone }: { editing: VarianceReason | null; onDone: () => void }) {
  const t = useTranslations("adminShifts");
  const errorText = useErrorText();
  const save = useSaveReason();
  const idempotency = useIdempotencyKey();
  const box = useRef<HTMLDivElement>(null);
  const [code, setCode] = useState(editing?.code ?? "");
  const [nameEn, setNameEn] = useState(editing?.name_en ?? "");
  const [nameAm, setNameAm] = useState(editing?.name_am ?? "");
  const [requiresNote, setRequiresNote] = useState(editing?.requires_note ?? false);
  const [issues, setIssues] = useState<ReasonIssue[]>([]);
  const [saved, setSaved] = useState<string | null>(null);

  // Edit opens the form under the list: it is brought into view
  useEffect(() => {
    if (editing) box.current?.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }, [editing]);

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
    <div ref={box} className="scroll-mt-6">
      <Panel title={editing ? t("reasons.edit") : t("reasons.new")}>
        <form onSubmit={onSubmit} className="space-y-5" noValidate>
          <div className={FORM_GRID}>
            <Field label={t("reasons.code")} hint={editing ? t("reasons.code_fixed") : t("reasons.code_hint")} error={issue("code") ?? fieldError("code")}>
              {(props) => <TextInput {...props} controlSize="md" value={code} maxLength={32} disabled={editing !== null} onChange={(event) => setCode(event.target.value)} />}
            </Field>
            <label className="flex min-h-11 cursor-pointer items-center gap-3 self-start text-[15px] text-neutral-900 lg:mt-7">
              <input type="checkbox" className="size-5 accent-brand-600" checked={requiresNote} onChange={(event) => setRequiresNote(event.target.checked)} />
              {t("reasons.requires_note")}
            </label>
            <Field label={t("reasons.name_en")} error={issue("name_en") ?? fieldError("name_en")}>
              {(props) => <TextInput {...props} controlSize="md" value={nameEn} maxLength={80} onChange={(event) => setNameEn(event.target.value)} />}
            </Field>
            <Field label={t("reasons.name_am")} error={issue("name_am") ?? fieldError("name_am")}>
              {(props) => <TextInput {...props} controlSize="md" lang="am" value={nameAm} maxLength={80} onChange={(event) => setNameAm(event.target.value)} />}
            </Field>
          </div>
          {save.isError && Object.keys((save.error as { fieldErrors?: object }).fieldErrors ?? {}).length === 0 ? <Alert>{errorText.message(save.error)}</Alert> : null}
          <Status>{saved ? t("reasons.saved", { name: saved }) : null}</Status>
          <div className="flex flex-wrap gap-2.5">
            <Button type="submit" size="md" loading={save.isPending}>
              {editing ? t("reasons.save_changes") : t("reasons.add")}
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
