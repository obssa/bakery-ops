"use client";

import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useEffect, useId, useMemo, useRef, useState, type FormEvent } from "react";
import { useRouter } from "@/i18n/navigation";
import { olderPayments, paymentsExport, useAllChannels } from "@/lib/api/adminPayments";
import { usePaymentChannels } from "@/lib/api/till";
import { PAYMENT_STATUSES, type PaymentChannel, type PaymentDetail, type PaymentListRow } from "@/lib/api/types";
import {
  exportFileName,
  filterIssue,
  filterParams,
  filtersFromParams,
  foldHead,
  listedChannels,
  NO_FILTERS,
  olderCursor,
  patchRows,
  visibleRows,
  type ExportFormat,
  type ListPages,
  type PaymentFilters,
} from "@/lib/adminPayments";
import { useErrorText } from "@/lib/errors";
import { createPaymentsFeed } from "@/lib/live/payments";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { businessToday } from "@/lib/orderNo";
import { can, useSession } from "@/lib/session";
import { useDateFormat } from "@/lib/useDates";
import { Alert, Button, Field, PageHeader, Select, Status, TextInput } from "../ui";
import { PaymentDialog } from "./PaymentDialog";
import { PhotoThumb, PhotoViewer } from "./PhotoViewer";
import { addisStamp, Money, PaymentStatusCell, saveBlob, TD, TH, useChannelName, useOrderLabel } from "./shared";

// Admin > Payments > Payments (step 4.5c): the branch's payments, newest first, filtered by the page's address (dates, method, status, order
// number, missing photo, flagged), so a reload, the reconciliation's drill and the payment_disputed notification's link (?payment={id}) all
// land on the same list. The first page is a feed (lib/live/payments.ts: every 10 s visible, 30 s hidden, a 304 keeps what is shown); older
// pages are read by cursor on request. A row's photo opens the viewer (a fresh signed URL); a row opens the payment, where a checker
// (payments.verify) verifies or disputes it. The export (payments.verify) downloads the same filters as CSV or Excel with the bearer token.

const PATH = "/admin/payments";

