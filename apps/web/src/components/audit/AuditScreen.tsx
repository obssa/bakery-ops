"use client";

import { Download, RefreshCw } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from "react";
import { useRouter } from "@/i18n/navigation";
import { auditExport, olderAuditPage, useAuditPage } from "@/lib/api/audit";
import { useRosterPeople } from "@/lib/api/adminShifts";
import type { AuditEntry, Locale } from "@/lib/api/types";
import { foldHead, olderCursor, visibleRows, type ListPages } from "@/lib/adminPayments";
import {
  ACTION_MAX,
  ACTION_PREFIXES,
  actionChoice,
  actionOf,
  auditExportFileName,
  auditFilterIssue,
  auditFilterParams,
  auditFiltersFromParams,
  entryIdOf,
  NO_AUDIT_FILTERS,
  OTHER,
  peopleOptions,
  SUBJECT_CHOICES,
  SUBJECT_TYPE_MAX,
  subjectChoice,
  subjectTypeOf,
  tooLargeOf,
  type AuditFilterIssue,
  type AuditFilters,
  type PersonOption,
} from "@/lib/audit";
import { useErrorText } from "@/lib/errors";
import { businessToday } from "@/lib/orderNo";
import { can, useSession } from "@/lib/session";
import { useDateFormat } from "@/lib/useDates";
import { ACTIONS_CELL, EmptyRow, Notice, TableCard, Toolbar, ToolbarField, ToolbarSelect } from "../admin/kit";
import { LoadingRows } from "../admin/rows";
import { addisStamp, saveBlob } from "../payments/shared";
import { Alert, Button, PageHeader } from "../ui";
import { EntryDrawer } from "./EntryDrawer";
import { useNames } from "./names";
import { SubjectCell } from "./SubjectCell";

// Admin > Audit log (step 4.5f; the reads of step 4.5a, phase-4 plan section 4.7): every audited write and event of the branch, newest
// first, for audit.view (admin, manager). The filters live in the page's address (dates, person, action prefix, subject, station, shift),
// so a reload, a "History" link from a payment, an order or a count, and a shared link land on the same list; ?id= opens one entry in the
// drawer (its before and after as a diff). The list is not polled: an audit trail does not need a live refresh, so the first page is read
// when the filters change and again on Refresh, and older pages by cursor (4.5c's foldHead and olderCursor). The admin (audit.export)
// downloads the same filters as CSV or Excel.
//
// Step 6.4d (fresh-counter.md 4.4, the CatalogList board's title row, toolbar and table): the title row with its Amharic twin, the exports
// as its actions; the filters as the toolbar (still the search landmark "Audit log filters" with the same labels), Apply the primary
// button; the entries a table card (the time in tabular numbers, the action code in a neutral chip, the subject a brand link, Open at the
// row's end), Refresh and when it was read on the card's title row, the older entries in its foot.

const PATH = "/admin/audit";

export function AuditScreen({ titleAm }: { titleAm?: string }) {
  const t = useTranslations("audit");
  const params = useSearchParams();
  const router = useRouter();
  const filters = useMemo(() => auditFiltersFromParams(params), [params]);
  const query = useMemo(() => auditFilterParams(filters), [filters]);
  const queryKey = JSON.stringify(query);
  const openId = entryIdOf(params);
  const exporter = useSession((s) => can(s.me, "audit.export"));

  function go(next: Record<string, string>) {
    router.replace({ pathname: PATH, query: next });
  }

  return (
    <>
      <PageHeader
        title={t("title")}
        intro={t("intro")}
        twin={titleAm ? { text: titleAm, lang: "am" } : undefined}
        actions={exporter ? <ExportButtons filters={filters} query={query} /> : undefined}
      />
      <div className="flex flex-col gap-6">
        <FilterForm key={queryKey} filters={filters} onApply={(next) => go(auditFilterParams(next))} onClear={() => go({})} />
        <AuditList key={queryKey} query={query} onOpen={(id) => go({ ...query, id: String(id) })} />
      </div>
      {openId !== null ? <EntryDrawer key={openId} id={openId} onClose={() => go(query)} /> : null}
    </>
  );
}

