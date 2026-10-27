"use client";

import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
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
import { addisStamp, saveBlob, TD, TH } from "../payments/shared";
import { Alert, Button, Field, PageHeader, Select, Status, TextInput } from "../ui";
import { EntryDrawer } from "./EntryDrawer";
import { useNames } from "./names";
import { SubjectCell } from "./SubjectCell";

// Admin > Audit log (step 4.5f; the reads of step 4.5a, phase-4 plan section 4.7): every audited write and event of the branch, newest
// first, for audit.view (admin, manager). The filters live in the page's address (dates, person, action prefix, subject, station, shift),
// so a reload, a "History" link from a payment, an order or a count, and a shared link land on the same list; ?id= opens one entry in the
// drawer (its before and after as a diff). The list is not polled: an audit trail does not need a live refresh, so the first page is read
// when the filters change and again on Refresh, and older pages by cursor (4.5c's foldHead and olderCursor). The admin (audit.export)
// downloads the same filters as CSV or Excel.

const PATH = "/admin/audit";

export function AuditScreen() {
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
      <PageHeader title={t("title")} intro={t("intro")} />
      <div className="space-y-6">
        <FilterForm key={queryKey} filters={filters} onApply={(next) => go(auditFilterParams(next))} onClear={() => go({})} />
        {exporter ? <ExportBar filters={filters} query={query} /> : null}
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

  return (
    <section aria-label={t("list.region")} className="space-y-3">
      <div className="flex flex-wrap items-center gap-3">
        <Button variant="secondary" disabled={page.isFetching} onClick={() => void page.refetch()}>
          {page.isFetching && page.data ? t("list.refreshing") : t("list.refresh")}
        </Button>
        <p className="text-sm text-neutral-600">{readAt > 0 ? t("list.read_at", { time: addisStamp(new Date(readAt).toISOString(), dates) }) : t("list.not_polled")}</p>
      </div>
      {page.isError && page.data ? <Alert>{t("list.stale")}</Alert> : null}
      {page.data ? (
        <AuditTable rows={rows} onOpen={onOpen} />
      ) : page.isError ? (
        <div className="space-y-3">
          <Alert>{errorText.message(page.error)}</Alert>
          <Button variant="secondary" onClick={() => void page.refetch()}>
            {t("retry")}
          </Button>
        </div>
      ) : (
        <p role="status" className="py-6 text-base text-neutral-600">
          {t("list.loading")}
        </p>
      )}
      {page.data ? (
        <div className="flex flex-wrap items-center gap-3">
          {more ? (
            <Button variant="secondary" disabled={olderBusy} onClick={() => void loadOlder()}>
              {olderBusy ? t("list.older_loading") : t("list.older")}
            </Button>
          ) : rows.length > 0 ? (
            <p className="text-base text-neutral-600">{t("list.end")}</p>
          ) : null}
          {olderError ? <Alert>{errorText.message(olderError)}</Alert> : null}
        </div>
      ) : null}
    </section>
  );
}

