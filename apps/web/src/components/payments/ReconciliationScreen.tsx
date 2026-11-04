"use client";

import { useTranslations } from "next-intl";
import { useMemo, useState, type FormEvent } from "react";
import { Link } from "@/i18n/navigation";
import { useAllChannels } from "@/lib/api/adminPayments";
import { usePaymentChannels } from "@/lib/api/till";
import type { ReconciliationCell, ReconciliationChannelRow } from "@/lib/api/types";
import { canDrill, dayTotals, listedChannels, missingPhotoDrill, rangeIssue, shiftDate } from "@/lib/adminPayments";
import { groupDigits } from "@/lib/analytics";
import { useErrorText } from "@/lib/errors";
import { createReconciliationFeed } from "@/lib/live/payments";
import { useLiveFeed } from "@/lib/live/useLiveFeed";
import { businessToday } from "@/lib/orderNo";
import { can, useSession } from "@/lib/session";
import { Notice, TableCard, Toolbar, ToolbarField, ToolbarSelect } from "../admin/kit";
import { Alert, Button, PageHeader, Skeleton, Stat } from "../ui";
import { focusRing } from "../styles";
import { PaymentsNav, useChannelName } from "./shared";

// Admin > Payments > Reconciliation (step 4.5c; payments.verify): what each payment method took on each local day of a range (at most 62, the
// last 7 by default), to set against the bank's statement: receipts and amount (voided payments left out, a disputed one still counted),
// with and without a photo, with a reference, verified, disputed and voided, and the refunds paid back that day (none until step 4.3d),
// then the net. A day's total and the range's totals are added on decimal strings, never floats. A "without photo" cell opens the payments
// of that day and method that have no photo. The report is a feed (lib/live/payments.ts: every 30 s visible, 60 s hidden, 304 when unchanged).
//
// Step 6.4c (fresh-counter.md 4.4, the Admin board's KPI row and the CatalogList board's table): the title row with its Amharic twin, the
// section's tabs, the range as the toolbar (still the search landmark with the same labels), the range's totals as four Stats (the figures
// the totals table holds), then each day a table card titled by its day, the drill a brand-teal link, the day's total in the table's foot.

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

export function ReconciliationScreen({ titleAm }: { titleAm?: string }) {
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
  const empty = report ? [...report.days].reverse().filter((day) => day.channels.length === 0) : [];

  return (
    <>
      <PageHeader title={t("reconciliation.title")} intro={t("reconciliation.intro")} twin={titleAm ? { text: titleAm, lang: "am" } : undefined} />
      <div className="flex flex-col gap-6">
        <PaymentsNav />
        <RangeForm key={queryKey} range={range} onApply={setRange} />
        {snapshot?.error && snapshot.loaded ? <Notice tone="amber">{t("stale")}</Notice> : null}
        {report === null ? (
          snapshot?.error ? (
            <ReportError error={snapshot.error} onRetry={() => feed?.refresh()} />
          ) : (
            <div className="space-y-3">
              <p role="status" className="text-[15px] text-neutral-600">
                {t("reconciliation.loading")}
              </p>
              <div aria-hidden="true" className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                {[0, 1, 2, 3].map((i) => (
                  <Skeleton key={i} className="h-28 rounded-2xl" />
                ))}
              </div>
            </div>
          )
        ) : (
          <>
            <RangeStats total={report.totals} />
            <p className="-mt-2 text-[13px] text-neutral-600">{t("reconciliation.note")}</p>
            {[...report.days].reverse().map((day) => (day.channels.length === 0 ? null : <DayTable key={day.day} day={day.day} rows={day.channels} />))}
            {empty.length > 0 ? <Notice tone="neutral">{t("reconciliation.days_empty", { days: empty.map((day) => day.day).join(", ") })}</Notice> : null}
            <TotalsTable rows={report.totals.channels} total={report.totals} />
          </>
        )}
      </div>
    </>
  );
}

