"use client";

import { ChevronRight, Download } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useSearchParams } from "next/navigation";
import { useEffect, useId, useMemo, useRef, useState, type FormEvent, type ReactNode } from "react";
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
import { EmptyRow, Notice, TableCard, Toolbar, ToolbarCheck, ToolbarField, ToolbarSelect } from "../admin/kit";
import { Alert, Button, IconButton, PageHeader, Skeleton, Status } from "../ui";
import { PaymentDialog } from "./PaymentDialog";
import { PhotoThumb, PhotoViewer } from "./PhotoViewer";
import { addisStamp, Money, PaymentsNav, PaymentStatusCell, saveBlob, useChannelName, useOrderLabel } from "./shared";

// Admin > Payments > Payments (step 4.5c): the branch's payments, newest first, filtered by the page's address (dates, method, status, order
// number, missing photo, flagged), so a reload, the reconciliation's drill and the payment_disputed notification's link (?payment={id}) all
// land on the same list. The first page is a feed (lib/live/payments.ts: every 10 s visible, 30 s hidden, a 304 keeps what is shown); older
// pages are read by cursor on request. A row's photo opens the viewer (a fresh signed URL); a row opens the payment, where a checker
// (payments.verify) verifies or disputes it. The export (payments.verify) downloads the same filters as CSV or Excel with the bearer token.
//
// Step 6.4c (fresh-counter.md 4.4, the CatalogList board): the title row with its Amharic twin and the two exports as its actions; the
// section's tabs; the filters as the toolbar (the dates and the order number with their labels in the box, the method and status selects,
// the two switches, Apply and Clear), still the search landmark "Filters" with the same labels; the list a table card (the photo a 48 px
// thumb, the status chips, the chevron "Open payment N"), the older pages and the time of the last read in its footer.

const PATH = "/admin/payments";

export function PaymentsScreen({ titleAm }: { titleAm?: string }) {
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
  const exporter = useExport(filters, query);

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

  const footer = snapshot?.loaded ? (
    <div className="flex flex-wrap items-center gap-3">
      {more ? (
        <Button variant="secondary" size="md" disabled={olderBusy} onClick={() => void loadOlder()}>
          {olderBusy ? t("list.older_loading") : t("list.older")}
        </Button>
      ) : rows.length > 0 ? (
        <p className="text-[13px] text-neutral-600">{t("list.end")}</p>
      ) : null}
      {snapshot.state.serverTime ? <p className="ml-auto text-[13px] tabular-nums text-neutral-600">{t("updated", { time: addisStamp(snapshot.state.serverTime, dates) })}</p> : null}
    </div>
  ) : undefined;

  return (
    <>
      <PageHeader
        title={t("list.title")}
        intro={t("list.intro")}
        twin={titleAm ? { text: titleAm, lang: "am" } : undefined}
        actions={checker ? <ExportButtons exporter={exporter} /> : undefined}
      />
      <div className="flex flex-col gap-6">
        <PaymentsNav />
        <FilterForm key={queryKey} filters={filters} onApply={(next) => go(filterParams(next))} onClear={() => go({})} />
        {checker && exporter.error ? <Alert>{exporter.errorText}</Alert> : null}
        {checker ? <Status>{exporter.saved ? t("list.exported", { name: exporter.saved }) : null}</Status> : null}
        {snapshot?.error && snapshot.loaded ? <Notice tone="amber">{t("stale")}</Notice> : null}
        {!snapshot?.loaded ? (
          snapshot?.error ? (
            <FeedError error={snapshot.error} onRetry={() => feed?.refresh()} />
          ) : (
            <div className="space-y-3">
              <p role="status" className="text-[15px] text-neutral-600">
                {t("list.loading")}
              </p>
              <div aria-hidden="true" className="space-y-3 rounded-[18px] border border-neutral-200 bg-white p-5">
                {Array.from({ length: 6 }, (_, i) => (
                  <Skeleton key={i} className="h-12" />
                ))}
              </div>
            </div>
          )
        ) : (
          <PaymentsTable
            rows={rows}
            footer={footer}
            onOpen={(id) => go({ ...query, payment: String(id) })}
            onPhoto={(row) => row.receipt && setViewing({ paymentId: row.id, fileId: row.receipt.file_id })}
          />
        )}
        {olderError ? <OlderError error={olderError} /> : null}
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
      <Button variant="secondary" size="md" onClick={onRetry}>
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
    <form role="search" aria-label={t("list.filters")} onSubmit={onSubmit} className="space-y-2" noValidate>
      <Toolbar className="items-start">
        <ToolbarField label={t("list.from")} type="date" value={draft.from} error={issue === "dates" ? t("list.issue_dates") : undefined} onChange={(event) => set("from", event.target.value)} />
        <ToolbarField label={t("list.to")} type="date" value={draft.to} onChange={(event) => set("to", event.target.value)} />
        <ToolbarSelect label={t("list.channel")} value={draft.channelId} onChange={(event) => set("channelId", event.target.value)}>
          <option value="">{t("list.all_channels")}</option>
          {channels.map((channel) => (
            <option key={channel.id} value={String(channel.id)}>
              {channel.deleted_at ? t("list.channel_removed", { name: channelName(channel) }) : channelName(channel)}
            </option>
          ))}
          {draft.channelId && !known ? <option value={draft.channelId}>{`#${draft.channelId}`}</option> : null}
        </ToolbarSelect>
        <ToolbarSelect label={t("list.status")} value={draft.status} onChange={(event) => set("status", event.target.value as PaymentFilters["status"])}>
          <option value="">{t("list.all_statuses")}</option>
          {PAYMENT_STATUSES.map((status) => (
            <option key={status} value={status}>
              {t(`status.${status}`)}
            </option>
          ))}
        </ToolbarSelect>
        <ToolbarField
          label={t("list.order_no")}
          inputMode="numeric"
          value={draft.orderNo}
          maxLength={10}
          className="w-52"
          error={issue === "order_no" ? t("list.issue_order_no") : undefined}
          onChange={(event) => set("orderNo", event.target.value)}
        />
        <ToolbarCheck label={t("list.missing_photo")} checked={draft.missingPhoto} onChange={(event) => set("missingPhoto", event.target.checked)} />
        <ToolbarCheck label={t("list.flagged")} checked={draft.flagged} aria-describedby={flaggedHint} onChange={(event) => set("flagged", event.target.checked)} />
        <div className="flex gap-2.5">
          <Button type="submit" size="md">
            {t("list.apply")}
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="md"
            onClick={() => {
              setDraft(NO_FILTERS);
              setIssue(null);
              onClear();
            }}
          >
            {t("list.clear")}
          </Button>
        </div>
      </Toolbar>
      <p id={flaggedHint} className="text-[13px] text-neutral-600">
        {t("list.flagged_hint")}
      </p>
    </form>
  );
}