// --- the list ------------------------------------------------------------------------------------------------------------------------------

function AuditList({ query, onOpen }: { query: Record<string, string>; onOpen: (id: number) => void }) {
  const t = useTranslations("audit");
  const errorText = useErrorText();
  const dates = useDateFormat();
  const page = useAuditPage(query);
  const [pages, setPages] = useState<ListPages<AuditEntry>>({ head: [], older: [] });
  const [olderEnd, setOlderEnd] = useState(false);
  const [olderBusy, setOlderBusy] = useState(false);
  const [olderError, setOlderError] = useState<unknown>(null);

  // each first page read (the first time, and on Refresh) is folded in; rows pushed out of it stay above the older pages
  const head = page.data?.data;
  const readAt = page.dataUpdatedAt;
  useEffect(() => {
    if (head) setPages((current) => foldHead(current, head));
  }, [head, readAt]);

  const rows = pages.head.length > 0 || pages.older.length > 0 ? visibleRows(pages) : (head ?? []);
  const headCursor = page.data?.next_cursor ?? null;
  const cursor = olderCursor(pages, headCursor);
  const more = pages.older.length > 0 ? !olderEnd && cursor !== null : headCursor !== null;

  async function loadOlder() {
    if (!cursor) return;
    setOlderBusy(true);
    setOlderError(null);
    try {
      const older = await olderAuditPage(query, cursor);
      setPages((current) => ({ ...current, older: visibleRows({ head: [], older: [...current.older, ...older.data] }) }));
      setOlderEnd(older.next_cursor === null);
    } catch (error) {
      setOlderError(error);
    } finally {
      setOlderBusy(false);
    }
  }

  const footer = page.data ? (
    <div className="flex flex-wrap items-center gap-3">
      {more ? (
        <Button variant="secondary" size="md" loading={olderBusy} onClick={() => void loadOlder()}>
          {olderBusy ? t("list.older_loading") : t("list.older")}
        </Button>
      ) : rows.length > 0 ? (
        <p className="text-[13px] text-neutral-600">{t("list.end")}</p>
      ) : null}
      {olderError ? <Alert>{errorText.message(olderError)}</Alert> : null}
    </div>
  ) : undefined;

  return (
    <section aria-label={t("list.region")} className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-3">
        <Button variant="secondary" size="md" disabled={page.isFetching} onClick={() => void page.refetch()}>
          <RefreshCw className={`size-4 ${page.isFetching && page.data ? "motion-safe:animate-spin" : ""}`} strokeWidth={2} aria-hidden="true" />
          {page.isFetching && page.data ? t("list.refreshing") : t("list.refresh")}
        </Button>
        <p className="text-[13px] text-neutral-600">{readAt > 0 ? t("list.read_at", { time: addisStamp(new Date(readAt).toISOString(), dates) }) : t("list.not_polled")}</p>
      </div>
      {page.isError && page.data ? <Notice tone="amber">{t("list.stale")}</Notice> : null}
      {page.isError && !page.data ? (
        <div className="space-y-3">
          <Alert>{errorText.message(page.error)}</Alert>
          <Button variant="secondary" size="md" onClick={() => void page.refetch()}>
            {t("retry")}
          </Button>
        </div>
      ) : (
        <AuditTable rows={page.data ? rows : null} onOpen={onOpen} footer={footer} />
      )}
    </section>
  );
}