/** The range at a glance, the Admin board's KPI row: receipts, the amount taken, what was paid back and the net, from the range's totals. */
function RangeStats({ total }: { total: ReconciliationCell }) {
  const t = useTranslations("adminPayments");
  // the report carries no currency: every payment of a branch is in its one currency, the birr
  const money = (amount: string) => t("money", { amount: groupDigits(amount), currency: "ETB" });
  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <Stat label={t("reconciliation.col_receipts")} value={total.receipts} />
      <Stat label={t("reconciliation.col_amount")} value={money(total.amount)} />
      <Stat label={t("reconciliation.col_refund_amount")} value={money(total.refund_amount)} />
      <Stat label={t("reconciliation.col_net")} value={money(total.net_amount)} />
    </div>
  );
}

function ReportError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
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
    <form role="search" aria-label={t("reconciliation.filters")} onSubmit={onSubmit} noValidate>
      <Toolbar className="items-start">
        <ToolbarField
          label={t("reconciliation.from")}
          type="date"
          value={draft.from}
          error={issue ? t(`reconciliation.issue_${issue}`) : undefined}
          onChange={(event) => setDraft({ ...draft, from: event.target.value })}
        />
        <ToolbarField label={t("reconciliation.to")} type="date" value={draft.to} onChange={(event) => setDraft({ ...draft, to: event.target.value })} />
        <ToolbarSelect label={t("reconciliation.channel")} value={draft.channelId} onChange={(event) => setDraft({ ...draft, channelId: event.target.value })}>
          <option value="">{t("reconciliation.all_channels")}</option>
          {channels.map((channel) => (
            <option key={channel.id} value={String(channel.id)}>
              {channel.deleted_at ? t("list.channel_removed", { name: channelName(channel) }) : channelName(channel)}
            </option>
          ))}
        </ToolbarSelect>
        <Button type="submit" size="md">
          {t("reconciliation.apply")}
        </Button>
      </Toolbar>
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
          <td key={column} className={`text-right tabular-nums ${money ? "whitespace-nowrap font-semibold" : ""}`}>
            {column === "without_photo" && drill ? (
              <Link
                href={drill.href}
                aria-label={drill.label}
                className={`-my-1 inline-flex min-h-11 min-w-11 items-center justify-end rounded-lg px-2 font-semibold text-brand-600 underline underline-offset-2 hover:bg-brand-50 hover:text-brand-700 ${focusRing}`}
              >
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
      <tr>
        <th scope="col">{first}</th>
        {COLUMNS.map((column) => (
          <th key={column} scope="col" className="text-right">
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
  const title = t("reconciliation.day", { day });
  return (
    <TableCard caption={title} title={title} minWidth="min-w-[960px]" compact>
      <Head first={t("reconciliation.col_channel")} />
      <tbody>
        {rows.map((row) => {
          const name = channelName(row.channel);
          return (
            <tr key={row.channel.id}>
              <th scope="row" className="font-semibold text-neutral-900">
                {name}
              </th>
              <Cells
                cell={row}
                drill={canDrill(row) ? { href: { pathname: "/admin/payments", query: missingPhotoDrill(day, row.channel.id) }, label: t("reconciliation.drill", { channel: name, day, count: String(row.without_photo) }) } : undefined}
              />
            </tr>
          );
        })}
      </tbody>
      <tfoot>
        <tr className="font-semibold">
          <th scope="row">{t("reconciliation.day_total")}</th>
          <Cells cell={total} />
        </tr>
      </tfoot>
    </TableCard>
  );
}

function TotalsTable({ rows, total }: { rows: ReconciliationChannelRow[]; total: ReconciliationCell }) {
  const t = useTranslations("adminPayments");
  const channelName = useChannelName();
  return (
    <TableCard caption={t("reconciliation.totals")} title={t("reconciliation.totals")} minWidth="min-w-[960px]" compact>
      <Head first={t("reconciliation.col_channel")} />
      <tbody>
        {rows.length === 0 ? (
          <tr>
            <td colSpan={COLUMNS.length + 1} className="text-neutral-600">
              {t("reconciliation.range_empty")}
            </td>
          </tr>
        ) : (
          rows.map((row) => (
            <tr key={row.channel.id}>
              <th scope="row" className="font-semibold text-neutral-900">
                {channelName(row.channel)}
              </th>
              <Cells cell={row} />
            </tr>
          ))
        )}
      </tbody>
      <tfoot>
        <tr className="font-semibold">
          <th scope="row">{t("reconciliation.range_total")}</th>
          <Cells cell={total} />
        </tr>
      </tfoot>
    </TableCard>
  );
}
