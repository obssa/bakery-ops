"use client";

import { useTranslations } from "next-intl";
import { useMemo, useState, type ReactNode } from "react";
import { useAuditEntry } from "@/lib/api/audit";
import { useRosterPeople } from "@/lib/api/adminShifts";
import type { AuditDetail } from "@/lib/api/types";
import { diffOf, foldOpen, personFieldId, subjectKindOf, valuesOf, type DiffRow, type DiffStatus, type Shown, type TextPart, type ValueRow } from "@/lib/audit";
import { useErrorText } from "@/lib/errors";
import { useDateFormat } from "@/lib/useDates";
import { RecordLink } from "../admin/RecordLink";
import { addisStamp } from "../payments/shared";
import { Alert, Badge, Button, Drawer } from "../ui";
import { SubjectCell } from "./SubjectCell";
import { useNames } from "./names";

// One entry of the audit log (step 4.5f): who, what, about which subject, where and when, then its before and after as a diff (lib/audit.ts
// diffOf): the changed, added and removed fields first and highlighted, the unchanged after them, the bookkeeping fields last and quiet; a
// nested object folded with its own diff inside (open when something in it changed); a list shown whole. A value the API hid ("[redacted]":
// a PIN, a reference number, a file key, a signed link) shows as a marker, never as data. A row with nothing before (an event, a create)
// lists what was recorded. The context (a reason, an approver, an order) reads as one column. A field that names a person is named. The
// raw JSON is there on request, as the API sent it (redacted too).

const STATUS_TONE: Record<Exclude<DiffStatus, "same">, "amber" | "green" | "red"> = { changed: "amber", added: "green", removed: "red" };
const ROW_TONE: Record<DiffStatus, string> = { changed: "bg-amber-50", added: "bg-green-50", removed: "bg-red-50", same: "" };
const CELL = "py-2 pr-3 align-top";

type People = Map<number, string | null>;

export function EntryDrawer({ id, onClose }: { id: number; onClose: () => void }) {
  const t = useTranslations("audit");
  const errorText = useErrorText();
  const entry = useAuditEntry(id);
  const title = t("entry.title", { id });

  let body: ReactNode;
  if (entry.isPending) {
    body = (
      <p role="status" className="py-6 text-base text-neutral-600">
        {t("entry.loading")}
      </p>
    );
  } else if (entry.isError) {
    body = (
      <div className="space-y-3">
        <Alert>{errorText.message(entry.error)}</Alert>
        <Button variant="secondary" onClick={() => void entry.refetch()}>
          {t("retry")}
        </Button>
      </div>
    );
  } else {
    body = <EntryBody entry={entry.data} />;
  }

  return (
    <Drawer label={title} onClose={onClose}>
      <div className="flex items-start justify-between gap-3">
        <h2 className="text-xl font-semibold text-neutral-900">{title}</h2>
        <Button variant="secondary" onClick={onClose}>
          {t("entry.close")}
        </Button>
      </div>
      {body}
    </Drawer>
  );
}

function usePeople(entry: AuditDetail): People {
  const list = useRosterPeople();
  return useMemo(() => {
    const people: People = new Map();
    for (const user of list.data ?? []) people.set(user.id, user.name);
    for (const person of [entry.actor, entry.acting_user]) if (person && !people.get(person.id)) people.set(person.id, person.name);
    return people;
  }, [list.data, entry]);
}

