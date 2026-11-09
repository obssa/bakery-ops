"use client";

import { Plus, Rows3, UsersRound, UtensilsCrossed, X } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useId, useMemo, useRef, useState, type FormEvent } from "react";
import { CARD } from "@/components/admin/kit";
import { ICON_STROKE } from "@/components/icons";
import { Alert, Button, Chip, Drawer, EmptyState, Field, IconButton, PageHeader, Skeleton, Status, TextInput, type Tone } from "@/components/ui";
import { ApiError } from "@/lib/api/client";
import { uuid, useIdempotencyKey } from "@/lib/api/idempotency";
import { createTable, useCreateTable, useManagedTables, useTablesWritten, useUpdateTable, type NewTable } from "@/lib/api/tables";
import type { DiningTable, Locale, TableState } from "@/lib/api/types";
import { useErrorText } from "@/lib/errors";

// Admin > Tables (Phase 6 follow-up; Ramin, 2026-11-09: "when we wish to add a table, where can we do that?"): the dining tables the pads
// show, for tables.manage (the admin and the manager). fresh-counter.md 4.5. The title row (the title with its Amharic twin, the intro,
// "Add several" and "Add table" at the right); then the tables as a grid of white cards in the pads' order, each with its code large, its
// name in the page's language over the other one, its seats, the live state chip the pads show (free, ordering, waiting, served, bill; in a
// word as well as a colour) or "Off" for a switched-off table, and its Edit and Switch off / Switch on. "Add table" and Edit open the form
// in a drawer (the code is fixed once the table exists: orders keep it); "Add several" makes a range (T11 to T20) by one create per code,
// each with its own Idempotency-Key, and says which codes were taken already. Every write reads the tables again everywhere
// (["dining-tables"]), so an open pad's grid shows the change on its next poll. A table is never deleted, only switched off.

const STATE_TONE: Record<TableState, Tone> = { free: "neutral", ordering: "blue", waiting: "amber", served: "brand", bill: "neutral" };

/** The API's rule for a code (StoreDiningTableRequest): letters, digits, - and _, at most 16, starting with a letter or a digit. */
const CODE = /^[A-Za-z0-9][A-Za-z0-9_-]{0,15}$/;
/** The most tables "Add several" makes at once. */
const MAX_RANGE = 50;
/** The names "Add several" gives by default: "Table 11" / "ጠረጴዛ 11", as the demo bakery's tables are named. Data, in both languages. */
const NAME_PREFIX = { en: "Table", am: "ጠረጴዛ" } as const;

type Sheet = { kind: "new" } | { kind: "edit"; table: DiningTable } | { kind: "several" } | null;

export function TablesScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("tables");
  const tables = useManagedTables();
  const [sheet, setSheet] = useState<Sheet>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const rows = tables.data ?? [];

  function open(next: Sheet) {
    setNotice(null);
    setSheet(next);
  }

  function finished(message: string | null) {
    setSheet(null);
    if (message) setNotice(message);
  }

  return (
    <>
      <PageHeader
        title={t("title")}
        intro={t("intro")}
        twin={titleAm ? { text: titleAm, lang: "am" } : undefined}
        actions={
          <>
            <Button variant="secondary" size="md" onClick={() => open({ kind: "several" })} aria-haspopup="dialog">
              <Rows3 className="size-[18px]" strokeWidth={ICON_STROKE} aria-hidden="true" />
              {t("add_several")}
            </Button>
            <Button size="md" onClick={() => open({ kind: "new" })} aria-haspopup="dialog">
              <Plus className="size-[18px]" strokeWidth={2} aria-hidden="true" />
              {t("add")}
            </Button>
          </>
        }
      />
      <div className="flex flex-col gap-5">
        <Status>{notice}</Status>
        <TableGrid query={tables} onEdit={(table) => open({ kind: "edit", table })} onAdd={() => open({ kind: "new" })} onNotice={setNotice} />
      </div>
      {sheet?.kind === "new" || sheet?.kind === "edit" ? (
        <TableDrawer editing={sheet.kind === "edit" ? sheet.table : null} tables={rows} onClose={() => setSheet(null)} onDone={finished} />
      ) : null}
      {sheet?.kind === "several" ? <SeveralDrawer tables={rows} onClose={finished} /> : null}
    </>
  );
}

// ------------------------------------------------------------------ the grid