function AuditTable({ rows, onOpen, footer }: { rows: AuditEntry[] | null; onOpen: (id: number) => void; footer?: ReactNode }) {
  const t = useTranslations("audit");
  const dates = useDateFormat();
  const names = useNames();
  return (
    <TableCard caption={t("list.table")} minWidth="min-w-[980px]" footer={footer}>
      <thead>
        <tr>
          <th scope="col">{t("list.col_time")}</th>
          <th scope="col">{t("list.col_who")}</th>
          <th scope="col">{t("list.col_action")}</th>
          <th scope="col">{t("list.col_subject")}</th>
          <th scope="col">{t("list.col_where")}</th>
          <th scope="col">{t("list.col_summary")}</th>
          <th scope="col">
            <span className="sr-only">{t("list.col_open")}</span>
          </th>
        </tr>
      </thead>
      <tbody>
        {rows === null ? (
          <LoadingRows span={7} rows={6} label={t("list.loading")} />
        ) : rows.length === 0 ? (
          <EmptyRow span={7}>{t("list.empty")}</EmptyRow>
        ) : (
          rows.map((row) => (
            <tr key={row.id} className="align-top">
              <td className="whitespace-nowrap tabular-nums text-neutral-700">{addisStamp(row.at, dates)}</td>
              <td>
                <span className="font-semibold text-neutral-900">{names.person(row.actor)}</span>
                {row.acting_user ? <span className="block text-[13px] text-neutral-600">{t("list.acting", { name: names.person(row.acting_user) })}</span> : null}
              </td>
              <td>
                <span className="inline-flex min-h-7 items-center rounded-full bg-neutral-100 px-2.5 font-mono text-[13px] font-medium text-neutral-800" translate="no">
                  {row.action}
                </span>
              </td>
              <td>
                <SubjectCell entry={row} />
              </td>
              <td>
                {row.station_id !== null ? <span className="block">{names.station(row.station_id)}</span> : null}
                {row.shift_id !== null ? <span className="block text-[13px] text-neutral-600">{t("shift_no", { id: row.shift_id })}</span> : null}
                {row.station_id === null && row.shift_id === null ? <span className="text-neutral-600">—</span> : null}
              </td>
              <td>
                <Summary entry={row} />
              </td>
              <td className={ACTIONS_CELL}>
                <Button variant="secondary" size="md" compact aria-label={t("list.open_aria", { id: row.id })} onClick={() => onOpen(row.id)}>
                  {t("list.open")}
                </Button>
              </td>
            </tr>
          ))
        )}
      </tbody>
    </TableCard>
  );
}

function Summary({ entry }: { entry: AuditEntry }) {
  const t = useTranslations("audit");
  const { changed, more, reason } = entry.summary;
  return (
    <>
      {changed.length > 0 ? (
        <span className="block font-mono text-[13px] text-neutral-800">
          <span translate="no">{changed.join(", ")}</span>
          {more > 0 ? <span className="font-sans text-neutral-600"> {t("list.more", { count: more })}</span> : null}
        </span>
      ) : (
        <span className="block text-[13px] text-neutral-600">{t("list.nothing_changed")}</span>
      )}
      {reason ? <span className="mt-1 block text-[13px] text-neutral-700">{t("list.reason", { reason })}</span> : null}
    </>
  );
}

// --- the filters ----------------------------------------------------------------------------------------------------------------------------

interface Draft extends AuditFilters {
  actionPick: string;
  actionText: string;
  subjectPick: string;
  subjectText: string;
}

function draftOf(filters: AuditFilters): Draft {
  const actionPick = actionChoice(filters.action);
  const subjectPick = subjectChoice(filters.subjectType);
  return {
    ...filters,
    actionPick,
    actionText: actionPick === OTHER ? filters.action : "",
    subjectPick,
    subjectText: subjectPick === OTHER ? filters.subjectType : "",
  };
}

function filtersOf(draft: Draft): AuditFilters {
  return {
    from: draft.from,
    to: draft.to,
    actorId: draft.actorId,
    action: actionOf(draft.actionPick, draft.actionText),
    subjectType: subjectTypeOf(draft.subjectPick, draft.subjectText),
    subjectId: draft.subjectId.trim(),
    stationId: draft.stationId,
    shiftId: draft.shiftId.trim(),
  };
}

