"use client";

import { useTranslations } from "next-intl";
import { useMemo, useState, type FormEvent } from "react";
import { Link } from "@/i18n/navigation";
import { useAllChannels } from "@/lib/api/adminPayments";
import { usePaymentChannels } from "@/lib/api/till";
import type { ReconciliationCell, ReconciliationChannelRow } from "@/lib/api/types";
import { canDrill, dayTotals, listedChannels, missingPhotoDrill, rangeIssue, shiftDate } from "@/lib/adminPayments";
import { useErrorText } from "@/lib/errors";
import { createReconciliationFeed } from "@/lib/live/payments";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { businessToday } from "@/lib/orderNo";
import { can, useSession } from "@/lib/session";
import { Alert, Button, Field, PageHeader, Select, TextInput } from "../ui";
import { focusRing } from "../styles";
import { useChannelName } from "./shared";

// Admin > Payments > Reconciliation (step 4.5c; payments.verify): what each payment method took on each local day of a range (at most 62, the
// last 7 by default), to set against the bank's statement: receipts and amount (voided payments left out, a disputed one still counted),
// with and without a photo, with a reference, verified, disputed and voided, and the refunds paid back that day (none until step 4.3d),
// then the net. A day's total and the range's totals are added on decimal strings, never floats. A "without photo" cell opens the payments
// of that day and method that have no photo. The report is a feed (lib/live/payments.ts: every 30 s visible, 60 s hidden, 304 when unchanged).

const COLUMNS = ["receipts", "amount", "with_photo", "without_photo", "with_reference", "verified", "disputed", "voided", "refunds", "refund_amount", "net_amount"] as const;
const MONEY = new Set<string>(["amount", "refund_amount", "net_amount"]);
const HEADINGS: Record<(typeof COLUMNS)[number], string> = {
  receipts: "col_receipts",
  amount: "col_amount",
  with_photo: "col_with_photo",
  without_photo: "col_without_photo",
  with_reference: "col_with_reference",
  verified: "col_verified",
  disputed: "col_disputed",
  voided: "col_voided",
  refunds: "col_refunds",
  refund_amount: "col_refund_amount",
  net_amount: "col_net",
};

interface Range {
  from: string;
  to: string;
  channelId: string;
}

export function ReconciliationScreen() {
  const t = useTranslations("adminPayments");
  const signedIn = useSession((s) => s.status === "signed-in");
  const [range, setRange] = useState<Range>(() => {
    const today = businessToday();
    return { from: shiftDate(today, -6), to: today, channelId: "" };
  });
  const queryKey = JSON.stringify(range);
  // eslint-disable-next-line react-hooks/exhaustive-deps -- one feed per range; the key holds every field of it
  const feed = useMemo(() => (signedIn ? createReconciliationFeed({ from: range.from, to: range.to, ...(range.channelId ? { channel_id: range.channelId } : {}) }) : null), [queryKey, signedIn]);
  const snapshot = useLiveFeed(feed);
  const report = snapshot?.state.report ?? null;

  return (
    <>
      <PageHeader title={t("reconciliation.title")} intro={t("reconciliation.intro")} />
      <div className="space-y-6">
        <RangeForm key={queryKey} range={range} onApply={setRange} />
        <p className="text-sm text-neutral-600">{t("reconciliation.note")}</p>
        {snapshot?.error && snapshot.loaded ? <Alert>{t("stale")}</Alert> : null}
        {report === null ? (
          snapshot?.error ? (
            <ReportError error={snapshot.error} onRetry={() => feed?.refresh()} />
          ) : (
            <p role="status" className="py-6 text-base text-neutral-600">
              {t("reconciliation.loading")}
            </p>
          )
        ) : (
          <>
            {[...report.days].reverse().map((day) => (day.channels.length === 0 ? null : <DayTable key={day.day} day={day.day} rows={day.channels} />))}
            {report.days.some((day) => day.channels.length === 0) ? (
              <p className="rounded-lg border border-neutral-200 bg-white px-5 py-3 text-base text-neutral-600">
                {t("reconciliation.days_empty", {
                  days: [...report.days]
                    .reverse()
                    .filter((day) => day.channels.length === 0)
                    .map((day) => day.day)
                    .join(", "),
                })}
              </p>
            ) : null}
            <TotalsTable rows={report.totals.channels} total={report.totals} />
          </>
        )}
      </div>
    </>
  );
}

function ReportError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
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