interface Exporter {
  busy: ExportFormat | null;
  error: unknown;
  errorText: string;
  saved: string | null;
  download: (format: ExportFormat) => void;
}

/** The export of the filtered list as CSV or Excel (payments.verify), one file at a time, with what came of the last one. */
function useExport(filters: PaymentFilters, query: Record<string, string>): Exporter {
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

  return { busy, error, errorText: error ? errorText.message(error) : "", saved, download: (format) => void download(format) };
}

/** The two exports, the title row's actions: a group named "Export", described by what the file holds. */
function ExportButtons({ exporter }: { exporter: Exporter }) {
  const t = useTranslations("adminPayments");
  const hint = useId();
  return (
    <div role="group" aria-label={t("list.export_title")} aria-describedby={hint} className="flex flex-wrap gap-2.5">
      <span id={hint} className="sr-only">
        {t("list.export_hint")}
      </span>
      {(["csv", "xlsx"] as const).map((format) => (
        <Button key={format} variant="secondary" size="md" disabled={exporter.busy !== null} title={t("list.export_hint")} onClick={() => exporter.download(format)}>
          <Download className="size-[18px]" strokeWidth={2} aria-hidden="true" />
          {exporter.busy === format ? t("list.exporting") : format === "csv" ? t("list.export_csv") : t("list.export_xlsx")}
        </Button>
      ))}
    </div>
  );
}

function PaymentsTable({
  rows,
  footer,
  onOpen,
  onPhoto,
}: {
  rows: PaymentListRow[];
  footer: ReactNode;
  onOpen: (id: number) => void;
  onPhoto: (row: PaymentListRow) => void;
}) {
  const t = useTranslations("adminPayments");
  const dates = useDateFormat();
  const channelName = useChannelName();
  const orderLabel = useOrderLabel();
  return (
    <TableCard caption={t("list.table")} footer={footer} minWidth="min-w-[960px]">
      <thead>
        <tr>
          <th scope="col">{t("list.col_payment")}</th>
          <th scope="col">{t("list.col_received")}</th>
          <th scope="col">{t("list.col_order")}</th>
          <th scope="col">{t("list.col_channel")}</th>
          <th scope="col" className="text-right">
            {t("list.col_amount")}
          </th>
          <th scope="col">{t("list.col_reference")}</th>
          <th scope="col">{t("list.col_photo")}</th>
          <th scope="col">{t("list.col_status")}</th>
          <th scope="col">
            <span className="sr-only">{t("list.col_actions")}</span>
          </th>
        </tr>
      </thead>
      <tbody>
        {rows.length === 0 ? (
          <EmptyRow span={9}>{t("list.empty")}</EmptyRow>
        ) : (
          rows.map((row) => (
            <tr key={row.id}>
              <td className="tabular-nums text-neutral-600">{row.id}</td>
              <td className="whitespace-nowrap tabular-nums">
                {addisStamp(row.received_at, dates)}
                {row.received_by.name ? <span className="block text-[13px] text-neutral-600">{t("list.by", { name: row.received_by.name })}</span> : null}
              </td>
              <td>
                <span className="whitespace-nowrap font-semibold">{t("list.order_label", { no: orderLabel(row.order) })}</span>
                {row.order?.table_code ? <span className="block text-[13px] text-neutral-600">{t("list.table_code", { table: row.order.table_code })}</span> : null}
              </td>
              <td className="text-neutral-700">{channelName(row.channel)}</td>
              <td className="whitespace-nowrap text-right font-semibold tabular-nums">
                <Money amount={row.amount} currency={row.currency} />
              </td>
              <td className="whitespace-nowrap font-mono text-sm text-neutral-700">{row.reference_no ?? "—"}</td>
              <td>
                {row.receipt ? (
                  <PhotoThumb key={row.receipt.url ?? "none"} label={t("list.photo_open", { id: row.id })} fileId={row.receipt.file_id} url={row.receipt.url} onOpen={() => onPhoto(row)} />
                ) : (
                  <span className="whitespace-nowrap text-[13px] text-neutral-600">{t("list.no_photo")}</span>
                )}
              </td>
              <td>
                <PaymentStatusCell payment={row} />
              </td>
              <td className="text-right">
                <IconButton label={t("list.open_aria", { id: row.id })} size="md" onClick={() => onOpen(row.id)}>
                  <ChevronRight className="size-[18px]" strokeWidth={2} aria-hidden="true" />
                </IconButton>
              </td>
            </tr>
          ))
        )}
      </tbody>
    </TableCard>
  );
}