/** Whom the person filter offers: the people list for an admin, and everyone the list on screen names (cached rows of this page). */
function usePeopleChoices(selected: string): PersonOption[] {
  const people = useRosterPeople();
  const params = useSearchParams();
  const query = useMemo(() => auditFilterParams(auditFiltersFromParams(params)), [params]);
  const page = useAuditPage(query);
  return peopleOptions(people.data?.map((user) => ({ id: user.id, name: user.name })) ?? [], page.data?.data ?? [], selected);
}

function FilterForm({ filters, onApply, onClear }: { filters: AuditFilters; onApply: (filters: AuditFilters) => void; onClear: () => void }) {
  const t = useTranslations("audit");
  const names = useNames();
  const locale = useLocale() as Locale;
  const workStations = useSession((s) => s.me?.work_stations ?? []);
  const [draft, setDraft] = useState<Draft>(() => draftOf(filters));
  const [issue, setIssue] = useState<AuditFilterIssue | null>(null);
  const people = usePeopleChoices(draft.actorId);
  const set = <K extends keyof Draft>(key: K, value: Draft[K]) => setDraft((current) => ({ ...current, [key]: value }));

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const next = filtersOf(draft);
    const found = auditFilterIssue(next);
    setIssue(found);
    if (found === null) onApply(next);
  }

  const stations = [...workStations].sort((a, b) => a.station_id - b.station_id);
  const stationKnown = stations.some((station) => String(station.station_id) === draft.stationId);
  return (
    <form role="search" aria-label={t("filters.title")} onSubmit={onSubmit} className="flex flex-col gap-2.5" noValidate>
      <Toolbar className="items-start">
        <ToolbarField label={t("filters.from")} type="date" value={draft.from} error={issue === "dates" ? t("filters.issue_dates") : undefined} onChange={(event) => set("from", event.target.value)} />
        <ToolbarField label={t("filters.to")} type="date" value={draft.to} onChange={(event) => set("to", event.target.value)} />
        <ToolbarSelect label={t("filters.person")} title={t("filters.person_hint")} value={draft.actorId} onChange={(event) => set("actorId", event.target.value)}>
          <option value="">{t("filters.anyone")}</option>
          {people.map((person) => (
            <option key={person.id} value={String(person.id)}>
              {names.person(person)}
            </option>
          ))}
        </ToolbarSelect>
        <ToolbarSelect label={t("filters.station")} value={draft.stationId} onChange={(event) => set("stationId", event.target.value)}>
          <option value="">{t("filters.any_station")}</option>
          {stations.map((station) => (
            <option key={station.station_id} value={String(station.station_id)}>
              {locale === "am" ? station.name_am || station.name_en : station.name_en || station.name_am}
            </option>
          ))}
          {draft.stationId && !stationKnown ? <option value={draft.stationId}>{t("station_no", { id: draft.stationId })}</option> : null}
        </ToolbarSelect>
      </Toolbar>
      <Toolbar className="items-start">
        <ToolbarSelect label={t("filters.action")} className="w-60" value={draft.actionPick} onChange={(event) => set("actionPick", event.target.value)}>
          <option value="">{t("filters.any_action")}</option>
          {ACTION_PREFIXES.map((prefix) => (
            <option key={prefix} value={prefix}>
              {t("filters.prefix_option", { name: t(`prefix.${prefix.slice(0, -1)}`), prefix })}
            </option>
          ))}
          <option value={OTHER}>{t("filters.other_action")}</option>
        </ToolbarSelect>
        {draft.actionPick === OTHER ? (
          <ToolbarField
            label={t("filters.action_text")}
            title={t("filters.action_text_hint")}
            value={draft.actionText}
            maxLength={ACTION_MAX}
            onChange={(event) => set("actionText", event.target.value)}
          />
        ) : null}
        <ToolbarSelect label={t("filters.subject")} className="w-52" value={draft.subjectPick} onChange={(event) => set("subjectPick", event.target.value)}>
          <option value="">{t("filters.any_subject")}</option>
          {SUBJECT_CHOICES.map((kind) => (
            <option key={kind} value={kind}>
              {t(`subject.${kind}`)}
            </option>
          ))}
          <option value={OTHER}>{t("filters.other_subject")}</option>
        </ToolbarSelect>
        {draft.subjectPick === OTHER ? (
          <ToolbarField
            label={t("filters.subject_text")}
            title={t("filters.subject_text_hint")}
            value={draft.subjectText}
            maxLength={SUBJECT_TYPE_MAX}
            onChange={(event) => set("subjectText", event.target.value)}
          />
        ) : null}
        <ToolbarField
          label={t("filters.subject_id")}
          inputMode="numeric"
          value={draft.subjectId}
          maxLength={10}
          size={8}
          error={issue === "subject_id" ? t("filters.issue_id") : undefined}
          onChange={(event) => set("subjectId", event.target.value)}
        />
        <ToolbarField
          label={t("filters.shift_id")}
          inputMode="numeric"
          value={draft.shiftId}
          maxLength={10}
          size={8}
          error={issue === "shift_id" ? t("filters.issue_id") : undefined}
          onChange={(event) => set("shiftId", event.target.value)}
        />
        <Button type="submit" size="md">
          {t("filters.apply")}
        </Button>
        <Button
          type="button"
          variant="ghost"
          size="md"
          onClick={() => {
            setDraft(draftOf(NO_AUDIT_FILTERS));
            setIssue(null);
            onClear();
          }}
        >
          {t("filters.clear")}
        </Button>
      </Toolbar>
    </form>
  );
}