function RangeForm({ range, onApply }: { range: Range; onApply: (range: Range) => void }) {
  const t = useTranslations("adminPayments");
  const channelName = useChannelName();
  const me = useSession((s) => s.me);
  const manager = can(me, "channels.manage");
  const all = useAllChannels(manager);
  const live = usePaymentChannels();
  const channels = listedChannels((manager ? all.data : live.data) ?? []);
  const [draft, setDraft] = useState<Range>(range);
  const [issue, setIssue] = useState<"dates" | "order" | "too_long" | null>(null);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const found = rangeIssue(draft.from, draft.to);
    setIssue(found);
    if (found === null) onApply(draft);
  }

  return (
    <form role="search" aria-label={t("reconciliation.filters")} onSubmit={onSubmit} className="space-y-4 rounded-lg border border-neutral-200 bg-white p-5" noValidate>
      <div className="grid gap-4 sm:grid-cols-3">
        <Field label={t("reconciliation.from")} error={issue ? t(`reconciliation.issue_${issue}`) : undefined}>
          {(props) => <TextInput {...props} type="date" value={draft.from} onChange={(event) => setDraft({ ...draft, from: event.target.value })} />}
        </Field>
        <Field label={t("reconciliation.to")}>{(props) => <TextInput {...props} type="date" value={draft.to} onChange={(event) => setDraft({ ...draft, to: event.target.value })} />}</Field>
        <Field label={t("reconciliation.channel")}>
          {(props) => (
            <Select {...props} value={draft.channelId} onChange={(event) => setDraft({ ...draft, channelId: event.target.value })}>
              <option value="">{t("reconciliation.all_channels")}</option>
              {channels.map((channel) => (
                <option key={channel.id} value={String(channel.id)}>
                  {channel.deleted_at ? t("list.channel_removed", { name: channelName(channel) }) : channelName(channel)}
                </option>
              ))}
            </Select>
          )}
        </Field>
      </div>
      <Button type="submit">{t("reconciliation.apply")}</Button>
    </form>
  );
}

function Cells({ cell, drill }: { cell: ReconciliationCell; drill?: { href: { pathname: string; query: Record<string, string> }; label: string } }) {
  return (
    <>
      {COLUMNS.map((column) => {
        const value = cell[column];
        const money = MONEY.has(column);
        return (
          <td key={column} className={`py-3 pr-4 text-right tabular-nums ${money ? "whitespace-nowrap" : ""}`}>
            {column === "without_photo" && drill ? (
              <Link href={drill.href} aria-label={drill.label} className={`inline-flex min-h-12 min-w-12 items-center justify-end rounded-md px-2 font-semibold text-blue-800 underline hover:bg-blue-50 ${focusRing}`}>
                {String(value)}
              </Link>
            ) : (
              String(value)
            )}
          </td>
        );
      })}
    </>
  );
}

function Head({ first }: { first: string }) {
  const t = useTranslations("adminPayments");
  return (
    <thead>
      <tr className="border-b border-neutral-200 text-sm text-neutral-600">
        <th scope="col" className="py-2 pr-4 font-medium">
          {first}
        </th>
        {COLUMNS.map((column) => (
          <th key={column} scope="col" className="py-2 pr-4 text-right font-medium">
            {t(`reconciliation.${HEADINGS[column]}`)}
          </th>
        ))}
      </tr>
    </thead>
  );
}

function DayTable({ day, rows }: { day: string; rows: ReconciliationChannelRow[] }) {
  const t = useTranslations("adminPayments");
  const channelName = useChannelName();
  const total = dayTotals(rows);
  return (
    <div className="overflow-x-auto rounded-lg border border-neutral-200 bg-white px-4">
      <table className="w-full border-collapse text-left text-base">
        <caption className="py-3 text-left text-lg font-semibold text-neutral-900">{t("reconciliation.day", { day })}</caption>
        <Head first={t("reconciliation.col_channel")} />
        <tbody>
          {rows.map((row) => {
            const name = channelName(row.channel);
            return (
              <tr key={row.channel.id} className="border-b border-neutral-100">
                <th scope="row" className="py-3 pr-4 font-medium text-neutral-900">
                  {name}
                </th>
                <Cells
                  cell={row}
                  drill={canDrill(row) ? { href: { pathname: "/admin/payments", query: missingPhotoDrill(day, row.channel.id) }, label: t("reconciliation.drill", { channel: name, day }) } : undefined}
                />
              </tr>
            );
          })}
        </tbody>
        <tfoot>
          <tr className="border-t-2 border-neutral-300 font-semibold">
            <th scope="row" className="py-3 pr-4">
              {t("reconciliation.day_total")}
            </th>
            <Cells cell={total} />
          </tr>
        </tfoot>
      </table>
    </div>
  );
}

function TotalsTable({ rows, total }: { rows: ReconciliationChannelRow[]; total: ReconciliationCell }) {
  const t = useTranslations("adminPayments");
  const channelName = useChannelName();
  return (
    <div className="overflow-x-auto rounded-lg border border-neutral-300 bg-neutral-50 px-4">
      <table className="w-full border-collapse text-left text-base">
        <caption className="py-3 text-left text-lg font-semibold text-neutral-900">{t("reconciliation.totals")}</caption>
        <Head first={t("reconciliation.col_channel")} />
        <tbody>
          {rows.length === 0 ? (
            <tr>
              <td colSpan={COLUMNS.length + 1} className="py-4 text-neutral-600">
                {t("reconciliation.range_empty")}
              </td>
            </tr>
          ) : (
            rows.map((row) => (
              <tr key={row.channel.id} className="border-b border-neutral-200">
                <th scope="row" className="py-3 pr-4 font-medium text-neutral-900">
                  {channelName(row.channel)}
                </th>
                <Cells cell={row} />
              </tr>
            ))
          )}
        </tbody>
        <tfoot>
          <tr className="border-t-2 border-neutral-300 font-semibold">
            <th scope="row" className="py-3 pr-4">
              {t("reconciliation.range_total")}
            </th>
            <Cells cell={total} />
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