function AuditTable({ rows, onOpen }: { rows: AuditEntry[]; onOpen: (id: number) => void }) {
  const t = useTranslations("audit");
  const dates = useDateFormat();
  const names = useNames();
  return (
    <div className="overflow-x-auto rounded-lg border border-neutral-200 bg-white px-4">
      <table className="w-full border-collapse text-left text-base">
        <caption className="sr-only">{t("list.table")}</caption>
        <thead>
          <tr className="border-b border-neutral-200 text-sm text-neutral-600">
            <th scope="col" className={TH}>{t("list.col_time")}</th>
            <th scope="col" className={TH}>{t("list.col_who")}</th>
            <th scope="col" className={TH}>{t("list.col_action")}</th>
            <th scope="col" className={TH}>{t("list.col_subject")}</th>
            <th scope="col" className={TH}>{t("list.col_where")}</th>
            <th scope="col" className={TH}>{t("list.col_summary")}</th>
            <th scope="col" className="py-2 font-medium">
              <span className="sr-only">{t("list.col_open")}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={7} className="py-6 text-neutral-600">
                {t("list.empty")}
              </td>
            </tr>
          ) : (
            rows.map((row) => (
              <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                <td className={`${TD} whitespace-nowrap tabular-nums`}>{addisStamp(row.at, dates)}</td>
                <td className={TD}>
                  {names.person(row.actor)}
                  {row.acting_user ? <span className="block text-sm text-neutral-600">{t("list.acting", { name: names.person(row.acting_user) })}</span> : null}
                </td>
                <td className={`${TD} font-mono text-sm`}>{row.action}</td>
                <td className={TD}>
                  <SubjectCell entry={row} />
                </td>
                <td className={TD}>
                  {row.station_id !== null ? <span className="block">{names.station(row.station_id)}</span> : null}
                  {row.shift_id !== null ? <span className="block text-sm text-neutral-600">{t("shift_no", { id: row.shift_id })}</span> : null}
                  {row.station_id === null && row.shift_id === null ? "—" : null}
                </td>
                <td className={TD}>
                  <Summary entry={row} />
                </td>
                <td className="py-3 align-top">
                  <Button variant="secondary" compact aria-label={t("list.open_aria", { id: row.id })} onClick={() => onOpen(row.id)}>
                    {t("list.open")}
                  </Button>
                </td>
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}

function Summary({ entry }: { entry: AuditEntry }) {
  const t = useTranslations("audit");
  const { changed, more, reason } = entry.summary;
  return (
    <>
      {changed.length > 0 ? (
        <span className="block font-mono text-sm text-neutral-800">
          {changed.join(", ")}
          {more > 0 ? <span className="font-sans text-neutral-600"> {t("list.more", { count: more })}</span> : null}
        </span>
      ) : (
        <span className="block text-sm text-neutral-600">{t("list.nothing_changed")}</span>
      )}
      {reason ? <span className="mt-1 block text-sm text-neutral-700">{t("list.reason", { reason })}</span> : null}
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
    <form role="search" aria-label={t("filters.title")} onSubmit={onSubmit} className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4" noValidate>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Field label={t("filters.from")} error={issue === "dates" ? t("filters.issue_dates") : undefined}>
          {(props) => <TextInput {...props} type="date" value={draft.from} onChange={(event) => set("from", event.target.value)} />}
        </Field>
        <Field label={t("filters.to")}>{(props) => <TextInput {...props} type="date" value={draft.to} onChange={(event) => set("to", event.target.value)} />}</Field>
        <Field label={t("filters.person")} hint={t("filters.person_hint")}>
          {(props) => (
            <Select {...props} value={draft.actorId} onChange={(event) => set("actorId", event.target.value)}>
              <option value="">{t("filters.anyone")}</option>
              {people.map((person) => (
                <option key={person.id} value={String(person.id)}>
                  {names.person(person)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t("filters.station")}>
          {(props) => (
            <Select {...props} value={draft.stationId} onChange={(event) => set("stationId", event.target.value)}>
              <option value="">{t("filters.any_station")}</option>
              {stations.map((station) => (
                <option key={station.station_id} value={String(station.station_id)}>
                  {locale === "am" ? station.name_am || station.name_en : station.name_en || station.name_am}
                </option>
              ))}
              {draft.stationId && !stationKnown ? <option value={draft.stationId}>{t("station_no", { id: draft.stationId })}</option> : null}
            </Select>
          )}
        </Field>
        <Field label={t("filters.action")}>
          {(props) => (
            <Select {...props} value={draft.actionPick} onChange={(event) => set("actionPick", event.target.value)}>
              <option value="">{t("filters.any_action")}</option>
              {ACTION_PREFIXES.map((prefix) => (
                <option key={prefix} value={prefix}>
                  {t("filters.prefix_option", { name: t(`prefix.${prefix.slice(0, -1)}`), prefix })}
                </option>
              ))}
              <option value={OTHER}>{t("filters.other_action")}</option>
            </Select>
          )}
        </Field>
        {draft.actionPick === OTHER ? (
          <Field label={t("filters.action_text")} hint={t("filters.action_text_hint")}>
            {(props) => <TextInput {...props} value={draft.actionText} maxLength={ACTION_MAX} className="font-mono" onChange={(event) => set("actionText", event.target.value)} />}
          </Field>
        ) : null}
        <Field label={t("filters.subject")}>
          {(props) => (
            <Select {...props} value={draft.subjectPick} onChange={(event) => set("subjectPick", event.target.value)}>
              <option value="">{t("filters.any_subject")}</option>
              {SUBJECT_CHOICES.map((kind) => (
                <option key={kind} value={kind}>
                  {t(`subject.${kind}`)}
                </option>
              ))}
              <option value={OTHER}>{t("filters.other_subject")}</option>
            </Select>
          )}
        </Field>
        {draft.subjectPick === OTHER ? (
          <Field label={t("filters.subject_text")} hint={t("filters.subject_text_hint")}>
            {(props) => <TextInput {...props} value={draft.subjectText} maxLength={SUBJECT_TYPE_MAX} className="font-mono" onChange={(event) => set("subjectText", event.target.value)} />}
          </Field>
        ) : null}
        <Field label={t("filters.subject_id")} error={issue === "subject_id" ? t("filters.issue_id") : undefined}>
          {(props) => <TextInput {...props} inputMode="numeric" value={draft.subjectId} maxLength={10} onChange={(event) => set("subjectId", event.target.value)} />}
        </Field>
        <Field label={t("filters.shift_id")} error={issue === "shift_id" ? t("filters.issue_id") : undefined}>
          {(props) => <TextInput {...props} inputMode="numeric" value={draft.shiftId} maxLength={10} onChange={(event) => set("shiftId", event.target.value)} />}
        </Field>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit">{t("filters.apply")}</Button>
        <Button
          type="button"
          variant="secondary"
          onClick={() => {
            setDraft(draftOf(NO_AUDIT_FILTERS));
            setIssue(null);
            onClear();
          }}
        >
          {t("filters.clear")}
        </Button>
      </div>
    </form>
  );
}

// --- the export (audit.export, the admin) ---------------------------------------------------------------------------------------------------

function ExportBar({ filters, query }: { filters: AuditFilters; query: Record<string, string> }) {
  const t = useTranslations("audit");
  const locale = useLocale();
  const errorText = useErrorText();
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
    <section aria-label={t("export.title")} className="space-y-3">
      <div className="flex flex-wrap items-center gap-3">
        <Button variant="secondary" disabled={busy !== null} onClick={() => void download("csv")}>
          {busy === "csv" ? t("export.busy") : t("export.csv")}
        </Button>
        <Button variant="secondary" disabled={busy !== null} onClick={() => void download("xlsx")}>
          {busy === "xlsx" ? t("export.busy") : t("export.xlsx")}
        </Button>
        <p className="text-sm text-neutral-600">{t("export.hint")}</p>
      </div>
      {error ? (
        <Alert>
          {errorText.message(error)}
          {tooLarge ? <span className="mt-1 block">{t("export.too_large", { rows: tooLarge.rows, max: tooLarge.maxRows })}</span> : null}
        </Alert>
      ) : null}
      <Status>{saved ? t("export.saved", { name: saved }) : null}</Status>
    </section>
  );
}