// --- the export (audit.export, the admin) ---------------------------------------------------------------------------------------------------

/** CSV and Excel of the list's filters: the title row's actions, a group named "Export" described by what the file holds. */
function ExportButtons({ filters, query }: { filters: AuditFilters; query: Record<string, string> }) {
  const t = useTranslations("audit");
  const locale = useLocale();
  const errorText = useErrorText();
  const hintId = useId();
  const [busy, setBusy] = useState<"csv" | "xlsx" | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [saved, setSaved] = useState<string | null>(null);
  const running = useRef(false);

  async function download(format: "csv" | "xlsx") {
    if (running.current) return;
    running.current = true;
    setBusy(format);
    setError(null);
    setSaved(null);
    try {
      const blob = await auditExport(query, format, locale);
      const name = auditExportFileName(filters, format, businessToday());
      saveBlob(blob, name);
      setSaved(name);
    } catch (failure) {
      setError(failure);
    } finally {
      running.current = false;
      setBusy(null);
    }
  }

  const tooLarge = tooLargeOf(error);
  return (
    <div className="flex flex-col items-end gap-1.5">
      <div role="group" aria-label={t("export.title")} aria-describedby={hintId} className="flex flex-wrap justify-end gap-2">
        <Button variant="secondary" size="md" compact disabled={busy !== null} onClick={() => void download("csv")}>
          <Download className="size-4" strokeWidth={2} aria-hidden="true" />
          {busy === "csv" ? t("export.busy") : t("export.csv")}
        </Button>
        <Button variant="secondary" size="md" compact disabled={busy !== null} onClick={() => void download("xlsx")}>
          <Download className="size-4" strokeWidth={2} aria-hidden="true" />
          {busy === "xlsx" ? t("export.busy") : t("export.xlsx")}
        </Button>
      </div>
      <p id={hintId} className="sr-only">
        {t("export.hint")}
      </p>
      {error ? (
        <p role="alert" className="max-w-md text-right text-[13px] font-medium text-red-700">
          {errorText.message(error)}
          {tooLarge ? <span className="block">{t("export.too_large", { rows: tooLarge.rows, max: tooLarge.maxRows })}</span> : null}
        </p>
      ) : null}
      <p role="status" className={saved ? "max-w-md truncate text-right text-[13px] font-medium text-brand-700" : "sr-only"}>
        {saved ? t("export.saved", { name: saved }) : null}
      </p>
    </div>
  );
}