function EntryBody({ entry }: { entry: AuditDetail }) {
  const t = useTranslations("audit");
  const dates = useDateFormat();
  const names = useNames();
  const people = usePeople(entry);
  const [raw, setRaw] = useState(false);
  const recorded = entry.before === null;

  return (
    <>
      <dl className="grid gap-x-6 gap-y-2 rounded-lg border border-neutral-200 p-4 sm:grid-cols-2">
        <Fact term={t("entry.time")}>{addisStamp(entry.at, dates)}</Fact>
        <Fact term={t("entry.action")}>
          <span className="font-mono text-sm">{entry.action}</span>
        </Fact>
        <Fact term={t("entry.who")}>
          {names.person(entry.actor)}
          {entry.acting_user ? <span className="block text-sm text-neutral-600">{t("list.acting", { name: names.person(entry.acting_user) })}</span> : null}
        </Fact>
        <Fact term={t("entry.subject")}>
          <SubjectCell entry={entry} />
          {subjectKindOf(entry.subject_type) === "order" && entry.subject_id !== null ? (
            <span className="block">
              <RecordLink orderId={entry.subject_id} label={t("entry.open_record")} inline />
            </span>
          ) : null}
        </Fact>
        <Fact term={t("entry.station")}>{entry.station_id !== null ? names.station(entry.station_id) : "—"}</Fact>
        <Fact term={t("entry.shift")}>{entry.shift_id !== null ? t("shift_no", { id: entry.shift_id }) : "—"}</Fact>
        <Fact term={t("entry.device")}>{entry.device_id !== null ? t("device_no", { id: entry.device_id }) : "—"}</Fact>
        <Fact term={t("entry.ip")}>
          <span className="font-mono text-sm">{entry.ip ?? "—"}</span>
        </Fact>
      </dl>

      <section className="space-y-2">
        <h3 className="text-lg font-semibold text-neutral-900">{recorded ? t("entry.recorded") : t("entry.diff")}</h3>
        <p className="text-sm text-neutral-600">{recorded ? t("entry.recorded_hint") : t("entry.diff_hint")}</p>
        {recorded ? (
          <ValuesTable caption={t("entry.recorded")} rows={valuesOf(entry.after)} people={people} />
        ) : (
          <DiffTable caption={t("entry.diff")} rows={diffOf(entry.before, entry.after)} people={people} />
        )}
      </section>

      {entry.context && Object.keys(entry.context).length > 0 ? (
        <section className="space-y-2">
          <h3 className="text-lg font-semibold text-neutral-900">{t("entry.context")}</h3>
          <ValuesTable caption={t("entry.context")} rows={valuesOf(entry.context)} people={people} />
        </section>
      ) : null}

      <section className="space-y-2">
        <Button variant="secondary" aria-pressed={raw} onClick={() => setRaw((open) => !open)}>
          {raw ? t("entry.raw_hide") : t("entry.raw_show")}
        </Button>
        {raw ? (
          <section aria-label={t("entry.raw")} className="overflow-x-auto rounded-md bg-neutral-50 p-3">
            <pre className="whitespace-pre-wrap break-words font-mono text-sm text-neutral-800">{JSON.stringify({ before: entry.before, after: entry.after, context: entry.context }, null, 2)}</pre>
          </section>
        ) : null}
      </section>
    </>
  );
}

function Fact({ term, children }: { term: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-sm font-medium text-neutral-600">{term}</dt>
      <dd className="break-words text-base text-neutral-900">{children}</dd>
    </div>
  );
}