function TableGrid({
  query,
  onEdit,
  onAdd,
  onNotice,
}: {
  query: ReturnType<typeof useManagedTables>;
  onEdit: (table: DiningTable) => void;
  onAdd: () => void;
  onNotice: (message: string | null) => void;
}) {
  const t = useTranslations("tables");
  const tc = useTranslations("common");
  const errorText = useErrorText();
  const headingId = useId();
  const update = useUpdateTable();
  const idempotency = useIdempotencyKey();

  if (query.isPending) {
    return (
      <div className="space-y-3">
        <p className="text-[15px] text-neutral-600">{t("loading")}</p>
        <div aria-hidden="true" className="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-4">
          {[0, 1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-44 rounded-[18px]" />
          ))}
        </div>
      </div>
    );
  }
  if (query.isError) {
    return (
      <div className="space-y-3">
        <Alert>{errorText.message(query.error)}</Alert>
        <Button variant="secondary" size="md" onClick={() => void query.refetch()}>
          {tc("retry")}
        </Button>
      </div>
    );
  }

  const rows = query.data;
  if (rows.length === 0) {
    return (
      <div className={CARD}>
        <EmptyState
          icon={UtensilsCrossed}
          title={t("none_title")}
          body={t("none_body")}
          action={
            <Button size="md" onClick={onAdd} aria-haspopup="dialog">
              {t("add")}
            </Button>
          }
        />
      </div>
    );
  }

  function toggle(table: DiningTable) {
    onNotice(null);
    const body = { is_active: !table.is_active };
    update.mutate(
      { id: table.id, body, idempotencyKey: idempotency.keyFor({ id: table.id, ...body }) },
      {
        onSuccess: (saved) => {
          idempotency.done();
          onNotice(t(saved.is_active ? "switched_on" : "switched_off", { code: saved.code }));
        },
      },
    );
  }

  const on = rows.filter((row) => row.is_active).length;

  return (
    <section aria-labelledby={headingId} className="flex min-w-0 flex-col gap-3">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 id={headingId} className="text-[17px] font-semibold text-neutral-900">
          {t("list")}
        </h2>
        <p className="text-sm tabular-nums text-neutral-600">{t("summary", { on, off: rows.length - on })}</p>
      </div>
      {update.isError ? <Alert>{errorText.message(update.error)}</Alert> : null}
      <ul className="grid grid-cols-[repeat(auto-fill,minmax(15rem,1fr))] gap-4">
        {rows.map((table) => (
          <li key={table.id} className="min-w-0">
            <TableCard table={table} busy={update.isPending} onEdit={() => onEdit(table)} onToggle={() => toggle(table)} />
          </li>
        ))}
      </ul>
    </section>
  );
}

function TableCard({ table, busy, onEdit, onToggle }: { table: DiningTable; busy: boolean; onEdit: () => void; onToggle: () => void }) {
  const t = useTranslations("tables");
  const locale = useLocale() as Locale;
  const id = useId();
  const off = !table.is_active;
  const [primary, secondary, otherLang] = locale === "am" ? [table.name_am, table.name_en, "en"] : [table.name_en, table.name_am, "am"];

  return (
    <article aria-labelledby={id} className={`${CARD} flex h-full flex-col gap-3 p-5 ${off ? "bg-neutral-50" : ""}`}>
      <div className="flex items-start justify-between gap-3">
        <h3 id={id} translate="no" className={`min-w-0 truncate text-[28px] font-bold leading-tight tracking-tight ${off ? "text-neutral-500" : "text-neutral-900"}`}>
          {table.code}
        </h3>
        {off ? (
          <Chip dot className="shrink-0">
            {t("off")}
          </Chip>
        ) : (
          <Chip tone={STATE_TONE[table.state]} dot className="shrink-0">
            {t(`state_${table.state}`)}
          </Chip>
        )}
      </div>
      <div className="min-w-0">
        <p className={`truncate text-[15px] font-semibold ${off ? "text-neutral-600" : "text-neutral-900"}`}>{primary}</p>
        <p lang={otherLang} className="truncate text-sm text-neutral-600">
          {secondary}
        </p>
      </div>
      <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm tabular-nums text-neutral-600">
        <span className="inline-flex items-center gap-1.5">
          <UsersRound className="size-4 shrink-0" strokeWidth={ICON_STROKE} aria-hidden="true" />
          {table.seats ? t("seats", { count: table.seats }) : t("no_seats")}
        </span>
        <span>{t("sort_value", { sort: table.sort })}</span>
      </p>
      <div className="mt-auto flex gap-2 pt-1">
        <Button variant="secondary" size="md" compact className="flex-1" onClick={onEdit} aria-haspopup="dialog" aria-label={t("edit_named", { code: table.code })}>
          {t("edit")}
        </Button>
        <Button
          variant="secondary"
          size="md"
          compact
          className="flex-1"
          disabled={busy}
          onClick={onToggle}
          aria-label={t(off ? "switch_on_named" : "switch_off_named", { code: table.code })}
        >
          {t(off ? "switch_on" : "switch_off")}
        </Button>
      </div>
    </article>
  );
}