export function PaymentsScreen() {
  const t = useTranslations("adminPayments");
  const dates = useDateFormat();
  const params = useSearchParams();
  const router = useRouter();
  const me = useSession((s) => s.me);
  const signedIn = useSession((s) => s.status === "signed-in");
  const checker = can(me, "payments.verify");

  const filters = useMemo(() => filtersFromParams(params), [params]);
  const query = useMemo(() => filterParams(filters), [filters]);
  const queryKey = JSON.stringify(query);
  const paymentParam = params.get("payment");
  const openId = paymentParam && /^[1-9]\d{0,9}$/.test(paymentParam) ? Number(paymentParam) : null;

  // eslint-disable-next-line react-hooks/exhaustive-deps -- one feed per filter set; `query` is rebuilt on every render of the same filters
  const feed = useMemo(() => (signedIn ? createPaymentsFeed(query) : null), [queryKey, signedIn]);
  const snapshot = useLiveFeed(feed);

  const [pages, setPages] = useState<ListPages>({ head: [], older: [] });
  const [olderEnd, setOlderEnd] = useState(false);
  const [olderBusy, setOlderBusy] = useState(false);
  const [olderError, setOlderError] = useState<unknown>(null);
  const [viewing, setViewing] = useState<{ paymentId: number; fileId: number } | null>(null);

  // a new filter set starts a new list
  useEffect(() => {
    setPages({ head: [], older: [] });
    setOlderEnd(false);
    setOlderError(null);
  }, [feed]);

  // each changed first page is folded in (a 304 changes nothing)
  const revision = snapshot?.revision ?? 0;
  const headRows = snapshot?.state.rows;
  useEffect(() => {
    if (revision > 0 && headRows) setPages((current) => foldHead(current, headRows));
  }, [revision, headRows]);

  const rows = visibleRows(pages);
  const headCursor = snapshot?.state.nextCursor ?? null;
  const cursor = olderCursor(pages, headCursor);
  const more = pages.older.length > 0 ? !olderEnd && cursor !== null : headCursor !== null;

  async function loadOlder() {
    if (!cursor) return;
    setOlderBusy(true);
    setOlderError(null);
    try {
      const page = await olderPayments(query, cursor);
      setPages((current) => ({ ...current, older: visibleRows({ head: [], older: [...current.older, ...page.data] }) }));
      setOlderEnd(page.next_cursor === null);
    } catch (error) {
      setOlderError(error);
    } finally {
      setOlderBusy(false);
    }
  }

  function go(next: Record<string, string>) {
    router.replace({ pathname: PATH, query: next });
  }

  function onChanged(payment: PaymentDetail) {
    setPages((current) => patchRows(current, payment));
    feed?.refresh();
  }

  return (
    <>
      <PageHeader title={t("list.title")} intro={t("list.intro")} />
      <div className="space-y-6">
        <FilterForm key={queryKey} filters={filters} onApply={(next) => go(filterParams(next))} onClear={() => go({})} />
        {checker ? <ExportBar filters={filters} query={query} /> : null}
        {snapshot?.error && snapshot.loaded ? <Alert>{t("stale")}</Alert> : null}
        {!snapshot?.loaded ? (
          snapshot?.error ? (
            <FeedError error={snapshot.error} onRetry={() => feed?.refresh()} />
          ) : (
            <p role="status" className="py-6 text-base text-neutral-600">
              {t("list.loading")}
            </p>
          )
        ) : (
          <PaymentsTable
            rows={rows}
            onOpen={(id) => go({ ...query, payment: String(id) })}
            onPhoto={(row) => row.receipt && setViewing({ paymentId: row.id, fileId: row.receipt.file_id })}
          />
        )}
        {snapshot?.loaded ? (
          <div className="flex flex-wrap items-center gap-3">
            {more ? (
              <Button variant="secondary" disabled={olderBusy} onClick={() => void loadOlder()}>
                {olderBusy ? t("list.older_loading") : t("list.older")}
              </Button>
            ) : rows.length > 0 ? (
              <p className="text-base text-neutral-600">{t("list.end")}</p>
            ) : null}
            {olderError ? <OlderError error={olderError} /> : null}
            {snapshot.state.serverTime ? <p className="ml-auto text-sm text-neutral-500">{t("updated", { time: addisStamp(snapshot.state.serverTime, dates) })}</p> : null}
          </div>
        ) : null}
      </div>
      {openId !== null ? (
        <PaymentDialog
          key={openId}
          id={openId}
          canDecide={checker}
          onClose={() => go(query)}
          onChanged={onChanged}
        />
      ) : null}
      {viewing ? <PhotoViewer paymentId={viewing.paymentId} fileId={viewing.fileId} onClose={() => setViewing(null)} /> : null}
    </>
  );
}

function FeedError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  const t = useTranslations("adminPayments");
  const errorText = useErrorText();
  return (
    <div className="space-y-3">
      <Alert>{errorText.message(error)}</Alert>
      <Button variant="secondary" onClick={onRetry}>
        {t("viewer.retry")}
      </Button>
    </div>
  );
}

function OlderError({ error }: { error: unknown }) {
  const errorText = useErrorText();
  return <Alert>{errorText.message(error)}</Alert>;
}

/** The channels offered as a filter: every one of them, removed ones too, for an admin; the live ones for others (GET /payment-channels). */
function useFilterChannels(): PaymentChannel[] {
  const me = useSession((s) => s.me);
  const manager = can(me, "channels.manage");
  const all = useAllChannels(manager);
  const live = usePaymentChannels();
  return listedChannels((manager ? all.data : live.data) ?? []);
}