function DiffTable({ caption, rows, people, nested = false }: { caption: string; rows: DiffRow[]; people: People; nested?: boolean }) {
  const t = useTranslations("audit");
  if (rows.length === 0) return <p className="text-base text-neutral-600">{t("entry.no_fields")}</p>;
  return (
    <div className={nested ? "" : "overflow-x-auto rounded-lg border border-neutral-200 px-3"}>
      <table className="w-full border-collapse text-left text-base">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr className="border-b border-neutral-200 text-sm text-neutral-600">
            <th scope="col" className="py-2 pr-3 font-medium">{t("entry.col_field")}</th>
            <th scope="col" className="py-2 pr-3 font-medium">
              <span className="sr-only">{t("entry.col_change")}</span>
            </th>
            <th scope="col" className="py-2 pr-3 font-medium">{t("entry.col_before")}</th>
            <th scope="col" className="py-2 pr-3 font-medium">{t("entry.col_after")}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            const quiet = row.bookkeeping;
            return (
              <tr key={row.key} className={`border-b border-neutral-100 last:border-0 ${quiet ? "text-neutral-500" : ROW_TONE[row.status]}`}>
                <th scope="row" className={`${CELL} break-all font-mono text-sm font-medium`}>
                  {row.key}
                </th>
                <td className={CELL}>{row.status !== "same" && !quiet ? <Badge tone={STATUS_TONE[row.status]}>{t(`entry.status_${row.status}`)}</Badge> : null}</td>
                {row.fields ? (
                  <td colSpan={2} className={CELL}>
                    <details open={foldOpen(row)} className="space-y-2">
                      <summary className="min-h-12 cursor-pointer py-2 text-sm text-neutral-700">
                        {t("entry.nested", { count: row.fields.length, changed: row.fields.filter((field) => field.status !== "same").length })}
                      </summary>
                      <DiffTable caption={t("entry.fields_of", { field: row.key })} rows={row.fields} people={people} nested />
                    </details>
                  </td>
                ) : (
                  <>
                    <td className={CELL}>
                      <Value shown={row.before} field={row.key} people={people} />
                    </td>
                    <td className={CELL}>
                      <Value shown={row.after} field={row.key} people={people} />
                    </td>
                  </>
                )}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function ValuesTable({ caption, rows, people, nested = false }: { caption: string; rows: ValueRow[]; people: People; nested?: boolean }) {
  const t = useTranslations("audit");
  if (rows.length === 0) return <p className="text-base text-neutral-600">{t("entry.no_fields")}</p>;
  return (
    <div className={nested ? "" : "overflow-x-auto rounded-lg border border-neutral-200 px-3"}>
      <table className="w-full border-collapse text-left text-base">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr className="border-b border-neutral-200 text-sm text-neutral-600">
            <th scope="col" className="py-2 pr-3 font-medium">{t("entry.col_field")}</th>
            <th scope="col" className="py-2 pr-3 font-medium">{t("entry.col_value")}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.key} className="border-b border-neutral-100 last:border-0">
              <th scope="row" className={`${CELL} break-all font-mono text-sm font-medium`}>
                {row.key}
              </th>
              <td className={CELL}>
                <Value shown={row.value} field={row.key} people={people} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Parts({ parts }: { parts: TextPart[] }) {
  return (
    <>
      {parts.map((part, i) => ("redacted" in part ? <Redacted key={i} /> : <span key={i}>{part.text}</span>))}
    </>
  );
}

function Redacted() {
  const t = useTranslations("audit");
  return (
    <Badge tone="neutral" className="mx-0.5 align-baseline">
      <span title={t("entry.redacted_hint")}>{t("entry.redacted")}</span>
    </Badge>
  );
}

/** One value: the marker for a hidden one, "empty" for null, a dash for a field that is not on that side, a nested object folded. */
function Value({ shown, field, people }: { shown: Shown; field: string; people: People }) {
  const t = useTranslations("audit");
  switch (shown.kind) {
    case "missing":
      return (
        <span className="text-neutral-400">
          <span aria-hidden="true">—</span>
          <span className="sr-only">{t("entry.missing")}</span>
        </span>
      );
    case "null":
      return <span className="italic text-neutral-500">{t("entry.null")}</span>;
    case "redacted":
      return <Redacted />;
    case "text":
      return shown.parts.length === 0 ? <span className="italic text-neutral-500">{t("entry.empty_text")}</span> : <span className="whitespace-pre-wrap break-words">{<Parts parts={shown.parts} />}</span>;
    case "scalar": {
      const personId = personFieldId(field, Number.isInteger(Number(shown.text)) ? Number(shown.text) : null);
      const name = personId !== null ? people.get(personId) : undefined;
      return (
        <span className="font-mono text-sm">
          {shown.text}
          {name ? <span className="font-sans text-base text-neutral-800"> · {name}</span> : null}
        </span>
      );
    }
    case "list":
      return (
        <span className="break-words font-mono text-sm">
          <Parts parts={shown.parts} />
        </span>
      );
    case "object":
      return (
        <details>
          <summary className="min-h-12 cursor-pointer py-2 text-sm text-neutral-700">{t("entry.object", { count: shown.rows.length })}</summary>
          <ValuesTable caption={t("entry.fields_of", { field })} rows={shown.rows} people={people} nested />
        </details>
      );
  }
}