// ------------------------------------------------------------------ the drawers

function DrawerHead({ id, title, onClose }: { id: string; title: string; onClose: () => void }) {
  const t = useTranslations("tables");
  return (
    <div className="flex items-center justify-between gap-3">
      <h2 id={id} className="text-xl font-semibold text-neutral-900">
        {title}
      </h2>
      <IconButton label={t("close")} size="md" onClick={onClose}>
        <X className="size-5" strokeWidth={ICON_STROKE} aria-hidden="true" />
      </IconButton>
    </div>
  );
}

/** The next place in the pads' order: after the last table. */
function nextSort(tables: DiningTable[]): number {
  return tables.reduce((max, table) => Math.max(max, table.sort), 0) + 1;
}

/** A whole number typed in a box, or null when the box is empty or holds something else. */
function wholeNumber(text: string): number | null {
  const trimmed = text.trim();
  return /^\d+$/.test(trimmed) ? Number(trimmed) : null;
}

function TableDrawer({
  editing,
  tables,
  onClose,
  onDone,
}: {
  editing: DiningTable | null;
  tables: DiningTable[];
  onClose: () => void;
  onDone: (message: string) => void;
}) {
  const t = useTranslations("tables");
  const errorText = useErrorText();
  const headingId = useId();
  const create = useCreateTable();
  const update = useUpdateTable();
  const idempotency = useIdempotencyKey();
  const [code, setCode] = useState(editing?.code ?? "");
  const [nameEn, setNameEn] = useState(editing?.name_en ?? "");
  const [nameAm, setNameAm] = useState(editing?.name_am ?? "");
  const [seats, setSeats] = useState(editing?.seats ? String(editing.seats) : "");
  const [sort, setSort] = useState(String(editing ? editing.sort : nextSort(tables)));
  const mutation = editing ? update : create;
  const title = editing ? t("edit_title", { code: editing.code }) : t("new_title");

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const fields = { name_en: nameEn.trim(), name_am: nameAm.trim(), seats: wholeNumber(seats), sort: wholeNumber(sort) ?? 0 };
    if (editing) {
      update.mutate(
        { id: editing.id, body: fields, idempotencyKey: idempotency.keyFor({ id: editing.id, ...fields }) },
        {
          onSuccess: (saved) => {
            idempotency.done();
            onDone(t("saved", { code: saved.code }));
          },
        },
      );
      return;
    }
    const body: NewTable = { code: code.trim(), ...fields };
    create.mutate(
      { body, idempotencyKey: idempotency.keyFor(body) },
      {
        onSuccess: (saved) => {
          idempotency.done();
          onDone(t("added", { code: saved.code }));
        },
      },
    );
  }

  const error = mutation.error;
  // a code already used in the branch (orders.table.code_taken) is the code's own error, in the page's language
  const codeTaken = error instanceof ApiError && error.body.field === "code" ? errorText.message(error) : undefined;
  const fieldError = (field: string) => errorText.field(error, field);
  const general = error && !codeTaken && !(error instanceof ApiError && Object.keys(error.fieldErrors).length > 0);
  const ready = (editing || code.trim() !== "") && nameEn.trim() !== "" && nameAm.trim() !== "";

  return (
    <Drawer label={title} onClose={onClose}>
      <section aria-labelledby={headingId} className="space-y-5">
        <DrawerHead id={headingId} title={title} onClose={onClose} />
        <form onSubmit={onSubmit} noValidate className="space-y-4">
          <Field label={t("field_code")} hint={editing ? t("code_fixed") : t("code_hint")} error={codeTaken ?? fieldError("code")}>
            {(props) => (
              <TextInput
                {...props}
                controlSize="md"
                value={code}
                onChange={(event) => setCode(event.target.value)}
                readOnly={!!editing}
                required
                maxLength={16}
                autoCapitalize="characters"
                autoComplete="off"
                spellCheck={false}
                className={`font-semibold ${editing ? "bg-neutral-100 text-neutral-600" : ""}`}
              />
            )}
          </Field>
          <Field label={t("field_name_en")} error={fieldError("name_en")}>
            {(props) => <TextInput {...props} controlSize="md" lang="en" value={nameEn} onChange={(event) => setNameEn(event.target.value)} required maxLength={120} />}
          </Field>
          <Field label={t("field_name_am")} error={fieldError("name_am")}>
            {(props) => <TextInput {...props} controlSize="md" lang="am" value={nameAm} onChange={(event) => setNameAm(event.target.value)} required maxLength={120} />}
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t("field_seats")} optionalLabel={t("optional")} error={fieldError("seats")}>
              {(props) => (
                <TextInput {...props} controlSize="md" type="number" inputMode="numeric" min={1} max={255} value={seats} onChange={(event) => setSeats(event.target.value)} />
              )}
            </Field>
            <Field label={t("field_sort")} hint={t("sort_hint")} error={fieldError("sort")}>
              {(props) => (
                <TextInput {...props} controlSize="md" type="number" inputMode="numeric" min={0} max={32767} value={sort} onChange={(event) => setSort(event.target.value)} />
              )}
            </Field>
          </div>
          {general ? <Alert>{errorText.message(error)}</Alert> : null}
          <div className="flex flex-wrap gap-2.5 pt-1">
            <Button type="submit" size="md" loading={mutation.isPending} disabled={!ready}>
              {editing ? t("save") : t("save_new")}
            </Button>
            <Button type="button" variant="secondary" size="md" onClick={onClose}>
              {t("cancel")}
            </Button>
          </div>
        </form>
      </section>
    </Drawer>
  );
}