function FilterForm({ filters, onApply, onClear }: { filters: PaymentFilters; onApply: (filters: PaymentFilters) => void; onClear: () => void }) {
  const t = useTranslations("adminPayments");
  const channelName = useChannelName();
  const channels = useFilterChannels();
  const [draft, setDraft] = useState<PaymentFilters>(filters);
  const [issue, setIssue] = useState<"dates" | "order_no" | null>(null);
  const set = <K extends keyof PaymentFilters>(key: K, value: PaymentFilters[K]) => setDraft((current) => ({ ...current, [key]: value }));

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const found = filterIssue(draft);
    setIssue(found);
    if (found === null) onApply(draft);
  }

  const known = channels.some((channel) => String(channel.id) === draft.channelId);
  const flaggedHint = useId();
  return (
    <form role="search" aria-label={t("list.filters")} onSubmit={onSubmit} className="space-y-3 rounded-lg border border-neutral-200 bg-white p-4" noValidate>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <Field label={t("list.from")} error={issue === "dates" ? t("list.issue_dates") : undefined}>
          {(props) => <TextInput {...props} type="date" value={draft.from} onChange={(event) => set("from", event.target.value)} />}
        </Field>
        <Field label={t("list.to")}>{(props) => <TextInput {...props} type="date" value={draft.to} onChange={(event) => set("to", event.target.value)} />}</Field>
        <Field label={t("list.channel")}>
          {(props) => (
            <Select {...props} value={draft.channelId} onChange={(event) => set("channelId", event.target.value)}>
              <option value="">{t("list.all_channels")}</option>
              {channels.map((channel) => (
                <option key={channel.id} value={String(channel.id)}>
                  {channel.deleted_at ? t("list.channel_removed", { name: channelName(channel) }) : channelName(channel)}
                </option>
              ))}
              {draft.channelId && !known ? <option value={draft.channelId}>{`#${draft.channelId}`}</option> : null}
            </Select>
          )}
        </Field>
        <Field label={t("list.status")}>
          {(props) => (
            <Select {...props} value={draft.status} onChange={(event) => set("status", event.target.value as PaymentFilters["status"])}>
              <option value="">{t("list.all_statuses")}</option>
              {PAYMENT_STATUSES.map((status) => (
                <option key={status} value={status}>
                  {t(`status.${status}`)}
                </option>
              ))}
            </Select>
          )}
        </Field>
        <Field label={t("list.order_no")} error={issue === "order_no" ? t("list.issue_order_no") : undefined}>
          {(props) => <TextInput {...props} inputMode="numeric" value={draft.orderNo} maxLength={10} onChange={(event) => set("orderNo", event.target.value)} />}
        </Field>
      </div>
      <div className="flex flex-wrap items-center gap-x-8 gap-y-2">
        <label className="flex min-h-12 items-center gap-3 text-base">
          <input type="checkbox" className="size-5" checked={draft.missingPhoto} onChange={(event) => set("missingPhoto", event.target.checked)} />
          {t("list.missing_photo")}
        </label>
        <label className="flex min-h-12 items-center gap-3 text-base">
          <input type="checkbox" className="size-5" checked={draft.flagged} onChange={(event) => set("flagged", event.target.checked)} aria-describedby={flaggedHint} />
          {t("list.flagged")}
        </label>
        <span id={flaggedHint} className="text-sm text-neutral-600">
          {t("list.flagged_hint")}
        </span>
        <div className="ml-auto flex flex-wrap gap-3">
          <Button type="submit">{t("list.apply")}</Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => {
              setDraft(NO_FILTERS);
              setIssue(null);
              onClear();
            }}
          >
            {t("list.clear")}
          </Button>
        </div>
      </div>
    </form>
  );
}