interface RunResult {
  added: string[];
  taken: string[];
  failed: { code: string; message: string }[];
}

/** The largest number after `prefix` among the codes (T10 for "T"), so a range starts after the tables there are. */
function lastNumber(tables: DiningTable[], prefix: string): number {
  const pattern = new RegExp(`^${prefix.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}(\\d+)$`, "i");
  return tables.reduce((max, table) => {
    const match = pattern.exec(table.code);
    return match ? Math.max(max, Number(match[1])) : max;
  }, 0);
}

function SeveralDrawer({ tables, onClose }: { tables: DiningTable[]; onClose: (message: string | null) => void }) {
  const t = useTranslations("tables");
  const errorText = useErrorText();
  const headingId = useId();
  const written = useTablesWritten();
  const start = lastNumber(tables, "T") + 1;
  const [prefix, setPrefix] = useState("T");
  const [from, setFrom] = useState(String(start));
  const [to, setTo] = useState(String(start + 9));
  const [nameEn, setNameEn] = useState<string>(NAME_PREFIX.en);
  const [nameAm, setNameAm] = useState<string>(NAME_PREFIX.am);
  const [seats, setSeats] = useState("");
  const [running, setRunning] = useState<{ done: number; count: number } | null>(null);
  const [result, setResult] = useState<RunResult | null>(null);
  // one Idempotency-Key per code for as long as the drawer is open: a retry of a code whose answer was lost replays it, never a second table
  const keys = useRef(new Map<string, string>());
  // the range goes after the last table as the drawer opened, fixed, so a retry puts a code where the first try would have
  const [base] = useState(() => tables.reduce((max, table) => Math.max(max, table.sort), 0));

  const first = wholeNumber(from);
  const last = wholeNumber(to);
  const rangeOk = first !== null && last !== null && first <= last && last - first + 1 <= MAX_RANGE;
  const plan = useMemo(() => {
    if (!rangeOk || first === null || last === null) return [];
    return Array.from({ length: last - first + 1 }, (_, i) => first + i).map((n) => ({
      n,
      code: `${prefix.trim()}${n}`,
      name_en: `${nameEn.trim()} ${n}`.trim(),
      name_am: `${nameAm.trim()} ${n}`.trim(),
    }));
  }, [rangeOk, first, last, prefix, nameEn, nameAm]);
  const codesOk = plan.length > 0 && plan.every((row) => CODE.test(row.code));
  const namesOk = nameEn.trim() !== "" && nameAm.trim() !== "";

  async function run(rows: typeof plan) {
    const outcome: RunResult = { added: [], taken: [], failed: [] };
    const seatCount = wholeNumber(seats);
    setRunning({ done: 0, count: rows.length });
    for (const [index, row] of rows.entries()) {
      let key = keys.current.get(row.code);
      if (!key) {
        key = uuid();
        keys.current.set(row.code, key);
      }
      try {
        await createTable(
          { code: row.code, name_en: row.name_en, name_am: row.name_am, seats: seatCount, sort: base + plan.findIndex((p) => p.code === row.code) + 1 },
          key,
        );
        outcome.added.push(row.code);
      } catch (error) {
        if (error instanceof ApiError && error.key === "orders.table.code_taken") {
          outcome.taken.push(row.code); // nothing to retry: the code is another table's
        } else {
          outcome.failed.push({ code: row.code, message: errorText.message(error) });
        }
      }
      setRunning({ done: index + 1, count: rows.length });
    }
    setRunning(null);
    setResult((before) => ({
      added: [...(before?.added ?? []), ...outcome.added],
      taken: [...(before?.taken ?? []), ...outcome.taken],
      failed: outcome.failed,
    }));
    await written();
  }

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!codesOk || !namesOk || running) return;
    // a second press tries again only the codes that failed (a lost connection); the added and the taken ones are done
    void run(result ? plan.filter((row) => result.failed.some((f) => f.code === row.code)) : plan);
  }

  function close() {
    onClose(result && result.added.length > 0 ? t("several_added", { codes: result.added.join(", ") }) : null);
  }

  const locked = running !== null || result !== null;
  const finished = result !== null && result.failed.length === 0;

  return (
    <Drawer label={t("several_title")} onClose={close}>
      <section aria-labelledby={headingId} className="space-y-5">
        <DrawerHead id={headingId} title={t("several_title")} onClose={close} />
        <p className="text-[15px] text-neutral-600">{t("several_intro")}</p>
        <form onSubmit={onSubmit} noValidate className="space-y-4">
          <fieldset disabled={locked} className="space-y-4">
            <Field label={t("field_prefix")} optionalLabel={t("optional")} error={plan.length > 0 && !codesOk ? t("several_code") : undefined}>
              {(props) => (
                <TextInput
                  {...props}
                  controlSize="md"
                  value={prefix}
                  onChange={(event) => setPrefix(event.target.value)}
                  maxLength={12}
                  autoCapitalize="characters"
                  autoComplete="off"
                  spellCheck={false}
                  className="font-semibold"
                />
              )}
            </Field>
            <div className="grid grid-cols-2 gap-4">
              <Field label={t("field_from")}>
                {(props) => <TextInput {...props} controlSize="md" type="number" inputMode="numeric" min={0} value={from} onChange={(event) => setFrom(event.target.value)} />}
              </Field>
              <Field label={t("field_to")}>
                {(props) => <TextInput {...props} controlSize="md" type="number" inputMode="numeric" min={0} value={to} onChange={(event) => setTo(event.target.value)} />}
              </Field>
            </div>
            {!rangeOk ? <p className="text-sm font-medium text-red-700">{t("several_range", { max: MAX_RANGE })}</p> : null}
            <Field label={t("field_name_en_prefix")}>
              {(props) => <TextInput {...props} controlSize="md" lang="en" value={nameEn} onChange={(event) => setNameEn(event.target.value)} maxLength={100} />}
            </Field>
            <Field label={t("field_name_am_prefix")}>
              {(props) => <TextInput {...props} controlSize="md" lang="am" value={nameAm} onChange={(event) => setNameAm(event.target.value)} maxLength={100} />}
            </Field>
            <Field label={t("field_seats")} optionalLabel={t("optional")}>
              {(props) => (
                <TextInput {...props} controlSize="md" type="number" inputMode="numeric" min={1} max={255} value={seats} onChange={(event) => setSeats(event.target.value)} />
              )}
            </Field>
          </fieldset>
          {plan.length > 0 && codesOk ? (
            <p className="rounded-xl bg-neutral-100 px-4 py-3 text-[15px] text-neutral-700">
              {t("several_preview", { count: plan.length, first: plan[0].code, name: plan[0].name_en, last: plan[plan.length - 1].code })}
            </p>
          ) : null}
          <Status>
            {running
              ? t("several_progress", { done: running.done, count: running.count })
              : result
                ? [
                    result.added.length > 0 ? t("several_added", { codes: result.added.join(", ") }) : null,
                    result.taken.length > 0 ? t("several_taken", { codes: result.taken.join(", ") }) : null,
                  ]
                    .filter(Boolean)
                    .join(" ") || null
                : null}
          </Status>
          {result && result.failed.length > 0 ? (
            <Alert>{t("several_failed", { codes: result.failed.map((f) => f.code).join(", "), message: result.failed[0].message })}</Alert>
          ) : null}
          <div className="flex flex-wrap gap-2.5 pt-1">
            {finished ? (
              <Button type="button" size="md" onClick={close}>
                {t("done")}
              </Button>
            ) : (
              <Button type="submit" size="md" loading={running !== null} disabled={!codesOk || !namesOk}>
                {result ? t("several_retry") : t("several_submit", { count: plan.length })}
              </Button>
            )}
            {finished ? null : (
              <Button type="button" variant="secondary" size="md" onClick={close}>
                {t("cancel")}
              </Button>
            )}
          </div>
        </form>
      </section>
    </Drawer>
  );
}