function ExportBar({ filters, query }: { filters: PaymentFilters; query: Record<string, string> }) {
  const t = useTranslations("adminPayments");
  const locale = useLocale();
  const errorText = useErrorText();
  const [busy, setBusy] = useState<ExportFormat | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [saved, setSaved] = useState<string | null>(null);
  const running = useRef(false);

  async function download(format: ExportFormat) {
    if (running.current) return;
    running.current = true;
    setBusy(format);
    setError(null);
    setSaved(null);
    try {
      const blob = await paymentsExport(query, format, locale);
      const name = exportFileName(filters, format, businessToday());
      saveBlob(blob, name);
      setSaved(name);
    } catch (failure) {
      setError(failure);
    } finally {
      running.current = false;
      setBusy(null);
    }
  }

  return (
    <section aria-label={t("list.export_title")} className="space-y-3">
      <div className="flex flex-wrap items-center gap-3">
        <Button variant="secondary" disabled={busy !== null} onClick={() => void download("csv")}>
          {busy === "csv" ? t("list.exporting") : t("list.export_csv")}
        </Button>
        <Button variant="secondary" disabled={busy !== null} onClick={() => void download("xlsx")}>
          {busy === "xlsx" ? t("list.exporting") : t("list.export_xlsx")}
        </Button>
        <p className="text-sm text-neutral-600">{t("list.export_hint")}</p>
      </div>
      {error ? <Alert>{errorText.message(error)}</Alert> : null}
      <Status>{saved ? t("list.exported", { name: saved }) : null}</Status>
    </section>
  );
}

function PaymentsTable({ rows, onOpen, onPhoto }: { rows: PaymentListRow[]; onOpen: (id: number) => void; onPhoto: (row: PaymentListRow) => void }) {
  const t = useTranslations("adminPayments");
  const dates = useDateFormat();
  const channelName = useChannelName();
  const orderLabel = useOrderLabel();
  return (
    <div className="overflow-x-auto rounded-lg border border-neutral-200 bg-white px-4">
      <table className="w-full border-collapse text-left text-base">
        <caption className="sr-only">{t("list.table")}</caption>
        <thead>
          <tr className="border-b border-neutral-200 text-sm text-neutral-600">
            <th scope="col" className={TH}>{t("list.col_payment")}</th>
            <th scope="col" className={TH}>{t("list.col_received")}</th>
            <th scope="col" className={TH}>{t("list.col_order")}</th>
            <th scope="col" className={TH}>{t("list.col_channel")}</th>
            <th scope="col" className={`${TH} text-right`}>{t("list.col_amount")}</th>
            <th scope="col" className={TH}>{t("list.col_reference")}</th>
            <th scope="col" className={TH}>{t("list.col_photo")}</th>
            <th scope="col" className={TH}>{t("list.col_status")}</th>
            <th scope="col" className="py-2 font-medium">
              <span className="sr-only">{t("list.col_actions")}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={9} className="py-6 text-neutral-600">
                {t("list.empty")}
              </td>
            </tr>
          ) : (
            rows.map((row) => (
              <tr key={row.id} className="border-b border-neutral-100 last:border-0">
                <td className={`${TD} tabular-nums text-neutral-700`}>{row.id}</td>
                <td className={`${TD} whitespace-nowrap`}>
                  {addisStamp(row.received_at, dates)}
                  {row.received_by.name ? <span className="block text-sm text-neutral-600">{t("list.by", { name: row.received_by.name })}</span> : null}
                </td>
                <td className={TD}>
                  {t("list.order_label", { no: orderLabel(row.order) })}
                  {row.order?.table_code ? <span className="block text-sm text-neutral-600">{t("list.table_code", { table: row.order.table_code })}</span> : null}
                </td>
                <td className={TD}>{channelName(row.channel)}</td>
                <td className={`${TD} whitespace-nowrap text-right`}>
                  <Money amount={row.amount} currency={row.currency} />
                </td>
                <td className={`${TD} font-mono text-sm`}>{row.reference_no ?? "—"}</td>
                <td className={TD}>
                  {row.receipt ? (
                    <PhotoThumb key={row.receipt.url ?? "none"} label={t("list.photo_open", { id: row.id })} fileId={row.receipt.file_id} url={row.receipt.url} onOpen={() => onPhoto(row)} />
                  ) : (
                    <span className="whitespace-nowrap text-sm text-neutral-600">{t("list.no_photo")}</span>
                  )}
                </td>
                <td className={TD}>
                  <PaymentStatusCell payment={row} />
                </td>
                <td className="py-3 align-top">
                  <Button variant="secondary" aria-label={t("list.open_aria", { id: row.id })} onClick={() => onOpen(row.id)}>
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
